using System;
using System.Diagnostics;
using System.Runtime.InteropServices;
using System.Text;
using AZCKeeper_Cliente.Logging;

namespace AZCKeeper_Cliente.Tracking
{
    /// <summary>
    /// Rastrea la ventana activa del sistema.
    ///
    /// Diseño híbrido (recomendado):
    /// - "Episodios" (start/end/duration) para persistencia en backend (bajo volumen).
    /// - Métricas en tiempo real:
    ///     - IsInCallNow: si actualmente la ventana activa se clasifica como llamada.
    ///     - CallSessionSeconds: segundos acumulados en sesión en apps de llamada,
    ///       incluyendo el episodio actual aunque aún no haya "cambio de ventana".
    /// 
    /// Esto permite:
    /// - UI en tiempo real (DebugWindow).
    /// - ActivityTracker pueda considerar "en llamada" como activo (no-away).
    /// - BD sin ruido (solo cambios).
    /// Comunicación:
    /// - CoreService consume OnEpisodeClosed para enviar a ApiClient.
    /// - DebugWindowForm lee métricas en tiempo real (CallSessionSeconds, IsInCallNow).
    /// - ActivityTracker puede usar IsInCallNow para override de actividad.
    /// </summary>
    internal class WindowTracker
    {
        /// <summary>
        /// Episodio de ventana (unidad persistente para backend).
        /// </summary>
        internal class WindowEpisode
        {
            public DateTime StartLocalTime { get; set; }
            public DateTime EndLocalTime { get; set; }
            public double DurationSeconds { get; set; }
            public string ProcessName { get; set; }
            public string WindowTitle { get; set; }
            public bool IsCallApp { get; set; }
        }

        private readonly double _intervalSeconds;

        private readonly bool _enableCallTracking;
        private readonly string[] _callProcessKeywords;
        private readonly string[] _callTitleKeywords;

        private System.Timers.Timer _timer;
        private IntPtr _hookHandle = IntPtr.Zero;
        private WinEventDelegate _hookDelegate; // Mantener referencia para evitar GC

        private DateTime _lastSampleLocalTime;

        // Estado del episodio actual (ventana actual)
        private DateTime _currentEpisodeStartLocalTime;
        private string _currentProcessName = string.Empty;
        private string _currentWindowTitle = string.Empty;
        private bool _currentIsCallApp;

        // Métrica en tiempo real (sesión) para apps de llamada:
        // _callSessionClosedEpisodesSeconds: suma de episodios cerrados de llamadas
        // _currentCallEpisodeStartLocalTime: inicio del episodio actual si es llamada
        private double _callSessionClosedEpisodesSeconds;
        private DateTime _currentCallEpisodeStartLocalTime;

        /// <summary>
        /// Se dispara cuando se detecta un cambio de ventana y se cierra el episodio anterior.
        /// </summary>
        public Action<WindowEpisode> OnEpisodeClosed { get; set; }

        /// <summary>
        /// Se dispara cuando cambia la ventana (bajo ruido).
        /// </summary>
        public Action<DateTime, string, string> OnWindowSnapshot { get; set; }

        // Para Debug/UI
        internal DateTime LastSnapshotLocalTime => _lastSampleLocalTime;
        internal string LastProcessName => _currentProcessName;
        internal string LastWindowTitle => _currentWindowTitle;
        internal DateTime LastChangeLocalTime => _currentEpisodeStartLocalTime;

        internal bool CallTrackingEnabled => _enableCallTracking;

        /// <summary>
        /// True si, en este instante, la ventana activa se clasifica como llamada.
        /// (Solo tiene sentido si CallTrackingEnabled = true)
        /// </summary>
        internal bool IsInCallNow => _enableCallTracking && _currentIsCallApp;

        /// <summary>
        /// Segundos acumulados en sesión en apps de llamada (foreground).
        /// Incluye el episodio actual (realtime), no solo episodios cerrados.
        /// Se calcula dinámicamente para evitar drift por acumulación tick a tick.
        /// </summary>
        internal double CallSessionSeconds
        {
            get
            {
                if (!_enableCallTracking)
                    return 0;

                double total = _callSessionClosedEpisodesSeconds;

                // Si ahora mismo está en llamada, sumar tiempo desde inicio del episodio actual
                if (_currentIsCallApp && _currentCallEpisodeStartLocalTime != DateTime.MinValue)
                {
                    double currentDelta = (DateTime.Now - _currentCallEpisodeStartLocalTime).TotalSeconds;
                    if (currentDelta > 0 && currentDelta < 86400) // guardrail: <24h
                        total += currentDelta;
                }

                return total;
            }
        }

        /// <summary>
        /// Crea tracker de ventanas y parámetros de detección de llamadas.
        /// </summary>
        public WindowTracker(
            double intervalSeconds,
            bool enableCallTracking,
            string[] callProcessKeywords,
            string[] callTitleKeywords)
        {
            _intervalSeconds = intervalSeconds;
            _enableCallTracking = enableCallTracking;
            _callProcessKeywords = callProcessKeywords ?? Array.Empty<string>();
            _callTitleKeywords = callTitleKeywords ?? Array.Empty<string>();
        }

        /// <summary>
        /// Inicia el muestreo periódico de ventana activa.
        /// </summary>
        public void Start()
        {
            if (_timer != null || _hookHandle != IntPtr.Zero)
                return;

            LocalLogger.Info($"WindowTracker.Start(): iniciando tracking de ventana. Interval={_intervalSeconds:F3}s. CallTracking={_enableCallTracking}");

            _lastSampleLocalTime = DateTime.Now;

            _currentEpisodeStartLocalTime = DateTime.MinValue;
            _currentProcessName = string.Empty;
            _currentWindowTitle = string.Empty;
            _currentIsCallApp = false;

            _callSessionClosedEpisodesSeconds = 0;
            _currentCallEpisodeStartLocalTime = DateTime.MinValue;

            // Instalar hook para detectar cambios de ventana en tiempo real
            _hookDelegate = new WinEventDelegate(WinEventProc);
            _hookHandle = SetWinEventHook(EVENT_SYSTEM_FOREGROUND, EVENT_SYSTEM_FOREGROUND, IntPtr.Zero, _hookDelegate, 0, 0, WINEVENT_OUTOFCONTEXT);

            if (_hookHandle == IntPtr.Zero)
            {
                LocalLogger.Warn("WindowTracker.Start(): No se pudo instalar hook de ventana. Usando timer de fallback.");
            }
            else
            {
                LocalLogger.Info("WindowTracker.Start(): Hook de ventana instalado correctamente.");
            }

            // Timer de respaldo para casos donde el hook falle o para actualizar métricas
            _timer = new System.Timers.Timer(_intervalSeconds * 1000.0);
            _timer.AutoReset = true;
            _timer.Elapsed += Timer_Elapsed;
            _timer.Start();

            // Captura inicial
            CaptureCurrentWindow();
        }

        /// <summary>
        /// Detiene el muestreo y hace flush del episodio actual.
        /// </summary>
        public void Stop()
        {
            if (_timer == null && _hookHandle == IntPtr.Zero)
                return;

            LocalLogger.Info("WindowTracker.Stop(): deteniendo tracking de ventana.");

            try
            {
                // Flush del episodio actual para no perder datos.
                FlushCurrentEpisode(DateTime.Now);
            }
            catch
            {
                // No romper Stop por flush.
            }

            // Desinstalar hook
            if (_hookHandle != IntPtr.Zero)
            {
                UnhookWinEvent(_hookHandle);
                _hookHandle = IntPtr.Zero;
                LocalLogger.Info("WindowTracker.Stop(): Hook de ventana desinstalado.");
            }

            if (_timer != null)
            {
                _timer.Stop();
                _timer.Elapsed -= Timer_Elapsed;
                _timer.Dispose();
                _timer = null;
            }

            _hookDelegate = null;
        }

        /// <summary>
        /// Callback del hook de Windows cuando cambia la ventana activa.
        /// </summary>
        private void WinEventProc(IntPtr hWinEventHook, uint eventType, IntPtr hwnd, int idObject, int idChild, uint dwEventThread, uint dwmsEventTime)
        {
            if (eventType == EVENT_SYSTEM_FOREGROUND && hwnd != IntPtr.Zero)
            {
                CaptureCurrentWindow();
            }
        }

        /// <summary>
        /// Tick del timer: actualiza métricas y detecta cambios (fallback si hook falla).
        /// </summary>
        private void Timer_Elapsed(object sender, System.Timers.ElapsedEventArgs e)
        {
            try
            {
                _lastSampleLocalTime = DateTime.Now;
                
                // Si el hook está activo, solo actualizar timestamp
                // Si no hay hook, hacer captura manual
                if (_hookHandle == IntPtr.Zero)
                {
                    CaptureCurrentWindow();
                }
            }
            catch (Exception ex)
            {
                LocalLogger.Error(ex, "WindowTracker.Timer_Elapsed(): error en timer.");
            }
        }

        /// <summary>
        /// Captura la ventana activa actual y detecta cambios.
        /// </summary>
        private void CaptureCurrentWindow()
        {
            try
            {
                var nowLocal = DateTime.Now;

                IntPtr hWnd = GetForegroundWindow();
                if (hWnd == IntPtr.Zero)
                    return;

                string title = GetWindowTextSafe(hWnd);
                if (string.IsNullOrWhiteSpace(title))
                    title = "(sin título)";

                string processName = GetProcessNameFromWindowHandle(hWnd);
                if (string.IsNullOrWhiteSpace(processName))
                    processName = "(desconocido)";

                // Primera lectura -> inicia episodio
                if (_currentEpisodeStartLocalTime == DateTime.MinValue)
                {
                    _currentEpisodeStartLocalTime = nowLocal;
                    _currentProcessName = processName;
                    _currentWindowTitle = title;
                    _currentIsCallApp = _enableCallTracking && IsCallApplication(processName, title);

                    // Si es llamada, registrar inicio para cálculo realtime
                    if (_currentIsCallApp)
                        _currentCallEpisodeStartLocalTime = nowLocal;

                    OnWindowSnapshot?.Invoke(nowLocal, processName, title);
                    return;
                }

                // Filtro de ruido
                if (string.Equals(processName, _currentProcessName, StringComparison.Ordinal) &&
                    string.Equals(title, _currentWindowTitle, StringComparison.Ordinal))
                {
                    // No cambió ventana: CallSessionSeconds se calcula dinámicamente en getter
                    return;
                }

                // Cambió la ventana -> cerrar episodio anterior y abrir nuevo
                CloseEpisodeAndStartNew(nowLocal, processName, title);
            }
            catch (Exception ex)
            {
                LocalLogger.Error(ex, "WindowTracker.CaptureCurrentWindow(): error al obtener ventana activa.");
            }
        }

        /// <summary>
        /// Cierra el episodio anterior y abre uno nuevo con la ventana actual.
        /// </summary>
        private void CloseEpisodeAndStartNew(DateTime nowLocal, string newProcess, string newTitle)
        {
            // Si el episodio anterior era llamada, acumular su duración a la sesión
            if (_enableCallTracking && _currentIsCallApp && _currentCallEpisodeStartLocalTime != DateTime.MinValue)
            {
                double episodeDuration = (nowLocal - _currentCallEpisodeStartLocalTime).TotalSeconds;
                if (episodeDuration > 0 && episodeDuration < 86400) // guardrail: <24h
                {
                    _callSessionClosedEpisodesSeconds += episodeDuration;
                }
                _currentCallEpisodeStartLocalTime = DateTime.MinValue;
            }

            // Cerrar episodio anterior para BD
            var episode = BuildEpisode(_currentEpisodeStartLocalTime, nowLocal, _currentProcessName, _currentWindowTitle, _currentIsCallApp);
            if (episode != null)
                OnEpisodeClosed?.Invoke(episode);

            // Iniciar episodio nuevo con +1ms para evitar overlap
            _currentEpisodeStartLocalTime = nowLocal.AddMilliseconds(1);
            _currentProcessName = newProcess;
            _currentWindowTitle = newTitle;
            _currentIsCallApp = _enableCallTracking && IsCallApplication(newProcess, newTitle);

            // Si el nuevo episodio es llamada, marcar inicio para cálculo realtime
            if (_currentIsCallApp)
                _currentCallEpisodeStartLocalTime = _currentEpisodeStartLocalTime;

            OnWindowSnapshot?.Invoke(nowLocal, newProcess, newTitle);
        }

        /// <summary>
        /// Cierra el episodio actual si existe (para no perder datos al detener).
        /// </summary>
        private void FlushCurrentEpisode(DateTime endLocalTime)
        {
            if (_currentEpisodeStartLocalTime == DateTime.MinValue)
                return;

            var episode = BuildEpisode(_currentEpisodeStartLocalTime, endLocalTime, _currentProcessName, _currentWindowTitle, _currentIsCallApp);
            if (episode != null)
                OnEpisodeClosed?.Invoke(episode);

            _currentEpisodeStartLocalTime = DateTime.MinValue;
            _currentProcessName = string.Empty;
            _currentWindowTitle = string.Empty;
            _currentIsCallApp = false;
        }

        /// <summary>
        /// Construye episodio válido (duration &gt; 0).
        /// </summary>
        private static WindowEpisode BuildEpisode(DateTime start, DateTime end, string process, string title, bool isCall)
        {
            if (start == DateTime.MinValue)
                return null;

            double seconds = (end - start).TotalSeconds;
            if (seconds <= 0)
                return null;

            return new WindowEpisode
            {
                StartLocalTime = start,
                EndLocalTime = end,
                DurationSeconds = seconds,
                ProcessName = process ?? string.Empty,
                WindowTitle = title ?? string.Empty,
                IsCallApp = isCall
            };
        }

        /// <summary>
        /// Determina si una ventana/proceso coincide con keywords de llamada.
        /// </summary>
        private bool IsCallApplication(string processName, string windowTitle)
        {
            string proc = processName?.ToLowerInvariant() ?? string.Empty;
            string title = windowTitle?.ToLowerInvariant() ?? string.Empty;

            foreach (var kw in _callProcessKeywords)
            {
                if (string.IsNullOrWhiteSpace(kw)) continue;
                if (proc.Contains(kw.ToLowerInvariant()))
                    return true;
            }

            foreach (var kw in _callTitleKeywords)
            {
                if (string.IsNullOrWhiteSpace(kw)) continue;
                if (title.Contains(kw.ToLowerInvariant()))
                    return true;
            }

            return false;
        }

        #region Win32

        [DllImport("user32.dll")]
        private static extern IntPtr GetForegroundWindow();

        [DllImport("user32.dll", CharSet = CharSet.Unicode)]
        private static extern int GetWindowTextW(IntPtr hWnd, StringBuilder lpString, int nMaxCount);

        [DllImport("user32.dll")]
        private static extern int GetWindowThreadProcessId(IntPtr hWnd, out int lpdwProcessId);

        // Hook de eventos de Windows para detectar cambios de ventana en tiempo real
        private delegate void WinEventDelegate(IntPtr hWinEventHook, uint eventType, IntPtr hwnd, int idObject, int idChild, uint dwEventThread, uint dwmsEventTime);

        [DllImport("user32.dll")]
        private static extern IntPtr SetWinEventHook(uint eventMin, uint eventMax, IntPtr hmodWinEventProc, WinEventDelegate lpfnWinEventProc, uint idProcess, uint idThread, uint dwFlags);

        [DllImport("user32.dll")]
        private static extern bool UnhookWinEvent(IntPtr hWinEventHook);

        private const uint EVENT_SYSTEM_FOREGROUND = 0x0003;
        private const uint WINEVENT_OUTOFCONTEXT = 0x0000;

        private static string GetWindowTextSafe(IntPtr hWnd)
        {
            try
            {
                const int nChars = 512;
                var sb = new StringBuilder(nChars);
                int length = GetWindowTextW(hWnd, sb, nChars);
                return length > 0 ? sb.ToString() : string.Empty;
            }
            catch
            {
                return string.Empty;
            }
        }

        private static string GetProcessNameFromWindowHandle(IntPtr hWnd)
        {
            try
            {
                GetWindowThreadProcessId(hWnd, out int pid);
                if (pid <= 0)
                    return string.Empty;

                using var proc = Process.GetProcessById(pid);
                return proc.ProcessName;
            }
            catch
            {
                return string.Empty;
            }
        }

        #endregion
    }
}
