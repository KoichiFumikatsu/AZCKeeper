using System;
using System.Drawing;
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
        private UpdateManager _updateManager;  // actualización automática

        // --- UI ---
        private DebugWindowForm _debugWindow; // ventana de diagnóstico
        private LoginForm _loginForm;         // UI de login
        private readonly System.Threading.SynchronizationContext _uiContext; // Thread de UI

        // --- Timers/flush ---
        private System.Timers.Timer _activityFlushTimer; // envío periódico activity-day
        private DateTime? _activityFirstEventLocal;      // primera muestra del día
        private int _activitySamplesCount;               // muestras enviadas
        private DateTime _lastFlushDayLocalDate = default; // corte de día local

        private System.Timers.Timer _handshakeTimer; // handshake periódico
        private DateTime _lastHandshakeTime = DateTime.MinValue; // último handshake ok
        private bool _hasSuccessfulHandshake = false; // flag para primer handshake exitoso

        /// <summary>
        /// Constructor: captura el SynchronizationContext del thread de UI
        /// </summary>
        public CoreService()
        {
            _uiContext = System.Threading.SynchronizationContext.Current;
        }

        /// <summary>
        /// Ejecuta una acción en el thread de UI de forma segura
        /// </summary>
        private void RunOnUiThread(Action action)
        {
            if (_uiContext != null)
            {
                _uiContext.Post(_ =>
                {
                    try
                    {
                        action();
                    }
                    catch (Exception ex)
                    {
                        LocalLogger.Error(ex, "CoreService.RunOnUiThread(): error ejecutando acción.");
                    }
                }, null);
            }
            else
            {
                // Fallback: ejecutar directamente si no hay context
                try
                {
                    action();
                }
                catch (Exception ex)
                {
                    LocalLogger.Error(ex, "CoreService.RunOnUiThread(): error en fallback.");
                }
            }
        }

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

                _authManager = new AuthManager();
                _authManager.TryLoadTokenFromDisk();

                if (!_authManager.HasToken)
                    PrepareLoginUi();

                _apiClient = new ApiClient(_configManager, _authManager);

                // Handshake solo será válido si hay token.
                PerformHandshake();

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
                
                var debugWindowToShow = _debugWindow;
                if (debugWindowToShow != null && !debugWindowToShow.IsDisposed)
                {
                    RunOnUiThread(() =>
                    {
                        if (debugWindowToShow != null && !debugWindowToShow.IsDisposed)
                            debugWindowToShow.Show();
                    });
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

                _handshakeTimer?.Stop();
                _handshakeTimer?.Dispose();
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
        /// Inicia handshake periódico para refrescar config y políticas.
        /// </summary>
        private void StartHandshakeTimer()
        {
            try
            {
                if (_handshakeTimer != null) return;

                int intervalMinutes = _configManager.CurrentConfig.Timers?.HandshakeIntervalMinutes ?? 5;

                _handshakeTimer = new System.Timers.Timer(intervalMinutes * 60_000);
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
                _handshakeTimer.Start();
                LocalLogger.Info($"CoreService: HandshakeTimer iniciado (cada {intervalMinutes}min).");
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
                    return;
                }

                if (hs.IsUnauthorized)
                {
                    LocalLogger.Warn("CoreService.PerformHandshake(): 401/403. Se limpia token y se solicitará login.");
                    _authManager.ClearToken();

                    StopActivityFlushTimer();

                    if (_loginForm == null || _loginForm.IsDisposed)
                        PrepareLoginUi();

                    return;
                }

                if (!hs.IsSuccess || hs.Response == null || hs.Response.EffectiveConfig == null)
                {
                    LocalLogger.Warn($"CoreService.PerformHandshake(): no aplicado. Status={hs.StatusCode?.ToString() ?? "null"}, NonJson={hs.IsNonJsonResponse}, BodyPreview={hs.BodyPreview}");
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
                if (effective.Blocking != null && effective.Blocking.EnableDeviceLock)
                {
                    LocalLogger.Warn("CoreService: política de bloqueo detectada. Verificando estado...");
                    CheckDeviceLockStatus();
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
                    LocalLogger.Info($"CoreService: Blocking guardado en config.json. UnlockPin='{(blocking.UnlockPin ?? "NULL")}'");

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

                    if (!modules.EnableDebugWindow)
                    {
                        var debugWindowToClose = _debugWindow;
                        if (debugWindowToClose != null && !debugWindowToClose.IsDisposed)
                        {
                            RunOnUiThread(() =>
                            {
                                if (debugWindowToClose != null && !debugWindowToClose.IsDisposed)
                                    debugWindowToClose.Close();
                            });
                        }

                        _debugWindow = null;
                    }
                    else
                    {
                        if (_debugWindow == null || _debugWindow.IsDisposed)
                        {
                            if (_activityTracker == null)
                            {
                                LocalLogger.Warn("CoreService.PerformHandshake(): DebugWindow habilitado pero ActivityTracker no iniciado.");
                            }
                            else
                            {
                                RunOnUiThread(() =>
                                {
                                    if (_debugWindow == null || _debugWindow.IsDisposed)
                                    {
                                        _debugWindow = new DebugWindowForm(_activityTracker, _windowTracker, () => _lastHandshakeTime);
                                        _debugWindow.Show();
                                    }
                                });
                            }
                        }
                        else if (!_debugWindow.IsDisposed)
                        {
                            var debugWindowToShow = _debugWindow;
                            RunOnUiThread(() =>
                            {
                                if (debugWindowToShow != null && !debugWindowToShow.IsDisposed && !debugWindowToShow.Visible)
                                {
                                    debugWindowToShow.Show();
                                }
                            });
                        }
                    }
                }
                // -------------------- Timers --------------------
                if (effective.Timers != null)
                {
                    var timers = _configManager.CurrentConfig.Timers ?? new ConfigManager.TimersConfig();

                    timers.ActivityFlushIntervalSeconds = effective.Timers.ActivityFlushIntervalSeconds > 0
                        ? effective.Timers.ActivityFlushIntervalSeconds
                        : 6;

                    timers.HandshakeIntervalMinutes = effective.Timers.HandshakeIntervalMinutes > 0
                        ? effective.Timers.HandshakeIntervalMinutes
                        : 5;

                    timers.OfflineQueueRetrySeconds = effective.Timers.OfflineQueueRetrySeconds > 0
                        ? effective.Timers.OfflineQueueRetrySeconds
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

                LocalLogger.Info("CoreService.PerformHandshake(): configuración aplicada desde effectiveConfig.");
            }
            catch (Exception ex)
            {
                LocalLogger.Error(ex, "CoreService.PerformHandshake(): error. Se continúa con config local.");
            }
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

                // Reiniciar Handshake con nuevo intervalo
                if (_handshakeTimer != null)
                {
                    _handshakeTimer.Stop();
                    _handshakeTimer.Interval = timers.HandshakeIntervalMinutes * 60_000;
                    _handshakeTimer.Start();
                    LocalLogger.Info($"CoreService: Handshake actualizado a {timers.HandshakeIntervalMinutes}min");
                }

                // Actualizar OfflineQueue retry
                if (_apiClient != null)
                {
                    _apiClient.UpdateRetryInterval(timers.OfflineQueueRetrySeconds);
                    LocalLogger.Info($"CoreService: OfflineQueue retry actualizado a {timers.OfflineQueueRetrySeconds}s");
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
                        // Validar que la duración sea >= 1 segundo para evitar errores de redondeo
                        // al serializar sin milisegundos (backend espera YYYY-MM-DD HH:MM:SS)
                        if (episode.DurationSeconds < 1.0)
                        {
                            LocalLogger.Info($"CoreService: episodio ignorado por duración <1s ({episode.DurationSeconds:F3}s): {episode.ProcessName}");
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

                        _ = _apiClient.SendWindowEpisodeAsync(payload);
                    }
                    catch (Exception ex)
                    {
                        LocalLogger.Error(ex, "CoreService: error al enviar window-episode.");
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
                    try
                    {
                        // Crear ventana de debug en el thread de UI
                        var openForms = System.Windows.Forms.Application.OpenForms;
                        if (openForms.Count > 0 && openForms[0].InvokeRequired)
                        {
                            openForms[0].Invoke(new Action(() =>
                            {
                                _debugWindow = new DebugWindowForm(_activityTracker, _windowTracker, () => _lastHandshakeTime);
                            }));
                        }
                        else
                        {
                            // Sin Forms o ya en thread UI
                            _debugWindow = new DebugWindowForm(_activityTracker, _windowTracker, () => _lastHandshakeTime);
                        }
                    }
                    catch (Exception ex)
                    {
                        LocalLogger.Error(ex, "CoreService.InitializeModules(): error creando DebugWindow.");
                    }
                }
            }
        }
        /// <summary>
        /// Consulta estado de bloqueo remoto y aplica si corresponde.
        /// </summary>
        private async void CheckDeviceLockStatus()
        {
            try
            {
                var response = await _apiClient.PostAsync("client/device-lock/status", new { });
                // Parsear y activar bloqueo si locked=true
            }
            catch (Exception ex)
            {
                LocalLogger.Error(ex, "CoreService: error al verificar estado de bloqueo.");
            }
        }
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

                int intervalSeconds = _configManager.CurrentConfig.Timers?.ActivityFlushIntervalSeconds ?? 6;

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

                _activityFlushTimer.Start();
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
    }

    /// <summary>
    /// DebugWindowForm:
    /// UI de diagnóstico en tiempo real para activity/window tracking.
    /// Lee datos de ActivityTracker/WindowTracker y muestra categorías de tiempo.
    /// </summary>
    internal class DebugWindowForm : Form
    {
        private readonly ActivityTracker _activityTracker;
        private readonly WindowTracker _windowTracker;
        private readonly System.Windows.Forms.Timer _uiTimer;

        private readonly Label _lblStartTime;
        private readonly Label _lblCurrentDate;
        private readonly Label _lblSessionActive;
        private readonly Label _lblSessionInactive;
        private readonly Label _lblDayActive;
        private readonly Label _lblDayInactive;
        private readonly Label _lblWindowInfo;
        private readonly Label _lblCallTime;
        private readonly Label _lblQueueStatus;
        private readonly Label _lblHandshake; 
        private readonly Func<DateTime> _getLastHandshake;
        private readonly Label _lblWorkTime;
        private readonly Label _lblLunchTime;
        private readonly Label _lblAfterHoursTime;
        /// <summary>
        /// Crea ventana de debug con referencias a trackers y función de último handshake.
        /// </summary>
        public DebugWindowForm(ActivityTracker activityTracker, WindowTracker windowTracker, Func<DateTime> getLastHandshake)
        {
            _activityTracker = activityTracker ?? throw new ArgumentNullException(nameof(activityTracker));
            _windowTracker = windowTracker; 
            _getLastHandshake = getLastHandshake;

            Text = "AZCKeeper - Debug Activity";
            StartPosition = FormStartPosition.CenterScreen;
            Size = new Size(900, 480); // Era 720x340, ahora más grande
            FormBorderStyle = FormBorderStyle.FixedDialog;
            MaximizeBox = false;

            var table = new TableLayoutPanel
            {
                Dock = DockStyle.Fill,
                ColumnCount = 1,
                RowCount = 8,
                AutoSize = true,
                Padding = new Padding(8)
            };

            _lblStartTime = CreateLabel();
            _lblCurrentDate = CreateLabel();
            _lblSessionActive = CreateLabel();
            _lblSessionInactive = CreateLabel();
            _lblDayActive = CreateLabel();
            _lblDayInactive = CreateLabel();
            _lblWindowInfo = CreateLabel();
            _lblCallTime = CreateLabel();
            _lblQueueStatus = CreateLabel();
            _lblHandshake = CreateLabel();
            _lblWorkTime = CreateLabel();
            _lblLunchTime = CreateLabel();
            _lblAfterHoursTime = CreateLabel();

            table.Controls.Add(_lblStartTime, 0, 0);
            table.Controls.Add(_lblCurrentDate, 0, 1);
            table.Controls.Add(_lblSessionActive, 0, 2);
            table.Controls.Add(_lblSessionInactive, 0, 3);
            table.Controls.Add(_lblDayActive, 0, 4);
            table.Controls.Add(_lblDayInactive, 0, 5);
            table.Controls.Add(_lblWindowInfo, 0, 6);
            table.Controls.Add(_lblCallTime, 0, 7);
            table.Controls.Add(_lblQueueStatus, 0, 8); 
            table.Controls.Add(_lblHandshake, 0, 9); 
            table.Controls.Add(_lblWorkTime, 0, 10);
            table.Controls.Add(_lblLunchTime, 0, 11);
            table.Controls.Add(_lblAfterHoursTime, 0, 12);

            table.RowCount = 13; // Era 9, ahora 12

            Controls.Add(table);

            _uiTimer = new System.Windows.Forms.Timer { Interval = 1000 };
            _uiTimer.Tick += UiTimer_Tick;
            _uiTimer.Start();

            UpdateLabels();
        }

        /// <summary>
        /// Helper de UI: crea labels con estilo estándar.
        /// </summary>
        private Label CreateLabel()
        {
            return new Label
            {
                AutoSize = true,
                Font = new Font("Segoe UI", 9F, FontStyle.Regular, GraphicsUnit.Point),
                Padding = new Padding(4)
            };
        }

        /// <summary>
        /// Tick del timer UI: refresca etiquetas.
        /// </summary>
        private void UiTimer_Tick(object sender, EventArgs e) => UpdateLabels();

        /// <summary>
        /// Actualiza todas las etiquetas con métricas actuales de tracking.
        /// </summary>
        private void UpdateLabels()
        {
            try
            {
                var nowLocal = DateTime.Now;

                DateTime start = _activityTracker.StartLocalTime;
                _lblStartTime.Text = start == default
                    ? "Inicio tracker: (aún no inicializado)"
                    : $"Inicio tracker: {start:yyyy-MM-dd HH:mm:ss}";

                _lblCurrentDate.Text = $"Fecha actual: {nowLocal:yyyy-MM-dd HH:mm:ss}";

                _lblSessionActive.Text = $"Sesión - Activo: {FormatSeconds(_activityTracker.SessionActiveSeconds)}";
                _lblSessionInactive.Text = $"Sesión - Inactivo: {FormatSeconds(_activityTracker.SessionInactiveSeconds)}";

                _lblDayActive.Text = $"Día {_activityTracker.CurrentDayLocalDate:yyyy-MM-dd} - Activo: {FormatSeconds(_activityTracker.CurrentDayActiveSeconds)}";
                _lblDayInactive.Text = $"Día {_activityTracker.CurrentDayLocalDate:yyyy-MM-dd} - Inactivo: {FormatSeconds(_activityTracker.CurrentDayInactiveSeconds)}";

                if (_windowTracker != null)
                {
                    string time = _windowTracker.LastSnapshotLocalTime == default
                        ? "sin datos"
                        : _windowTracker.LastSnapshotLocalTime.ToString("HH:mm:ss");

                    _lblWindowInfo.Text = $"Ventana activa: [{_windowTracker.LastProcessName}] \"{_windowTracker.LastWindowTitle}\" ({time})";

                    if (_windowTracker.CallTrackingEnabled)
                    {
                        string inCall = _windowTracker.IsInCallNow ? "Sí" : "No";
                        _lblCallTime.Text =
                            $"Llamada (ahora): {inCall} | Sesión - Tiempo en apps de llamada: {FormatSeconds(_windowTracker.CallSessionSeconds)}";
                    }
                    else
                    {
                        _lblCallTime.Text = "Sesión - Tiempo en apps de llamada: (deshabilitado)";
                    }
                }
                else
                {
                    _lblWindowInfo.Text = "Ventana activa: (WindowTracker deshabilitado)";
                    _lblCallTime.Text = "Sesión - Tiempo en apps de llamada: (no aplica)";
                }

                // Handshake
                var lastHs = _getLastHandshake();
                if (lastHs == DateTime.MinValue)
                    _lblHandshake.Text = "Último handshake: Nunca";
                else
                {
                    var elapsed = (DateTime.Now - lastHs).TotalSeconds;
                    _lblHandshake.Text = $"Último handshake: {lastHs:HH:mm:ss} (hace {elapsed:F0}s)";
                }

                // ==================== CATEGORÍAS DE TIEMPO ====================

                // Determinar categoría actual (validación defensiva)
                var currentCategory = _activityTracker.WorkSchedule?.GetTimeCategory(nowLocal) ?? Tracking.TimeCategory.AfterHours;
                string categoryIndicator = currentCategory switch
                {
                    Tracking.TimeCategory.WorkHours => "🟢 HORARIO LABORAL",
                    Tracking.TimeCategory.LunchTime => "🟡 HORA DE ALMUERZO",
                    Tracking.TimeCategory.AfterHours => "🔴 FUERA DE HORARIO",
                    _ => "⚪ DESCONOCIDO"
                };

                // Work Hours
                double workTotal = _activityTracker.CurrentDayWorkActiveSeconds + _activityTracker.CurrentDayWorkIdleSeconds;
                string workPercent = workTotal > 0
                    ? $"({(_activityTracker.CurrentDayWorkActiveSeconds / workTotal * 100):F1}% activo)"
                    : "";
                _lblWorkTime.Text = $"🟢 Horario laboral (7am-7pm): {FormatSeconds(_activityTracker.CurrentDayWorkActiveSeconds)} activo / {FormatSeconds(_activityTracker.CurrentDayWorkIdleSeconds)} inactivo {workPercent}";

                // Lunch Time
                double lunchTotal = _activityTracker.CurrentDayLunchActiveSeconds + _activityTracker.CurrentDayLunchIdleSeconds;
                string lunchPercent = lunchTotal > 0
                    ? $"({(_activityTracker.CurrentDayLunchActiveSeconds / lunchTotal * 100):F1}% activo)"
                    : "";
                _lblLunchTime.Text = $"🟡 Hora de almuerzo (12pm-1pm): {FormatSeconds(_activityTracker.CurrentDayLunchActiveSeconds)} activo / {FormatSeconds(_activityTracker.CurrentDayLunchIdleSeconds)} inactivo {lunchPercent}";

                // After Hours
                double afterTotal = _activityTracker.CurrentDayAfterHoursActiveSeconds + _activityTracker.CurrentDayAfterHoursIdleSeconds;
                string afterPercent = afterTotal > 0
                    ? $"({(_activityTracker.CurrentDayAfterHoursActiveSeconds / afterTotal * 100):F1}% activo)"
                    : "";
                _lblAfterHoursTime.Text = $"🔴 Fuera de horario: {FormatSeconds(_activityTracker.CurrentDayAfterHoursActiveSeconds)} activo / {FormatSeconds(_activityTracker.CurrentDayAfterHoursIdleSeconds)} inactivo {afterPercent}";

                // Actualizar título del form con categoría actual
                Text = $"AZCKeeper - Debug Activity";
            }
            catch (Exception ex)
            {
                // No romper UI pero loguear el error
                LocalLogger.Warn($"DebugWindowForm.UpdateLabels(): error al actualizar UI. {ex.Message}");
            }
        }

        /// <summary>
        /// Formatea segundos como HH:mm:ss.
        /// </summary>
        private static string FormatSeconds(double seconds)
        {
            if (seconds < 0) seconds = 0;
            var ts = TimeSpan.FromSeconds(seconds);
            return $"{(int)ts.TotalHours:00}:{ts.Minutes:00}:{ts.Seconds:00}";
        }

        /// <summary>
        /// Libera recursos del timer UI al cerrar el formulario.
        /// </summary>
        protected override void OnFormClosed(FormClosedEventArgs e)
        {
            _uiTimer.Stop();
            _uiTimer.Tick -= UiTimer_Tick;
            _uiTimer.Dispose();
            base.OnFormClosed(e);
        }
    }
}
