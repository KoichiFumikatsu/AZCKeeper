using System;
using System.Diagnostics;
using System.Runtime.InteropServices;
using System.Text;
using System.Windows.Forms;
using ModularApp.Core;
using ModularApp.Modules.Database;
using ModularApp.Modules.Login;

namespace ModularApp.Modules.WindowsTrack
{
    public sealed class WindowsTrackModule : IAppModule
    {
        private AppCore _core;
        private IDatabaseService _db;
        private ILoginService _login;
        private ModularApp.Modules.Connectivity.IConnectivityService _net;

        public string Name => "WindowsTrack";
        public bool Enabled => _core == null ? true : _core.Config.Modules.WindowsTrackEnabled;

        private System.Windows.Forms.Timer _tick;

        // Identidad de la ventana actual
        private string _lastTitle = "";
        private string _lastProc = null;

        // Día lógico en UTC para evitar DST
        private DateTime _currentDay = DateTime.UtcNow.Date;

        // Acumuladores
        private int _curSec = 0;          // segundos acumulados de la ventana actual
        private int _secsSinceFlush = 0;  // flush periódico

        // Buffer offline: key = "yyyy-MM-dd␟title␟proc", value = seconds
        private readonly System.Collections.Generic.Dictionary<string, int> _pending =
            new System.Collections.Generic.Dictionary<string, int>(StringComparer.Ordinal);

        // Reloj monotónico para medir segundos exactos
        private readonly Stopwatch _mono = Stopwatch.StartNew();
        private double _carryMs = 0;

        [DllImport("user32.dll")] static extern IntPtr GetForegroundWindow();
        [DllImport("user32.dll")] static extern int GetWindowText(IntPtr hWnd, StringBuilder text, int count);
        [DllImport("user32.dll")] static extern uint GetWindowThreadProcessId(IntPtr hWnd, out uint lpdwProcessId);

        public void Init(AppCore core)
        {
            _core = core;
            _db = core.Resolve<IDatabaseService>();
            _login = core.Resolve<ILoginService>();
            _net = core.Resolve<ModularApp.Modules.Connectivity.IConnectivityService>();
        }

        public void Start()
        {
            if (_login == null)
            {
                _core?.Logger.Warn("[WindowsTrack] Login service no disponible.");
                return;
            }

            _login.LoggedIn += OnLoggedIn;
            _login.LoggedOut += OnLoggedOut;

            if (_net != null)
            {
                _net.ConnectivityChanged += (online) =>
                {
                    if (online) TryFlushPendingIfOnline();
                };
            }

            EnsureStart();
        }

        private bool _started = false;

        private void EnsureStart()
        {
            if (_started) return;
            if (_login == null || !_login.IsLogged) return;

            _tick = new System.Windows.Forms.Timer { Interval = 1000 };
            _tick.Tick += Tick;
            _tick.Start();
            _mono.Restart();
            _started = true;

            TryFlushPendingIfOnline();
            _core?.Logger.Info("[WindowsTrack] Iniciado");
        }

        private void OnLoggedIn()
        {
            EnsureStart();
            TryFlushPendingIfOnline();
        }

        private void OnLoggedOut()
        {
            FlushCurrent();   // si está offline se bufferiza
            _tick?.Stop();
            _started = false;
        }

        public void Stop()
        {
            FlushCurrent();
            _tick?.Stop();
        }

        public void Dispose() { Stop(); }

        private void Tick(object s, EventArgs e)
        {
            // 1) Segundos exactos desde el último tick
            double elapsedMs = _mono.Elapsed.TotalMilliseconds;
            _mono.Restart();
            _carryMs += elapsedMs;
            int wholeSeconds = (int)(_carryMs / 1000.0); // floor
            if (wholeSeconds <= 0) return;
            _carryMs -= wholeSeconds * 1000.0;

            // 2) Rollover de día en UTC
            var today = DateTime.UtcNow.Date;
            if (today != _currentDay)
            {
                FlushCurrent();          // escribe lo acumulado del día anterior
                _currentDay = today;
                _lastTitle = "";
                _lastProc = null;
                _curSec = 0;
                _secsSinceFlush = 0;
            }

            // 3) Lee ventana foreground
            string title, proc;
            if (!TryGetActiveWindow(out title, out proc))
            {
                // Si no pudimos leer, asume continuidad de la última ventana conocida
                title = _lastTitle;
                proc = _lastProc;
            }

            // 4) Cambio de ventana -> flush inmediato de lo acumulado
            bool changed = !StringEquals(title, _lastTitle) || !StringEquals(proc, _lastProc);
            if (changed)
            {
                FlushCurrent();  // escribe _curSec para la ventana anterior
                _lastTitle = title;
                _lastProc = proc;
                _curSec = 0;

                if (!string.IsNullOrWhiteSpace(title))
                    _core?.Logger.Debug("[WindowsTrack] Ventana activa: " + (proc ?? "?") + " | " + title);
            }

            // 5) Acumula segundos a la ventana actual solo si hay título válido
            if (!string.IsNullOrWhiteSpace(_lastTitle))
            {
                _curSec += wholeSeconds;
                _secsSinceFlush += wholeSeconds;
            }

            // 6) Flush periódico cada 15 s contados para evitar pérdida en cierres
            if (_secsSinceFlush >= 15)
            {
                _secsSinceFlush = 0;
                // persistimos sin perder la ventana actual
                FlushCurrent(keepWindowIdentity: true);
            }
        }

        private bool TryGetActiveWindow(out string title, out string proc)
        {
            title = null; proc = null;

            var h = GetForegroundWindow();
            if (h == IntPtr.Zero) return false;

            var sb = new StringBuilder(256);
            if (GetWindowText(h, sb, sb.Capacity) <= 0) return false;

            title = sb.ToString();
            uint pid = 0;
            GetWindowThreadProcessId(h, out pid);
            try { proc = Process.GetProcessById((int)pid).ProcessName; }
            catch { proc = null; }

            return true;
        }

        private static bool StringEquals(string a, string b)
            => string.Equals(a ?? "", b ?? "", StringComparison.Ordinal);

        private void FlushCurrent(bool keepWindowIdentity = false)
        {
            if (_curSec <= 0) return;
            if (string.IsNullOrWhiteSpace(_lastTitle)) { _curSec = 0; return; }

            // Si no hay login activo, descarta acumulación de seguridad
            if (_login == null || !_login.IsLogged)
            {
                _curSec = 0;
                return;
            }

            var day = _currentDay;
            var title = _lastTitle;
            var proc = _lastProc;
            int sec = _curSec;

            // Si está offline, bufferiza
            if (_net != null && !_net.IsOnline)
            {
                BufferPending(day, title, proc, sec);
                _curSec = 0;
                return;
            }

            // Online -> guardar directo
            try
            {
                _db.UpsertWindowTimer(_login.EmployeeId, day, title, sec, proc);
            }
            catch (Exception ex)
            {
                BufferPending(day, title, proc, sec);
                _core?.Logger.Warn("[WindowsTrack] Flush directo falló; se bufferiza. Motivo: " + ex.Message);
            }
            finally
            {
                _curSec = 0;
                if (!keepWindowIdentity)
                {
                    _lastTitle = "";
                    _lastProc = null;
                }
            }
        }

        // ----------------- Buffer offline -----------------

        private static string MakeKey(DateTime day, string title, string proc)
            => day.ToString("yyyy-MM-dd") + "\u001F" + (title ?? "") + "\u001F" + (proc ?? "");

        private void BufferPending(DateTime day, string title, string proc, int seconds)
        {
            if (seconds <= 0 || string.IsNullOrWhiteSpace(title)) return;
            var key = MakeKey(day, title, proc);
            int cur;
            _pending.TryGetValue(key, out cur);
            _pending[key] = cur + seconds;
        }

        private void TryFlushPendingIfOnline()
        {
            if (_pending.Count == 0) return;
            if (_net != null && !_net.IsOnline) return;
            if (_login == null || !_login.IsLogged) return;

            int flushed = 0;
            var copy = new System.Collections.Generic.List<System.Collections.Generic.KeyValuePair<string, int>>(_pending);
            foreach (var kv in copy)
            {
                var parts = kv.Key.Split('\u001F');
                if (parts.Length != 3) continue;

                if (!DateTime.TryParse(parts[0], out var day)) continue;

                string title = parts[1];
                string proc = parts[2];
                int sec = kv.Value;
                if (sec <= 0 || string.IsNullOrWhiteSpace(title)) { _pending.Remove(kv.Key); continue; }

                try
                {
                    _db.UpsertWindowTimer(_login.EmployeeId, day, title, sec, proc);
                    _pending.Remove(kv.Key);
                    flushed += sec;
                }
                catch (Exception ex)
                {
                    _core?.Logger.Warn("[WindowsTrack] Flush pending falló (" + title + "): " + ex.Message);
                }
            }

            if (flushed > 0)
                _core?.Logger.Info("[WindowsTrack] Pendientes sincronizados: " + flushed + "s");
        }
    }
}
