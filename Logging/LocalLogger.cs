using System;
using System.IO;
using System.Net.Http;
using System.Text;
using System.Text.Json;
using System.Text.RegularExpressions;
using System.Threading.Tasks;

namespace AZCKeeper_Cliente.Logging
{
    /// <summary>
    /// Logger central del cliente AZC Keeper.
    ///
    /// Responsabilidades:
    /// - Definir niveles de log (None, Error, Warn, Info).
    /// - Controlar destinos de salida (archivo local, webhook).
    /// - Sanitizar información sensible (tokens, webhooks, headers) para evitar leaks.
    /// - Escribir mensajes de log sin lanzar excepciones hacia el exterior.
    ///
    /// Comunicación:
    /// - ConfigManager aplica la configuración de logging (niveles y webhook).
    /// - Todas las capas (Core/Network/Auth/Tracking) usan LocalLogger.* para diagnóstico.
    /// </summary>
    internal static class LocalLogger
    {
        internal enum LogLevel
        {
            None = 0,
            Error = 1,
            Warn = 2,
            Info = 3
        }

        private static LogLevel _globalLevel = LogLevel.Info;
        private static LogLevel? _clientOverrideLevel = null;

        private static bool _enableFileLogging = true;
        private static bool _enableWebhookLogging = false;

        private static readonly string _logBaseDirectory;
        private static string _currentLogFilePath;
        private static readonly object _fileLock = new object();

        private static readonly HttpClient _httpClient = new HttpClient();
        private static string _webhookUrl;

        // ---- Sanitización de secretos ----
        // Reemplaza patrones típicos: Authorization: Bearer xxx, token=xxx, webhook urls, etc.
        // (No buscamos ser perfectos, buscamos reducir riesgo de leak).
        private static readonly Regex _rxBearer =
            new Regex(@"(Authorization\s*:\s*Bearer\s+)([A-Za-z0-9\-\._~\+\/]+=*)",
                      RegexOptions.IgnoreCase | RegexOptions.Compiled);

        private static readonly Regex _rxBearerInline =
            new Regex(@"(\bBearer\s+)([A-Za-z0-9\-\._~\+\/]+=*)",
                      RegexOptions.IgnoreCase | RegexOptions.Compiled);

        private static readonly Regex _rxTokenKeyValue =
            new Regex(@"(\b(authToken|token|apiAuthToken|password)\b\s*[:=]\s*)([^\s,;]+)",
                      RegexOptions.IgnoreCase | RegexOptions.Compiled);

        private static readonly Regex _rxDiscordWebhook =
            new Regex(@"https?:\/\/(canary\.)?discord(app)?\.com\/api\/webhooks\/[^\s]+",
                      RegexOptions.IgnoreCase | RegexOptions.Compiled);

        // Límite defensivo para evitar mensajes enormes al webhook
        private const int WebhookMaxChars = 1500;

        /// <summary>
        /// Inicializa carpeta de logs en AppData y prepara el archivo del día.
        /// </summary>
        static LocalLogger()
        {
            try
            {
                string appData = Environment.GetFolderPath(Environment.SpecialFolder.ApplicationData);
                _logBaseDirectory = Path.Combine(appData, "AZCKeeper", "Logs");

                if (!Directory.Exists(_logBaseDirectory))
                    Directory.CreateDirectory(_logBaseDirectory);

                UpdateCurrentLogFilePath();
            }
            catch
            {
                // Degradar silenciosamente.
            }
        }

        /// <summary>
        /// Actualiza la ruta del archivo diario (YYYY-MM-DD.log).
        /// </summary>
        private static void UpdateCurrentLogFilePath()
        {
            try
            {
                string fileName = $"{DateTime.Now:yyyy-MM-dd}.log";
                _currentLogFilePath = Path.Combine(_logBaseDirectory, fileName);
            }
            catch
            {
            }
        }

        /// <summary>
        /// Configura niveles y destinos.
        /// Nota: si webhook no está configurado, enableWebhookLogging queda false.
        /// </summary>
        public static void ConfigureLevels(
            LogLevel globalLevel,
            LogLevel? clientOverrideLevel,
            bool enableFileLogging,
            bool enableWebhookLogging)
        {
            _globalLevel = globalLevel;
            _clientOverrideLevel = clientOverrideLevel;
            _enableFileLogging = enableFileLogging;

            // Solo se habilita si hay webhook presente
            _enableWebhookLogging = enableWebhookLogging && !string.IsNullOrWhiteSpace(_webhookUrl);

            Info($"LocalLogger.ConfigureLevels(): global={_globalLevel}, clientOverride={_clientOverrideLevel?.ToString() ?? "null"}, file={_enableFileLogging}, webhook={_enableWebhookLogging}");
        }

        /// <summary>
        /// Configura la URL del webhook remoto.
        /// No loguea URL completa por seguridad.
        /// </summary>
        public static void ConfigureWebhook(string webhookUrl)
        {
            _webhookUrl = webhookUrl;

            // Si el usuario activó webhook pero no hay URL, quedará apagado.
            _enableWebhookLogging = _enableWebhookLogging && !string.IsNullOrWhiteSpace(_webhookUrl);

            string present = (!string.IsNullOrWhiteSpace(_webhookUrl)).ToString();
            Info($"LocalLogger.ConfigureWebhook(): webhook configurado. URL presente={present}");
        }

        private static bool ShouldLog(LogLevel level)
        {
            if (_globalLevel == LogLevel.None)
                return false;

            LogLevel effective = _clientOverrideLevel ?? _globalLevel;
            if (effective == LogLevel.None)
                return false;

            return level <= effective;
        }

        /// <summary>
        /// Escribe un log de nivel INFO si está habilitado.
        /// </summary>
        public static void Info(string message)
        {
            if (!ShouldLog(LogLevel.Info)) return;
            WriteLog(LogLevel.Info, message);
        }

        /// <summary>
        /// Escribe un log de nivel WARN si está habilitado.
        /// </summary>
        public static void Warn(string message)
        {
            if (!ShouldLog(LogLevel.Warn)) return;
            WriteLog(LogLevel.Warn, message);
        }

        /// <summary>
        /// Escribe un log de nivel ERROR si está habilitado.
        /// </summary>
        public static void Error(string message)
        {
            if (!ShouldLog(LogLevel.Error)) return;
            WriteLog(LogLevel.Error, message);
        }

        /// <summary>
        /// Escribe un log de error con excepción y contexto.
        /// </summary>
        public static void Error(Exception exception, string contextMessage = null)
        {
            if (!ShouldLog(LogLevel.Error)) return;

            var sb = new StringBuilder();

            if (!string.IsNullOrWhiteSpace(contextMessage))
                sb.AppendLine(contextMessage);

            if (exception != null)
            {
                sb.AppendLine(exception.Message);
                sb.AppendLine(exception.StackTrace);

                if (exception.InnerException != null)
                {
                    sb.AppendLine("InnerException:");
                    sb.AppendLine(exception.InnerException.Message);
                    sb.AppendLine(exception.InnerException.StackTrace);
                }
            }

            WriteLog(LogLevel.Error, sb.ToString());
        }

        /// <summary>
        /// Maneja la escritura de logs a archivo y webhook (si aplica).
        /// </summary>
        private static void WriteLog(LogLevel level, string message)
        {
            try
            {
                string prefix = level.ToString().ToUpperInvariant();

                // Sanitizar antes de escribir/enviar
                string safeMessage = Sanitize(message);

                string line = $"{DateTime.Now:yyyy-MM-dd HH:mm:ss.fff} [{prefix}] {safeMessage}";

                // 1) Archivo local
                if (_enableFileLogging && !string.IsNullOrWhiteSpace(_logBaseDirectory))
                {
                    UpdateCurrentLogFilePath();

                    lock (_fileLock)
                    {
                        File.AppendAllText(_currentLogFilePath, line + Environment.NewLine, Encoding.UTF8);
                    }
                }

                // 2) Webhook (solo WARN/ERROR)
                if (_enableWebhookLogging &&
                    !string.IsNullOrWhiteSpace(_webhookUrl) &&
                    (level == LogLevel.Warn || level == LogLevel.Error))
                {
                    // Fire-and-forget mejorado: ejecuta en background y observa excepciones
                    Task.Run(async () => await SendToWebhookAsync(level, safeMessage))
                        .ContinueWith(t =>
                        {
                            if (t.IsFaulted && t.Exception != null)
                            {
                                // Loguear error de webhook a archivo sin recursión
                                var baseEx = t.Exception.GetBaseException();
                                WriteToFileOnly(LogLevel.Warn, $"LocalLogger: webhook send failed - {baseEx.Message}");
                            }
                        }, TaskContinuationOptions.OnlyOnFaulted);
                }
            }
            catch
            {
                // Degradar silenciosamente.
            }
        }

        /// <summary>
        /// Escribe solo a archivo (usado internamente para evitar recursión con webhook).
        /// </summary>
        private static void WriteToFileOnly(LogLevel level, string message)
        {
            try
            {
                if (!_enableFileLogging || string.IsNullOrWhiteSpace(_logBaseDirectory))
                    return;

                string prefix = level.ToString().ToUpperInvariant();
                string line = $"{DateTime.Now:yyyy-MM-dd HH:mm:ss.fff} [{prefix}] {message}";

                UpdateCurrentLogFilePath();

                lock (_fileLock)
                {
                    File.AppendAllText(_currentLogFilePath, line + Environment.NewLine, Encoding.UTF8);
                }
            }
            catch
            {
                // Degradar silenciosamente.
            }
        }

        /// <summary>
        /// Sanitiza texto para ocultar tokens, passwords y webhooks.
        /// </summary>
        private static string Sanitize(string text)
        {
            if (string.IsNullOrWhiteSpace(text))
                return text ?? string.Empty;

            string s = text;

            // Authorization: Bearer xxxx
            s = _rxBearer.Replace(s, m => m.Groups[1].Value + "***REDACTED***");
            s = _rxBearerInline.Replace(s, m => m.Groups[1].Value + "***REDACTED***");

            // token=..., authToken:..., password:...
            s = _rxTokenKeyValue.Replace(s, m => m.Groups[1].Value + "***REDACTED***");

            // Discord webhook url
            s = _rxDiscordWebhook.Replace(s, "***DISCORD_WEBHOOK_REDACTED***");

            return s;
        }

        /// <summary>
        /// Envía log a webhook (Discord) de forma asíncrona.
        /// </summary>
        private static async Task SendToWebhookAsync(LogLevel level, string safeMessage)
        {
            if (string.IsNullOrWhiteSpace(_webhookUrl))
                return;

            try
            {
                // Payload corto (Discord tiene límites)
                string msg = safeMessage ?? string.Empty;
                if (msg.Length > WebhookMaxChars)
                    msg = msg.Substring(0, WebhookMaxChars) + "...(truncated)";

                string contentText = $"`AZCKeeper_Cliente` **[{level}]**\n{msg}";

                var payload = new { content = contentText };
                string json = JsonSerializer.Serialize(payload);

                using var httpContent = new StringContent(json, Encoding.UTF8, "application/json");
                using var _ = await _httpClient.PostAsync(_webhookUrl, httpContent).ConfigureAwait(false);
            }
            catch
            {
                // No romper flujo por webhook.
            }
        }
    }
}
