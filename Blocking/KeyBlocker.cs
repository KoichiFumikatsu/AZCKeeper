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
                foreach (Screen screen in Screen.AllScreens)
                {
                    var container = new LockFormContainer();

                    ThreadStart ts = () =>
                    {
                        try
                        {
                            var lf = new LockScreenForm(reason, allowUnlock, apiClient: _apiClient, screen: screen, unlockPin: unlockPin);
                            lf.OnUnlockSuccess += () =>
                            {
                                // Cuando se desbloquea en uno, cerrar todos
                                DeactivateLock();
                            };

                            container.Form = lf;
                            container.Ready.Set();

                            Application.Run(lf);
                        }
                        catch (Exception ex)
                        {
                            LocalLogger.Error(ex, "LockScreen thread error");
                        }
                    };

                    var thread = new System.Threading.Thread(ts);
                    thread.SetApartmentState(System.Threading.ApartmentState.STA);
                    thread.IsBackground = true;
                    container.Thread = thread;
                    thread.Start();

                    // Esperar a que el form esté creado antes de continuar
                    container.Ready.Wait(5000);

                    lock (_sync)
                    {
                        _lockForms.Add(container);
                    }
                }

                LocalLogger.Info($"KeyBlocker: {_lockForms.Count} pantallas bloqueadas.");
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
                }
            }
            catch (Exception ex)
            {
                LocalLogger.Error(ex, "KeyBlocker.DeactivateLock(): error.");
            }
        }
    }

    /// <summary>
    /// Contenedor para mantener formulario de bloqueo y su hilo STA.
    /// </summary>
    internal class LockFormContainer
    {
        public LockScreenForm Form;
        public System.Threading.Thread Thread;
        public System.Threading.ManualResetEventSlim Ready = new System.Threading.ManualResetEventSlim(false);
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
        /// </summary>
        private Panel CreateUnlockPanel()
        {
            Panel panel = new Panel
            {
                Width = 550,
                Height = 450,
                BackColor = Color.FromArgb(35, 35, 45)
            };

            // Icono
            Label lblIcon = new Label
            {
                Text = "🔒",
                Font = new Font("Segoe UI Emoji", 60F, FontStyle.Bold),
                ForeColor = Color.FromArgb(231, 76, 60),
                AutoSize = true,
                Location = new Point(215, 40)
            };
            panel.Controls.Add(lblIcon);

            // Título
            Label lblTitle = new Label
            {
                Text = "EQUIPO BLOQUEADO",
                Font = new Font("Segoe UI", 22F, FontStyle.Bold),
                ForeColor = Color.White,
                Width = 500,
                TextAlign = ContentAlignment.MiddleCenter,
                Location = new Point(25, 140)
            };
            panel.Controls.Add(lblTitle);

            // Razón
            Label lblReason = new Label
            {
                Text = _reason,
                Font = new Font("Segoe UI", 11F),
                ForeColor = Color.FromArgb(189, 195, 199),
                Width = 500,
                Height = 80,
                TextAlign = ContentAlignment.MiddleCenter,
                Location = new Point(25, 200)
            };
            panel.Controls.Add(lblReason);

            if (_allowUnlock)
            {
                // Label PIN
                Label lblPin = new Label
                {
                    Text = "PIN de IT:",
                    Font = new Font("Segoe UI", 11F, FontStyle.Bold),
                    ForeColor = Color.White,
                    AutoSize = true,
                    Location = new Point(100, 305)
                };
                panel.Controls.Add(lblPin);

                // TextBox PIN
                _txtPin = new TextBox
                {
                    Font = new Font("Consolas", 14F),
                    Width = 200,
                    UseSystemPasswordChar = true,
                    BackColor = Color.FromArgb(52, 73, 94),
                    ForeColor = Color.White,
                    BorderStyle = BorderStyle.FixedSingle,
                    TextAlign = HorizontalAlignment.Center,
                    Location = new Point(200, 302)
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

                // Botón desbloquear
                _btnUnlock = new Button
                {
                    Text = "🔓 Desbloquear",
                    Font = new Font("Segoe UI", 11F, FontStyle.Bold),
                    Width = 200,
                    Height = 40,
                    BackColor = Color.FromArgb(52, 152, 219),
                    ForeColor = Color.White,
                    FlatStyle = FlatStyle.Flat,
                    Location = new Point(175, 350)
                };
                _btnUnlock.FlatAppearance.BorderSize = 0;
                _btnUnlock.Click += (s, e) => TryUnlock();
                panel.Controls.Add(_btnUnlock);

                // Status
                _lblStatus = new Label
                {
                    Text = "",
                    Font = new Font("Segoe UI", 10F, FontStyle.Bold),
                    ForeColor = Color.FromArgb(231, 76, 60),
                    Width = 500,
                    Height = 30,
                    TextAlign = ContentAlignment.MiddleCenter,
                    Location = new Point(25, 405)
                };
                panel.Controls.Add(_lblStatus);
            }
            else
            {
                Label lblNoUnlock = new Label
                {
                    Text = "Este equipo no puede ser desbloqueado.\n\nContacta al administrador de IT.",
                    Font = new Font("Segoe UI", 11F, FontStyle.Bold),
                    ForeColor = Color.FromArgb(231, 76, 60),
                    Width = 500,
                    Height = 80,
                    TextAlign = ContentAlignment.MiddleCenter,
                    Location = new Point(25, 320)
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
                    }
                    catch (Exception ex)
                    {
                        LocalLogger.Warn("No se pudo notificar al servidor, pero desbloqueando localmente: " + ex.Message);
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
        /// Enfoca el PIN al mostrar el formulario.
        /// </summary>
        protected override void OnShown(EventArgs e)
        {
            base.OnShown(e);
            Activate();
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
            catch (Exception ex)
            {
                LocalLogger.Warn("KeyboardHook: falló obtener MainModule, intentando fallback.");
            }

            // Fallback: intentar con hMod = IntPtr.Zero
            return SetWindowsHookEx(WH_KEYBOARD_LL, proc, IntPtr.Zero, 0);
        }

        [DllImport("user32.dll")]
        private static extern short GetAsyncKeyState(int vKey);

        /// <summary>
        /// Callback del hook: bloquea Win y Alt+Tab.
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