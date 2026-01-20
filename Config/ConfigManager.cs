using System;
using System.IO;
using System.Text.Json;
using AZCKeeper_Cliente.Logging;

namespace AZCKeeper_Cliente.Config
{
    /// <summary>
    /// Gestiona la configuración local del cliente AZC Keeper.
    /// 
    /// Responsabilidades:
    /// - Cargar y guardar el archivo de configuración (client_config.json)
    ///   en una carpeta de datos de aplicación.
    /// - Garantizar la existencia de un DeviceId único por instalación.
    /// - Proveer acceso a:
    ///   - Versión del cliente.
    ///   - ApiBaseUrl.
    ///   - Parámetros de logging.
    ///   - Flags de módulos.
    /// - Aplicar la configuración de logging a LocalLogger.
    /// </summary>
    internal class ConfigManager
    {
        /// <summary>
        /// Ruta base donde se almacenará la configuración local del cliente.
        /// Ejemplo: %AppData%\AZCKeeper\Config
        /// </summary>
        public string ConfigBaseDirectory { get; }

        /// <summary>
        /// Ruta completa del archivo de configuración del cliente.
        /// </summary>
        public string ConfigFilePath { get; }

        /// <summary>
        /// Objeto que representa la configuración actual.
        /// </summary>
        public ClientConfig CurrentConfig { get; set; }

        /// <summary>
        /// Constructor del ConfigManager.
        /// </summary>
        public ConfigManager()
        {
            // Carpeta base de AppData del usuario actual.
            string appData = Environment.GetFolderPath(Environment.SpecialFolder.ApplicationData);

            // Construimos la ruta base de configuración para AZCKeeper.
            ConfigBaseDirectory = Path.Combine(appData, "AZCKeeper", "Config");

            // Ruta del archivo client_config.json.
            ConfigFilePath = Path.Combine(ConfigBaseDirectory, "client_config.json");
        }

        /// <summary>
        /// Carga la configuración desde disco o crea una nueva por defecto
        /// si el archivo no existe o es inválido.
        /// </summary>
        public void LoadOrCreate()
        {
            try
            {
                // Aseguramos que la carpeta de configuración exista.
                if (!Directory.Exists(ConfigBaseDirectory))
                {
                    Directory.CreateDirectory(ConfigBaseDirectory);
                    LocalLogger.Info($"ConfigManager: carpeta de configuración creada en {ConfigBaseDirectory}");
                }

                if (File.Exists(ConfigFilePath))
                {
                    string json = File.ReadAllText(ConfigFilePath);

                    if (string.IsNullOrWhiteSpace(json))
                    {
                        LocalLogger.Warn("ConfigManager.LoadOrCreate(): client_config.json está vacío. Se generará configuración por defecto.");
                        CurrentConfig = CreateDefaultConfig();
                        Save();
                        return;
                    }

                    var config = JsonSerializer.Deserialize<ClientConfig>(json);

                    if (config == null)
                    {
                        LocalLogger.Warn("ConfigManager.LoadOrCreate(): client_config.json existe pero no se pudo deserializar. Se generará configuración por defecto.");
                        CurrentConfig = CreateDefaultConfig();
                        Save();
                    }
                    else
                    {
                        CurrentConfig = config;
                        LocalLogger.Info("ConfigManager.LoadOrCreate(): configuración cargada correctamente desde disco.");
                    }
                }

                else
                {
                    LocalLogger.Warn("ConfigManager.LoadOrCreate(): no se encontró client_config.json. Se generará configuración por defecto.");
                    CurrentConfig = CreateDefaultConfig();
                    Save();
                }
            }
            catch (Exception ex)
            {
                LocalLogger.Error(ex, "ConfigManager.LoadOrCreate(): error al cargar/crear configuración local. Se usará configuración por defecto en memoria.");

                CurrentConfig = CreateDefaultConfig();

                try
                {
                    Save();
                }
                catch (Exception innerEx)
                {
                    LocalLogger.Error(innerEx, "ConfigManager.LoadOrCreate(): error adicional al guardar configuración generada por defecto.");
                }
            }
        }

        /// <summary>
        /// Guarda la configuración actual (CurrentConfig) en disco.
        /// </summary>
        public void Save()
        {
            if (CurrentConfig == null)
                return;

            string json = JsonSerializer.Serialize(CurrentConfig, new JsonSerializerOptions
            {
                WriteIndented = true
            });

            File.WriteAllText(ConfigFilePath, json);
            LocalLogger.Info($"ConfigManager.Save(): configuración guardada en {ConfigFilePath}");
        }

        /// <summary>
        /// Asegura que exista un DeviceId válido en la configuración.
        /// Si no existe, genera un nuevo GUID y lo persiste.
        /// </summary>
        public void EnsureDeviceId()
        {
            if (CurrentConfig == null)
            {
                LocalLogger.Warn("ConfigManager.EnsureDeviceId(): CurrentConfig es null. Se generará configuración por defecto.");
                CurrentConfig = CreateDefaultConfig();
            }

            if (string.IsNullOrWhiteSpace(CurrentConfig.DeviceId))
            {
                CurrentConfig.DeviceId = Guid.NewGuid().ToString();
                LocalLogger.Info($"ConfigManager.EnsureDeviceId(): se generó un nuevo DeviceId: {CurrentConfig.DeviceId}");
                Save();
            }
        }

        /// <summary>
        /// Aplica a LocalLogger la configuración de logging definida en CurrentConfig.Logging.
        /// </summary>
        public void ApplyLoggingConfiguration()
        {
            if (CurrentConfig == null)
            {
                LocalLogger.Warn("ConfigManager.ApplyLoggingConfiguration(): CurrentConfig es null. No se aplicará configuración de logging.");
                return;
            }

            var logConfig = CurrentConfig.Logging;

            if (logConfig == null)
            {
                LocalLogger.Warn("ConfigManager.ApplyLoggingConfiguration(): LoggingConfig es null. Se usará configuración por defecto.");
                // Configuración por defecto: INFO, archivo ON, webhook OFF.
                LocalLogger.ConfigureLevels(
                    globalLevel: LocalLogger.LogLevel.Info,
                    clientOverrideLevel: null,
                    enableFileLogging: true,
                    enableWebhookLogging: false);
                return;
            }

            var globalLevel = ParseLogLevel(logConfig.GlobalLevel, LocalLogger.LogLevel.Info);
            LocalLogger.LogLevel? clientOverrideLevel = null;

            if (!string.IsNullOrWhiteSpace(logConfig.ClientOverrideLevel))
            {
                clientOverrideLevel = ParseLogLevel(logConfig.ClientOverrideLevel, globalLevel);
            }

            bool enableFile = logConfig.EnableFileLogging;
            bool enableWebhook = logConfig.EnableDiscordLogging;

            LocalLogger.ConfigureLevels(
                globalLevel: globalLevel,
                clientOverrideLevel: clientOverrideLevel,
                enableFileLogging: enableFile,
                enableWebhookLogging: enableWebhook);

            if (!string.IsNullOrWhiteSpace(logConfig.DiscordWebhookUrl))
            {
                LocalLogger.ConfigureWebhook(logConfig.DiscordWebhookUrl);
            }
        }

        /// <summary>
        /// Crea una configuración por defecto cuando no existe archivo
        /// o no se puede deserializar la configuración.
        /// </summary>
        private ClientConfig CreateDefaultConfig()
        {
            var config = new ClientConfig
            {
                Version = "3.0.0.0",
                DeviceId = null, // Se generará posteriormente en EnsureDeviceId.
                ApiBaseUrl = "http://localhost/AZCKeeper/AZCKeeper_Client/Web/public/index.php/api/", // Placeholder para la API real.
                ApiAuthToken = null,
                Startup = new StartupConfig
                {
                    EnableAutoStartup = true,
                    StartMinimized = false
                },
                Updates = new UpdatesConfig
                {
                    EnableAutoUpdate = true,
                    CheckIntervalMinutes = 60,
                    AutoDownload = false,
                    AllowBetaVersions = false
                },
                Logging = new LoggingConfig
                {
                    GlobalLevel = "INFO",
                    ClientOverrideLevel = null,
                    EnableFileLogging = true,
                    EnableDiscordLogging = false,
                    DiscordWebhookUrl = null
                },
                Modules = new ModulesConfig
                {
                    EnableActivityTracking = true,
                    EnableWindowTracking = false,
                    EnableProcessTracking = false,
                    EnableBlocking = false,
                    EnableKeyboardHook = false,
                    EnableMouseHook = false,
                    EnableUpdateManager = false,
                    EnableDebugWindow = false,
                    EnableCallTracking = false,
                    CallProcessKeywords = new[]
                    {
                        "zoom", "teams", "skype", "meet", "webex", "3cx", "zoiper", "softphone"
                    },
                                    CallTitleKeywords = new[]
                    {
                        "meeting", "reunión", "llamada", "videollamada", "call", "zoom meeting"
                    }
                }
            };

            return config;
        }

        /// <summary>
        /// Convierte un texto ("INFO", "WARN", etc.) en un valor LogLevel.
        /// </summary>
        private LocalLogger.LogLevel ParseLogLevel(string levelString, LocalLogger.LogLevel defaultLevel)
        {
            if (string.IsNullOrWhiteSpace(levelString))
                return defaultLevel;

            switch (levelString.Trim().ToUpperInvariant())
            {
                case "NONE":
                    return LocalLogger.LogLevel.None;
                case "ERROR":
                    return LocalLogger.LogLevel.Error;
                case "WARN":
                case "WARNING":
                    return LocalLogger.LogLevel.Warn;
                case "INFO":
                    return LocalLogger.LogLevel.Info;
                default:
                    LocalLogger.Warn($"ConfigManager.ParseLogLevel(): nivel desconocido '{levelString}'. Se usa valor por defecto {defaultLevel}.");
                    return defaultLevel;
            }
        }

        /// <summary>
        /// Modelo principal de configuración del cliente.
        /// Representa la estructura que se almacena en el archivo JSON.
        /// </summary>
        internal class ClientConfig
        {
            public string Version { get; set; }
            public string DeviceId { get; set; }
            public string ApiBaseUrl { get; set; }
            public string ApiAuthToken { get; set; }
            public LoggingConfig Logging { get; set; }
            public ModulesConfig Modules { get; set; }
            public StartupConfig Startup { get; set; }     
            public UpdatesConfig Updates { get; set; }
        }
        /// <summary>
        /// Configuración de inicio automático.
        /// </summary>
        internal class StartupConfig
        {
            public bool EnableAutoStartup { get; set; } = true;
            public bool StartMinimized { get; set; } = false;
        }

        /// <summary>
        /// Configuración de actualizaciones automáticas.
        /// </summary>
        internal class UpdatesConfig
        {
            public bool EnableAutoUpdate { get; set; } = true;
            public int CheckIntervalMinutes { get; set; } = 60;
            public bool AutoDownload { get; set; } = false;
            public bool AllowBetaVersions { get; set; } = false;
        }
        /// <summary>
        /// Configuración de logging: niveles y destinos.
        /// </summary>
        internal class LoggingConfig
        {
            public string GlobalLevel { get; set; }
            public string ClientOverrideLevel { get; set; }
            public bool EnableFileLogging { get; set; }
            public bool EnableDiscordLogging { get; set; }
            public string DiscordWebhookUrl { get; set; }
        }

        /// <summary>
        /// Configuración de módulos: flags para activar/desactivar
        /// los distintos componentes de tracking o bloqueo.
        /// </summary>
        internal class ModulesConfig
        {
            public bool EnableActivityTracking { get; set; }
            public bool EnableWindowTracking { get; set; }
            public bool EnableProcessTracking { get; set; }
            public bool EnableBlocking { get; set; }
            public bool EnableKeyboardHook { get; set; }
            public bool EnableMouseHook { get; set; }
            public bool EnableUpdateManager { get; set; }
            public bool EnableDebugWindow { get; set; }
            public bool CountCallsAsActive { get; set; } = true;

            // Máximo idle permitido para considerar "en llamada" como activo (segundos).
            // 1800 = 30 minutos (default).
            public double CallActiveMaxIdleSeconds { get; set; } = 1800.0;

            /// <summary>
            /// Intervalo de muestreo para ActivityTracker, en segundos.
            /// Si no se establece (0), se usará valor por defecto (1s).
            /// </summary>
            public double ActivityIntervalSeconds { get; set; } = 1.0;

            /// <summary>
            /// Umbral de inactividad, en segundos. Si el idle es mayor o igual,
            /// se considera tiempo inactivo. Por defecto, 15s.
            /// </summary>
            public double ActivityInactivityThresholdSeconds { get; set; } = 15.0;

            /// <summary>
            /// Intervalo de muestreo para WindowTracker, en segundos.
            /// Por defecto, 1s.
            /// </summary>
            public double WindowTrackingIntervalSeconds { get; set; } = 1.0;


            /// <summary>
            /// Habilita el tracking específico de aplicaciones de llamada (VoIP / video).
            /// </summary>
            public bool EnableCallTracking { get; set; } = false;

            /// <summary>
            /// Palabras clave (subcadenas) para detectar procesos de apps de llamada.
            /// Ejemplo: "zoom", "teams", "skype", "3cx", "zoiper".
            /// </summary>
            public string[] CallProcessKeywords { get; set; }

            /// <summary>
            /// Palabras clave (subcadenas) para detectar títulos de ventanas de llamada.
            /// Ejemplo: "meeting", "call", "llamada", "videollamada".
            /// </summary>
            public string[] CallTitleKeywords { get; set; }
        }
    }
}
