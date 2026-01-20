using System;
using System.Drawing;
using System.Runtime.InteropServices;
using System.Windows.Forms;
using AZCKeeper_Cliente.Logging;
using AZCKeeper_Cliente.Network;

namespace AZCKeeper_Cliente.Blocking
{
    internal class KeyBlocker
    {
        private LockScreenForm _lockForm;
        private readonly ApiClient _apiClient;
        private LowLevelKeyboardHook _keyboardHook;

        public KeyBlocker(ApiClient apiClient)
        {
            _apiClient = apiClient;
        }

        public void ActivateLock(string reason, bool allowUnlock)
        {
            try
            {
                if (_lockForm != null && !_lockForm.IsDisposed)
                {
                    LocalLogger.Warn("KeyBlocker: ya hay un bloqueo activo.");
                    return;
                }

                LocalLogger.Warn($"KeyBlocker: activando bloqueo. Motivo: {reason}");

                // Bloquear teclas del sistema
                _keyboardHook = new LowLevelKeyboardHook();
                _keyboardHook.Install();

                // Mostrar pantalla de bloqueo
                _lockForm = new LockScreenForm(reason, allowUnlock, _apiClient);
                _lockForm.OnUnlockSuccess += () =>
                {
                    DeactivateLock();
                };

                _lockForm.ShowDialog();
            }
            catch (Exception ex)
            {
                LocalLogger.Error(ex, "KeyBlocker.ActivateLock(): error.");
            }
        }

        public void DeactivateLock()
        {
            try
            {
                LocalLogger.Info("KeyBlocker: desactivando bloqueo.");

                _keyboardHook?.Uninstall();
                _keyboardHook = null;

                if (_lockForm != null && !_lockForm.IsDisposed)
                {
                    _lockForm.Close();
                    _lockForm = null;
                }
            }
            catch (Exception ex)
            {
                LocalLogger.Error(ex, "KeyBlocker.DeactivateLock(): error.");
            }
        }
    }

    // ========== PANTALLA DE BLOQUEO ==========
    internal class LockScreenForm : Form
    {
        private readonly string _reason;
        private readonly bool _allowUnlock;
        private readonly ApiClient _apiClient;

        private TextBox _txtPin;
        private Button _btnUnlock;
        private Label _lblStatus;

        public event Action OnUnlockSuccess;

        public LockScreenForm(string reason, bool allowUnlock, ApiClient apiClient)
        {
            _reason = reason;
            _allowUnlock = allowUnlock;
            _apiClient = apiClient;

            InitializeUI();
        }

        private void InitializeUI()
        {
            // Fullscreen sin bordes
            FormBorderStyle = FormBorderStyle.None;
            WindowState = FormWindowState.Maximized;
            TopMost = true;
            BackColor = Color.FromArgb(20, 20, 30);
            StartPosition = FormStartPosition.Manual;
            Location = new Point(0, 0);
            Size = Screen.PrimaryScreen.Bounds.Size;

            // Deshabilitar Alt+F4
            ControlBox = false;
            ShowInTaskbar = false;

            var panel = new Panel
            {
                Size = new Size(500, 350),
                BackColor = Color.FromArgb(30, 30, 40),
                BorderStyle = BorderStyle.FixedSingle
            };
            panel.Location = new Point(
                (Width - panel.Width) / 2,
                (Height - panel.Height) / 2
            );

            // Icono de advertencia
            var lblIcon = new Label
            {
                Text = "🔒",
                Font = new Font("Segoe UI Emoji", 48F, FontStyle.Bold),
                ForeColor = Color.FromArgb(220, 50, 50),
                AutoSize = true,
                TextAlign = ContentAlignment.MiddleCenter
            };
            lblIcon.Location = new Point((panel.Width - lblIcon.Width) / 2, 20);

            // Título
            var lblTitle = new Label
            {
                Text = "EQUIPO BLOQUEADO",
                Font = new Font("Segoe UI", 18F, FontStyle.Bold),
                ForeColor = Color.White,
                AutoSize = true,
                TextAlign = ContentAlignment.MiddleCenter
            };
            lblTitle.Location = new Point((panel.Width - 300) / 2, 100);
            lblTitle.Width = 300;

            // Mensaje
            var lblReason = new Label
            {
                Text = _reason,
                Font = new Font("Segoe UI", 10F),
                ForeColor = Color.LightGray,
                AutoSize = false,
                TextAlign = ContentAlignment.MiddleCenter,
                Width = 450,
                Height = 80
            };
            lblReason.Location = new Point((panel.Width - lblReason.Width) / 2, 150);

            panel.Controls.Add(lblIcon);
            panel.Controls.Add(lblTitle);
            panel.Controls.Add(lblReason);

            // Solo mostrar input de PIN si está permitido
            if (_allowUnlock)
            {
                var lblPin = new Label
                {
                    Text = "PIN de IT:",
                    Font = new Font("Segoe UI", 10F),
                    ForeColor = Color.White,
                    AutoSize = true
                };
                lblPin.Location = new Point(75, 240);

                _txtPin = new TextBox
                {
                    Font = new Font("Consolas", 12F),
                    Width = 200,
                    UseSystemPasswordChar = true,
                    BackColor = Color.FromArgb(40, 40, 50),
                    ForeColor = Color.White,
                    BorderStyle = BorderStyle.FixedSingle
                };
                _txtPin.Location = new Point(160, 238);
                _txtPin.KeyPress += (s, e) =>
                {
                    if (e.KeyChar == (char)Keys.Enter)
                    {
                        e.Handled = true;
                        TryUnlock();
                    }
                };

                _btnUnlock = new Button
                {
                    Text = "Desbloquear",
                    Font = new Font("Segoe UI", 10F, FontStyle.Bold),
                    Width = 120,
                    Height = 35,
                    BackColor = Color.FromArgb(50, 120, 200),
                    ForeColor = Color.White,
                    FlatStyle = FlatStyle.Flat
                };
                _btnUnlock.FlatAppearance.BorderSize = 0;
                _btnUnlock.Location = new Point((panel.Width - _btnUnlock.Width) / 2, 280);
                _btnUnlock.Click += (s, e) => TryUnlock();

                _lblStatus = new Label
                {
                    Text = "",
                    Font = new Font("Segoe UI", 9F),
                    ForeColor = Color.FromArgb(220, 50, 50),
                    AutoSize = false,
                    TextAlign = ContentAlignment.MiddleCenter,
                    Width = 400,
                    Height = 20
                };
                _lblStatus.Location = new Point((panel.Width - _lblStatus.Width) / 2, 320);

                panel.Controls.Add(lblPin);
                panel.Controls.Add(_txtPin);
                panel.Controls.Add(_btnUnlock);
                panel.Controls.Add(_lblStatus);
            }

            Controls.Add(panel);
        }

        private async void TryUnlock()
        {
            string pin = _txtPin.Text.Trim();

            if (string.IsNullOrEmpty(pin))
            {
                _lblStatus.Text = "Ingresa el PIN";
                return;
            }

            _btnUnlock.Enabled = false;
            _lblStatus.Text = "Verificando...";
            _lblStatus.ForeColor = Color.Yellow;

            try
            {
                var payload = new { Pin = pin };
                string json = System.Text.Json.JsonSerializer.Serialize(payload);

                using var content = new System.Net.Http.StringContent(json, System.Text.Encoding.UTF8, "application/json");
                using var request = new System.Net.Http.HttpRequestMessage(System.Net.Http.HttpMethod.Post, "client/device-lock/unlock")
                {
                    Content = content
                };

                // Aquí necesitarías acceso al HttpClient del ApiClient
                // Por simplificar, asume que ApiClient tiene un método público para esto
                var response = await _apiClient.PostAsync("client/device-lock/unlock", payload);

                if (response != null && response.Contains("\"ok\":true"))
                {
                    _lblStatus.Text = "✓ Desbloqueado";
                    _lblStatus.ForeColor = Color.LightGreen;
                    await System.Threading.Tasks.Task.Delay(1000);
                    OnUnlockSuccess?.Invoke();
                }
                else
                {
                    _lblStatus.Text = "✗ PIN incorrecto";
                    _lblStatus.ForeColor = Color.FromArgb(220, 50, 50);
                    _txtPin.Clear();
                    _btnUnlock.Enabled = true;
                }
            }
            catch (Exception ex)
            {
                _lblStatus.Text = "Error de conexión";
                LocalLogger.Error(ex, "LockScreenForm.TryUnlock(): error.");
                _btnUnlock.Enabled = true;
            }
        }

        protected override void OnFormClosing(FormClosingEventArgs e)
        {
            // Evitar que el usuario cierre la ventana
            if (e.CloseReason == CloseReason.UserClosing)
            {
                e.Cancel = true;
            }
            base.OnFormClosing(e);
        }
    }

    // ========== HOOK DE TECLADO DE BAJO NIVEL ==========
    internal class LowLevelKeyboardHook
    {
        private const int WH_KEYBOARD_LL = 13;
        private const int WM_KEYDOWN = 0x0100;
        private IntPtr _hookID = IntPtr.Zero;
        private LowLevelKeyboardProc _proc;

        private delegate IntPtr LowLevelKeyboardProc(int nCode, IntPtr wParam, IntPtr lParam);

        public void Install()
        {
            _proc = HookCallback;
            _hookID = SetHook(_proc);
        }

        public void Uninstall()
        {
            if (_hookID != IntPtr.Zero)
            {
                UnhookWindowsHookEx(_hookID);
                _hookID = IntPtr.Zero;
            }
        }

        private IntPtr SetHook(LowLevelKeyboardProc proc)
        {
            using (var curProcess = System.Diagnostics.Process.GetCurrentProcess())
            using (var curModule = curProcess.MainModule)
            {
                return SetWindowsHookEx(WH_KEYBOARD_LL, proc, GetModuleHandle(curModule.ModuleName), 0);
            }
        }

        private IntPtr HookCallback(int nCode, IntPtr wParam, IntPtr lParam)
        {
            if (nCode >= 0 && wParam == (IntPtr)WM_KEYDOWN)
            {
                int vkCode = Marshal.ReadInt32(lParam);

                // Bloquear teclas peligrosas
                if (vkCode == (int)Keys.LWin || vkCode == (int)Keys.RWin ||      // Windows key
                    vkCode == (int)Keys.Escape ||                                 // ESC
                    vkCode == (int)Keys.F4 ||                                     // Alt+F4
                    (vkCode >= (int)Keys.F1 && vkCode <= (int)Keys.F12) ||       // F1-F12
                    vkCode == (int)Keys.LControlKey || vkCode == (int)Keys.RControlKey || // Ctrl
                    vkCode == (int)Keys.LMenu || vkCode == (int)Keys.RMenu ||     // Alt
                    vkCode == (int)Keys.Tab)                                       // Tab (Ctrl+Alt+Del)
                {
                    return (IntPtr)1; // Bloquear tecla
                }
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