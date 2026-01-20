using System;
using System.Collections.Generic;
using System.Drawing;
using System.Runtime.InteropServices;
using System.Threading;
using System.Windows.Forms;
using AZCKeeper_Cliente.Logging;
using AZCKeeper_Cliente.Network;

namespace AZCKeeper_Cliente.Blocking
{
    internal class KeyBlocker
    {
        private List<LockScreenForm> _lockForms = new List<LockScreenForm>();
        private readonly ApiClient _apiClient;
        private LowLevelKeyboardHook _keyboardHook;
        private Thread _lockThread;
        private bool _isLocking = false;

        public KeyBlocker(ApiClient apiClient)
        {
            _apiClient = apiClient;
        }

        public void ActivateLock(string reason, bool allowUnlock)
        {
            try
            {
                if (_isLocking)
                {
                    LocalLogger.Warn("KeyBlocker: ya hay un bloqueo activo.");
                    return;
                }

                LocalLogger.Warn($"KeyBlocker: activando bloqueo en todas las pantallas. Motivo: {reason}");
                _isLocking = true;

                // Hook de teclado en thread principal
                _keyboardHook = new LowLevelKeyboardHook();
                _keyboardHook.Install();

                // Crear formularios en thread separado para evitar bloqueo
                _lockThread = new Thread(() =>
                {
                    try
                    {
                        foreach (var screen in Screen.AllScreens)
                        {
                            var lockForm = new LockScreenForm(reason, allowUnlock, _apiClient);
                            lockForm.StartPosition = FormStartPosition.Manual;
                            lockForm.Location = screen.Bounds.Location;
                            lockForm.Size = screen.Bounds.Size;
                            lockForm.OnUnlockSuccess += () =>
                            {
                                _isLocking = false;
                                DeactivateLock();
                            };

                            // Mostrar sin bloquear el thread
                            lockForm.Show();
                            _lockForms.Add(lockForm);
                        }

                        // Mantener el thread vivo para que los forms funcionen
                        Application.Run();
                    }
                    catch (Exception ex)
                    {
                        LocalLogger.Error(ex, "KeyBlocker: error en thread de bloqueo.");
                    }
                });

                _lockThread.SetApartmentState(ApartmentState.STA);
                _lockThread.IsBackground = false;
                _lockThread.Start();
            }
            catch (Exception ex)
            {
                LocalLogger.Error(ex, "KeyBlocker.ActivateLock(): error.");
                _isLocking = false;
            }
        }

        public void DeactivateLock()
        {
            try
            {
                LocalLogger.Info("KeyBlocker: desactivando bloqueo.");
                _isLocking = false;

                _keyboardHook?.Uninstall();
                _keyboardHook = null;

                // Cerrar formularios de forma segura
                foreach (var form in _lockForms.ToArray())
                {
                    try
                    {
                        if (form != null && !form.IsDisposed)
                        {
                            if (form.InvokeRequired)
                            {
                                form.Invoke(new Action(() =>
                                {
                                    form.Close();
                                    form.Dispose();
                                }));
                            }
                            else
                            {
                                form.Close();
                                form.Dispose();
                            }
                        }
                    }
                    catch (Exception ex)
                    {
                        LocalLogger.Error(ex, "KeyBlocker: error al cerrar form.");
                    }
                }
                _lockForms.Clear();

                // Terminar thread
                if (_lockThread != null && _lockThread.IsAlive)
                {
                    try
                    {
                        _lockThread.Abort();
                    }
                    catch { }
                    _lockThread = null;
                }
            }
            catch (Exception ex)
            {
                LocalLogger.Error(ex, "KeyBlocker.DeactivateLock(): error.");
            }
        }
    }

    internal class LockScreenForm : Form
    {
        private readonly string _reason;
        private readonly bool _allowUnlock;
        private readonly ApiClient _apiClient;
        private TextBox _txtPin;
        private Button _btnUnlock;
        private Label _lblStatus;
        private System.Windows.Forms.Timer _topMostTimer;

        public event Action OnUnlockSuccess;

        public LockScreenForm(string reason, bool allowUnlock, ApiClient apiClient)
        {
            _reason = reason;
            _allowUnlock = allowUnlock;
            _apiClient = apiClient;

            InitializeUI();

            // Timer para mantener siempre encima
            _topMostTimer = new System.Windows.Forms.Timer { Interval = 200 };
            _topMostTimer.Tick += (s, e) =>
            {
                if (!TopMost) TopMost = true;
                BringToFront();
            };
            _topMostTimer.Start();
        }

        private void InitializeUI()
        {
            FormBorderStyle = FormBorderStyle.None;
            WindowState = FormWindowState.Maximized;
            TopMost = true;
            BackColor = Color.FromArgb(20, 20, 30);
            ControlBox = false;
            ShowInTaskbar = false;
            KeyPreview = true;

            var panel = new Panel
            {
                Size = new Size(500, 400),
                BackColor = Color.FromArgb(30, 30, 40),
                BorderStyle = BorderStyle.FixedSingle
            };
            panel.Location = new Point((Width - panel.Width) / 2, (Height - panel.Height) / 2);

            var lblIcon = new Label
            {
                Text = "🔒",
                Font = new Font("Segoe UI Emoji", 48F, FontStyle.Bold),
                ForeColor = Color.FromArgb(220, 50, 50),
                AutoSize = true
            };
            lblIcon.Location = new Point((panel.Width - 100) / 2, 30);

            var lblTitle = new Label
            {
                Text = "EQUIPO BLOQUEADO",
                Font = new Font("Segoe UI", 20F, FontStyle.Bold),
                ForeColor = Color.White,
                Width = 450,
                TextAlign = ContentAlignment.MiddleCenter
            };
            lblTitle.Location = new Point((panel.Width - lblTitle.Width) / 2, 120);

            var lblReason = new Label
            {
                Text = _reason,
                Font = new Font("Segoe UI", 11F),
                ForeColor = Color.LightGray,
                Width = 450,
                Height = 100,
                TextAlign = ContentAlignment.MiddleCenter
            };
            lblReason.Location = new Point((panel.Width - lblReason.Width) / 2, 170);

            panel.Controls.Add(lblIcon);
            panel.Controls.Add(lblTitle);
            panel.Controls.Add(lblReason);

            if (_allowUnlock)
            {
                var lblPin = new Label
                {
                    Text = "PIN de IT:",
                    Font = new Font("Segoe UI", 11F),
                    ForeColor = Color.White,
                    AutoSize = true
                };
                lblPin.Location = new Point(80, 280);

                _txtPin = new TextBox
                {
                    Font = new Font("Consolas", 13F),
                    Width = 200,
                    UseSystemPasswordChar = true,
                    BackColor = Color.FromArgb(40, 40, 50),
                    ForeColor = Color.White,
                    BorderStyle = BorderStyle.FixedSingle
                };
                _txtPin.Location = new Point(180, 278);
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
                    Font = new Font("Segoe UI", 11F, FontStyle.Bold),
                    Width = 140,
                    Height = 40,
                    BackColor = Color.FromArgb(50, 120, 200),
                    ForeColor = Color.White,
                    FlatStyle = FlatStyle.Flat
                };
                _btnUnlock.FlatAppearance.BorderSize = 0;
                _btnUnlock.Location = new Point((panel.Width - _btnUnlock.Width) / 2, 320);
                _btnUnlock.Click += (s, e) => TryUnlock();

                _lblStatus = new Label
                {
                    Text = "",
                    Font = new Font("Segoe UI", 9F),
                    ForeColor = Color.FromArgb(220, 50, 50),
                    Width = 450,
                    Height = 25,
                    TextAlign = ContentAlignment.MiddleCenter
                };
                _lblStatus.Location = new Point((panel.Width - _lblStatus.Width) / 2, 370);

                panel.Controls.Add(lblPin);
                panel.Controls.Add(_txtPin);
                panel.Controls.Add(_btnUnlock);
                panel.Controls.Add(_lblStatus);
            }
            else
            {
                var lblNoUnlock = new Label
                {
                    Text = "Este equipo no puede ser desbloqueado.\nContacta al administrador de IT.",
                    Font = new Font("Segoe UI", 10F),
                    ForeColor = Color.FromArgb(220, 50, 50),
                    Width = 450,
                    Height = 60,
                    TextAlign = ContentAlignment.MiddleCenter
                };
                lblNoUnlock.Location = new Point((panel.Width - lblNoUnlock.Width) / 2, 300);
                panel.Controls.Add(lblNoUnlock);
            }

            Controls.Add(panel);
        }

        private async void TryUnlock()
        {
            string pin = _txtPin.Text.Trim();
            if (string.IsNullOrEmpty(pin))
            {
                _lblStatus.Text = "❌ Ingresa el PIN";
                return;
            }

            _btnUnlock.Enabled = false;
            _txtPin.Enabled = false;
            _lblStatus.Text = "⏳ Verificando...";
            _lblStatus.ForeColor = Color.Yellow;

            try
            {
                var response = await _apiClient.PostAsync("client/device-lock/unlock", new { Pin = pin });

                if (response != null && response.Contains("\"ok\":true"))
                {
                    _lblStatus.Text = "✓ Desbloqueado correctamente";
                    _lblStatus.ForeColor = Color.LightGreen;
                    await System.Threading.Tasks.Task.Delay(1000);

                    _topMostTimer?.Stop();
                    OnUnlockSuccess?.Invoke();
                }
                else
                {
                    _lblStatus.Text = "✗ PIN incorrecto";
                    _lblStatus.ForeColor = Color.FromArgb(220, 50, 50);
                    _txtPin.Clear();
                    _txtPin.Focus();
                    _btnUnlock.Enabled = true;
                    _txtPin.Enabled = true;
                }
            }
            catch (Exception ex)
            {
                _lblStatus.Text = "⚠️ Error de conexión con servidor";
                _lblStatus.ForeColor = Color.Orange;
                LocalLogger.Error(ex, "LockScreenForm.TryUnlock(): error.");
                _btnUnlock.Enabled = true;
                _txtPin.Enabled = true;
            }
        }

        protected override void OnFormClosing(FormClosingEventArgs e)
        {
            if (e.CloseReason == CloseReason.UserClosing)
            {
                e.Cancel = true;
            }
            else
            {
                _topMostTimer?.Stop();
                _topMostTimer?.Dispose();
            }
            base.OnFormClosing(e);
        }

        // Bloquear Alt+F4
        protected override void OnKeyDown(KeyEventArgs e)
        {
            if (e.Alt && e.KeyCode == Keys.F4)
            {
                e.Handled = true;
            }
            base.OnKeyDown(e);
        }
    }

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

                // Solo bloquear teclas específicas del sistema
                if (vkCode == (int)Keys.LWin || vkCode == (int)Keys.RWin ||  // Windows key
                    vkCode == (int)Keys.F4)                                    // Alt+F4 (se maneja combinado)
                {
                    return (IntPtr)1; // Bloquear
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