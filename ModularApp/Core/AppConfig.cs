using System;
using System.IO;
using System.Xml.Linq;

namespace ModularApp.Core
{
    public sealed class AppConfig
    {
        public GeneralConfig General { get; private set; }
        public LoggingConfig Logging { get; private set; }
        public ModulesConfig Modules { get; private set; }
        public UserConfig User { get; private set; }
        public DatabaseConfig Database { get; private set; }

        private string _filePath;
        private XDocument _doc;

        public AppConfig()
        {
            General = new GeneralConfig();
            Logging = new LoggingConfig();
            Modules = new ModulesConfig();
            User = new UserConfig();
            Database = new DatabaseConfig();
        }

        public static AppConfig Load(string file)
        {
            // Si no existe, crear con defaults
            if (!File.Exists(file))
                File.WriteAllText(file, DefaultXml());

            var doc = XDocument.Load(file);
            var cfg = new AppConfig();
            cfg._filePath = file;
            cfg._doc = doc;

            var root = doc.Root ?? new XElement("Config");

            // ---- General
            var general = root.Element("General");
            cfg.General.FirstRun = ParseBool(general, "FirstRun", true);
            cfg.General.AutoStart = ParseBool(general, "AutoStart", true);

            // ---- Logging (tolerante a null)
            var logging = root.Element("Logging");
            string path = logging != null ? (string)logging.Element("Path") : null;
            cfg.Logging.Path = path ?? Path.Combine(AppContext.BaseDirectory, "logs", "app.log");

            LogLevel lvl;
            string lvlStr = logging != null ? (string)logging.Element("Level") : null;
            cfg.Logging.Level = Enum.TryParse<LogLevel>(lvlStr ?? "Info", true, out lvl) ? lvl : LogLevel.Info;

            cfg.Logging.EnableDiscord = ParseBool(logging, "EnableDiscord", false);
            cfg.Logging.DiscordWebhook = logging != null ? ((string)logging.Element("DiscordWebhook") ?? "") : "";

            // ---- Modules (tolerante a null)
            var mods = root.Element("Modules");
            cfg.Modules.StarterEnabled = GetEnabled(mods, "Starter", true);
            cfg.Modules.DatabaseEnabled = GetEnabled(mods, "Database", true);
            cfg.Modules.LogsEnabled = GetEnabled(mods, "Logs", true);
            cfg.Modules.GeoEnabled = GetEnabled(mods, "Geo", true); // NUEVO
            cfg.Modules.LoginEnabled = GetEnabled(mods, "Login", true);
            cfg.Modules.XmlEnabled = GetEnabled(mods, "Xml", true);
            cfg.Modules.UpdaterEnabled = GetEnabled(mods, "Updater", false);
            cfg.Modules.TimerTrackEnabled = GetEnabled(mods, "TimerTrack", true);
            cfg.Modules.WindowsTrackEnabled = GetEnabled(mods, "WindowsTrack", true);
            cfg.Modules.MalwareTrackEnabled = GetEnabled(mods, "MalwareTrack", false);
            cfg.Modules.ConnectivityEnabled = GetEnabled(mods, "Connectivity", true); // NUEVO

            // ---- User (tolerante a null)
            var user = root.Element("User");
            cfg.User.Remembered = ParseBool(user, "Remembered", false);
            cfg.User.UserName = user != null ? ((string)user.Element("UserName") ?? "") : "";
            cfg.User.EmployeeId = ParseInt(user, "EmployeeId", 0);
            cfg.User.Secret = user != null ? ((string)user.Element("Secret") ?? "") : "";
            cfg.User.DisplayName = user != null ? ((string)user.Element("DisplayName") ?? "") : "";

            // ---- Database (tolerante a null)
            var db = root.Element("Database");
            cfg.Database.Provider = db != null ? ((string)db.Element("Provider") ?? "MySql") : "MySql";
            cfg.Database.ConnectionString = db != null
                ? ((string)db.Element("ConnectionString") ?? "Server=localhost;Port=3306;Database=keeper;Uid=keeper;Pwd=pass;")
                : "Server=localhost;Port=3306;Database=keeper;Uid=keeper;Pwd=pass;";

            return cfg;
        }

        public void SaveUser(UserConfig user)
        {
            User = user;
            if (_doc == null || _filePath == null) return;

            var root = _doc.Root ?? new XElement("Config");
            var el = root.Element("User");
            if (el == null)
            {
                el = new XElement("User");
                root.Add(el);
            }

            if (el.Element("Remembered") == null) el.Add(new XElement("Remembered"));
            if (el.Element("UserName") == null) el.Add(new XElement("UserName"));
            if (el.Element("EmployeeId") == null) el.Add(new XElement("EmployeeId"));
            if (el.Element("Secret") == null) el.Add(new XElement("Secret"));
            if (el.Element("DisplayName") == null) el.Add(new XElement("DisplayName"));

            el.Element("Remembered").Value = user.Remembered.ToString().ToLower();
            el.Element("UserName").Value = user.UserName ?? "";
            el.Element("EmployeeId").Value = user.EmployeeId.ToString();
            el.Element("Secret").Value = user.Secret ?? "";
            el.Element("DisplayName").Value = user.DisplayName ?? "";

            _doc.Save(_filePath);
        }

        public void SaveGeneral()
        {
            if (_doc == null || _filePath == null) return;

            var root = _doc.Root ?? new XElement("Config");
            var general = root.Element("General");
            if (general == null)
            {
                general = new XElement("General");
                root.Add(general);
            }

            if (general.Element("FirstRun") == null) general.Add(new XElement("FirstRun"));
            if (general.Element("AutoStart") == null) general.Add(new XElement("AutoStart"));

            general.Element("FirstRun").Value = General.FirstRun.ToString().ToLower();
            general.Element("AutoStart").Value = General.AutoStart.ToString().ToLower();

            _doc.Save(_filePath);
        }

        private static bool GetEnabled(XElement mods, string name, bool def)
        {
            if (mods == null) return def;
            var el = mods.Element(name);
            var attr = el == null ? null : el.Attribute("enabled");
            bool val;
            if (attr == null || !bool.TryParse(attr.Value, out val)) return def;
            return val;
        }

        private static bool ParseBool(XElement parent, string name, bool def)
        {
            bool b;
            if (parent == null) return def;
            if (!bool.TryParse((string)parent.Element(name), out b)) return def;
            return b;
        }

        private static int ParseInt(XElement parent, string name, int def)
        {
            int v;
            if (parent == null) return def;
            if (!int.TryParse((string)parent.Element(name), out v)) return def;
            return v;
        }

        private static string DefaultXml()
        {
            // Mantengo tus defaults; incluye Connectivity
            return @"<?xml version=""1.0"" encoding=""utf-8""?>
<Config>
  <General>
    <FirstRun>true</FirstRun>
    <AutoStart>true</AutoStart>
  </General>
  <Logging>
    <Path>logs\app.log</Path>
    <Level>Warn</Level>  <!-- Debug | Info | Warn | Error -->
    <EnableDiscord>true</EnableDiscord>
    <DiscordWebhook>https://discord.com/api/webhooks/1404524499184914595/CW-u9Ndu0D1zDJ0hXy2_edH7sYywnCbLxyvLbyiwRWFLmEHgnduenEAcjdKcNdcH5mQ4</DiscordWebhook>
  </Logging>
  <Modules>
    <Starter enabled=""true"" />
    <Database enabled=""true"" />
    <Logs enabled=""true"" />
    <Geo enabled=""true"" />
    <Login enabled=""true"" />
    <Xml enabled=""true"" />
    <Updater enabled=""true"" />
    <TimerTrack enabled=""true"" />
    <WindowsTrack enabled=""true"" />
    <Connectivity enabled=""true"" />
    <MalwareTrack enabled=""false"" />
  </Modules>
  <User>
    <Remembered>false</Remembered>
    <UserName></UserName>
    <EmployeeId>0</EmployeeId>
    <Secret></Secret>
    <DisplayName></DisplayName>
  </User>
  <Database>
    <Provider>MySql</Provider>
    <ConnectionString>server=mysql.server1872.mylogin.co;database=pipezafra_manager;user=pipezafra_manager;password=z3321483Z@!$2024**;</ConnectionString>
  </Database>
</Config>";
        }
    }

    public sealed class GeneralConfig
    {
        public bool FirstRun { get; set; }
        public bool AutoStart { get; set; }
    }

    public sealed class LoggingConfig
    {
        public string Path { get; set; }
        public LogLevel Level { get; set; }
        public bool EnableDiscord { get; set; }
        public string DiscordWebhook { get; set; }
    }

    public sealed class ModulesConfig
    {
        public bool StarterEnabled { get; set; }
        public bool DatabaseEnabled { get; set; }
        public bool LogsEnabled { get; set; }
        public bool GeoEnabled { get; set; } // NUEVO
        public bool LoginEnabled { get; set; }
        public bool XmlEnabled { get; set; }
        public bool UpdaterEnabled { get; set; }
        public bool TimerTrackEnabled { get; set; }
        public bool WindowsTrackEnabled { get; set; }
        public bool MalwareTrackEnabled { get; set; }
        public bool ConnectivityEnabled { get; set; } // NUEVO
    }

    public sealed class UserConfig
    {
        public bool Remembered { get; set; }
        public string UserName { get; set; }
        public int EmployeeId { get; set; }
        public string Secret { get; set; }
        public string DisplayName { get; set; }   // <-- NUEVO
    }

    public sealed class DatabaseConfig
    {
        public string Provider { get; set; }
        public string ConnectionString { get; set; }
    }
}
