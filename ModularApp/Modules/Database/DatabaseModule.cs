using System;
using System.Globalization;
using ModularApp.Core;
using MySql.Data.MySqlClient;
using static Mysqlx.Notice.Frame.Types;

namespace ModularApp.Modules.Database
{
    public interface IDatabaseService
    {
        MySqlConnection Open();

        (int workSec, int idleSec, bool exists) GetTimers(int idLogin, DateTime day);
        void UpsertTimers(int idLogin, DateTime day, int workSec, int idleSec);
        void UpsertWindowTimer(int idLogin, DateTime day, string windowName, int seconds, string processName);

        // 👇 firma única y estable (3 parámetros)
        void SetLastStatus(int idEmployee, string status, bool force = false);

        void UpdateGeo(int idEmployee, string geo);

        // NUEVO: graba "lat,lon" en LOGIN.geo
        void UpdateGeoLatLon(int idEmployee, double lat, double lon);
        Tuple<int, string, string> GetLoginByUser(string user);
        bool Ping();
    }
    public sealed class DatabaseModule : IAppModule, IDatabaseService
    {
        private AppCore _core;
        private string _conn = "";

        private ModularApp.Modules.Connectivity.IConnectivityService _net;
        private DateTime _lastOfflineLog = DateTime.MinValue;
        private static readonly TimeSpan _offlineLogCooldown = TimeSpan.FromSeconds(30);

        public string Name { get { return "Database"; } }
        public bool Enabled { get { return _core == null ? true : _core.Config.Modules.DatabaseEnabled; } }

        public void Init(AppCore core)
        {
            _core = core;
            _conn = core.Config.Database.ConnectionString;
            _net = core.Resolve<ModularApp.Modules.Connectivity.IConnectivityService>(); // puede ser null
        }

        public void Start() { _core?.Logger.Info("[Database] Ready (TIME columns)"); }
        public void Stop() { }
        public void Dispose() { }

        // ----------------- Helpers -----------------
        private bool ShouldBypassDb(bool critical = false)
        {
            // Para llamadas críticas (login/estado/geo) NO bypass
            if (critical) return false;

            if (_net != null && !_net.IsOnline)
            {
                if ((DateTime.Now - _lastOfflineLog) > _offlineLogCooldown)
                {
                    _lastOfflineLog = DateTime.Now;
                    _core?.Logger.Debug("[Database] Sin conexión: se omite operación.");
                }
                return true;
            }
            return false;
        }

        public MySqlConnection Open()
        {
            var c = new MySqlConnection(_conn);
            c.Open();
            return c;
        }

        public bool Ping()
        {
            try
            {
                if (ShouldBypassDb()) return false;
                using (var c = Open())
                using (var cmd = new MySqlCommand("SELECT 1;", c))
                {
                    cmd.ExecuteScalar();
                    return true;
                }
            }
            catch { return false; }
        }

        // ---------- TIMERS (TIME) ----------
        public (int workSec, int idleSec, bool exists) GetTimers(int idLogin, DateTime day)
        {
            try
            {
                if (ShouldBypassDb()) return (0, 0, false);

                using (var c = Open())
                using (var cmd = new MySqlCommand(
                    "SELECT TIME_TO_SEC(COALESCE(worktime,'00:00:00')) AS w, " +
                    "       TIME_TO_SEC(COALESCE(outtime, '00:00:00')) AS i " +
                    "FROM TIMERS WHERE id_login=@id AND date_Work=@d LIMIT 1;", c))
                {
                    cmd.Parameters.AddWithValue("@id", idLogin);
                    cmd.Parameters.AddWithValue("@d", day.ToString("yyyy-MM-dd"));

                    using (var r = cmd.ExecuteReader())
                    {
                        if (r.Read())
                        {
                            int w = Convert.ToInt32(r["w"]);
                            int i = Convert.ToInt32(r["i"]);
                            return (w, i, true);
                        }
                    }
                }
                return (0, 0, false);
            }
            catch (Exception ex)
            {
                _core?.Logger.Error("[Database] GetTimers error: " + ex.Message);
                return (0, 0, false);
            }
        }

        public void UpsertTimers(int idLogin, DateTime day, int workSec, int idleSec)
        {
            try
            {
                if (ShouldBypassDb()) return;

                using (var c = Open())
                {
                    using (var upd = new MySqlCommand(
                        "UPDATE TIMERS SET worktime=SEC_TO_TIME(@w), outtime=SEC_TO_TIME(@i), updated_at=NOW() " +
                        "WHERE id_login=@id AND date_Work=@d;", c))
                    {
                        upd.Parameters.AddWithValue("@w", workSec);
                        upd.Parameters.AddWithValue("@i", idleSec);
                        upd.Parameters.AddWithValue("@id", idLogin);
                        upd.Parameters.AddWithValue("@d", day.ToString("yyyy-MM-dd"));

                        int rows = upd.ExecuteNonQuery();
                        if (rows > 0) return;
                    }

                    using (var ins = new MySqlCommand(
                        "INSERT INTO TIMERS (id_login, worktime, outtime, date_Work, updated_at) " +
                        "VALUES (@id, SEC_TO_TIME(@w), SEC_TO_TIME(@i), @d, NOW());", c))
                    {
                        ins.Parameters.AddWithValue("@id", idLogin);
                        ins.Parameters.AddWithValue("@w", workSec);
                        ins.Parameters.AddWithValue("@i", idleSec);
                        ins.Parameters.AddWithValue("@d", day.ToString("yyyy-MM-dd"));
                        ins.ExecuteNonQuery();
                    }
                }
            }
            catch (Exception ex)
            {
                _core?.Logger.Error("[Database] UpsertTimers error: " + ex.Message);
            }
        }

        // ---------- WINDOWS (TIME) ----------
        public void UpsertWindowTimer(int idLogin, DateTime day, string windowName, int seconds, string processName)
        {
            try
            {
                if (ShouldBypassDb()) return;
                if (string.IsNullOrWhiteSpace(windowName)) return;
                windowName = windowName.Trim();
                if (windowName.Length > 512) windowName = windowName.Substring(0, 512);

                using var c = Open();
                const string sql = @"
                    INSERT INTO WINDOWS (id_login, window_name, timer, date_Window)
                    VALUES (@id, @name, SEC_TO_TIME(@sec), @d)
                    ON DUPLICATE KEY UPDATE
                      timer = ADDTIME(COALESCE(timer,'00:00:00'), VALUES(timer));";
                using var cmd = new MySqlCommand(sql, c);
                cmd.Parameters.AddWithValue("@id", idLogin);
                cmd.Parameters.AddWithValue("@name", windowName);
                cmd.Parameters.AddWithValue("@sec", Math.Max(0, seconds));
                cmd.Parameters.AddWithValue("@d", day.Date);
                cmd.ExecuteNonQuery();
            }
            catch (Exception ex)
            {
                _core?.Logger.Error("[Database] UpsertWindowTimer error: " + ex.Message);
            }
        }


        // ---------- LOGIN / GEO (críticos: sin bypass) ----------
        public void SetLastStatus(int idEmployee, string status, bool force = false)
        {
            try
            {
                // Nunca escribir Offline salvo force=true
                if (!force && status != null && status.Equals("Offline", StringComparison.OrdinalIgnoreCase))
                {
                    _core?.Logger.Warn("[Database] SetLastStatus ignorado (Offline no forzado).");
                    return;
                }

                // Normaliza a valores válidos del ENUM
                string s = "Online";
                if (!string.IsNullOrWhiteSpace(status))
                {
                    if (status.Equals("Online", StringComparison.OrdinalIgnoreCase)) s = "Online";
                    else if (status.Equals("Away", StringComparison.OrdinalIgnoreCase)) s = "Away";
                    else if (status.Equals("Offline", StringComparison.OrdinalIgnoreCase)) s = "Offline";
                }

                using (var c = Open())
                using (var cmd = new MySqlCommand(
                    "UPDATE LOGIN SET last_status=@s WHERE id_employee=@e;", c))
                {
                    cmd.Parameters.AddWithValue("@s", s);
                    cmd.Parameters.AddWithValue("@e", idEmployee);
                    int rows = cmd.ExecuteNonQuery();
                    _core?.Logger.Info("[Database] SetLastStatus id=" + idEmployee + " -> " + s + " (rows=" + rows + ")");
                }
            }
            catch (Exception ex)
            {
                _core?.Logger.Error("[Database] SetLastStatus error: " + ex.Message);
            }
        }


        // ModularApp.Modules.Database.DatabaseModule

        // ...

        // Ya existente (compat):
        public void UpdateGeo(int idEmployee, string geo)
        {
            try
            {
                using (var c = Open())
                using (var cmd = new MySqlCommand("UPDATE LOGIN SET geo=@g WHERE id_employee=@e;", c))
                {
                    cmd.Parameters.AddWithValue("@g", geo ?? "");
                    cmd.Parameters.AddWithValue("@e", idEmployee);
                    var rows = cmd.ExecuteNonQuery();
                    _core?.Logger.Info("[Database] UpdateGeo id=" + idEmployee + " -> " + geo + " (rows=" + rows + ")");
                }
            }
            catch (Exception ex)
            {
                _core?.Logger.Error("[Database] UpdateGeo error: " + ex.Message);
            }
        }

        // NUEVO: formatea "lat,lon" y lo guarda en LOGIN.geo (VARCHAR 32)
        public void UpdateGeoLatLon(int idEmployee, double lat, double lon)
        {
            try
            {
                string latlon = lat.ToString("F6", CultureInfo.InvariantCulture) + "," +
                                lon.ToString("F6", CultureInfo.InvariantCulture);

                using (var c = Open())
                using (var cmd = new MySqlCommand("UPDATE LOGIN SET geo=@g WHERE id_employee=@e;", c))
                {
                    cmd.Parameters.AddWithValue("@g", latlon);
                    cmd.Parameters.AddWithValue("@e", idEmployee);
                    int rows = cmd.ExecuteNonQuery();
                    _core?.Logger.Info("[Database] UpdateGeoLatLon id=" + idEmployee + " -> " + latlon + " (rows=" + rows + ")");
                }
            }
            catch (Exception ex)
            {
                _core?.Logger.Error("[Database] UpdateGeoLatLon error: " + ex.Message);
            }
        }


        public Tuple<int, string, string> GetLoginByUser(string user)
        {
            try
            {
                using (var c = Open())
                using (var cmd = new MySqlCommand(
                    "SELECT L.id_employee, E.first_Name, E.first_LastName, L.pass " +
                    "FROM LOGIN L INNER JOIN EMPLOYEE E ON L.id_employee = E.id " +
                    "WHERE L.user=@u LIMIT 1;", c))
                {
                    cmd.Parameters.AddWithValue("@u", user);
                    using (var r = cmd.ExecuteReader())
                    {
                        if (r.Read())
                        {
                            int id = Convert.ToInt32(r["id_employee"]);
                            string full = Convert.ToString(r["first_Name"]) + " " + Convert.ToString(r["first_LastName"]);
                            string hash = Convert.ToString(r["pass"]);
                            return Tuple.Create(id, full, hash);
                        }
                    }
                }
                return null;
            }
            catch (Exception ex)
            {
                _core?.Logger.Error("[Database] GetLoginByUser error: " + ex.Message);
                return null;
            }
        }
    }
}
