using System;
using System.Drawing;
using System.IO;
using System.Windows.Forms;
using AZCKeeper_Cliente.Auth;
using AZCKeeper_Cliente.Blocking;
using AZCKeeper_Cliente.Config;
using AZCKeeper_Cliente.Logging;
using AZCKeeper_Cliente.Network;
using AZCKeeper_Cliente.Startup;
using AZCKeeper_Cliente.Tracking;
using AZCKeeper_Cliente.Update;

namespace AZCKeeper_Cliente.Core
{
    /// <summary>
    /// CoreService:
    /// Orquesta el ciclo de vida del cliente: configuración, autenticación, handshake,
    /// inicialización de módulos (tracking, blocking, updates) y timers de sincronización.
    ///
    /// Comunicación principal:
    /// - ConfigManager: lee/escribe config.json y aplica logging.
    /// - AuthManager: carga/guarda token y valida sesión.
    /// - ApiClient: handshake, login, envío de actividad y ventanas.
    /// - ActivityTracker/WindowTracker: producen eventos y snapshots locales.
    /// - StartupManager/UpdateManager/KeyBlocker: acciones del sistema.
    /// </summary>
    internal class CoreService
    {
        // --- Servicios base ---
        private ConfigManager _configManager; // config.json, logging, device id
        private AuthManager _authManager;     // token en memoria/disco
        private ApiClient _apiClient;         // HTTP hacia backend

        // --- Tracking ---
        private ActivityTracker _activityTracker; // actividad/idle por día
        private WindowTracker _windowTracker;     // procesos/ventanas y llamadas

        // --- Control/updates ---
        private KeyBlocker _keyBlocker;        // bloqueo por política
        private WebBlockingManager _webBlockingManager; // política local de dominios
        private UpdateManager _updateManager;  // actualización automática

        // --- UI ---
        private DebugWindowForm _debugWindow; // ventana de diagnóstico
        private LoginForm _loginForm;         // UI de login

        // --- Timers/flush ---
        private System.Timers.Timer _activityFlushTimer; // envío periódico activity-day
        private DateTime? _activityFirstEventLocal;      // primera muestra del día
        private int _activitySamplesCount;               // muestras enviadas
        private DateTime _lastFlushDayLocalDate = default; // corte de día local

        private System.Timers.Timer _handshakeTimer; // handshake periódico
        private DateTime _lastHandshakeTime = DateTime.MinValue; // último handshake ok
        private string _lastHandshakeStatus = "—"; // resultado del último intento de handshake (OK / HTTP xxx / error)
        private bool _hasSuccessfulHandshake = false; // flag para primer handshake exitoso

        // Buffer para batch de window-episodes (Fix #4 anti-DDoS).
        // Evita 1 POST por cada Alt+Tab. Se vacía cada WindowEpisodeBatchIntervalSeconds
        // o cuando llega al límite _windowEpisodeBatchMaxSize.
        private readonly System.Collections.Generic.List<ApiClient.WindowEpisodePayload> _windowEpisodeBuffer
            = new System.Collections.Generic.List<ApiClient.WindowEpisodePayload>();
        private readonly object _windowEpisodeBufferLock = new object();
        private System.Timers.Timer _windowEpisodeFlushTimer;
        private const int _windowEpisodeBatchMaxSize = 40; // backend acepta 50
        // Configurable vía política (timers.windowEpisodeBatchIntervalSeconds). Piso 15s.
        private int _windowEpisodeBatchIntervalSeconds = 30;

        /// <summary>
        /// Inicializa servicios base, carga config/token, crea ApiClient y módulos.
        /// También prepara UI de login si no hay token.
        /// </summary>
        public void Initialize()
        {
            try
            {
                LocalLogger.Info("CoreService.Initialize(): inicio.");

                _configManager = new ConfigManager();
                _configManager.LoadOrCreate();
                _configManager.EnsureDeviceId();

                _configManager.ApplyLoggingConfiguration();

                LocalLogger.Info($"Versión cliente: {_configManager.CurrentConfig.Version}");
                LocalLogger.Info($"DeviceId: {_configManager.CurrentConfig.DeviceId}");
                LocalLogger.Info($"ApiBaseUrl: {_configManager.CurrentConfig.ApiBaseUrl}");

                if (!string.IsNullOrWhiteSpace(_configManager.CurrentConfig.UserDisplayName))
                    LocalLogger.SetUserContext(_configManager.CurrentConfig.UserDisplayName);

                _authManager = new AuthManager();
                _authManager.TryLoadTokenFromDisk();

                _apiClient = new ApiClient(_configManager, _authManager);

                if (!_authManager.HasToken)
                {
                    if (!TrySilentReLogin())
                        PrepareLoginUi();
                }

                // Handshake se ejecuta en Start() → evita doble handshake en startup
                // que genera ráfaga de 40 requests simultáneos cuando todos los clientes
                // arrancan a la misma hora (thundering herd).
                InitializeModules();
                // Habilitar startup automático
                if (!StartupManager.IsEnabled())
                {
                    StartupManager.EnableStartup();
                }

                LocalLogger.Info("CoreService.Initialize(): OK.");
            }
            catch (Exception ex)
            {
                LocalLogger.Error(ex, "CoreService.Initialize(): error.");
                throw;
            }

            Microsoft.Win32.SystemEvents.SessionEnding += OnSessionEnding;
        }

        /// <summary>
        /// Handler de cierre de sesión Windows: dispara flush final.
        /// </summary>
        private void OnSessionEnding(object sender, Microsoft.Win32.SessionEndingEventArgs e)
        {
            LocalLogger.Warn($"CoreService: Windows cerrando sesión ({e.Reason}). Flush final...");
            // En contexto de shutdown, bloqueamos para garantizar envío
            FinalFlushBeforeShutdownAsync().Wait();
        }
        /// <summary>
        /// Inicia trackers, timers y UI; realiza handshake y retoma actividad del día.
        /// </summary>
        public void Start()
        {
            LocalLogger.Info("CoreService.Start(): iniciando.");

            try
            {
                PerformHandshake();
                // 1) Retomar ANTES de iniciar ActivityTracker (tu SeedDayTotals lo exige)
                TryResumeTodayActivityFromServer();

                // 2) Start trackers
                _activityTracker?.Start();
                _windowTracker?.Start();
                _updateManager?.Start();

                // 3) Flush periódico
                StartActivityFlushTimer();
                StartHandshakeTimer();
                StartWindowEpisodeFlushTimer();
                if (_debugWindow != null && !_debugWindow.IsDisposed)
                {
                    try { _debugWindow.Show(); }
                    catch (Exception ex) { LocalLogger.Error(ex, "CoreService.Start(): error DebugWindow."); }
                }

                if (_loginForm != null && !_loginForm.IsDisposed)
                {
                    try { _loginForm.Show(); }
                    catch (Exception ex) { LocalLogger.Error(ex, "CoreService.Start(): error LoginForm."); }
                }

                LocalLogger.Info("CoreService.Start(): OK.");
            }
            catch (Exception ex)
            {
                LocalLogger.Error(ex, "CoreService.Start(): error.");
            }
        }

        /// <summary>
        /// Detiene trackers/timers/UI y fuerza flush final.
        /// </summary>
        public void Stop()
        {
            Microsoft.Win32.SystemEvents.SessionEnding -= OnSessionEnding;
            LocalLogger.Info("CoreService.Stop(): deteniendo.");

            try
            {        
                // FLUSH FINAL ANTES DE DETENER TRACKERS
                // En contexto de shutdown, bloqueamos para garantizar envío
                FinalFlushBeforeShutdownAsync().Wait();
                StopActivityFlushTimer();
                StopWindowEpisodeFlushTimer();

                _handshakeTimer?.Stop();
                _handshakeTimer?.Dispose();
                // _lockStatusTimer eliminado: bloqueo va por handshake
                _webBlockingManager?.Shutdown();
                _activityTracker?.Stop();
                _windowTracker?.Stop();
                _updateManager?.Stop();

                if (_debugWindow != null && !_debugWindow.IsDisposed)
                {
                    try
                    {
                        if (_debugWindow.IsHandleCreated) _debugWindow.BeginInvoke(new Action(() => _debugWindow.Close()));
                        else _debugWindow.Close();
                    }
                    catch { }
                }

                if (_loginForm != null && !_loginForm.IsDisposed)
                {
                    try
                    {
                        if (_loginForm.IsHandleCreated) _loginForm.BeginInvoke(new Action(() => _loginForm.Close()));
                        else _loginForm.Close();
                    }
                    catch { }
                }

                LocalLogger.Info("CoreService.Stop(): OK.");
            }
            catch (Exception ex)
            {
                LocalLogger.Error(ex, "CoreService.Stop(): error.");
            }
        }

        /// <summary>
        /// Prepara formulario de login y flujo de autenticación.
        /// Comunica con ApiClient.SendLoginAsync y actualiza AuthManager/Config.
        /// </summary>
        private void PrepareLoginUi()
        {
            _loginForm = new AZCKeeper_Cliente.Auth.LoginForm();

            _loginForm.OnLoginSubmitted += async (user, pass) =>
            {
                try
                {
                    _loginForm.SetBusy(true, "Validando credenciales...");

                    var login = await _apiClient.SendLoginAsync(new ApiClient.LoginRequest
                    {
                        Username = user,
                        Password = pass,
                        DeviceId = _configManager.CurrentConfig.DeviceId,
                        DeviceName = Environment.MachineName
                    }).ConfigureAwait(false);

                    if (login == null || !login.IsSuccess || login.Response == null || string.IsNullOrWhiteSpace(login.Response.Token))
                    {
                        _loginForm.SetBusy(false, $"Login falló: {login?.Error ?? login?.Response?.Error ?? "sin detalle"}");
                        return;
                    }

                    _authManager.UpdateAuthToken(login.Response.Token);
                    _configManager.CurrentConfig.ApiAuthToken = login.Response.Token;

                    // Guardar credenciales para auto-re-login futuro
                    _authManager.SaveCredentials(user, pass);

                    if (!string.IsNullOrWhiteSpace(login.Response.DisplayName))
                    {
                        _configManager.CurrentConfig.UserDisplayName = login.Response.DisplayName;
                        LocalLogger.SetUserContext(login.Response.DisplayName);
                    }

                    _configManager.Save();

                    _loginForm.SetBusy(true, "Sincronizando configuración...");
                    PerformHandshake();

                    // Seed del día (antes de activity start, si el cliente ya estaba corriendo sin tracker)
                    TryResumeTodayActivityFromServer();

                    // Si el tracker ya estaba iniciado, el SeedDayTotals se aplica "en caliente" (por el ActivityTracker actualizado).
                    // Si el tracker no se ha iniciado aún, quedará listo para Start().
                    StartActivityFlushTimer();

                    _loginForm.SetBusy(false, "OK");
                    _loginForm.BeginInvoke(new Action(() => _loginForm.Close()));
                }
                catch (Exception ex)
                {
                    _loginForm.SetBusy(false, $"Error en login: {ex.Message}");
                }
            };
        }

        /// <summary>
        /// Intenta recuperar autenticación silenciosamente.
        /// Orden: 1) Re-enroll por device_guid, 2) Credenciales guardadas (DPAPI).
        /// Retorna true si obtuvo un nuevo token válido.
        /// </summary>
        private bool TrySilentReLogin()
        {
            // 1) Intentar re-enroll (solo necesita device_guid — sin credenciales)
            if (TryReEnroll())
                return true;

            // 2) Intentar con credenciales guardadas
            try
            {
                var creds = _authManager.TryLoadCredentials();
                if (creds == null)
                {
                    LocalLogger.Info("CoreService.TrySilentReLogin(): sin credenciales guardadas.");
                    return false;
                }

                LocalLogger.Info("CoreService.TrySilentReLogin(): credenciales encontradas. Intentando login silencioso...");

                var login = _apiClient.SendLoginAsync(new ApiClient.LoginRequest
                {
                    Username = creds.Value.Username,
                    Password = creds.Value.Password,
                    DeviceId = _configManager.CurrentConfig.DeviceId,
                    DeviceName = Environment.MachineName
                }).GetAwaiter().GetResult();

                if (login == null || !login.IsSuccess || login.Response == null || string.IsNullOrWhiteSpace(login.Response.Token))
                {
                    LocalLogger.Warn($"CoreService.TrySilentReLogin(): login falló. Error={login?.Error ?? login?.Response?.Error ?? "sin detalle"}");
                    return false;
                }

                _authManager.UpdateAuthToken(login.Response.Token);
                _configManager.CurrentConfig.ApiAuthToken = login.Response.Token;
                _authManager.SaveCredentials(creds.Value.Username, creds.Value.Password);

                if (!string.IsNullOrWhiteSpace(login.Response.DisplayName))
                {
                    _configManager.CurrentConfig.UserDisplayName = login.Response.DisplayName;
                    LocalLogger.SetUserContext(login.Response.DisplayName);
                }

                _configManager.Save();
                LocalLogger.Info("CoreService.TrySilentReLogin(): token restaurado vía credenciales.");
                return true;
            }
            catch (Exception ex)
            {
                LocalLogger.Error(ex, "CoreService.TrySilentReLogin(): error durante auto-re-login.");
                return false;
            }
        }

        /// <summary>
        /// Intenta re-enrollment usando solo device_guid (sin credenciales).
        /// El servidor reconoce el dispositivo ya registrado y emite un nuevo token.
        /// </summary>
        private bool TryReEnroll()
        {
            try
            {
                string deviceGuid = _configManager.CurrentConfig.DeviceId;
                if (string.IsNullOrWhiteSpace(deviceGuid))
                {
                    LocalLogger.Info("CoreService.TryReEnroll(): sin DeviceId.");
                    return false;
                }

                LocalLogger.Info("CoreService.TryReEnroll(): intentando re-enroll por device_guid...");

                var result = _apiClient.SendReEnrollAsync(deviceGuid, Environment.MachineName)
                    .GetAwaiter().GetResult();

                if (result == null || !result.IsSuccess || result.Response == null || string.IsNullOrWhiteSpace(result.Response.Token))
                {
                    LocalLogger.Warn($"CoreService.TryReEnroll(): falló. Error={result?.Error ?? "sin detalle"}");
                    return false;
                }

                _authManager.UpdateAuthToken(result.Response.Token);
                _configManager.CurrentConfig.ApiAuthToken = result.Response.Token;

                if (!string.IsNullOrWhiteSpace(result.Response.DisplayName))
                {
                    _configManager.CurrentConfig.UserDisplayName = result.Response.DisplayName;
                    LocalLogger.SetUserContext(result.Response.DisplayName);
                }

                _configManager.Save();
                LocalLogger.Info("CoreService.TryReEnroll(): token restaurado vía re-enroll.");
                return true;
            }
            catch (Exception ex)
            {
                LocalLogger.Error(ex, "CoreService.TryReEnroll(): error.");
                return false;
            }
        }

        /// <summary>
        /// Inicia handshake periódico para refrescar config y políticas.
        /// </summary>
        private void StartHandshakeTimer()
        {
            try
            {
                if (_handshakeTimer != null) return;

                int intervalSeconds = _configManager.CurrentConfig.Timers?.HandshakeIntervalSeconds ?? 300;
                intervalSeconds = Math.Max(120, intervalSeconds);

                // Jitter: retraso aleatorio de 0 a intervalSeconds antes del primer tick.
                // Evita que 500 clientes que arrancan al mismo tiempo (ej: 8:00 AM)
                // disparen todos sus handshakes en el mismo segundo → thundering herd.
                // Ejemplo con 60s: clientes se distribuyen en una ventana de 60s
                // → máximo ~9 req/s en lugar de 500 simultáneos.
                int jitterMs = new Random().Next(0, intervalSeconds * 1000);

                _handshakeTimer = new System.Timers.Timer(intervalSeconds * 1000);
                _handshakeTimer.AutoReset = true;
                _handshakeTimer.Elapsed += (s, e) =>
                {
                    try
                    {
                        LocalLogger.Info("CoreService: ejecutando handshake periódico...");
                        PerformHandshake();
                        _lastHandshakeTime = DateTime.Now;
                    }
                    catch (Exception ex)
                    {
                        LocalLogger.Error(ex, "CoreService: error en handshake periódico.");
                    }
                };

                // Primer tick retrasado por jitter, luego AutoReset se encarga
                System.Threading.Tasks.Task.Delay(jitterMs).ContinueWith(_ =>
                {
                    try
                    {
                        PerformHandshake();
                        _lastHandshakeTime = DateTime.Now;
                    }
                    catch { }
                    _handshakeTimer?.Start();
                });

                LocalLogger.Info($"CoreService: HandshakeTimer iniciado (cada {intervalSeconds}s, jitter={jitterMs}ms).");
            }
            catch (Exception ex)
            {
                LocalLogger.Error(ex, "CoreService: error al iniciar HandshakeTimer.");
            }
        }
        /// <summary>
        /// Ejecuta handshake con backend y aplica effectiveConfig local.
        /// Puede habilitar/deshabilitar módulos y políticas en caliente.
        /// </summary>
        private void PerformHandshake()
        {
            try
            {
                LocalLogger.Info("CoreService.PerformHandshake(): iniciando.");

                if (!_authManager.HasToken)
                {
                    LocalLogger.Warn("CoreService.PerformHandshake(): sin token. Se omitirá handshake hasta login.");
                    return;
                }

                var request = new ApiClient.HandshakeRequest
                {
                    DeviceId = _configManager.CurrentConfig.DeviceId,
                    Version = _configManager.CurrentConfig.Version,
                    DeviceName = Environment.MachineName
                };

                var hs = _apiClient.SendHandshakeAsync(request)
                    .GetAwaiter()
                    .GetResult();

                if (hs == null)
                {
                    LocalLogger.Warn("CoreService.PerformHandshake(): resultado null.");
                    _lastHandshakeStatus = "error: sin respuesta";
                    return;
                }

                if (hs.IsUnauthorized)
                {
                    LocalLogger.Warn("CoreService.PerformHandshake(): 401/403. Intentando auto-re-login...");
                    _lastHandshakeStatus = "HTTP " + (hs.StatusCode?.ToString() ?? "401/403");
                    _authManager.ClearToken(deleteFromDisk: true);

                    if (TrySilentReLogin())
                    {
                        LocalLogger.Info("CoreService.PerformHandshake(): auto-re-login exitoso. Reintentando handshake...");
                        // Reintentar handshake con el nuevo token (recursión controlada: solo 1 nivel)
                        return;
                    }

                    // Auto-re-login falló — mostrar formulario
                    LocalLogger.Warn("CoreService.PerformHandshake(): auto-re-login falló. Se solicitará login manual.");
                    _authManager.ClearCredentials();
                    StopActivityFlushTimer();

                    if (_loginForm == null || _loginForm.IsDisposed)
                        PrepareLoginUi();

                    return;
                }

                if (!hs.IsSuccess || hs.Response == null || hs.Response.EffectiveConfig == null)
                {
                    LocalLogger.Warn($"CoreService.PerformHandshake(): no aplicado. Status={hs.StatusCode?.ToString() ?? "null"}, NonJson={hs.IsNonJsonResponse}, BodyPreview={hs.BodyPreview}");
                    _lastHandshakeStatus = hs.IsNonJsonResponse
                        ? "error: respuesta no-JSON"
                        : "HTTP " + (hs.StatusCode?.ToString() ?? "error");
                    return;
                }

                if (!string.IsNullOrWhiteSpace(hs.Response.ServerTimeUtc))
                {
                    TimeSync.UpdateFromServer(hs.Response.ServerTimeUtc);
                }

                var effective = hs.Response.EffectiveConfig;

                if (!string.IsNullOrWhiteSpace(effective.ApiBaseUrl))
                    _configManager.CurrentConfig.ApiBaseUrl = effective.ApiBaseUrl;
                if (effective.Startup != null)
                {
                    var startup = _configManager.CurrentConfig.Startup ?? new ConfigManager.StartupConfig();

                    startup.EnableAutoStartup = effective.Startup.EnableAutoStartup;
                    startup.StartMinimized = effective.Startup.StartMinimized;

                    _configManager.CurrentConfig.Startup = startup;

                    // Aplicar cambio inmediatamente
                    if (startup.EnableAutoStartup)
                        Startup.StartupManager.EnableStartup();
                    else
                        Startup.StartupManager.DisableStartup();
                }
                // -------------------- Blocking --------------------
                if (effective.Blocking != null)
                {
                    var blocking = _configManager.CurrentConfig.Blocking ?? new ConfigManager.BlockingConfig();

                    bool wasLocked = blocking.EnableDeviceLock;
                    blocking.EnableDeviceLock = effective.Blocking.EnableDeviceLock;
                    blocking.LockMessage = effective.Blocking.LockMessage ?? blocking.LockMessage;
                    blocking.AllowUnlockWithPin = effective.Blocking.AllowUnlockWithPin;
                    blocking.UnlockPin = effective.Blocking.UnlockPin ?? null;

                    LocalLogger.Info($"CoreService: Blocking recibido. EnableDeviceLock={blocking.EnableDeviceLock}, UnlockPin='{(blocking.UnlockPin ?? "NULL")}'");

                    _configManager.CurrentConfig.Blocking = blocking;
                    _configManager.Save();
                    LocalLogger.Info($"CoreService: Blocking guardado en config.json. UnlockPin='" +
                        $"{(blocking.UnlockPin ?? "NULL")}'");

                    // Aplicar bloqueo: cambio de false→true
                    if (!wasLocked && blocking.EnableDeviceLock && _keyBlocker != null)
                    {
                        LocalLogger.Warn($"CoreService: BLOQUEANDO dispositivo. PIN='{(blocking.UnlockPin ?? "NULL")}'");
                        _keyBlocker.ActivateLock(blocking.LockMessage, blocking.AllowUnlockWithPin, blocking.UnlockPin);
                    }
                    // Aplicar desbloqueo: cambio de true→false
                    else if (wasLocked && !blocking.EnableDeviceLock && _keyBlocker != null)
                    {
                        LocalLogger.Info("CoreService: DESBLOQUEANDO dispositivo por política remota...");
                        _keyBlocker.DeactivateLock();
                    }
                    // Si sigue bloqueado: verificar que esté REALMENTE activo (caso de reinicio)
                    else if (blocking.EnableDeviceLock && _keyBlocker != null)
                    {
                        if (!_keyBlocker.IsLocked())
                        {
                            // El bloqueo debería estar activo pero no lo está (caso reinicio)
                            LocalLogger.Warn($"CoreService: REACTIVANDO bloqueo post-reinicio. PIN='{(blocking.UnlockPin ?? "NULL")}'");
                            _keyBlocker.ActivateLock(blocking.LockMessage, blocking.AllowUnlockWithPin, blocking.UnlockPin);
                        }
                        else
                        {
                            LocalLogger.Info("CoreService: Dispositivo ya está bloqueado, manteniendo estado.");
                        }
                    }
                }

                if (effective.WebBlocking != null)
                {
                    var webBlocking = _configManager.CurrentConfig.WebBlocking ?? new ConfigManager.WebBlockingConfig();
                    webBlocking.Enabled = effective.WebBlocking.Enabled;
                    webBlocking.SyncIntervalSeconds = effective.WebBlocking.SyncIntervalSeconds > 0
                        ? Math.Max(300, effective.WebBlocking.SyncIntervalSeconds)
                        : 600;
                    webBlocking.Domains = effective.WebBlocking.Domains ?? Array.Empty<string>();
                    webBlocking.PolicyVersion = hs.Response.PolicyApplied?.Version ?? 0;
                    webBlocking.LastUpdatedUtc = DateTime.UtcNow.ToString("O");
                    _configManager.CurrentConfig.WebBlocking = webBlocking;

                    _webBlockingManager?.ApplyRemotePolicy(webBlocking, webBlocking.PolicyVersion, _configManager.CurrentConfig.ApiBaseUrl);
                }
                else
                {
                    var webBlocking = _configManager.CurrentConfig.WebBlocking ?? new ConfigManager.WebBlockingConfig();
                    webBlocking.Enabled = false;
                    webBlocking.SyncIntervalSeconds = 600;
                    webBlocking.Domains = Array.Empty<string>();
                    webBlocking.PolicyVersion = hs.Response.PolicyApplied?.Version ?? 0;
                    webBlocking.LastUpdatedUtc = DateTime.UtcNow.ToString("O");
                    _configManager.CurrentConfig.WebBlocking = webBlocking;

                    _webBlockingManager?.ApplyRemotePolicy(webBlocking, webBlocking.PolicyVersion, _configManager.CurrentConfig.ApiBaseUrl);
                }

                _configManager.Save();

                if (effective.Updates != null)
                {
                    var updates = _configManager.CurrentConfig.Updates ?? new ConfigManager.UpdatesConfig();

                    updates.EnableAutoUpdate = effective.Updates.EnableAutoUpdate;
                    updates.CheckIntervalMinutes = effective.Updates.CheckIntervalMinutes;
                    updates.AutoDownload = effective.Updates.AutoDownload;
                    updates.AllowBetaVersions = effective.Updates.AllowBetaVersions;

                    _configManager.CurrentConfig.Updates = updates;

                    // Reiniciar UpdateManager si cambió configuración
                    if (_updateManager != null)
                    {
                        _updateManager.Stop();
                        if (updates.EnableAutoUpdate)
                        {
                            _updateManager.UpdateInterval(updates.CheckIntervalMinutes);
                            _updateManager.Start();
                        }
                    }
                }

                if (effective.Logging != null)
                {
                    var logging = _configManager.CurrentConfig.Logging ?? new ConfigManager.LoggingConfig();

                    if (!string.IsNullOrWhiteSpace(effective.Logging.GlobalLevel))
                        logging.GlobalLevel = effective.Logging.GlobalLevel;

                    if (!string.IsNullOrWhiteSpace(effective.Logging.ClientOverrideLevel))
                        logging.ClientOverrideLevel = effective.Logging.ClientOverrideLevel;

                    logging.EnableFileLogging = effective.Logging.EnableFileLogging;
                    logging.EnableDiscordLogging = effective.Logging.EnableDiscordLogging;
                    logging.DiscordWebhookUrl = effective.Logging.DiscordWebhookUrl;

                    _configManager.CurrentConfig.Logging = logging;
                }

                if (effective.Modules != null)
                {
                    var modules = _configManager.CurrentConfig.Modules ?? new ConfigManager.ModulesConfig();

                    modules.EnableActivityTracking = effective.Modules.EnableActivityTracking;
                    modules.EnableWindowTracking = effective.Modules.EnableWindowTracking;
                    modules.EnableProcessTracking = effective.Modules.EnableProcessTracking;
                    modules.EnableBlocking = effective.Modules.EnableBlocking;
                    modules.EnableUpdateManager = effective.Modules.EnableUpdateManager;
                    modules.EnableDebugWindow = effective.Modules.EnableDebugWindow;

                    modules.CountCallsAsActive = effective.Modules.CountCallsAsActive;
                    if (effective.Modules.CallActiveMaxIdleSeconds > 0)
                        modules.CallActiveMaxIdleSeconds = effective.Modules.CallActiveMaxIdleSeconds;

                    if (effective.Modules.ActivityIntervalSeconds > 0)
                        modules.ActivityIntervalSeconds = effective.Modules.ActivityIntervalSeconds;

                    if (effective.Modules.ActivityInactivityThresholdSeconds > 0)
                        modules.ActivityInactivityThresholdSeconds = effective.Modules.ActivityInactivityThresholdSeconds;

                    if (effective.Modules.WindowTrackingIntervalSeconds > 0)
                        modules.WindowTrackingIntervalSeconds = effective.Modules.WindowTrackingIntervalSeconds;

                    modules.EnableCallTracking = effective.Modules.EnableCallTracking;
                    modules.CallProcessKeywords = effective.Modules.CallProcessKeywords ?? modules.CallProcessKeywords;
                    modules.CallTitleKeywords = effective.Modules.CallTitleKeywords ?? modules.CallTitleKeywords;

                    _configManager.CurrentConfig.Modules = modules;
                }
                // -------------------- Timers --------------------
                if (effective.Timers != null)
                {
                    var timers = _configManager.CurrentConfig.Timers ?? new ConfigManager.TimersConfig();

                    // Mínimo 10s para flush de actividad (evitar saturar servidor)
                    timers.ActivityFlushIntervalSeconds = effective.Timers.ActivityFlushIntervalSeconds > 0
                        ? Math.Max(10, effective.Timers.ActivityFlushIntervalSeconds)
                        : 10;

                    timers.HandshakeIntervalMinutes = effective.Timers.HandshakeIntervalMinutes > 0
                        ? effective.Timers.HandshakeIntervalMinutes
                        : 5;

                    timers.OfflineQueueRetrySeconds = effective.Timers.OfflineQueueRetrySeconds > 0
                        ? effective.Timers.OfflineQueueRetrySeconds
                        : 30;

                    timers.WindowEpisodeBatchIntervalSeconds = effective.Timers.WindowEpisodeBatchIntervalSeconds > 0
                        ? Math.Max(15, effective.Timers.WindowEpisodeBatchIntervalSeconds)
                        : 30;

                    _configManager.CurrentConfig.Timers = timers;

                    // Aplicar cambios inmediatamente
                    ApplyTimerChanges(timers);
                }

                _configManager.Save();
                _configManager.ApplyLoggingConfiguration();

                // Si es el primer handshake exitoso después del inicio, intentar resumir actividad
                if (!_hasSuccessfulHandshake)
                {
                    _hasSuccessfulHandshake = true;
                    LocalLogger.Info("CoreService.PerformHandshake(): primer handshake exitoso. Intentando resumir actividad del día...");
                    TryResumeTodayActivityFromServer();
                }

                // Aplicar horario laboral desde keeper_work_schedules si el servidor lo retornó
                // Reemplaza los valores hardcodeados en WorkSchedule.cs (07:00, 19:00, 12:00, 13:00)
                if (hs.Response.WorkSchedule != null && _activityTracker != null)
                {
                    ApplyWorkSchedule(hs.Response.WorkSchedule);
                }

                // Refrescar UserDisplayName desde el servidor
                if (!string.IsNullOrWhiteSpace(hs.Response.DisplayName))
                {
                    _configManager.CurrentConfig.UserDisplayName = hs.Response.DisplayName;
                    _configManager.Save();
                    LocalLogger.SetUserContext(hs.Response.DisplayName);
                }

                LocalLogger.Info("CoreService.PerformHandshake(): configuración aplicada desde effectiveConfig.");
                _lastHandshakeStatus = "OK";

                // Va pegado al handshake exitoso a propósito: no abre conexión propia ni
                // agrega un timer. Si la red está mal, no llegamos hasta aquí y los logs
                // esperan en el buffer.
                ReportPendingLogs();
            }
            catch (Exception ex)
            {
                LocalLogger.Error(ex, "CoreService.PerformHandshake(): error. Se continúa con config local.");
                _lastHandshakeStatus = "error: " + ex.Message;
            }
        }

        /// <summary>
        /// Envía a /client/logs los Warn/Error y eventos de update acumulados.
        /// Lo que no se confirme vuelve al buffer para el próximo handshake.
        /// </summary>
        private void ReportPendingLogs()
        {
            try
            {
                string deviceGuid = _configManager.CurrentConfig.DeviceId;
                if (string.IsNullOrWhiteSpace(deviceGuid)) return;

                var batch = LocalLogger.DrainForReport(50);
                if (batch.Count == 0) return;

                bool enviado = _apiClient.SendClientLogsAsync(deviceGuid, batch)
                                         .GetAwaiter().GetResult();

                if (!enviado)
                    LocalLogger.RequeueForReport(batch);
            }
            catch
            {
                // Nunca romper el handshake por el reporte de logs.
            }
        }

        /// <summary>
        /// Arma una foto read-only del estado actual de los subsistemas para la ventana Debug.
        /// </summary>
        internal DebugSnapshot BuildDebugSnapshot()
        {
            var cfg = _configManager?.CurrentConfig;
            var s = new DebugSnapshot
            {
                RunningVersion = cfg?.Version ?? "—",
                AvailableVersion = _updateManager?.LastAvailableVersion ?? "—",
                MinimumVersion = _updateManager?.LastMinimumVersion ?? "—",
                UpdateStatus = string.IsNullOrEmpty(_updateManager?.LastUpdateError) ? "OK" : _updateManager.LastUpdateError,
                ApiBaseUrl = cfg?.ApiBaseUrl ?? "—",
                HandshakeStatus = _lastHandshakeStatus,
                BackoffStatus = _apiClient != null && _apiClient.IsInBackoff
                    ? "activo hasta " + _apiClient.BackoffUntilUtc.ToLocalTime().ToString("HH:mm:ss")
                    : "no",
                QueuePending = _apiClient?.PendingQueueCount ?? -1,
                RecentIssues = AZCKeeper_Cliente.Logging.LocalLogger.GetRecentIssues(),
                WebBlockEnabled = _webBlockingManager?.Enabled ?? false,
                WebBlockDomains = _webBlockingManager?.DomainCount ?? 0,
                PacActive = _webBlockingManager?.PacActive ?? false,
                DeviceId = cfg?.DeviceId ?? "—",
                UserName = cfg?.UserDisplayName ?? "—",
                HasToken = _authManager?.HasToken ?? false,
            };

            if (_lastHandshakeTime == DateTime.MinValue) s.LastHandshake = "Nunca";
            else s.LastHandshake = $"{_lastHandshakeTime:HH:mm:ss} (hace {(DateTime.Now - _lastHandshakeTime).TotalSeconds:F0}s)";
            return s;
        }

        /// <summary>
        /// Aplica cambios de intervalos (flush, handshake, offline queue).
        /// </summary>
        private void ApplyTimerChanges(ConfigManager.TimersConfig timers)
        {
            try
            {
                // Reiniciar ActivityFlush con nuevo intervalo
                if (_activityFlushTimer != null)
                {
                    _activityFlushTimer.Stop();
                    _activityFlushTimer.Interval = timers.ActivityFlushIntervalSeconds * 1000;
                    _activityFlushTimer.Start();
                    LocalLogger.Info($"CoreService: ActivityFlush actualizado a {timers.ActivityFlushIntervalSeconds}s");
                }

                // Reiniciar Handshake con nuevo intervalo (en segundos)
                // Mínimo absoluto: 120s (2 min). Con 40+ clientes, intervalos menores
                // generan >240 queries/min solo de handshake y tumban Apache/MySQL.
                if (_handshakeTimer != null)
                {
                    int hs = timers.HandshakeIntervalSeconds > 0
                        ? Math.Max(120, timers.HandshakeIntervalSeconds)
                        : Math.Max(120, timers.HandshakeIntervalMinutes * 60);
                    _handshakeTimer.Stop();
                    _handshakeTimer.Interval = hs * 1000;
                    _handshakeTimer.Start();
                    LocalLogger.Info($"CoreService: Handshake actualizado a {hs}s");
                }

                // Actualizar OfflineQueue retry
                if (_apiClient != null)
                {
                    _apiClient.UpdateRetryInterval(timers.OfflineQueueRetrySeconds);
                    LocalLogger.Info($"CoreService: OfflineQueue retry actualizado a {timers.OfflineQueueRetrySeconds}s");
                }

                // Reiniciar WindowEpisodeFlush con nuevo intervalo (piso 15s)
                if (_windowEpisodeFlushTimer != null && timers.WindowEpisodeBatchIntervalSeconds > 0)
                {
                    int wi = Math.Max(15, timers.WindowEpisodeBatchIntervalSeconds);
                    _windowEpisodeBatchIntervalSeconds = wi;
                    _windowEpisodeFlushTimer.Stop();
                    _windowEpisodeFlushTimer.Interval = wi * 1000;
                    _windowEpisodeFlushTimer.Start();
                    LocalLogger.Info($"CoreService: WindowEpisodeFlush actualizado a {wi}s");
                }
            }
            catch (Exception ex)
            {
                LocalLogger.Error(ex, "CoreService.ApplyTimerChanges(): error.");
            }
        }
        /// <summary>
        /// Inicializa módulos según configuración (tracking, hooks, blocking, updates).
        /// Conecta callbacks para enviar eventos a ApiClient.
        /// </summary>
        private void InitializeModules()
        {
            var modulesConfig = _configManager.CurrentConfig.Modules;
            var startupConfig = _configManager.CurrentConfig.Startup;
            var updatesConfig = _configManager.CurrentConfig.Updates;

            if (modulesConfig == null)
            {
                LocalLogger.Warn("CoreService.InitializeModules(): ModulesConfig null.");
                return;
            }

            // -------------------- ActivityTracker --------------------
            if (modulesConfig.EnableActivityTracking)
            {
                double activityInterval = modulesConfig.ActivityIntervalSeconds > 0 ? modulesConfig.ActivityIntervalSeconds : 1.0;
                double inactivityThreshold = modulesConfig.ActivityInactivityThresholdSeconds > 0 ? modulesConfig.ActivityInactivityThresholdSeconds : 15.0;

                _activityTracker = new ActivityTracker(activityInterval, inactivityThreshold);

                _activityTracker.OnDayClosed = (day, activeSeconds, inactiveSeconds) =>
                {
                    try
                    {
                        LocalLogger.Info($"CoreService: cierre de día {day:yyyy-MM-dd}, activo={activeSeconds:F3}s, inactivo={inactiveSeconds:F3}s.");

                        int tzOffsetMinutes = (int)TimeZoneInfo.Local.GetUtcOffset(DateTime.UtcNow).TotalMinutes;

                        var payload = new ApiClient.ActivityDayPayload
                        {
                            DeviceId = _configManager.CurrentConfig.DeviceId,
                            DayDate = day.ToString("yyyy-MM-dd"),
                            TzOffsetMinutes = tzOffsetMinutes,
                            ActiveSeconds = activeSeconds,
                            IdleSeconds = inactiveSeconds,
                            CallSeconds = _windowTracker?.CallSessionSeconds ?? 0,
                            SamplesCount = _activitySamplesCount,
                            FirstEventAt = _activityFirstEventLocal?.ToString("yyyy-MM-dd HH:mm:ss"),
                            // Categorías de tiempo
                            WorkHoursActiveSeconds = _activityTracker.CurrentDayWorkActiveSeconds,
                            WorkHoursIdleSeconds = _activityTracker.CurrentDayWorkIdleSeconds,
                            LunchActiveSeconds = _activityTracker.CurrentDayLunchActiveSeconds,
                            LunchIdleSeconds = _activityTracker.CurrentDayLunchIdleSeconds,
                            AfterHoursActiveSeconds = _activityTracker.CurrentDayAfterHoursActiveSeconds,
                            AfterHoursIdleSeconds = _activityTracker.CurrentDayAfterHoursIdleSeconds,
                            IsWorkday = day.DayOfWeek != DayOfWeek.Saturday && day.DayOfWeek != DayOfWeek.Sunday
                        };

                        _ = _apiClient.SendActivityDayAsync(payload);
                    }
                    catch (Exception ex)
                    {
                        LocalLogger.Error(ex, "CoreService.OnDayClosed(): error al enviar activityday.");
                    }
                };
            }

            // -------------------- WindowTracker --------------------
            if (modulesConfig.EnableWindowTracking)
            {
                double windowInterval = modulesConfig.WindowTrackingIntervalSeconds > 0 ? modulesConfig.WindowTrackingIntervalSeconds : 2.0;

                bool enableCallTracking = modulesConfig.EnableCallTracking;
                var callProcKeywords = modulesConfig.CallProcessKeywords ?? Array.Empty<string>();
                var callTitleKeywords = modulesConfig.CallTitleKeywords ?? Array.Empty<string>();

                _windowTracker = new WindowTracker(windowInterval, enableCallTracking, callProcKeywords, callTitleKeywords);

                _windowTracker.OnEpisodeClosed = (episode) =>
                {
                    try
                    {
                        // Descarta episodios <2s: son ruido (Alt+Tab rápido, popups transitorios,
                        // cambios de foreground forzados por notificaciones) que no aportan valor
                        // al monitoreo y representan ~10% del tráfico histórico.
                        // Umbral elegido tras auditoría: episodios reales de trabajo duran >2s.
                        if (episode.DurationSeconds < 2.0)
                        {
                            return;
                        }

                        var payload = new ApiClient.WindowEpisodePayload
                        {
                            DeviceId = _configManager.CurrentConfig.DeviceId,
                            StartLocalTime = episode.StartLocalTime.ToString("yyyy-MM-dd HH:mm:ss"),
                            EndLocalTime = episode.EndLocalTime.ToString("yyyy-MM-dd HH:mm:ss"),
                            DurationSeconds = episode.DurationSeconds,
                            ProcessName = episode.ProcessName,
                            WindowTitle = episode.WindowTitle,
                            IsCallApp = episode.IsCallApp
                        };

                        // Encolar en buffer local. Se flushea por timer o por tamaño.
                        EnqueueWindowEpisode(payload);
                    }
                    catch (Exception ex)
                    {
                        LocalLogger.Error(ex, "CoreService: error al encolar window-episode.");
                    }
                };

                _windowTracker.OnWindowSnapshot = (timestamp, processName, windowTitle) =>
                {
                    // intencionalmente vacío para evitar ruido
                };
            }

            // -------------------- Override de actividad por llamada --------------------
            if (_activityTracker != null)
            {
                bool countCallsAsActive = modulesConfig.CountCallsAsActive;

                _activityTracker.ActivityOverridePredicate = () =>
                    countCallsAsActive &&
                    _windowTracker != null &&
                    _windowTracker.CallTrackingEnabled &&
                    _windowTracker.IsInCallNow;

                double maxIdle = modulesConfig.CallActiveMaxIdleSeconds > 0 ? modulesConfig.CallActiveMaxIdleSeconds : 1800.0;
                _activityTracker.ActivityOverrideMaxIdleSeconds = maxIdle;
            }

            // -------------------- Startup --------------------
            if (startupConfig != null && startupConfig.EnableAutoStartup)
            {
                if (!Startup.StartupManager.IsEnabled())
                    Startup.StartupManager.EnableStartup();
            }

            // -------------------- Hooks / Blocking / Debug --------------------
            // KeyboardHook y MouseHook eliminados - no son necesarios (ActivityTracker usa GetLastInputInfo)
            if (modulesConfig.EnableBlocking) _keyBlocker = new KeyBlocker(_apiClient);
            _webBlockingManager = new WebBlockingManager();
            _webBlockingManager.Initialize(
                _configManager.CurrentConfig.WebBlocking ?? new ConfigManager.WebBlockingConfig(),
                _configManager.CurrentConfig.ApiBaseUrl);

            // -------------------- UpdateManager --------------------
            if (modulesConfig.EnableUpdateManager)
            {
                int intervalMinutes = updatesConfig?.CheckIntervalMinutes ?? 60;
                _updateManager = new UpdateManager(_configManager, _apiClient, intervalMinutes);
                LocalLogger.Info("CoreService: UpdateManager creado.");
            }

            if (modulesConfig.EnableDebugWindow)
            {
                if (_activityTracker == null)
                {
                    LocalLogger.Warn("CoreService.InitializeModules(): EnableDebugWindow activo pero ActivityTracker deshabilitado.");
                }
                else
                {
                    _debugWindow = new DebugWindowForm(_activityTracker, _windowTracker, BuildDebugSnapshot);
                }
            }
        }
        // CheckDeviceLockStatus() eliminado — el bloqueo se aplica dentro de PerformHandshake()
        // a través del effectiveConfig.blocking que ya retorna el backend.
        // El handshake se ejecuta cada 5 min (configurable), que es suficiente para
        // reflejar cambios de bloqueo sin necesidad de un timer separado de 30s.

        /// <summary>
        /// Si existe registro del día en backend, rehidrata contadores locales.
        /// </summary>
        private async void TryResumeTodayActivityFromServer()
        {
            try
            {
                if (_activityTracker == null) return;
                if (_apiClient == null) return;

                if (_authManager == null || !_authManager.HasToken)
                {
                    LocalLogger.Info("CoreService: no hay token -> no se retoma actividad del día.");
                    return;
                }

                string today = DateTime.Now.ToString("yyyy-MM-dd");
                string deviceId = _configManager.CurrentConfig.DeviceId;

                var res = await _apiClient.GetActivityDayAsync(deviceId, today).ConfigureAwait(false);
                if (res == null || !res.IsSuccess || res.Response == null)
                {
                    LocalLogger.Warn($"CoreService: activity-day/get no exitoso. Err={res?.Error} Preview={res?.BodyPreview}");
                    return;
                }

                if (!res.Response.Found)
                {
                    LocalLogger.Info("CoreService: no existe registro previo del día para retomar.");
                    return;
                }

                _activityTracker.SeedDayTotals(
                    DateTime.Now.Date,
                    res.Response.ActiveSeconds,
                    res.Response.IdleSeconds,
                    res.Response.WorkHoursActiveSeconds,
                    res.Response.WorkHoursIdleSeconds,
                    res.Response.LunchActiveSeconds,
                    res.Response.LunchIdleSeconds,
                    res.Response.AfterHoursActiveSeconds,
                    res.Response.AfterHoursIdleSeconds
                );

                // Para flush
                _activityFirstEventLocal = DateTime.Now; // o parsear res.Response.FirstEventAt si quieres
                _activitySamplesCount = res.Response.SamplesCount;

                LocalLogger.Info($"CoreService: ✅ Seed aplicado exitosamente. dayDate={today} active={res.Response.ActiveSeconds}s idle={res.Response.IdleSeconds}s " +
                       $"work={res.Response.WorkHoursActiveSeconds}s lunch={res.Response.LunchActiveSeconds}s after={res.Response.AfterHoursActiveSeconds}s samples={res.Response.SamplesCount}");
            }
            catch (Exception ex)
            {
                LocalLogger.Warn($"CoreService: ⚠️ No se pudo retomar actividad del día (posible inicio sin internet). Error: {ex.Message}");
            }
        }

        /// <summary>
        /// Inicia envío periódico de snapshot activity-day.
        /// </summary>
        private void StartActivityFlushTimer()
        {
            try
            {
                if (_activityTracker == null) return;
                if (_apiClient == null) return;
                if (_activityFlushTimer != null) return;

                int intervalSeconds = _configManager.CurrentConfig.Timers?.ActivityFlushIntervalSeconds ?? 10;

                // Jitter: 0 a intervalSeconds antes del primer flush.
                // Con 500 usuarios y flush cada 10s: sin jitter → 500 req simultáneos cada 10s.
                // Con jitter → máximo ~50 req/s distribuidos, nunca un pico.
                int jitterMs = new Random().Next(0, intervalSeconds * 1000);

                _activityFlushTimer = new System.Timers.Timer(intervalSeconds * 1000);
                _activityFlushTimer.AutoReset = true;
                _activityFlushTimer.Elapsed += (s, e) =>
                {
                    try
                    {
                        if (_authManager == null || !_authManager.HasToken)
                            return;

                        var snap = _activityTracker.GetCurrentDaySnapshot();
                        var dayLocal = snap.DayLocalDate;
                        var nowLocal = DateTime.Now;

                        if (_lastFlushDayLocalDate != dayLocal)
                        {
                            _lastFlushDayLocalDate = dayLocal;
                            _activitySamplesCount = 0;
                            _activityFirstEventLocal = nowLocal;
                        }

                        if (_activityFirstEventLocal == null)
                            _activityFirstEventLocal = nowLocal;

                        _activitySamplesCount++;

                        int tzOffsetMinutes = (int)TimeZoneInfo.Local.GetUtcOffset(DateTime.UtcNow).TotalMinutes;

                        var payload = new ApiClient.ActivityDayPayload
                        {
                            DeviceId = _configManager.CurrentConfig.DeviceId,
                            DayDate = dayLocal.ToString("yyyy-MM-dd"),
                            TzOffsetMinutes = tzOffsetMinutes,
                            ActiveSeconds = snap.ActiveSeconds,
                            IdleSeconds = snap.InactiveSeconds,
                            CallSeconds = _windowTracker?.CallSessionSeconds ?? 0,
                            SamplesCount = _activitySamplesCount,
                            FirstEventAt = _activityFirstEventLocal?.ToString("yyyy-MM-dd HH:mm:ss"),
                            LastEventAt = nowLocal.ToString("yyyy-MM-dd HH:mm:ss"),
                            WorkHoursActiveSeconds = snap.WorkActive,
                            WorkHoursIdleSeconds = snap.WorkIdle,
                            LunchActiveSeconds = snap.LunchActive,
                            LunchIdleSeconds = snap.LunchIdle,
                            AfterHoursActiveSeconds = snap.AfterActive,
                            AfterHoursIdleSeconds = snap.AfterIdle,
                            IsWorkday = dayLocal.DayOfWeek != DayOfWeek.Saturday && dayLocal.DayOfWeek != DayOfWeek.Sunday
                        };

                        _ = _apiClient.SendActivityDayAsync(payload);
                    }
                    catch (Exception ex)
                    {
                        LocalLogger.Error(ex, "CoreService: error en flush periódico de actividad.");
                    }
                };

                // Primer tick retrasado por jitter, luego AutoReset se encarga
                System.Threading.Tasks.Task.Delay(jitterMs).ContinueWith(_ =>
                {
                    try
                    {
                        if (_authManager == null || !_authManager.HasToken)
                            return;

                        var snap = _activityTracker.GetCurrentDaySnapshot();
                        var nowLocal = DateTime.Now;

                        _activityFirstEventLocal = nowLocal;
                        _activitySamplesCount = 1;

                        int tzOffsetMinutes = (int)TimeZoneInfo.Local.GetUtcOffset(DateTime.UtcNow).TotalMinutes;

                        var payload = new ApiClient.ActivityDayPayload
                        {
                            DeviceId = _configManager.CurrentConfig.DeviceId,
                            DayDate = snap.DayLocalDate.ToString("yyyy-MM-dd"),
                            TzOffsetMinutes = tzOffsetMinutes,
                            ActiveSeconds = snap.ActiveSeconds,
                            IdleSeconds = snap.InactiveSeconds,
                            CallSeconds = _windowTracker?.CallSessionSeconds ?? 0,
                            SamplesCount = _activitySamplesCount,
                            FirstEventAt = _activityFirstEventLocal?.ToString("yyyy-MM-dd HH:mm:ss"),
                            LastEventAt = nowLocal.ToString("yyyy-MM-dd HH:mm:ss"),
                            WorkHoursActiveSeconds = snap.WorkActive,
                            WorkHoursIdleSeconds = snap.WorkIdle,
                            LunchActiveSeconds = snap.LunchActive,
                            LunchIdleSeconds = snap.LunchIdle,
                            AfterHoursActiveSeconds = snap.AfterActive,
                            AfterHoursIdleSeconds = snap.AfterIdle,
                            IsWorkday = snap.DayLocalDate.DayOfWeek != DayOfWeek.Saturday && snap.DayLocalDate.DayOfWeek != DayOfWeek.Sunday
                        };

                        _ = _apiClient.SendActivityDayAsync(payload);
                    }
                    catch (Exception ex)
                    {
                        LocalLogger.Error(ex, "CoreService: error en primer flush de actividad con jitter.");
                    }
                    _activityFlushTimer?.Start();
                });

                LocalLogger.Info($"CoreService: ActivityFlushTimer iniciado (cada {intervalSeconds}s).");
            }
            catch (Exception ex)
            {
                LocalLogger.Error(ex, "CoreService: error al iniciar ActivityFlushTimer.");
            }
        }

        /// <summary>
        /// Envía snapshot final de actividad antes de cerrar.
        /// Llamar en Stop() para evitar pérdida de datos.
        /// </summary>
        private async Task FinalFlushBeforeShutdownAsync()
        {
            try
            {
                LocalLogger.Info("CoreService.FinalFlushBeforeShutdown(): enviando datos finales...");

                if (_authManager == null || !_authManager.HasToken)
                {
                    LocalLogger.Warn("CoreService.FinalFlushBeforeShutdown(): sin token, no se envía.");
                    return;
                }

                if (_activityTracker == null || _apiClient == null)
                {
                    LocalLogger.Warn("CoreService.FinalFlushBeforeShutdown(): tracker o apiClient null.");
                    return;
                }

                var snap = _activityTracker.GetCurrentDaySnapshot();
                var nowLocal = DateTime.Now;

                int tzOffsetMinutes = (int)TimeZoneInfo.Local.GetUtcOffset(DateTime.UtcNow).TotalMinutes;

                var payload = new ApiClient.ActivityDayPayload
                {
                    DeviceId = _configManager.CurrentConfig.DeviceId,
                    DayDate = snap.DayLocalDate.ToString("yyyy-MM-dd"),
                    TzOffsetMinutes = tzOffsetMinutes,
                    ActiveSeconds = snap.ActiveSeconds,
                    IdleSeconds = snap.InactiveSeconds,
                    CallSeconds = _windowTracker?.CallSessionSeconds ?? 0,
                    SamplesCount = _activitySamplesCount,
                    FirstEventAt = _activityFirstEventLocal?.ToString("yyyy-MM-dd HH:mm:ss"),
                    LastEventAt = nowLocal.ToString("yyyy-MM-dd HH:mm:ss"),
                    WorkHoursActiveSeconds = snap.WorkActive,
                    WorkHoursIdleSeconds = snap.WorkIdle,
                    LunchActiveSeconds = snap.LunchActive,
                    LunchIdleSeconds = snap.LunchIdle,
                    AfterHoursActiveSeconds = snap.AfterActive,
                    AfterHoursIdleSeconds = snap.AfterIdle,
                    IsWorkday = snap.DayLocalDate.DayOfWeek != DayOfWeek.Saturday && snap.DayLocalDate.DayOfWeek != DayOfWeek.Sunday
                };

                // Envío asíncrono con ConfigureAwait(false) para evitar deadlocks
                await _apiClient.SendActivityDayAsync(payload).ConfigureAwait(false);

                // Vaciar también el buffer de episodios pendientes
                await FlushWindowEpisodeBufferAsync(forceAll: true).ConfigureAwait(false);

                LocalLogger.Info("CoreService.FinalFlushBeforeShutdown(): datos enviados correctamente.");
            }
            catch (Exception ex)
            {
                LocalLogger.Error(ex, "CoreService.FinalFlushBeforeShutdown(): error al enviar flush final.");
            }
        }
        /// <summary>
        /// Detiene el timer de flush de actividad.
        /// </summary>
        private void StopActivityFlushTimer()
        {
            try
            {
                if (_activityFlushTimer == null) return;

                _activityFlushTimer.Stop();
                _activityFlushTimer.Dispose();
                _activityFlushTimer = null;

                LocalLogger.Info("CoreService: ActivityFlushTimer detenido.");
            }
            catch { }
        }

        /// <summary>
        /// Aplica el horario laboral recibido del servidor al ActivityTracker.
        /// El servidor consulta keeper_work_schedules priorizando el registro del usuario
        /// sobre el registro global (user_id IS NULL).
        /// Formato de tiempo esperado: "HH:mm:ss" (ej: "07:00:00").
        /// </summary>
        private void ApplyWorkSchedule(ApiClient.WorkScheduleConfig ws)
        {
            try
            {
                var schedule = _activityTracker.WorkSchedule;

                if (TimeSpan.TryParse(ws.WorkStartTime, out var workStart))
                    schedule.WorkStart = workStart;
                if (TimeSpan.TryParse(ws.WorkEndTime, out var workEnd))
                    schedule.WorkEnd = workEnd;
                if (TimeSpan.TryParse(ws.LunchStartTime, out var lunchStart))
                    schedule.LunchStart = lunchStart;
                if (TimeSpan.TryParse(ws.LunchEndTime, out var lunchEnd))
                    schedule.LunchEnd = lunchEnd;

                LocalLogger.Info($"CoreService: WorkSchedule aplicado desde servidor. " +
                    $"Work={schedule.WorkStart:hh\\:mm}-{schedule.WorkEnd:hh\\:mm} " +
                    $"Lunch={schedule.LunchStart:hh\\:mm}-{schedule.LunchEnd:hh\\:mm}");
            }
            catch (Exception ex)
            {
                LocalLogger.Warn($"CoreService.ApplyWorkSchedule(): error al aplicar horario. Se usan valores anteriores. {ex.Message}");
            }
        }

        // ================== Buffer de Window Episodes (Fix #4) ==================

        /// <summary>
        /// Encola un episodio en el buffer local. Si el buffer alcanza el tamaño máximo,
        /// dispara un flush inmediato en background. El timer periódico también vacía
        /// el buffer cada _windowEpisodeBatchIntervalSeconds.
        /// </summary>
        private void EnqueueWindowEpisode(ApiClient.WindowEpisodePayload payload)
        {
            bool needsImmediateFlush = false;

            lock (_windowEpisodeBufferLock)
            {
                _windowEpisodeBuffer.Add(payload);
                if (_windowEpisodeBuffer.Count >= _windowEpisodeBatchMaxSize)
                {
                    needsImmediateFlush = true;
                }
            }

            if (needsImmediateFlush)
            {
                // Fire-and-forget: no bloquear el hilo de captura de ventanas
                _ = FlushWindowEpisodeBufferAsync(forceAll: false);
            }
        }

        /// <summary>
        /// Extrae episodios del buffer y los envía en batches. Retorna sin hacer nada
        /// si el buffer está vacío.
        ///
        /// - forceAll=true: vacía todo el buffer (usado en shutdown).
        /// - forceAll=false: envía solo hasta _windowEpisodeBatchMaxSize por llamada.
        /// </summary>
        private async Task FlushWindowEpisodeBufferAsync(bool forceAll)
        {
            if (_apiClient == null) return;
            if (_authManager == null || !_authManager.HasToken) return;

            while (true)
            {
                System.Collections.Generic.List<ApiClient.WindowEpisodePayload> batch;

                lock (_windowEpisodeBufferLock)
                {
                    if (_windowEpisodeBuffer.Count == 0) return;

                    int take = Math.Min(_windowEpisodeBuffer.Count, _windowEpisodeBatchMaxSize);
                    batch = _windowEpisodeBuffer.GetRange(0, take);
                    _windowEpisodeBuffer.RemoveRange(0, take);
                }

                try
                {
                    string deviceGuid = _configManager.CurrentConfig.DeviceId;
                    bool ok = await _apiClient.SendWindowEpisodesBatchAsync(deviceGuid, batch).ConfigureAwait(false);

                    if (!ok)
                    {
                        // El batch ya encoló cada episodio individualmente en offline queue.
                        LocalLogger.Warn($"CoreService.FlushWindowEpisodeBufferAsync(): batch falló, {batch.Count} episodios encolados en offline queue.");
                    }
                }
                catch (Exception ex)
                {
                    LocalLogger.Error(ex, "CoreService.FlushWindowEpisodeBufferAsync(): error enviando batch.");
                }

                if (!forceAll) break;
            }
        }

        /// <summary>
        /// Inicia el timer periódico de flush del buffer de window-episodes.
        /// </summary>
        private void StartWindowEpisodeFlushTimer()
        {
            try
            {
                if (_windowEpisodeFlushTimer != null) return;
                if (_apiClient == null) return;

                int intervalSeconds = _configManager.CurrentConfig.Timers?.WindowEpisodeBatchIntervalSeconds ?? 30;
                intervalSeconds = Math.Max(15, intervalSeconds);
                _windowEpisodeBatchIntervalSeconds = intervalSeconds;

                // Jitter: arranque con retraso aleatorio 0..interval para que 1000 clientes
                // que bootean a la misma hora no alineen sus flushes de window-episodes en el
                // mismo segundo (mismo patrón que handshake/activity → evita thundering herd).
                int jitterMs = new Random().Next(0, intervalSeconds * 1000);

                _windowEpisodeFlushTimer = new System.Timers.Timer(intervalSeconds * 1000);
                _windowEpisodeFlushTimer.AutoReset = true;
                _windowEpisodeFlushTimer.Elapsed += async (s, e) =>
                {
                    try
                    {
                        await FlushWindowEpisodeBufferAsync(forceAll: false).ConfigureAwait(false);
                    }
                    catch (Exception ex)
                    {
                        LocalLogger.Error(ex, "CoreService: error en flush periódico de window-episodes.");
                    }
                };

                // Primer arranque retrasado por jitter; AutoReset mantiene la cadencia.
                System.Threading.Tasks.Task.Delay(jitterMs).ContinueWith(_ => { try { _windowEpisodeFlushTimer?.Start(); } catch { } });

                LocalLogger.Info($"CoreService: WindowEpisodeFlushTimer iniciado (cada {intervalSeconds}s, jitter={jitterMs}ms, batch max={_windowEpisodeBatchMaxSize}).");
            }
            catch (Exception ex)
            {
                LocalLogger.Error(ex, "CoreService: error al iniciar WindowEpisodeFlushTimer.");
            }
        }

        /// <summary>
        /// Detiene el timer de flush de window-episodes.
        /// </summary>
        private void StopWindowEpisodeFlushTimer()
        {
            try
            {
                if (_windowEpisodeFlushTimer == null) return;
                _windowEpisodeFlushTimer.Stop();
                _windowEpisodeFlushTimer.Dispose();
                _windowEpisodeFlushTimer = null;
                LocalLogger.Info("CoreService: WindowEpisodeFlushTimer detenido.");
            }
            catch { }
        }
    }
}