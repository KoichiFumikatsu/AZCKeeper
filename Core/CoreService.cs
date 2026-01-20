using System;
using System.Drawing;
using System.Windows.Forms;
using AZCKeeper_Cliente.Auth;
using AZCKeeper_Cliente.Blocking;
using AZCKeeper_Cliente.Config;
using AZCKeeper_Cliente.Logging;
using AZCKeeper_Cliente.Network;
using AZCKeeper_Cliente.Tracking;
using AZCKeeper_Cliente.Update;

namespace AZCKeeper_Cliente.Core
{
    internal class CoreService
    {
        private ConfigManager _configManager;
        private AuthManager _authManager;
        private ApiClient _apiClient;

        private ActivityTracker _activityTracker;
        private WindowTracker _windowTracker;

        private KeyboardHook _keyboardHook;
        private MouseHook _mouseHook;

        private KeyBlocker _keyBlocker;
        private UpdateManager _updateManager;

        private DebugWindowForm _debugWindow;
        private LoginForm _loginForm;

        // Envío periódico de actividad (no esperar hasta OnDayClosed)
        private System.Timers.Timer _activityFlushTimer;
        private DateTime? _activityFirstEventLocal;
        private int _activitySamplesCount; 
        private DateTime _lastFlushDayLocalDate = default;


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

                LocalLogger.Info("CoreService.Initialize(): OK.");
            }
            catch (Exception ex)
            {
                LocalLogger.Error(ex, "CoreService.Initialize(): error.");
                throw;
            }

            Microsoft.Win32.SystemEvents.SessionEnding += OnSessionEnding;
        }

        private void OnSessionEnding(object sender, Microsoft.Win32.SessionEndingEventArgs e)
        {
            LocalLogger.Warn($"CoreService: Windows cerrando sesión ({e.Reason}). Flush final...");
            FinalFlushBeforeShutdown();
        }
        public void Start()
        {
            LocalLogger.Info("CoreService.Start(): iniciando.");

            try
            {
                // 1) Retomar ANTES de iniciar ActivityTracker (tu SeedDayTotals lo exige)
                TryResumeTodayActivityFromServer();

                // 2) Start trackers
                _activityTracker?.Start();
                _windowTracker?.Start();
                _keyboardHook?.Start();
                _mouseHook?.Start();
                _updateManager?.Start();

                // 3) Flush periódico
                StartActivityFlushTimer();

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


        public void Stop()
        {
            Microsoft.Win32.SystemEvents.SessionEnding -= OnSessionEnding;
            LocalLogger.Info("CoreService.Stop(): deteniendo.");

            try
            {        
                // 🔥 FLUSH FINAL ANTES DE DETENER TRACKERS
                FinalFlushBeforeShutdown();
                StopActivityFlushTimer();

                _activityTracker?.Stop();
                _windowTracker?.Stop();
                _keyboardHook?.Stop();
                _mouseHook?.Stop();
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

        private void PrepareLoginUi()
        {
            _loginForm = new AZCKeeper_Cliente.Auth.LoginForm();

            _loginForm.OnLoginSubmitted += (user, pass) =>
            {
                try
                {
                    _loginForm.SetBusy(true, "Validando credenciales...");

                    var login = _apiClient.SendLoginAsync(new ApiClient.LoginRequest
                    {
                        Username = user,
                        Password = pass,
                        DeviceId = _configManager.CurrentConfig.DeviceId,
                        DeviceName = Environment.MachineName
                    }).GetAwaiter().GetResult();

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

                var effective = hs.Response.EffectiveConfig;

                if (!string.IsNullOrWhiteSpace(effective.ApiBaseUrl))
                    _configManager.CurrentConfig.ApiBaseUrl = effective.ApiBaseUrl;

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
                    modules.EnableKeyboardHook = effective.Modules.EnableKeyboardHook;
                    modules.EnableMouseHook = effective.Modules.EnableMouseHook;
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

                _configManager.Save();
                _configManager.ApplyLoggingConfiguration();

                LocalLogger.Info("CoreService.PerformHandshake(): configuración aplicada desde effectiveConfig.");
            }
            catch (Exception ex)
            {
                LocalLogger.Error(ex, "CoreService.PerformHandshake(): error. Se continúa con config local.");
            }
        }

        private void InitializeModules()
        {
            var modulesConfig = _configManager.CurrentConfig.Modules;

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
                            LastEventAt = DateTime.Now.ToString("yyyy-MM-dd HH:mm:ss")
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

            // -------------------- Hooks / Blocking / Updates / Debug --------------------
            if (modulesConfig.EnableKeyboardHook) _keyboardHook = new KeyboardHook();
            if (modulesConfig.EnableMouseHook) _mouseHook = new MouseHook();
            if (modulesConfig.EnableBlocking) _keyBlocker = new KeyBlocker();
            if (modulesConfig.EnableUpdateManager) _updateManager = new UpdateManager();

            if (modulesConfig.EnableDebugWindow)
            {
                if (_activityTracker == null)
                {
                    LocalLogger.Warn("CoreService.InitializeModules(): EnableDebugWindow activo pero ActivityTracker deshabilitado.");
                }
                else
                {
                    _debugWindow = new DebugWindowForm(_activityTracker, _windowTracker);
                }
            }
        }

        private void TryResumeTodayActivityFromServer()
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

                var res = _apiClient.GetActivityDayAsync(deviceId, today).GetAwaiter().GetResult();
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

                _activityTracker.SeedDayTotals(DateTime.Now.Date, res.Response.ActiveSeconds, res.Response.IdleSeconds);

                // Para flush
                _activityFirstEventLocal = DateTime.Now; // o parsear res.Response.FirstEventAt si quieres
                _activitySamplesCount = res.Response.SamplesCount;

                LocalLogger.Info($"CoreService: retomar OK dayDate={res.Response.DayDate} active={res.Response.ActiveSeconds}s idle={res.Response.IdleSeconds}s samples={res.Response.SamplesCount}");
            }
            catch (Exception ex)
            {
                LocalLogger.Error(ex, "CoreService: error al retomar actividad del día desde backend.");
            }
        }

        private void StartActivityFlushTimer()
        {
            try
            {
                if (_activityTracker == null) return;
                if (_apiClient == null) return;

                if (_activityFlushTimer != null) return;

                _activityFlushTimer = new System.Timers.Timer(6_000);
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

                        // reset por cambio de día (evita que el día nuevo herede samples/firstEvent)
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
                            LastEventAt = nowLocal.ToString("yyyy-MM-dd HH:mm:ss")
                        };

                        _ = _apiClient.SendActivityDayAsync(payload);
                    }
                    catch (Exception ex)
                    {
                        LocalLogger.Error(ex, "CoreService: error en flush periódico de actividad.");
                    }
                };

                _activityFlushTimer.Start();
                LocalLogger.Info("CoreService: ActivityFlushTimer iniciado (cada 6s).");
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
        private void FinalFlushBeforeShutdown()
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
                    LastEventAt = nowLocal.ToString("yyyy-MM-dd HH:mm:ss")
                };

                // Envío SINCRÓNICO (blocking) para garantizar que llegue antes de cerrar
                _apiClient.SendActivityDayAsync(payload).GetAwaiter().GetResult();

                LocalLogger.Info("CoreService.FinalFlushBeforeShutdown(): datos enviados correctamente.");
            }
            catch (Exception ex)
            {
                LocalLogger.Error(ex, "CoreService.FinalFlushBeforeShutdown(): error al enviar flush final.");
            }
        }
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

        public DebugWindowForm(ActivityTracker activityTracker, WindowTracker windowTracker)
        {
            _activityTracker = activityTracker ?? throw new ArgumentNullException(nameof(activityTracker));
            _windowTracker = windowTracker;

            Text = "AZCKeeper - Debug Activity";
            StartPosition = FormStartPosition.CenterScreen;
            Size = new Size(720, 340);
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

            table.Controls.Add(_lblStartTime, 0, 0);
            table.Controls.Add(_lblCurrentDate, 0, 1);
            table.Controls.Add(_lblSessionActive, 0, 2);
            table.Controls.Add(_lblSessionInactive, 0, 3);
            table.Controls.Add(_lblDayActive, 0, 4);
            table.Controls.Add(_lblDayInactive, 0, 5);
            table.Controls.Add(_lblWindowInfo, 0, 6);
            table.Controls.Add(_lblCallTime, 0, 7);

            Controls.Add(table);

            _uiTimer = new System.Windows.Forms.Timer { Interval = 1000 };
            _uiTimer.Tick += UiTimer_Tick;
            _uiTimer.Start();

            UpdateLabels();
        }

        private Label CreateLabel()
        {
            return new Label
            {
                AutoSize = true,
                Font = new Font("Segoe UI", 9F, FontStyle.Regular, GraphicsUnit.Point),
                Padding = new Padding(4)
            };
        }

        private void UiTimer_Tick(object sender, EventArgs e) => UpdateLabels();

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
            }
            catch
            {
                // no romper UI
            }
        }

        private static string FormatSeconds(double seconds)
        {
            if (seconds < 0) seconds = 0;
            var ts = TimeSpan.FromSeconds(seconds);
            return $"{(int)ts.TotalHours:00}:{ts.Minutes:00}:{ts.Seconds:00}";
        }

        protected override void OnFormClosed(FormClosedEventArgs e)
        {
            _uiTimer.Stop();
            _uiTimer.Tick -= UiTimer_Tick;
            _uiTimer.Dispose();
            base.OnFormClosed(e);
        }
    }
}
