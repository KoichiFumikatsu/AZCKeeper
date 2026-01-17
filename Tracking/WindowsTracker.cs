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
    /// </summary>
    internal class WindowTracker
    {
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

        private DateTime _lastSampleLocalTime;

        // Estado del episodio actual (ventana actual)
        private DateTime _currentEpisodeStartLocalTime;
        private string _currentProcessName = string.Empty;
        private string _currentWindowTitle = string.Empty;
        private bool _currentIsCallApp;

        // Métrica en tiempo real (sesión) para apps de llamada:
        // Acumula solo episodios cerrados + (si está en llamada) delta desde último tick.
        private double _callSessionSeconds;
        private DateTime _lastCallRealtimeUpdateLocalTime;

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
        /// </summary>
        internal double CallSessionSeconds => _callSessionSeconds;

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

        public void Start()
        {
            if (_timer != null)
                return;

            LocalLogger.Info($"WindowTracker.Start(): iniciando tracking de ventana. Interval={_intervalSeconds:F3}s. CallTracking={_enableCallTracking}");

            _lastSampleLocalTime = DateTime.Now;

            _currentEpisodeStartLocalTime = DateTime.MinValue;
            _currentProcessName = string.Empty;
            _currentWindowTitle = string.Empty;
            _currentIsCallApp = false;

            _callSessionSeconds = 0;
            _lastCallRealtimeUpdateLocalTime = DateTime.Now;

            _timer = new System.Timers.Timer(_intervalSeconds * 1000.0);
            _timer.AutoReset = true;
            _timer.Elapsed += Timer_Elapsed;
            _timer.Start();
        }

        public void Stop()
        {
            if (_timer == null)
                return;

            LocalLogger.Info("WindowTracker.Stop(): deteniendo tracking de ventana.");

            try
            {
                // Actualizar realtime por si estaba en llamada al cerrar.
                UpdateRealtimeCallSeconds(DateTime.Now);

                // Flush del episodio actual para no perder datos.
                FlushCurrentEpisode(DateTime.Now);
            }
            catch
            {
                // No romper Stop por flush.
            }

            _timer.Stop();
            _timer.Elapsed -= Timer_Elapsed;
            _timer.Dispose();
            _timer = null;
        }

        private void Timer_Elapsed(object sender, System.Timers.ElapsedEventArgs e)
        {
            try
            {
                var nowLocal = DateTime.Now;
                _lastSampleLocalTime = nowLocal;

                // Realtime: si está en llamada, acumula delta desde último tick.
                UpdateRealtimeCallSeconds(nowLocal);

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

                    OnWindowSnapshot?.Invoke(nowLocal, processName, title);
                    return;
                }

                // Filtro de ruido
                if (string.Equals(processName, _currentProcessName, StringComparison.Ordinal) &&
                    string.Equals(title, _currentWindowTitle, StringComparison.Ordinal))
                {
                    // No cambió ventana: ya acumulamos realtime arriba si estaba en llamada.
                    return;
                }

                // Cambió la ventana -> cerrar episodio anterior y abrir nuevo
                CloseEpisodeAndStartNew(nowLocal, processName, title);
            }
            catch (Exception ex)
            {
                LocalLogger.Error(ex, "WindowTracker.Timer_Elapsed(): error al obtener ventana activa.");
            }
        }

        /// <summary>
        /// Acumula segundos de llamada en tiempo real si la ventana actual es llamada.
        /// No genera ruido (no dispara eventos).
        /// </summary>
        private void UpdateRealtimeCallSeconds(DateTime nowLocal)
        {
            if (!_enableCallTracking)
            {
                _lastCallRealtimeUpdateLocalTime = nowLocal;
                return;
            }

            // Si no está en llamada ahora, no acumulamos.
            if (!_currentIsCallApp)
            {
                _lastCallRealtimeUpdateLocalTime = nowLocal;
                return;
            }

            double delta = (nowLocal - _lastCallRealtimeUpdateLocalTime).TotalSeconds;
            if (delta > 0 && delta < 60) // guardrail para evitar saltos raros (suspensión/clock jump)
            {
                _callSessionSeconds += delta;
            }

            _lastCallRealtimeUpdateLocalTime = nowLocal;
        }

        private void CloseEpisodeAndStartNew(DateTime nowLocal, string newProcess, string newTitle)
        {
            // Antes de cerrar, si el episodio anterior era llamada:
            // - Ya estamos acumulando en realtime tick a tick.
            // - No sumamos DurationSeconds aquí a CallSessionSeconds, para evitar doble conteo.

            // Cerrar episodio anterior para BD
            var episode = BuildEpisode(_currentEpisodeStartLocalTime, nowLocal, _currentProcessName, _currentWindowTitle, _currentIsCallApp);
            if (episode != null)
                OnEpisodeClosed?.Invoke(episode);

            // Iniciar episodio nuevo
            _currentEpisodeStartLocalTime = nowLocal;
            _currentProcessName = newProcess;
            _currentWindowTitle = newTitle;
            _currentIsCallApp = _enableCallTracking && IsCallApplication(newProcess, newTitle);

            // Reset realtime reference time (para que deltas sean correctos desde el cambio)
            _lastCallRealtimeUpdateLocalTime = nowLocal;

            OnWindowSnapshot?.Invoke(nowLocal, newProcess, newTitle);
        }

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
