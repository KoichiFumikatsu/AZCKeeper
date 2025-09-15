using System;
using System.Windows.Forms;
using ModularApp.Core;
using ModularApp.Modules.Connectivity;
using ModularApp.Modules.Database;
using ModularApp.Modules.Geo;

namespace ModularApp.Modules.Login
{
    public interface ILoginService
    {
        bool IsLogged { get; }
        int EmployeeId { get; }
        string UserName { get; }
        string FullName { get; }

        event Action LoggedIn;
        event Action LoggedOut;

        bool TryAutoLogin();
        bool Login(string user, string password);
        void Logout();
    }

    public sealed class LoginModule : IAppModule, ILoginService
    {
        private AppCore _core;
        private IDatabaseService _db;
        private IConnectivityService _conn;

        private System.Windows.Forms.Timer _reassertTimer;        // reafirmar 5s después del login
        private System.Windows.Forms.Timer _keepAliveTimer;       // latido periódico Online
        private System.Windows.Forms.Timer _reconnectDebounce;    // evitar flapping al reconectar

        public event Action LoggedIn;
        public event Action LoggedOut;

        public string Name { get { return "Login"; } }
        public bool Enabled { get { return _core == null ? true : _core.Config.Modules.LoginEnabled; } }

        public bool IsLogged { get; private set; }
        public int EmployeeId { get; private set; }
        public string UserName { get; private set; }
        public string FullName { get; private set; }

        public void Init(AppCore core)
        {
            _core = core;
            _db = core.Resolve<IDatabaseService>();
            _conn = core.Resolve<IConnectivityService>(); // puede ser null si no registras el módulo


            // ▶ Hook de contexto para logs
            // dentro de Init(AppCore core) del LoginModule
            LogContext.Provider = () =>
            {
                try
                {
                    if (IsLogged)
                    {
                        var name = string.IsNullOrWhiteSpace(FullName) ? UserName : FullName;
                        return $"uid={EmployeeId} user={name}";
                    }
                    var u = _core?.Config?.User;
                    if (u != null && u.EmployeeId > 0)
                    {
                        var name = string.IsNullOrWhiteSpace(u.DisplayName) ? u.UserName : u.DisplayName;
                        return $"uid={u.EmployeeId} user={name}";
                    }
                }
                catch { }
                return "uid=0 user=?";
            };

        }

        public void Start()
        {
            // Escuchar cambios de conectividad (para autorecuperar Online o autologin)
            if (_conn != null) _conn.ConnectivityChanged += OnConnectivityChanged;

            if (TryAutoLogin())
            {
                _core?.Logger.Info("[Login] Auto-login como " + UserName + " (id=" + EmployeeId + ")");
                var h = LoggedIn; if (h != null) h();
            }
            else
            {
                _core?.Logger.Info("[Login] Esperando credenciales (primer inicio).");
            }
        }

        public void Stop()
        {
            try
            {
                if (_conn != null) _conn.ConnectivityChanged -= OnConnectivityChanged;
                if (_reassertTimer != null) { _reassertTimer.Stop(); _reassertTimer.Dispose(); _reassertTimer = null; }
                if (_keepAliveTimer != null) { _keepAliveTimer.Stop(); _keepAliveTimer.Dispose(); _keepAliveTimer = null; }
                if (_reconnectDebounce != null) { _reconnectDebounce.Stop(); _reconnectDebounce.Dispose(); _reconnectDebounce = null; }
            }
            catch { }
        }
        public void Dispose() { Stop(); }

        public bool TryAutoLogin()
        {
            var cfg = _core.Config.User;
            if (!cfg.Remembered || string.IsNullOrWhiteSpace(cfg.UserName)) return false;

            // VALIDAR contra BD: si el usuario no existe o cambió, pide login manual
            Tuple<int, string, string> rec = null;
            try { rec = _db.GetLoginByUser(cfg.UserName); } catch { }
            if (rec == null) return false;

            // Tomar id y nombre reales desde BD
            EmployeeId = rec.Item1;
            UserName = cfg.UserName;
            FullName = !string.IsNullOrWhiteSpace(rec.Item2) ? rec.Item2 :
                         (!string.IsNullOrWhiteSpace(cfg.DisplayName) ? cfg.DisplayName : cfg.UserName);
            IsLogged = true;

            // Corregir XML si cambió id o nombre
            _core.Config.SaveUser(new UserConfig
            {
                Remembered = true,
                UserName = UserName,
                EmployeeId = EmployeeId,
                Secret = "",
                DisplayName = FullName
            });

            // Estado Online inmediato + reafirmación y keep-alive
            try { _db.SetLastStatus(EmployeeId, "Online"); } catch { }
            ReassertOnlineSoon();

            // Geo en background
            AfterLoginSuccessFireAndForget();

            return true;
        }

        public bool Login(string user, string password)
        {
            var rec = _db.GetLoginByUser(user);
            if (rec == null) return false;
            if (!BCrypt.Net.BCrypt.Verify(password, rec.Item3)) return false;

            EmployeeId = rec.Item1;
            UserName = user;
            FullName = rec.Item2;
            IsLogged = true;

            try { _db.SetLastStatus(EmployeeId, "Online"); } catch { }
            ReassertOnlineSoon();

            _core.Config.SaveUser(new UserConfig
            {
                Remembered = true,
                UserName = user,
                EmployeeId = EmployeeId,
                Secret = "",
                DisplayName = FullName
            });

            AfterLoginSuccessFireAndForget();

            var h = LoggedIn; if (h != null) h();
            return true;
        }

        public void Logout()
        {
            if (!IsLogged) return;
            try { _db.SetLastStatus(EmployeeId, "Offline", true); } catch { } // Offline solo forzado
            IsLogged = false;
            var h = LoggedOut; if (h != null) h();
        }

        // ----------------- Conectividad -----------------

        private void OnConnectivityChanged(bool online)
        {
            if (!online) return; // jamás escribimos Offline por conectividad

            // Debounce 2s para evitar flapping
            if (_reconnectDebounce == null)
            {
                _reconnectDebounce = new System.Windows.Forms.Timer();
                _reconnectDebounce.Interval = 2000;
                _reconnectDebounce.Tick += delegate
                {
                    _reconnectDebounce.Stop();
                    try
                    {
                        if (IsLogged)
                        {
                            // Usuario ya logueado: recuperar estado en BD
                            _db?.SetLastStatus(EmployeeId, "Online");
                            _core?.Logger.Info("[Login] Reconexión: estado actualizado a Online.");
                        }
                        else if (_core.Config.User.Remembered)
                        {
                            // Intentar autologin silencioso
                            if (TryAutoLogin())
                            {
                                _core?.Logger.Info("[Login] Autologin tras reconexión.");
                                var h = LoggedIn; if (h != null) h();
                            }
                        }
                    }
                    catch { }
                };
            }

            _reconnectDebounce.Stop();
            _reconnectDebounce.Start();
        }

        // ----------------- helpers -----------------

        private void ReassertOnlineSoon()
        {
            try { if (_reassertTimer != null) { _reassertTimer.Stop(); _reassertTimer.Dispose(); } } catch { }
            _reassertTimer = new System.Windows.Forms.Timer();
            _reassertTimer.Interval = 5000; // 5s
            _reassertTimer.Tick += delegate
            {
                _reassertTimer.Stop(); _reassertTimer.Dispose(); _reassertTimer = null;
                try { _db?.SetLastStatus(EmployeeId, "Online"); } catch { }
                _core?.Logger.Info("[Login] Reassert Online escrito.");
            };
            _reassertTimer.Start();
        }


        private void AfterLoginSuccessFireAndForget()
        {
            try
            {
                var geo = _core.Resolve<ModularApp.Modules.Geo.IGeoService>();
                var net = _core.Resolve<ModularApp.Modules.Connectivity.IConnectivityService>();
                if (geo == null || _db == null)
                {
                    //_core.Logger.Warn("[Login] GeoService no disponible o DB null (no se intentará geo).");
                    return;
                }

                System.Threading.Tasks.Task.Run(async () =>
                {
                    try
                    {
                        if (net != null && !net.IsOnline) return;

                        var pt = await geo.TryGetCoordinatesAsync(12000).ConfigureAwait(false);
                        if (!pt.HasValue)
                        {
                            _core.Logger.Warn("[Login] Geo: no hay coordenadas; no se actualizará LOGIN.geo.");
                            return; // no sobreescribimos con fallback
                        }

                        // Guardar LAT,LON en LOGIN.geo
                        _db.UpdateGeoLatLon(EmployeeId, pt.Value.Latitude, pt.Value.Longitude);
                        _core.Logger.Info("[Login] Geo (lat,lon) guardada en BD.");
                    }
                    catch (Exception ex)
                    {
                        _core.Logger.Warn("[Login] Geo background falló: " + ex.Message);
                    }
                });
            }
            catch { }
        }

    }
}
