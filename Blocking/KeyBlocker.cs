using System;
using System.Collections.Generic;
using System.Drawing;
using System.Runtime.InteropServices;
using System.Text.Json;
using System.Threading;
using System.Windows.Forms;
using AZCKeeper_Cliente.Logging;
using AZCKeeper_Cliente.Network;

namespace AZCKeeper_Cliente.Blocking
{
    /// <summary>
    /// KeyBlocker controla el bloqueo remoto de dispositivo mediante formularios fullscreen
    /// y un hook de teclado de bajo nivel.
    ///
    /// Comunicación:
    /// - CoreService lo activa/desactiva según políticas de handshake.
    /// - ApiClient se usa para notificar unlock remoto.
    /// </summary>
    internal class KeyBlocker
    {
        private readonly List<LockFormContainer> _lockForms = new List<LockFormContainer>();
        private readonly object _sync = new object();
        private readonly ApiClient _apiClient;
        private LowLevelKeyboardHook _keyboardHook;
        private int _isActive = 0; // use as atomic flag
        private System.Windows.Forms.Timer _keepFrontTimer; // Timer para mantener ventanas al frente

        /// <summary>
        /// Crea el bloqueador con ApiClient para notificar desbloqueos.
        /// </summary>
        public KeyBlocker(ApiClient apiClient)
        {
            _apiClient = apiClient;
        }

        /// <summary>
        /// Activa bloqueo en todas las pantallas (crea formularios fullscreen).
        /// </summary>
        public void ActivateLock(string reason, bool allowUnlock, string unlockPin = null)
        {
            if (System.Threading.Volatile.Read(ref _isActive) == 1)
            {
                LocalLogger.Warn("KeyBlocker: ya hay un bloqueo activo.");
                return;
            }

            try
            {
                LocalLogger.Warn($"KeyBlocker: activando bloqueo. Motivo: {reason}");

                if (System.Threading.Interlocked.Exchange(ref _isActive, 1) == 1)
                {
                    LocalLogger.Warn("KeyBlocker: ya hay un bloqueo activo.");
                    return;
                }

                // Hook de teclado
                _keyboardHook = new LowLevelKeyboardHook();
                _keyboardHook.Install();

                // Crear formulario para cada pantalla en su propio hilo STA
                int screenIndex = 0;
                foreach (Screen screen in Screen.AllScreens)
                {
                    screenIndex++;
                    var currentScreen = screen; // Capturar variable para evitar closure problems
                    var currentIndex = screenIndex;
                    var container = new LockFormContainer();

                    ThreadStart ts = () =>
                    {
                        try
                        {
                            LocalLogger.Info($"KeyBlocker: Creando formulario para pantalla {currentIndex} ({currentScreen.DeviceName})...");
                            var lf = new LockScreenForm(reason, allowUnlock, apiClient: _apiClient, screen: currentScreen, unlockPin: unlockPin);
                            lf.OnUnlockSuccess += () =>
                            {
                                // Cuando se desbloquea en uno, cerrar todos
                                DeactivateLock();
                            };

                            container.Form = lf;
                            container.Ready.Set();
                            LocalLogger.Info($"KeyBlocker: Formulario {currentIndex} creado exitosamente.");

                            Application.Run(lf);
                        }
                        catch (Exception ex)
                        {
                            LocalLogger.Error(ex, $"LockScreen thread error en pantalla {currentIndex}");
                            container.Ready.Set(); // Liberar wait aunque haya error
                        }
                    };

                    var thread = new System.Threading.Thread(ts);
                    thread.SetApartmentState(System.Threading.ApartmentState.STA);
                    thread.IsBackground = true;
                    thread.Name = $"LockScreen_{currentIndex}";
                    container.Thread = thread;
                    thread.Start();

                    // Esperar a que el form esté creado (2 segundos para dar más margen)
                    bool formReady = container.Ready.Wait(2000);
                    
                    if (!formReady)
                    {
                        LocalLogger.Warn($"KeyBlocker: Timeout esperando formulario de pantalla {currentIndex}. El formulario puede crearse tarde.");
                    }

                    // Agregar a la lista solo si el form se creó exitosamente
                    lock (_sync)
                    {
                        if (container.Form != null)
                        {
                            _lockForms.Add(container);
                            LocalLogger.Info($"KeyBlocker: Formulario {currentIndex} agregado a la lista.");
                        }
                        else if (formReady)
                        {
                            // Ready se activó pero Form es null - hubo error en creación
                            LocalLogger.Warn($"KeyBlocker: Formulario {currentIndex} no se pudo crear (Form es null).");
                        }
                        else
                        {
                            // Timeout - agregar de todas formas ya que puede estar creándose
                            _lockForms.Add(container);
                            LocalLogger.Warn($"KeyBlocker: Formulario {currentIndex} agregado a lista a pesar del timeout.");
                        }
                    }
                }

                LocalLogger.Info($"KeyBlocker: {_lockForms.Count} pantallas bloqueadas de {screenIndex} detectadas.");
                
                // IMPORTANTE: Forzar que TODOS los formularios se muestren al frente
                // Esto asegura que la pantalla donde está el mouse también se bloquee
                System.Threading.Tasks.Task.Delay(100).Wait(); // Pequeña pausa para que los forms se creen completamente
                
                lock (_sync)
                {
                    foreach (var container in _lockForms)
                    {
                        if (container.Form != null && container.Form.IsHandleCreated)
                        {
                            try
                            {
                                container.Form.Invoke(new Action(() =>
                                {
                                    container.Form.Show();
                                    container.Form.BringToFront();
                                    container.Form.Activate();
                                    container.Form.Focus();
                                }));
                                LocalLogger.Info($"KeyBlocker: Formulario activado al frente.");
                            }
                            catch (Exception activateEx)
                            {
                                LocalLogger.Warn($"KeyBlocker: No se pudo activar formulario: {activateEx.Message}");
                            }
                        }
                    }
                }
                
                LocalLogger.Info("KeyBlocker: Todos los formularios forzados al frente.");
                
                // Iniciar timer para mantener ventanas al frente cada segundo
                _keepFrontTimer = new System.Windows.Forms.Timer();
                _keepFrontTimer.Interval = 1000; // 1 segundo
                _keepFrontTimer.Tick += KeepFormsOnTop;
                _keepFrontTimer.Start();
                LocalLogger.Info("KeyBlocker: Timer de mantener ventanas al frente iniciado.");
            }
            catch (Exception ex)
            {
                LocalLogger.Error(ex, "KeyBlocker.ActivateLock(): error.");
                System.Threading.Interlocked.Exchange(ref _isActive, 0);
            }
        }

        /// <summary>
        /// Indica si el bloqueo está activo.
        /// </summary>
        public bool IsLocked()
        {
            return System.Threading.Volatile.Read(ref _isActive) == 1;
        }

        /// <summary>
        /// Desactiva bloqueo y cierra formularios.
        /// </summary>
        public void DeactivateLock()
        {
            if (System.Threading.Interlocked.Exchange(ref _isActive, 0) == 0) return;

            try
            {
                LocalLogger.Info("KeyBlocker: desactivando bloqueo.");
                
                // Detener timer de mantener ventanas al frente
                if (_keepFrontTimer != null)
                {
                    _keepFrontTimer.Stop();
                    _keepFrontTimer.Dispose();
                    _keepFrontTimer = null;
                }

                _keyboardHook?.Uninstall();
                _keyboardHook = null;

                List<LockFormContainer> copy;
                lock (_sync)
                {
                    copy = new List<LockFormContainer>(_lockForms);
                    _lockForms.Clear();
                }

                foreach (var c in copy)
                {
                    try
                    {
                        if (c?.Form != null && !c.Form.IsDisposed && c.Form.IsHandleCreated)
                        {
                            try
                            {
                                c.Form.BeginInvoke(new Action(() => c.Form.ForceClose()));
                            }
                            catch
                            {
                                // ignore
                            }
                        }
                        else
                        {
                            // si el form no está creado, intentar abortar el hilo gracilmente
                            try { if (c?.Thread != null && c.Thread.IsAlive) c.Thread.Join(200); } catch { }
                        }
                    }
                    catch (Exception ex)
                    {
                        LocalLogger.Error(ex, "KeyBlocker: error al cerrar form.");
                    }
                    finally
                    {
                        // Liberar recursos del contenedor (ManualResetEventSlim)
                        c?.Dispose();
                    }
                }
            }
            catch (Exception ex)
            {
                LocalLogger.Error(ex, "KeyBlocker.DeactivateLock(): error.");
            }
        }

        /// <summary>
        /// Evento del timer: mantiene las ventanas de bloqueo al frente.
        /// </summary>
        private void KeepFormsOnTop(object sender, EventArgs e)
        {
            lock (_sync)
            {
                foreach (var container in _lockForms)
                {
                    if (container?.Form != null && !container.Form.IsDisposed && container.Form.IsHandleCreated)
                    {
                        try
                        {
                            // Usar BeginInvoke para no bloquear el timer
                            container.Form.BeginInvoke(new Action(() =>
                            {
                                if (!container.Form.IsDisposed)
                                {
                                    container.Form.TopMost = false;
                                    container.Form.TopMost = true;
                                    container.Form.Activate();
                                }
                            }));
                        }
                        catch
                        {
                            // Ignorar errores si el form está siendo destruido
                        }
                    }
                }
            }
        }
    }

    /// <summary>
    /// Contenedor para mantener formulario de bloqueo y su hilo STA.
    /// </summary>
    internal class LockFormContainer : IDisposable
    {
        public LockScreenForm Form;
        public System.Threading.Thread Thread;
        public System.Threading.ManualResetEventSlim Ready = new System.Threading.ManualResetEventSlim(false);

        public void Dispose()
        {
            Ready?.Dispose();
        }
    }

    /// <summary>
    /// Formulario fullscreen que muestra el bloqueo y permite desbloqueo con PIN.
    /// </summary>
    internal class LockScreenForm : Form
    {
        private readonly string _reason;
        private readonly bool _allowUnlock;
        private readonly ApiClient _apiClient;
        private readonly Screen _screen;
        private readonly string _unlockPin; // PIN guardado en config.json
        private TextBox _txtPin;
        private Button _btnUnlock;
        private Label _lblStatus;
        private bool _canClose = false;

        public event Action OnUnlockSuccess;

        /// <summary>
        /// Crea formulario de bloqueo para una pantalla específica.
        /// </summary>
        public LockScreenForm(string reason, bool allowUnlock, ApiClient apiClient, Screen screen, string unlockPin = null)
        {
            _reason = reason;
            _allowUnlock = allowUnlock;
            _apiClient = apiClient;
            _screen = screen;
            _unlockPin = unlockPin; // PIN desde config.json para validación local

            InitializeComponent();
            SetupUI();
        }

        /// <summary>
        /// Configuración base del formulario (fullscreen, sin bordes).
        /// </summary>
        private void InitializeComponent()
        {
            SuspendLayout();

            // Configuración básica del form
            FormBorderStyle = FormBorderStyle.None;
            StartPosition = FormStartPosition.Manual;
            BackColor = Color.FromArgb(20, 20, 30);
            ControlBox = false;
            ShowInTaskbar = false;
            TopMost = true;
            KeyPreview = true;

            // Posicionar en la pantalla específica
            Bounds = _screen.Bounds;

            ResumeLayout(false);
        }

        /// <summary>
        /// Construye UI de bloqueo según pantalla (principal/secundaria).
        /// </summary>
        private void SetupUI()
        {
            // Determinar si es pantalla principal (para mostrar el panel)
            bool isPrimary = _screen.Primary;

            if (isPrimary)
            {
                // Solo en pantalla principal mostramos el panel de desbloqueo
                Panel panel = CreateUnlockPanel();
                Controls.Add(panel);

                // Centrar panel
                panel.Left = (Width - panel.Width) / 2;
                panel.Top = (Height - panel.Height) / 2;
            }
            else
            {
                // En pantallas secundarias solo mensaje
                Label lblSecondary = new Label
                {
                    Text = "🔒\n\nEQUIPO BLOQUEADO",
                    Font = new Font("Segoe UI", 28F, FontStyle.Bold),
                    ForeColor = Color.White,
                    TextAlign = ContentAlignment.MiddleCenter,
                    Dock = DockStyle.Fill
                };
                Controls.Add(lblSecondary);
            }
        }

        /// <summary>
        /// Crea el panel central con PIN y estado de desbloqueo.
        /// Panel responsive que se adapta al tamaño de pantalla.
        /// </summary>
        private Panel CreateUnlockPanel()
        {
            // Hacer panel responsive según tamaño de pantalla
            // Máximo 550x450, pero se ajusta a 80% ancho y 70% alto en pantallas pequeñas
            int panelWidth = Math.Min(550, (int)(_screen.WorkingArea.Width * 0.8));
            int panelHeight = Math.Min(450, (int)(_screen.WorkingArea.Height * 0.7));
            
            // Calcular factores de escala para ajustar controles proporcionalmente
            float scaleX = panelWidth / 550f;
            float scaleY = panelHeight / 450f;
            float scale = Math.Min(scaleX, scaleY); // Escala uniforme para mantener proporciones
            
            Panel panel = new Panel
            {
                Width = panelWidth,
                Height = panelHeight,
                BackColor = Color.FromArgb(35, 35, 45)
            };

            // Icono (escalado)
            Label lblIcon = new Label
            {
                Text = "🔒",
                Font = new Font("Segoe UI Emoji", 60F * scale, FontStyle.Bold),
                ForeColor = Color.FromArgb(231, 76, 60),
                AutoSize = true,
                Location = new Point((int)(215 * scaleX), (int)(40 * scaleY))
            };
            panel.Controls.Add(lblIcon);

            // Título (escalado)
            Label lblTitle = new Label
            {
                Text = "EQUIPO BLOQUEADO",
                Font = new Font("Segoe UI", 22F * scale, FontStyle.Bold),
                ForeColor = Color.White,
                Width = (int)(500 * scaleX),
                TextAlign = ContentAlignment.MiddleCenter,
                Location = new Point((int)(25 * scaleX), (int)(140 * scaleY))
            };
            panel.Controls.Add(lblTitle);

            // Razón (escalado)
            Label lblReason = new Label
            {
                Text = _reason,
                Font = new Font("Segoe UI", 11F * scale),
                ForeColor = Color.FromArgb(189, 195, 199),
                Width = (int)(500 * scaleX),
                Height = (int)(80 * scaleY),
                TextAlign = ContentAlignment.MiddleCenter,
                Location = new Point((int)(25 * scaleX), (int)(200 * scaleY))
            };
            panel.Controls.Add(lblReason);

            if (_allowUnlock)
            {
                // Label PIN (escalado)
                Label lblPin = new Label
                {
                    Text = "PIN de IT:",
                    Font = new Font("Segoe UI", 11F * scale, FontStyle.Bold),
                    ForeColor = Color.White,
                    AutoSize = true,
                    Location = new Point((int)(100 * scaleX), (int)(305 * scaleY))
                };
                panel.Controls.Add(lblPin);

                // TextBox PIN (escalado)
                _txtPin = new TextBox
                {
                    Font = new Font("Consolas", 14F * scale),
                    Width = (int)(200 * scaleX),
                    UseSystemPasswordChar = true,
                    BackColor = Color.FromArgb(52, 73, 94),
                    ForeColor = Color.White,
                    BorderStyle = BorderStyle.FixedSingle,
                    TextAlign = HorizontalAlignment.Center,
                    Location = new Point((int)(200 * scaleX), (int)(302 * scaleY))
                };
                _txtPin.KeyDown += (s, e) =>
                {
                    if (e.KeyCode == Keys.Enter)
                    {
                        e.Handled = true;
                        e.SuppressKeyPress = true;
                        TryUnlock();
                    }
                };
                panel.Controls.Add(_txtPin);

                // Botón desbloquear (escalado)
                _btnUnlock = new Button
                {
                    Text = "🔓 Desbloquear",
                    Font = new Font("Segoe UI", 11F * scale, FontStyle.Bold),
                    Width = (int)(200 * scaleX),
                    Height = (int)(40 * scaleY),
                    BackColor = Color.FromArgb(52, 152, 219),
                    ForeColor = Color.White,
                    FlatStyle = FlatStyle.Flat,
                    Location = new Point((int)(175 * scaleX), (int)(350 * scaleY))
                };
                _btnUnlock.FlatAppearance.BorderSize = 0;
                _btnUnlock.Click += (s, e) => TryUnlock();
                panel.Controls.Add(_btnUnlock);

                // Status (escalado)
                _lblStatus = new Label
                {
                    Text = "",
                    Font = new Font("Segoe UI", 10F * scale, FontStyle.Bold),
                    ForeColor = Color.FromArgb(231, 76, 60),
                    Width = (int)(500 * scaleX),
                    Height = (int)(30 * scaleY),
                    TextAlign = ContentAlignment.MiddleCenter,
                    Location = new Point((int)(25 * scaleX), (int)(405 * scaleY))
                };
                panel.Controls.Add(_lblStatus);
            }
            else
            {
                Label lblNoUnlock = new Label
                {
                    Text = "Este equipo ha sido bloqueado por temas de seguridad. \n\n Contacta al Director de IT o a tu jefe inmediato.",
                    Font = new Font("Segoe UI", 11F * scale, FontStyle.Bold),
                    ForeColor = Color.FromArgb(231, 76, 60),
                    Width = (int)(500 * scaleX),
                    Height = (int)(80 * scaleY),
                    TextAlign = ContentAlignment.MiddleCenter,
                    Location = new Point((int)(25 * scaleX), (int)(320 * scaleY))
                };
                panel.Controls.Add(lblNoUnlock);
            }

            return panel;
        }

        /// <summary>
        /// Intenta desbloquear comparando PIN local y notifica al servidor.
        /// </summary>
        private async void TryUnlock()
        {
            if (_txtPin == null) return;

            string pin = _txtPin.Text.Trim();
            if (string.IsNullOrEmpty(pin))
            {
                _lblStatus.Text = "❌ Ingresa el PIN";
                return;
            }

            _btnUnlock.Enabled = false;
            _txtPin.Enabled = false;
            _lblStatus.Text = "⏳ Verificando...";
            _lblStatus.ForeColor = Color.FromArgb(241, 196, 15);

            try
            {
                // Validación LOCAL: comparar con PIN en config.json
                LocalLogger.Info($"TryUnlock: Validando. PIN recibido='{pin}', PIN guardado='{(_unlockPin ?? "NULL")}'");
                
                if (!string.IsNullOrEmpty(_unlockPin) && pin == _unlockPin)
                {
                    // PIN correcto localmente
                    LocalLogger.Info("TryUnlock: PIN validado correctamente");
                    _lblStatus.Text = "✅ Desbloqueado";
                    _lblStatus.ForeColor = Color.FromArgb(46, 204, 113);
                    await System.Threading.Tasks.Task.Delay(500);

                    // Notificar al servidor (sin validar respuesta)
                    try
                    {
                        await _apiClient.PostAsync("client/device-lock/unlock", new { Pin = pin });
                        LocalLogger.Info("TryUnlock: Servidor notificado exitosamente del desbloqueo.");
                    }
                    catch (System.Net.Http.HttpRequestException httpEx)
                    {
                        LocalLogger.Warn($"TryUnlock: Error de red al notificar servidor: {httpEx.Message}. Desbloqueando localmente de todas formas.");
                    }
                    catch (TaskCanceledException timeoutEx)
                    {
                        LocalLogger.Warn($"TryUnlock: Timeout al notificar servidor: {timeoutEx.Message}. Desbloqueando localmente de todas formas.");
                    }
                    catch (Exception ex)
                    {
                        LocalLogger.Warn($"TryUnlock: No se pudo notificar al servidor: {ex.Message}. Desbloqueando localmente de todas formas.");
                    }

                    OnUnlockSuccess?.Invoke();
                }
                else
                {
                    // PIN incorrecto
                    LocalLogger.Warn($"TryUnlock: PIN incorrecto. Recibido='{pin}', Guardado='{(_unlockPin ?? "NULL")}'");
                    _lblStatus.Text = "❌ PIN incorrecto";
                    _lblStatus.ForeColor = Color.FromArgb(231, 76, 60);
                    _txtPin.Clear();
                    _btnUnlock.Enabled = true;
                    _txtPin.Enabled = true;
                    _txtPin.Focus();
                }
            }
            catch (Exception ex)
            {
                _lblStatus.Text = "⚠️ Error de validación";
                _lblStatus.ForeColor = Color.FromArgb(230, 126, 34);
                LocalLogger.Error(ex, "LockScreenForm.TryUnlock(): error.");
                _btnUnlock.Enabled = true;
                _txtPin.Enabled = true;
            }
        }

        /// <summary>
        /// Fuerza el cierre del formulario (usado por KeyBlocker).
        /// </summary>
        public void ForceClose()
        {
            _canClose = true;
            Close();
        }

        /// <summary>
        /// Evita cerrar por el usuario si el bloqueo sigue activo.
        /// </summary>
        protected override void OnFormClosing(FormClosingEventArgs e)
        {
            if (!_canClose && e.CloseReason == CloseReason.UserClosing)
            {
                e.Cancel = true;
            }
            base.OnFormClosing(e);
        }

        /// <summary>
        /// Enfoca el PIN al mostrar el formulario y fuerza ventana al frente.
        /// </summary>
        protected override void OnShown(EventArgs e)
        {
            base.OnShown(e);
            
            // Forzar ventana al frente de manera agresiva
            TopMost = false;
            TopMost = true;
            BringToFront();
            Activate();
            Focus();
            
            if (_txtPin != null)
            {
                _txtPin.Focus();
            }
        }
    }

    /// <summary>
    /// Hook de teclado de bajo nivel para bloquear combinaciones (Win/Alt+Tab).
    /// </summary>
    internal class LowLevelKeyboardHook
    {
        private const int WH_KEYBOARD_LL = 13;
        private const int WM_KEYDOWN = 0x0100;
        private const int WM_SYSKEYDOWN = 0x0104;
        private IntPtr _hookID = IntPtr.Zero;
        private LowLevelKeyboardProc _proc;
        private delegate IntPtr LowLevelKeyboardProc(int nCode, IntPtr wParam, IntPtr lParam);

        /// <summary>
        /// Instala el hook global.
        /// </summary>
        public void Install()
        {
            _proc = HookCallback;
            _hookID = SetHook(_proc);
            LocalLogger.Info("KeyboardHook: instalado.");
        }

        /// <summary>
        /// Desinstala el hook global.
        /// </summary>
        public void Uninstall()
        {
            if (_hookID != IntPtr.Zero)
            {
                UnhookWindowsHookEx(_hookID);
                _hookID = IntPtr.Zero;
                LocalLogger.Info("KeyboardHook: desinstalado.");
            }
        }

        /// <summary>
        /// Registra el hook con Win32 (fallback si MainModule no está disponible).
        /// </summary>
        private IntPtr SetHook(LowLevelKeyboardProc proc)
        {
            try
            {
                using (var curProcess = System.Diagnostics.Process.GetCurrentProcess())
                using (var curModule = curProcess.MainModule)
                {
                    if (curModule != null)
                        return SetWindowsHookEx(WH_KEYBOARD_LL, proc, GetModuleHandle(curModule.ModuleName), 0);
                }
            }
            catch
            {
                LocalLogger.Warn("KeyboardHook: falló obtener MainModule, intentando fallback.");
            }

            // Fallback: intentar con hMod = IntPtr.Zero
            return SetWindowsHookEx(WH_KEYBOARD_LL, proc, IntPtr.Zero, 0);
        }

        [DllImport("user32.dll")]
        private static extern short GetAsyncKeyState(int vKey);

        /// <summary>
        /// Callback del hook: bloquea Win, Alt+Tab, Ctrl+Shift+Esc y Ctrl+Alt+Del.
        /// </summary>
        private IntPtr HookCallback(int nCode, IntPtr wParam, IntPtr lParam)
        {
            try
            {
                if (nCode >= 0 && (wParam == (IntPtr)WM_KEYDOWN || wParam == (IntPtr)WM_SYSKEYDOWN))
                {
                    int vkCode = Marshal.ReadInt32(lParam);

                    // Bloquear teclas de Windows siempre
                    if (vkCode == (int)Keys.LWin || vkCode == (int)Keys.RWin)
                    {
                        return (IntPtr)1;
                    }

                    // Para Tab solo bloquear si Alt está presionado (Alt+Tab)
                    if (vkCode == (int)Keys.Tab)
                    {
                        bool altDown = (GetAsyncKeyState((int)Keys.Menu) & 0x8000) != 0;
                        if (altDown)
                            return (IntPtr)1;
                    }

                    // Bloquear Ctrl+Shift+Esc (Task Manager)
                    if (vkCode == (int)Keys.Escape)
                    {
                        bool ctrlDown = (GetAsyncKeyState((int)Keys.ControlKey) & 0x8000) != 0;
                        bool shiftDown = (GetAsyncKeyState((int)Keys.ShiftKey) & 0x8000) != 0;
                        if (ctrlDown && shiftDown)
                            return (IntPtr)1;
                    }

                    // Bloquear Ctrl+Alt+Del (intentar - no siempre funciona por ser Secure Attention Sequence)
                    if (vkCode == (int)Keys.Delete)
                    {
                        bool ctrlDown = (GetAsyncKeyState((int)Keys.ControlKey) & 0x8000) != 0;
                        bool altDown = (GetAsyncKeyState((int)Keys.Menu) & 0x8000) != 0;
                        if (ctrlDown && altDown)
                            return (IntPtr)1;
                    }
                }
            }
            catch
            {
                // no romper hook en caso de error
            }

            return CallNextHookEx(_hookID, nCode, wParam, lParam);
        }

        [DllImport("user32.dll", CharSet = CharSet.Auto, SetLastError = true)]
        private static extern IntPtr SetWindowsHookEx(int idHook, LowLevelKeyboardProc lpfn, IntPtr hMod, uint dwThreadId);
        [DllImport("user32.dll", CharSet = CharSet.Auto, SetLastError = true)]
        [return: MarshalAs(UnmanagedType.Bool)]
        private static extern bool UnhookWindowsHookEx(IntPtr hhk);
        [DllImport("user32.dll", CharSet = CharSet.Auto, SetLastError = true)]
        private static extern IntPtr CallNextHookEx(IntPtr hhk, int nCode, IntPtr wParam, IntPtr lParam);
        [DllImport("kernel32.dll", CharSet = CharSet.Auto, SetLastError = true)]
        private static extern IntPtr GetModuleHandle(string lpModuleName);
    }
}