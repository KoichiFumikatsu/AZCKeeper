using System;
using System.Net.Http;
using System.Net.Http.Headers;
using System.Text;
using System.Text.Json;
using System.Threading.Tasks;
using AZCKeeper_Cliente.Auth;
using AZCKeeper_Cliente.Config;
using AZCKeeper_Cliente.Logging;

namespace AZCKeeper_Cliente.Network
{
    /// <summary>
    /// ApiClient encapsula la comunicación HTTP con el backend PHP (API).
    /// Alineado al backend actual:
    /// - login: { ok, token, expiresAtUtc, userId, deviceId }
    /// - handshake: { ok, serverTimeUtc, policyApplied, effectiveConfig }
    /// - activity-day: Bearer + payload con DayDate + IdleSeconds (etc.)
    /// - activity-day (get): para retomar el día si ya existe registro en BD
    /// </summary>
    internal class ApiClient
    {
        private readonly ConfigManager _configManager;
        private readonly AuthManager _authManager;
        private readonly HttpClient _httpClient;
        private readonly OfflineQueue _offlineQueue;
        private System.Timers.Timer _retryTimer;

        private readonly JsonSerializerOptions _jsonOptions = new JsonSerializerOptions
        {
            PropertyNamingPolicy = JsonNamingPolicy.CamelCase,
            PropertyNameCaseInsensitive = true
        };

        public ApiClient(ConfigManager configManager, AuthManager authManager)
        {
            _configManager = configManager ?? throw new ArgumentNullException(nameof(configManager));
            _authManager = authManager ?? throw new ArgumentNullException(nameof(authManager));
            _offlineQueue = new OfflineQueue(); // ← AÑADIR

            _httpClient = new HttpClient();

            string baseUrl = _configManager.CurrentConfig?.ApiBaseUrl;

            if (string.IsNullOrWhiteSpace(baseUrl))
            {
                LocalLogger.Warn("ApiClient: ApiBaseUrl no definida. Las llamadas HTTP fallarán hasta configurar correctamente.");
            }
            else
            {
                if (!baseUrl.EndsWith("/"))
                    baseUrl += "/";

                _httpClient.BaseAddress = new Uri(baseUrl);
            }

            _httpClient.Timeout = TimeSpan.FromSeconds(15);

            string version = _configManager.CurrentConfig?.Version ?? "0.0.0.0";
            _httpClient.DefaultRequestHeaders.UserAgent.ParseAdd($"AZCKeeper-Cliente/{version}");
            StartRetryTimer();
        }

        // -------------------- LOGIN --------------------

        public async Task<LoginResult> SendLoginAsync(LoginRequest request)
        {
            if (request == null) throw new ArgumentNullException(nameof(request));

            var result = new LoginResult();

            try
            {
                if (_httpClient.BaseAddress == null)
                {
                    LocalLogger.Warn("ApiClient.SendLoginAsync(): BaseAddress es null.");
                    result.IsSuccess = false;
                    result.Error = "No ApiBaseUrl";
                    return result;
                }

                const string url = "client/login";

                string json = JsonSerializer.Serialize(request, _jsonOptions);
                using var content = new StringContent(json, Encoding.UTF8, "application/json");

                using var httpRequest = new HttpRequestMessage(HttpMethod.Post, url) { Content = content };

                using var response = await _httpClient.SendAsync(httpRequest).ConfigureAwait(false);
                result.StatusCode = (int)response.StatusCode;

                string responseBody = await SafeReadBodyAsync(response).ConfigureAwait(false);
                result.BodyPreview = Preview(responseBody);

                if (string.IsNullOrWhiteSpace(responseBody))
                {
                    result.IsSuccess = false;
                    result.Error = "Empty body";
                    return result;
                }

                if (!LooksLikeJson(response, responseBody))
                {
                    result.IsSuccess = false;
                    result.Error = "Non-JSON response";
                    return result;
                }

                var parsed = JsonSerializer.Deserialize<LoginResponse>(responseBody, _jsonOptions);
                if (parsed == null)
                {
                    result.IsSuccess = false;
                    result.Error = "Invalid JSON";
                    return result;
                }

                result.Response = parsed;
                result.IsSuccess = parsed.Ok && !string.IsNullOrWhiteSpace(parsed.Token);

                if (!result.IsSuccess)
                    result.Error = parsed.Error ?? $"Login failed HTTP {result.StatusCode}";

                return result;
            }
            catch (Exception ex)
            {
                LocalLogger.Error(ex, "ApiClient.SendLoginAsync(): error.");
                result.IsSuccess = false;
                result.Error = ex.Message;
                return result;
            }
        }

        // -------------------- HANDSHAKE --------------------

        public async Task<HandshakeResult> SendHandshakeAsync(HandshakeRequest request)
        {
            if (request == null) throw new ArgumentNullException(nameof(request));

            var result = new HandshakeResult();

            try
            {
                if (_httpClient.BaseAddress == null)
                {
                    LocalLogger.Warn("ApiClient.SendHandshakeAsync(): BaseAddress es null.");
                    result.IsSuccess = false;
                    return result;
                }

                const string url = "client/handshake";

                string json = JsonSerializer.Serialize(request, _jsonOptions);
                using var content = new StringContent(json, Encoding.UTF8, "application/json");
                using var httpRequest = CreateRequest(HttpMethod.Post, url, content);

                using var response = await _httpClient.SendAsync(httpRequest).ConfigureAwait(false);

                result.StatusCode = (int)response.StatusCode;

                string responseBody = await SafeReadBodyAsync(response).ConfigureAwait(false);
                result.BodyPreview = Preview(responseBody);

                if (response.StatusCode == System.Net.HttpStatusCode.Unauthorized ||
                    response.StatusCode == System.Net.HttpStatusCode.Forbidden)
                {
                    LocalLogger.Warn($"ApiClient.SendHandshakeAsync(): Unauthorized/Forbidden HTTP {(int)response.StatusCode}. BodyPreview={result.BodyPreview}");
                    result.IsUnauthorized = true;
                    result.IsSuccess = false;
                    return result;
                }

                if (!response.IsSuccessStatusCode)
                {
                    LocalLogger.Warn($"ApiClient.SendHandshakeAsync(): HTTP {(int)response.StatusCode} {response.ReasonPhrase}. BodyPreview={result.BodyPreview}");
                    result.IsSuccess = false;
                    return result;
                }

                if (string.IsNullOrWhiteSpace(responseBody))
                {
                    LocalLogger.Warn("ApiClient.SendHandshakeAsync(): cuerpo vacío.");
                    result.IsSuccess = false;
                    return result;
                }

                if (!LooksLikeJson(response, responseBody))
                {
                    string ctype = response.Content?.Headers?.ContentType?.MediaType ?? "(sin content-type)";
                    LocalLogger.Warn($"ApiClient.SendHandshakeAsync(): no parece JSON. ContentType={ctype}. BodyPreview={result.BodyPreview}");
                    result.IsNonJsonResponse = true;
                    result.IsSuccess = false;
                    return result;
                }

                var parsed = JsonSerializer.Deserialize<HandshakeResponse>(responseBody, _jsonOptions);

                if (parsed == null)
                {
                    LocalLogger.Warn($"ApiClient.SendHandshakeAsync(): no deserializa. BodyPreview={result.BodyPreview}");
                    result.IsSuccess = false;
                    return result;
                }

                result.Response = parsed;
                result.IsSuccess = parsed.Ok && parsed.EffectiveConfig != null;

                LocalLogger.Info("ApiClient.SendHandshakeAsync(): handshake recibido y deserializado correctamente.");
                return result;
            }
            catch (JsonException jex)
            {
                LocalLogger.Error(jex, "ApiClient.SendHandshakeAsync(): error JSON.");
                result.IsSuccess = false;
                return result;
            }
            catch (Exception ex)
            {
                LocalLogger.Error(ex, "ApiClient.SendHandshakeAsync(): error.");
                result.IsSuccess = false;
                return result;
            }
        }

        // -------------------- ACTIVITY DAY (UPSERT) --------------------

        public async Task SendActivityDayAsync(ActivityDayPayload payload, bool fromQueue = false)
        {
            if (payload == null) throw new ArgumentNullException(nameof(payload));

            try
            {
                if (_httpClient.BaseAddress == null)
                {
                    LocalLogger.Warn("ApiClient.SendActivityDayAsync(): BaseAddress es null.");
                    if (!fromQueue) _offlineQueue.Enqueue("client/activity-day", payload);
                    return;
                }

                const string url = "client/activity-day";

                string json = JsonSerializer.Serialize(payload, _jsonOptions);
                using var content = new StringContent(json, Encoding.UTF8, "application/json");
                using var httpRequest = CreateRequest(HttpMethod.Post, url, content);

                using var response = await _httpClient.SendAsync(httpRequest).ConfigureAwait(false);

                if (!response.IsSuccessStatusCode)
                {
                    string body = await SafeReadBodyAsync(response).ConfigureAwait(false);
                    LocalLogger.Warn($"ApiClient.SendActivityDayAsync(): HTTP {(int)response.StatusCode}. BodyPreview={Preview(body)}");

                    // Solo encolar si no viene de la cola (evitar loop infinito)
                    if (!fromQueue) _offlineQueue.Enqueue("client/activity-day", payload);
                    return;
                }

                LocalLogger.Info($"ApiClient.SendActivityDayAsync(): activity-day enviado. DayDate={payload.DayDate}");
            }
            catch (HttpRequestException ex)
            {
                // Error de red: encolar (log limpio sin stack trace completo)
                string errorMsg = ex.InnerException?.Message ?? ex.Message;
                LocalLogger.Warn($"ApiClient.SendActivityDayAsync(): red caída. Encolando... Error: {errorMsg}");

                if (!fromQueue) _offlineQueue.Enqueue("client/activity-day", payload);
            }
            catch (Exception ex)
            {
                // Otros errores: log completo
                LocalLogger.Error(ex, $"ApiClient.SendActivityDayAsync(): error inesperado. DayDate={payload.DayDate}");
                if (!fromQueue) _offlineQueue.Enqueue("client/activity-day", payload);
            }
        }

        // -------------------- ACTIVITY DAY (GET / RESUME) --------------------
        // Recomendado:
        // GET client/activity-day?deviceId=...&dayDate=YYYY-MM-DD
        //
        // Si tu backend lo dejó como POST /client/activity-day/get,
        // cambia a HttpMethod.Post y manda JSON { deviceId, dayDate }.

        public async Task<ActivityDayGetResult> GetActivityDayAsync(string deviceId, string dayDate)
        {
            var result = new ActivityDayGetResult();

            try
            {
                if (_httpClient.BaseAddress == null)
                {
                    result.IsSuccess = false;
                    result.Error = "No ApiBaseUrl";
                    return result;
                }

                if (string.IsNullOrWhiteSpace(deviceId) || string.IsNullOrWhiteSpace(dayDate))
                {
                    result.IsSuccess = false;
                    result.Error = "Missing deviceId/dayDate";
                    return result;
                }

                string url = $"client/activity-day?deviceId={Uri.EscapeDataString(deviceId)}&dayDate={Uri.EscapeDataString(dayDate)}";
                using var httpRequest = CreateRequest(HttpMethod.Get, url, content: null);

                using var response = await _httpClient.SendAsync(httpRequest).ConfigureAwait(false);
                result.StatusCode = (int)response.StatusCode;

                string body = await SafeReadBodyAsync(response).ConfigureAwait(false);
                result.BodyPreview = Preview(body);

                if (response.StatusCode == System.Net.HttpStatusCode.Unauthorized ||
                    response.StatusCode == System.Net.HttpStatusCode.Forbidden)
                {
                    result.IsUnauthorized = true;
                    result.IsSuccess = false;
                    result.Error = "Unauthorized";
                    return result;
                }

                if (!response.IsSuccessStatusCode)
                {
                    result.IsSuccess = false;
                    result.Error = $"HTTP {result.StatusCode}";
                    return result;
                }

                if (string.IsNullOrWhiteSpace(body))
                {
                    result.IsSuccess = false;
                    result.Error = "Empty body";
                    return result;
                }

                if (!LooksLikeJson(response, body))
                {
                    result.IsSuccess = false;
                    result.Error = "Non-JSON response";
                    return result;
                }

                var parsed = JsonSerializer.Deserialize<ActivityDayGetResponse>(body, _jsonOptions);
                if (parsed == null)
                {
                    result.IsSuccess = false;
                    result.Error = "Invalid JSON";
                    return result;
                }

                result.Response = parsed;
                result.IsSuccess = parsed.Ok;

                if (!result.IsSuccess)
                    result.Error = parsed.Error ?? "Get activity-day failed";

                return result;
            }
            catch (Exception ex)
            {
                LocalLogger.Error(ex, "ApiClient.GetActivityDayAsync(): error.");
                result.IsSuccess = false;
                result.Error = ex.Message;
                return result;
            }
        }

        // -------------------- WINDOW EPISODE --------------------
        private static string SanitizeString(string input, int maxLength)
        {
            if (string.IsNullOrEmpty(input)) return input;

            // Remover caracteres problemáticos (emojis, control chars)
            string cleaned = System.Text.RegularExpressions.Regex.Replace(input, @"[^\u0020-\u007E\u00A0-\uFFFF]", "");

            // Truncar
            if (cleaned.Length > maxLength)
                cleaned = cleaned.Substring(0, maxLength);

            return cleaned;
        }
        public async Task SendWindowEpisodeAsync(WindowEpisodePayload payload, bool fromQueue = false)
        {
            if (payload == null) throw new ArgumentNullException(nameof(payload));

            try
            {
                if (_httpClient.BaseAddress == null)
                {
                    LocalLogger.Warn("ApiClient.SendWindowEpisodeAsync(): BaseAddress es null.");
                    if (!fromQueue) _offlineQueue.Enqueue("client/window-episode", payload);
                    return;
                }

                const string url = "client/window-episode";

                payload.ProcessName = SanitizeString(payload.ProcessName, 190);
                payload.WindowTitle = SanitizeString(payload.WindowTitle, 500);

                string json = JsonSerializer.Serialize(payload, _jsonOptions);
                using var content = new StringContent(json, Encoding.UTF8, "application/json");
                using var httpRequest = CreateRequest(HttpMethod.Post, url, content);

                using var response = await _httpClient.SendAsync(httpRequest).ConfigureAwait(false);

                if (!response.IsSuccessStatusCode)
                {
                    string body = await SafeReadBodyAsync(response).ConfigureAwait(false);
                    LocalLogger.Warn($"ApiClient.SendWindowEpisodeAsync(): HTTP {(int)response.StatusCode}. BodyPreview={Preview(body)}");
                    if (!fromQueue) _offlineQueue.Enqueue("client/window-episode", payload);
                    return;
                }
            }
            catch (HttpRequestException ex)
            {
                string errorMsg = ex.InnerException?.Message ?? ex.Message;
                LocalLogger.Warn($"ApiClient.SendWindowEpisodeAsync(): red caída. Encolando... Error: {errorMsg}");

                if (!fromQueue) _offlineQueue.Enqueue("client/window-episode", payload);
            }
            catch (Exception ex)
            {
                LocalLogger.Error(ex, "ApiClient.SendWindowEpisodeAsync(): error inesperado.");
                if (!fromQueue) _offlineQueue.Enqueue("client/window-episode", payload);
            }
        }

        // -------------------- GET GENÉRICO --------------------

        /// <summary>
        /// Realiza un GET genérico y retorna el body como string.
        /// Útil para endpoints simples como /client/version.
        /// </summary>
        public async Task<string> GetAsync(string relativeUrl)
        {
            try
            {
                if (_httpClient.BaseAddress == null)
                {
                    LocalLogger.Warn($"ApiClient.GetAsync(): BaseAddress es null.");
                    return null;
                }

                using var httpRequest = CreateRequest(HttpMethod.Get, relativeUrl, content: null);
                using var response = await _httpClient.SendAsync(httpRequest).ConfigureAwait(false);

                if (!response.IsSuccessStatusCode)
                {
                    string body = await SafeReadBodyAsync(response).ConfigureAwait(false);
                    LocalLogger.Warn($"ApiClient.GetAsync({relativeUrl}): HTTP {(int)response.StatusCode}. BodyPreview={Preview(body)}");
                    return null;
                }

                return await SafeReadBodyAsync(response).ConfigureAwait(false);
            }
            catch (Exception ex)
            {
                LocalLogger.Error(ex, $"ApiClient.GetAsync({relativeUrl}): error.");
                return null;
            }
        }
        // -------------------- POST GENÉRICO --------------------

        /// <summary>
        /// Realiza un POST genérico y retorna el body como string.
        /// </summary>
        public async Task<string> PostAsync(string relativeUrl, object payload)
        {
            try
            {
                if (_httpClient.BaseAddress == null)
                {
                    LocalLogger.Warn($"ApiClient.PostAsync(): BaseAddress es null.");
                    return null;
                }

                string json = JsonSerializer.Serialize(payload, _jsonOptions);
                using var content = new StringContent(json, Encoding.UTF8, "application/json");
                using var httpRequest = CreateRequest(HttpMethod.Post, relativeUrl, content);

                using var response = await _httpClient.SendAsync(httpRequest).ConfigureAwait(false);

                if (!response.IsSuccessStatusCode)
                {
                    string body = await SafeReadBodyAsync(response).ConfigureAwait(false);
                    LocalLogger.Warn($"ApiClient.PostAsync({relativeUrl}): HTTP {(int)response.StatusCode}. BodyPreview={Preview(body)}");
                    return null;
                }

                return await SafeReadBodyAsync(response).ConfigureAwait(false);
            }
            catch (Exception ex)
            {
                LocalLogger.Error(ex, $"ApiClient.PostAsync({relativeUrl}): error.");
                return null;
            }
        }

        // -------------------- Helpers --------------------
        // Ajuste helper: permitir content null
        private HttpRequestMessage CreateRequest(HttpMethod method, string relativeUrl, HttpContent content)
        {
            var request = new HttpRequestMessage(method, relativeUrl);
            if (content != null) request.Content = content;

            if (!string.IsNullOrWhiteSpace(_authManager.AuthToken))
            {
                // Enviar token en AMBOS lugares para máxima compatibilidad
                request.Headers.Authorization = new AuthenticationHeaderValue("Bearer", _authManager.AuthToken);

                // Header custom que Apache SIEMPRE pasa (fallback)
                request.Headers.Add("X-Auth-Token", _authManager.AuthToken);
            }

            return request;
        }


        private static async Task<string> SafeReadBodyAsync(HttpResponseMessage response)
        {
            try
            {
                if (response?.Content == null) return string.Empty;
                return await response.Content.ReadAsStringAsync().ConfigureAwait(false);
            }
            catch
            {
                return string.Empty;
            }
        }

        private static bool LooksLikeJson(HttpResponseMessage response, string body)
        {
            try
            {
                var ctype = response?.Content?.Headers?.ContentType?.MediaType;
                if (!string.IsNullOrWhiteSpace(ctype) &&
                    ctype.IndexOf("json", StringComparison.OrdinalIgnoreCase) >= 0)
                    return true;

                if (string.IsNullOrWhiteSpace(body)) return false;

                string trimmed = body.TrimStart();
                if (trimmed.Length == 0) return false;

                char first = trimmed[0];
                return first == '{' || first == '[';
            }
            catch
            {
                return false;
            }
        }

        private static string Preview(string body, int maxLen = 140)
        {
            if (string.IsNullOrWhiteSpace(body)) return "(empty)";
            string t = body.Replace("\r", " ").Replace("\n", " ").Trim();
            if (t.Length <= maxLen) return t;
            return t.Substring(0, maxLen) + "...";
        }

        /// <summary>
        /// Inicia timer para procesar cola offline cada 30 segundos.
        /// </summary>
        private void StartRetryTimer()
        {
            _retryTimer = new System.Timers.Timer(30_000); // 30s
            _retryTimer.AutoReset = true;
            _retryTimer.Elapsed += async (s, e) => await ProcessOfflineQueueAsync();
            _retryTimer.Start();

            LocalLogger.Info("ApiClient: RetryTimer iniciado (cada 30s).");
        }/// <summary>
         /// Actualiza el intervalo del timer de reintentos offline.
         /// </summary>
        public void UpdateRetryInterval(int seconds)
        {
            if (_retryTimer != null && seconds > 0)
            {
                _retryTimer.Stop();
                _retryTimer.Interval = seconds * 1000;
                _retryTimer.Start();
                LocalLogger.Info($"ApiClient: RetryTimer actualizado a {seconds}s.");
            }
        }

        /// <summary>
        /// Procesa items pendientes de la cola offline.
        /// </summary>
        public async Task ProcessOfflineQueueAsync()
        {
            try
            {
                var pending = _offlineQueue.GetPendingItems(10); // Procesar 10 por lote
                if (pending.Count == 0) return;

                LocalLogger.Info($"ApiClient: procesando {pending.Count} items de cola offline...");

                foreach (var item in pending)
                {
                    try
                    {
                        bool success = false;

                        if (item.Endpoint == "client/activity-day")
                        {
                            var payload = JsonSerializer.Deserialize<ActivityDayPayload>(item.PayloadJson, _jsonOptions);
                            if (payload != null)
                            {
                                await SendActivityDayAsync(payload, fromQueue: true);
                                success = true;
                            }
                        }
                        else if (item.Endpoint == "client/window-episode")
                        {
                            var payload = JsonSerializer.Deserialize<WindowEpisodePayload>(item.PayloadJson, _jsonOptions);
                            if (payload != null)
                            {
                                await SendWindowEpisodeAsync(payload, fromQueue: true);
                                success = true;
                            }
                        }

                        if (success)
                        {
                            _offlineQueue.MarkAsSent(item.Id);
                            LocalLogger.Info($"ApiClient: item {item.Id} reenviado exitosamente.");
                        }
                        else
                        {
                            _offlineQueue.MarkAsRetried(item.Id, "Deserialización falló");
                        }
                    }
                    catch (Exception ex)
                    {
                        _offlineQueue.MarkAsRetried(item.Id, ex.Message);
                        LocalLogger.Warn($"ApiClient: retry falló para item {item.Id}: {ex.Message}");
                    }
                }

                // Limpiar dead letters
                _offlineQueue.CleanupDeadLetters();
            }
            catch (Exception ex)
            {
                LocalLogger.Error(ex, "ApiClient.ProcessOfflineQueueAsync(): error general.");
            }
        }
        // -------------------- MODELOS --------------------

        // Modelos GET
        internal class ActivityDayGetResponse
        {
            public bool Ok { get; set; }
            public bool Found { get; set; }
            public string DayDate { get; set; }
            public int TzOffsetMinutes { get; set; }
            public int ActiveSeconds { get; set; }
            public int IdleSeconds { get; set; }
            public int CallSeconds { get; set; }
            public int SamplesCount { get; set; }
            public string FirstEventAt { get; set; }
            public string LastEventAt { get; set; }
            public string Error { get; set; }

            // Categorías
            public double WorkHoursActiveSeconds { get; set; }
            public double WorkHoursIdleSeconds { get; set; }
            public double LunchActiveSeconds { get; set; }
            public double LunchIdleSeconds { get; set; }
            public double AfterHoursActiveSeconds { get; set; }
            public double AfterHoursIdleSeconds { get; set; }
        }

        internal class ActivityDayGetResult
        {
            public bool IsSuccess { get; set; }
            public int? StatusCode { get; set; }
            public bool IsUnauthorized { get; set; }

            public string BodyPreview { get; set; }
            public string Error { get; set; }
            public ActivityDayGetResponse Response { get; set; }
        }
        internal class LoginRequest
        {
            public string Username { get; set; }   // CC
            public string Password { get; set; }   // legacy plain (como definiste)
            public string DeviceId { get; set; }   // device guid (string)
            public string DeviceName { get; set; }
        }

        internal class LoginResponse
        {
            public bool Ok { get; set; }
            public string Token { get; set; }
            public string ExpiresAtUtc { get; set; }
            public int? UserId { get; set; }
            public int? DeviceId { get; set; }
            public string Error { get; set; }
        }

        internal class LoginResult
        {
            public bool IsSuccess { get; set; }
            public int? StatusCode { get; set; }
            public string BodyPreview { get; set; }
            public string Error { get; set; }
            public LoginResponse Response { get; set; }
        }

        internal class HandshakeRequest
        {
            public string DeviceId { get; set; }
            public string Version { get; set; }
            public string DeviceName { get; set; }
        }

        internal class HandshakeResponse
        {
            public bool Ok { get; set; }
            public string ServerTimeUtc { get; set; }
            public PolicyApplied PolicyApplied { get; set; }
            public EffectiveConfig EffectiveConfig { get; set; }
            public string Error { get; set; }
        }

        internal class PolicyApplied
        {
            public string Scope { get; set; }
            public int PolicyId { get; set; }
            public int Version { get; set; }
        }

        internal class EffectiveConfig
        {
            public string Version { get; set; }
            public string ApiBaseUrl { get; set; }
            public EffectiveLogging Logging { get; set; }
            public EffectiveModules Modules { get; set; }
            public EffectiveStartup Startup { get; set; }     
            public EffectiveUpdates Updates { get; set; }
            public EffectiveBlocking Blocking { get; set; }
            public EffectiveTimers Timers { get; set; }
        }
        internal class EffectiveBlocking
        {
            public bool EnableDeviceLock { get; set; }
            public string LockMessage { get; set; }
            public bool AllowUnlockWithPin { get; set; }
            public string UnlockPin { get; set; }
        }
        internal class EffectiveLogging
        {
            public string GlobalLevel { get; set; }
            public string ClientOverrideLevel { get; set; }
            public bool EnableFileLogging { get; set; }
            public bool EnableDiscordLogging { get; set; }
            public string DiscordWebhookUrl { get; set; }
        }
        internal class EffectiveTimers
        {
            public int ActivityFlushIntervalSeconds { get; set; }
            public int HandshakeIntervalMinutes { get; set; }
            public int OfflineQueueRetrySeconds { get; set; }
        }
        internal class EffectiveModules
        {
            public bool EnableActivityTracking { get; set; }
            public bool EnableWindowTracking { get; set; }
            public bool EnableProcessTracking { get; set; }
            public bool EnableBlocking { get; set; }
            public bool EnableKeyboardHook { get; set; }
            public bool EnableMouseHook { get; set; }
            public bool EnableUpdateManager { get; set; }
            public bool EnableDebugWindow { get; set; }

            public bool CountCallsAsActive { get; set; }
            public double CallActiveMaxIdleSeconds { get; set; }

            public double ActivityIntervalSeconds { get; set; }
            public double ActivityInactivityThresholdSeconds { get; set; }
            public double WindowTrackingIntervalSeconds { get; set; }

            public bool EnableCallTracking { get; set; }
            public string[] CallProcessKeywords { get; set; }
            public string[] CallTitleKeywords { get; set; }
        }
        internal class EffectiveStartup
        {
            public bool EnableAutoStartup { get; set; }
            public bool StartMinimized { get; set; }
        }

        internal class EffectiveUpdates
        {
            public bool EnableAutoUpdate { get; set; }
            public int CheckIntervalMinutes { get; set; }
            public bool AutoDownload { get; set; }
            public bool AllowBetaVersions { get; set; }
        }


        internal class HandshakeResult
        {
            public bool IsSuccess { get; set; }
            public bool IsUnauthorized { get; set; }
            public bool IsNonJsonResponse { get; set; }
            public int? StatusCode { get; set; }
            public string BodyPreview { get; set; }
            public HandshakeResponse Response { get; set; }
        }

        internal class WindowEpisodePayload
        {
            public string DeviceId { get; set; }
            public string StartLocalTime { get; set; }
            public string EndLocalTime { get; set; }
            public double DurationSeconds { get; set; }
            public string ProcessName { get; set; }
            public string WindowTitle { get; set; }
            public bool IsCallApp { get; set; }
        }

        internal class ActivityDayPayload
        {
            public string DeviceId { get; set; }
            public string DayDate { get; set; }          // YYYY-MM-DD
            public int TzOffsetMinutes { get; set; }     // -300
            public double ActiveSeconds { get; set; }
            public double IdleSeconds { get; set; }
            public double CallSeconds { get; set; }
            public int SamplesCount { get; set; }
            public string FirstEventAt { get; set; }     // "yyyy-MM-dd HH:mm:ss" opcional
            public string LastEventAt { get; set; }      // "yyyy-MM-dd HH:mm:ss" opcional

            // NUEVOS CAMPOS
            public double WorkHoursActiveSeconds { get; set; }
            public double WorkHoursIdleSeconds { get; set; }
            public double LunchActiveSeconds { get; set; }
            public double LunchIdleSeconds { get; set; }
            public double AfterHoursActiveSeconds { get; set; }
            public double AfterHoursIdleSeconds { get; set; }
        }

    }


}
