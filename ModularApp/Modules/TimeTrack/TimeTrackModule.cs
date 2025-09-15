using System;
using System.Diagnostics;
using System.Windows.Forms;
using ModularApp.Core;
using ModularApp.Modules.Database;
using ModularApp.Modules.Login;

namespace ModularApp.Modules.TimerTrack
{
    public sealed class TimerTrackModule : IAppModule
    {
        private AppCore _core;
        private IDatabaseService _db;
        private ILoginService _login;

        public string Name => "TimerTrack";
        public bool Enabled => _core == null ? true : _core.Config.Modules.TimerTrackEnabled;

        private System.Windows.Forms.Timer _tick;
        private DateTime _currentDay = DateTime.Today;   // día lógico local
        private int _workSec, _idleSec;
        private bool _dirty;
        private int _saving = 0;

        private bool _loadedFromDbAtBoot = false;
        private int _workBaseAtBoot = 0;
        private int _idleBaseAtBoot = 0;
        private bool _pendingMergeWithDb = false;

        // Reloj estable
        private readonly Stopwatch _mono = Stopwatch.StartNew();
        private double _carryMs = 0;
        private int _secsSinceSave = 0; // solo avanza dentro de franja

        // Umbral de inactividad: 10 min
        private const int IdleThresholdSec = 6;

        // Franja laboral local [07:00, 22:00)
        private static readonly TimeSpan WorkStart = new TimeSpan(7, 0, 0);
        private static readonly TimeSpan WorkEnd = new TimeSpan(22, 0, 0);

        // Estado actual
        private string _currentStatus = null;

        public void Init(AppCore core)
        {
            _core = core;
            _db = core.Resolve<IDatabaseService>();
            _login = core.Resolve<ILoginService>();
        }

        public void Start()
        {
            if (_login == null)
            {
                _core?.Logger.Warn("[TimerTrack] Login service no disponible.");
                return;
            }

            _login.LoggedIn += OnLoggedIn;
            _login.LoggedOut += OnLoggedOut;

            var conn = _core.Resolve<ModularApp.Modules.Connectivity.IConnectivityService>();
            if (conn != null)
            {
                conn.ConnectivityChanged += online =>
                {
                    if (online && _pendingMergeWithDb) TryMergeWithDb();
                };
            }

            EnsureStart();
        }

        private bool _started = false;

        private void EnsureStart()
        {
            if (_started) return;
            if (_login == null || !_login.IsLogged) return;

            bool dbOk = false;
            try
            {
                if (_db != null)
                {
                    dbOk = _db.Ping();
                    if (dbOk)
                    {
                        var rec = _db.GetTimers(_login.EmployeeId, DateTime.Today);
                        _workSec = rec.workSec;
                        _idleSec = rec.idleSec;
                        if (!rec.exists) _db.UpsertTimers(_login.EmployeeId, DateTime.Today, 0, 0);
                        _loadedFromDbAtBoot = true;
                    }
                }
            }
            catch { dbOk = false; }

            if (!dbOk)
            {
                _loadedFromDbAtBoot = false;
                _pendingMergeWithDb = true;
                _workSec = 0; _idleSec = 0;
                _core?.Logger.Warn("[TimerTrack] Arranque sin BD: acumulando local, se fusionará al reconectar.");
            }

            _workBaseAtBoot = _workSec;
            _idleBaseAtBoot = _idleSec;

            _tick = new System.Windows.Forms.Timer { Interval = 1000 };
            _tick.Tick += Tick;
            _tick.Start();
            _mono.Restart();

            _core?.Logger.Info("[TimerTrack] Iniciado. Base " + Format(_workSec) + "/" + Format(_idleSec));
            _started = true;
        }

        private void OnLoggedIn() { EnsureStart(); }
        private void OnLoggedOut()
        {
            _tick?.Stop();
            Flush();
            _started = false;
            _currentStatus = null;
        }

        public void Stop()
        {
            _tick?.Stop();
            Flush();
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

            // 2) Cambio de día local
            var today = DateTime.Today;
            if (today != _currentDay)
            {
                _core?.Logger.Debug("[TimerTrack] Rollover de día.");
                Flush();                      // persiste día anterior
                _currentDay = today;
                LoadOrCreateForToday();       // carga/crea día nuevo (resetea contadores)
                _workBaseAtBoot = _workSec;   // bases para futuros merges
                _idleBaseAtBoot = _idleSec;
                _secsSinceSave = 0;
                _carryMs = 0;
                _currentStatus = null;        // forzar primer write del día
            }

            // 3) Estado (siempre) → Away en inactividad
            int idleSeconds;
            try { idleSeconds = (int)Win32.GetIdleTime().TotalSeconds; }
            catch { idleSeconds = 0; }
            bool isIdle = idleSeconds >= IdleThresholdSec;

            string desired = isIdle ? "Away" : "Online";
            if (!string.Equals(desired, _currentStatus, StringComparison.OrdinalIgnoreCase))
                WriteStatus(desired);

            // 4) Solo acumular dentro de franja laboral
            bool inWorkWindow = IsWithinWorkWindow(DateTime.Now.TimeOfDay);
            if (inWorkWindow)
            {
                if (isIdle) _idleSec += wholeSeconds;
                else _workSec += wholeSeconds;

                _secsSinceSave += wholeSeconds;
                _dirty = true;
            }

            // 5) Guardado cada 15 s CONTADOS (solo si hubo contabilidad)
            if (_secsSinceSave >= 15 && _dirty)
            {
                _secsSinceSave = 0;
                System.Threading.ThreadPool.QueueUserWorkItem(_ => SaveSafe());
            }
        }

        private static bool IsWithinWorkWindow(TimeSpan localTime)
            => localTime >= WorkStart && localTime < WorkEnd;

        private void LoadOrCreateForToday()
        {
            var rec = _db.GetTimers(_login.EmployeeId, DateTime.Today);
            _workSec = rec.workSec;
            _idleSec = rec.idleSec;
            if (!rec.exists)
            {
                _db.UpsertTimers(_login.EmployeeId, DateTime.Today, 0, 0);
                _workSec = 0; _idleSec = 0;
            }
        }

        private void Flush()
        {
            if (!_dirty) return;
            SaveSafe();
        }

        private void SaveSafe()
        {
            if (System.Threading.Interlocked.Exchange(ref _saving, 1) == 1) return;
            try
            {
                var net = _core.Resolve<ModularApp.Modules.Connectivity.IConnectivityService>();
                if (net != null && !net.IsOnline)
                {
                    _pendingMergeWithDb = true;
                    _dirty = false;
                    return;
                }

                if (_pendingMergeWithDb && !TryMergeWithDb()) return;

                _db.UpsertTimers(_login.EmployeeId, _currentDay, _workSec, _idleSec);
                _dirty = false;

                _core.Logger.Debug("[TimerTrack] Saved " + _currentDay.ToString("yyyy-MM-dd") +
                                   " W=" + Format(_workSec) + " I=" + Format(_idleSec));
            }
            catch (Exception ex)
            {
                _core.Logger.Error("[TimerTrack] Save error: " + ex.Message);
            }
            finally { System.Threading.Interlocked.Exchange(ref _saving, 0); }
        }

        private bool TryMergeWithDb()
        {
            try
            {
                var rec = _db.GetTimers(_login.EmployeeId, _currentDay);
                int baseW = rec.exists ? rec.workSec : 0;
                int baseI = rec.exists ? rec.idleSec : 0;

                int deltaW = _workSec - _workBaseAtBoot; if (deltaW < 0) deltaW = 0;
                int deltaI = _idleSec - _idleBaseAtBoot; if (deltaI < 0) deltaI = 0;

                int mergedW = baseW + deltaW;
                int mergedI = baseI + deltaI;

                _db.UpsertTimers(_login.EmployeeId, _currentDay, mergedW, mergedI);

                _workSec = mergedW;
                _idleSec = mergedI;

                _workBaseAtBoot = _workSec;
                _idleBaseAtBoot = _idleSec;

                _pendingMergeWithDb = false;
                _loadedFromDbAtBoot = true;

                _core?.Logger.Info("[TimerTrack] Fusión realizada. Totales hoy: " +
                                   Format(_workSec) + " / " + Format(_idleSec));
                return true;
            }
            catch (Exception ex)
            {
                _core?.Logger.Warn("[TimerTrack] Fusión fallida, reintento posterior: " + ex.Message);
                return false;
            }
        }
        private void WriteStatus(string desired)
        {
            _currentStatus = desired ?? "Online";
            try
            {
                // Solo Online o Away desde TimerTrack
                if (!string.Equals(_currentStatus, "Online", StringComparison.OrdinalIgnoreCase) &&
                    !string.Equals(_currentStatus, "Away", StringComparison.OrdinalIgnoreCase))
                {
                    _currentStatus = "Online";
                }

                _db.SetLastStatus(_login.EmployeeId, _currentStatus);
                _core?.Logger.Info("[TimerTrack] Estado → " + _currentStatus);
            }
            catch (Exception ex)
            {
                _core?.Logger.Warn("[TimerTrack] No se pudo escribir estado: " + ex.Message);
            }
        }


        private static string Format(int sec)
            => TimeSpan.FromSeconds(sec).ToString(@"hh\:mm\:ss");
    }
}
