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
    /// <summary>
    /// Línea pendiente de reportar al servidor (tabla keeper_client_log).
    /// </summary>
    internal sealed class ClientLogReport
    {
        public string Level { get; set; }
        public string Source { get; set; }
        public string Message { get; set; }
        public DateTime LocalTs { get; set; }
    }

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

        // ---- Buffer circular de issues (Warn/Error) para la ventana Debug ----
        private const int RecentIssuesMax = 15;
        private static readonly System.Collections.Generic.Queue<string> _recentIssues =
            new System.Collections.Generic.Queue<string>();
        private static readonly object _recentIssuesLock = new object();

        // ---- Cola de reporte al servidor (keeper_client_log) ----
        // Separada del buffer de la ventana Debug: aquella es un anillo que se sobreescribe
        // para mirar en vivo; esta se DRENA solo cuando el servidor confirma el envío, para
        // que cada línea se persista una vez. Acotada: si el equipo pasa horas sin red, se
        // descartan las más viejas antes que crecer sin límite.
        private const int ReportQueueMax = 200;
        private static readonly System.Collections.Generic.Queue<ClientLogReport> _reportQueue =
            new System.Collections.Generic.Queue<ClientLogReport>();
        private static readonly object _reportLock = new object();

        // Corta el lazo de realimentación: mientras se envían logs, los Warn que genere
        // ese propio envío no se vuelven a encolar para reportar.
        private static readonly System.Threading.AsyncLocal<bool> _suppressReport =
            new System.Threading.AsyncLocal<bool>();

        private static bool _enableFileLogging = true;
        private static bool _enableWebhookLogging = false;

        private static readonly string _logBaseDirectory;
        private static string _currentLogFilePath;
        private static readonly object _fileLock = new object();

        private static readonly HttpClient _httpClient = new HttpClient();
        private static string _webhookUrl;
        private static string _userContext;

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

        /// <summary>
        /// Establece el nombre del usuario para incluirlo en cada línea de log.
        /// </summary>
        public static void SetUserContext(string displayName)
        {
            _userContext = string.IsNullOrWhiteSpace(displayName) ? null : displayName.Trim();
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
        /// Registra un issue (Warn/Error) en el buffer circular, sin pasar por ShouldLog,
        /// para que quede disponible en la ventana Debug aunque el nivel configurado
        /// filtre la salida a archivo/webhook.
        /// </summary>
        private static void CaptureIssue(LogLevel level, string message)
        {
            lock (_recentIssuesLock)
            {
                _recentIssues.Enqueue($"{DateTime.Now:HH:mm:ss} [{level}] {message}");
                while (_recentIssues.Count > RecentIssuesMax) _recentIssues.Dequeue();
            }

            EnqueueForReport(level, InferSource(message), message);
        }

        /// <summary>Últimos Warn/Error para diagnóstico en la ventana Debug (más reciente al final).</summary>
        public static System.Collections.Generic.IReadOnlyList<string> GetRecentIssues()
        {
            lock (_recentIssuesLock) { return _recentIssues.ToArray(); }
        }

        // ---------------- Reporte al servidor ----------------

        /// <summary>
        /// Reporta un evento al servidor aunque su nivel no llegue al archivo local.
        /// Pensado para eventos de update, que son Info y el nivel de la flota está en Warn:
        /// sin esto, un equipo que nunca actualiza no deja rastro de por qué.
        /// </summary>
        public static void ReportEvent(LogLevel level, string source, string message)
        {
            EnqueueForReport(level, source, message);

            // El reporte al servidor ignora el nivel; el archivo local no. Saltarse ShouldLog
            // aquí llenaba de líneas Info el log de una flota configurada en Warn.
            if (!ShouldLog(level)) return;
            WriteLog(level, message);
        }

        private static void EnqueueForReport(LogLevel level, string source, string message)
        {
            if (_suppressReport.Value) return;
            if (string.IsNullOrWhiteSpace(message)) return;

            var entry = new ClientLogReport
            {
                Level = LevelToWire(level),
                Source = source,
                Message = Sanitize(message),
                LocalTs = DateTime.Now
            };

            lock (_reportLock)
            {
                _reportQueue.Enqueue(entry);
                while (_reportQueue.Count > ReportQueueMax) _reportQueue.Dequeue();
            }
        }

        /// <summary>
        /// Saca hasta <paramref name="max"/> líneas para enviar. Solo se pierden si el envío
        /// confirma; si falla, el llamador debe devolverlas con <see cref="RequeueForReport"/>.
        /// </summary>
        public static System.Collections.Generic.List<ClientLogReport> DrainForReport(int max)
        {
            var batch = new System.Collections.Generic.List<ClientLogReport>();

            lock (_reportLock)
            {
                while (_reportQueue.Count > 0 && batch.Count < max)
                    batch.Add(_reportQueue.Dequeue());
            }

            return batch;
        }

        /// <summary>Devuelve al frente de la cola las líneas cuyo envío falló.</summary>
        public static void RequeueForReport(System.Collections.Generic.IEnumerable<ClientLogReport> entries)
        {
            if (entries == null) return;

            lock (_reportLock)
            {
                var pendientes = new System.Collections.Generic.List<ClientLogReport>(entries);
                pendientes.AddRange(_reportQueue);
                _reportQueue.Clear();

                int desde = Math.Max(0, pendientes.Count - ReportQueueMax);
                for (int i = desde; i < pendientes.Count; i++)
                    _reportQueue.Enqueue(pendientes[i]);
            }
        }

        public static int PendingReportCount { get { lock (_reportLock) { return _reportQueue.Count; } } }

        /// <summary>
        /// Suprime el encolado de reportes en el flujo async actual. Envolver el envío de
        /// logs con esto: reportar el fallo de reportar es un lazo infinito.
        /// </summary>
        public static IDisposable SuppressReporting() => new ReportSuppression();

        private sealed class ReportSuppression : IDisposable
        {
            private readonly bool _previo;
            public ReportSuppression() { _previo = _suppressReport.Value; _suppressReport.Value = true; }
            public void Dispose() { _suppressReport.Value = _previo; }
        }

        private static string LevelToWire(LogLevel level)
        {
            switch (level)
            {
                case LogLevel.Error: return "error";
                case LogLevel.Warn: return "warn";
                default: return "info";
            }
        }

        /// <summary>
        /// Deduce el subsistema desde el prefijo del mensaje ("ApiClient: ...").
        /// Debe devolver un valor de ClientLogRepo::SOURCES; el servidor normaliza
        /// a 'other' cualquier otro.
        /// </summary>
        private static string InferSource(string message)
        {
            if (string.IsNullOrWhiteSpace(message)) return "other";

            int corte = message.IndexOf(':');
            string prefijo = corte > 0 ? message.Substring(0, corte) : message;

            if (prefijo.StartsWith("ApiClient", StringComparison.OrdinalIgnoreCase) ||
                prefijo.StartsWith("OfflineQueue", StringComparison.OrdinalIgnoreCase))
                return "network";

            if (prefijo.StartsWith("UpdateManager", StringComparison.OrdinalIgnoreCase))
                return "update";

            if (prefijo.StartsWith("WebBlocking", StringComparison.OrdinalIgnoreCase) ||
                prefijo.StartsWith("SystemProxyManager", StringComparison.OrdinalIgnoreCase) ||
                prefijo.StartsWith("LocalPacServer", StringComparison.OrdinalIgnoreCase) ||
                prefijo.StartsWith("PacContentBuilder", StringComparison.OrdinalIgnoreCase) ||
                prefijo.StartsWith("KeyBlocker", StringComparison.OrdinalIgnoreCase))
                return "blocking";

            if (prefijo.StartsWith("AuthManager", StringComparison.OrdinalIgnoreCase))
                return "auth";

            if (prefijo.StartsWith("ActivityTracker", StringComparison.OrdinalIgnoreCase) ||
                prefijo.StartsWith("WindowTracker", StringComparison.OrdinalIgnoreCase) ||
                prefijo.StartsWith("ProcessTracker", StringComparison.OrdinalIgnoreCase))
                return "tracking";

            if (prefijo.StartsWith("CoreService", StringComparison.OrdinalIgnoreCase) ||
                prefijo.StartsWith("Program", StringComparison.OrdinalIgnoreCase) ||
                prefijo.StartsWith("StartupManager", StringComparison.OrdinalIgnoreCase))
                return "core";

            return "other";
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
            CaptureIssue(LogLevel.Warn, message);
            if (!ShouldLog(LogLevel.Warn)) return;
            WriteLog(LogLevel.Warn, message);
        }

        /// <summary>
        /// Escribe un log de nivel ERROR si está habilitado.
        /// </summary>
        public static void Error(string message)
        {
            CaptureIssue(LogLevel.Error, message);
            if (!ShouldLog(LogLevel.Error)) return;
            WriteLog(LogLevel.Error, message);
        }

        /// <summary>
        /// Escribe un log de error con excepción y contexto.
        /// </summary>
        public static void Error(Exception exception, string contextMessage = null)
        {
            CaptureIssue(LogLevel.Error, contextMessage ?? exception?.Message ?? "error");
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

                string userTag = _userContext != null ? $" [{_userContext}]" : "";
                string line = $"{DateTime.Now:yyyy-MM-dd HH:mm:ss.fff} [{prefix}]{userTag} {safeMessage}";

                // 1) Archivo local
                if (_enableFileLogging && !string.IsNullOrWhiteSpace(_logBaseDirectory))
                {
                    UpdateCurrentLogFilePath();

                    lock (_fileLock)
                    {
                        File.AppendAllText(_currentLogFilePath, line + Environment.NewLine, Encoding.UTF8);
                    }
                }

                // 2) Webhook (todos los niveles habilitados)
                if (_enableWebhookLogging &&
                    !string.IsNullOrWhiteSpace(_webhookUrl))
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

                string userTag = !string.IsNullOrWhiteSpace(_userContext) ? $" `{_userContext}`" : "";
                string contentText = $"`AZCKeeper_Cliente`{userTag} **[{level}]**\n{msg}";

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
