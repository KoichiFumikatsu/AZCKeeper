using System;
using System.IO;
using System.Windows.Forms;
using ModularApp.Core;
using ModularApp.Modules.Login;

// opcional si quieres usar el tipo sin calificar en ShowConnectingUntilOnline
// using ModularApp.Modules.Connectivity;

namespace ModularApp.UI
{
    public sealed class TrayApplicationContext : ApplicationContext
    {
        private readonly AppCore _core;
        private readonly ModuleManager _manager;
        private readonly NotifyIcon _tray;
        private ToolStripMenuItem _loginItem;
        private readonly ToolStripMenuItem _statusItem;

        public TrayApplicationContext(AppCore core, ModuleManager manager)
        {
            _core = core;
            _manager = manager;

            _tray = new NotifyIcon
            {
                Icon = System.Drawing.SystemIcons.Application,
                Visible = true,
                Text = "AZC Keeper (ejecutándose)"
            };

            var menu = new ContextMenuStrip();
            _statusItem = new ToolStripMenuItem("Estado: Offline");
            _loginItem = new ToolStripMenuItem("Iniciar sesión", null, delegate { ShowLogin(); });
            var viewLogs = new ToolStripMenuItem("Ver logs", null, delegate { ShowLogs(); });
            var logout = new ToolStripMenuItem("Cerrar sesión", null, delegate { DoLogout(); });

            menu.Items.Add(_statusItem);
            menu.Items.Insert(1, _loginItem);
            menu.Items.Add(viewLogs);
            menu.Items.Add(new ToolStripSeparator());
            menu.Items.Add(logout);

            _tray.ContextMenuStrip = menu;
            _tray.DoubleClick += delegate { ShowLogs(); };

            // 1) Arranca módulos (Login disparará LoggedIn si hace autologin)
            _manager.StartAll();

            var login = _core.Resolve<ILoginService>();
            var conn = _core.Resolve<ModularApp.Modules.Connectivity.IConnectivityService>();

            // 2) Suscripciones: SOLO UI (no tocan BD ni hacen autologin)
            if (login != null)
            {
                login.LoggedIn += delegate
                {
                    _statusItem.Text = "Estado: Online";
                    _loginItem.Enabled = false;
                    ShowSplash(); // manual o autologin (por evento)
                };
                login.LoggedOut += delegate
                {
                    _statusItem.Text = (conn != null && !conn.IsOnline) ? "Sin conexión a internet" : "Estado: Offline";
                    _loginItem.Enabled = (conn == null ? true : conn.IsOnline);
                };
            }

            // 3) Estado inicial del menú (solo refleja)
            _statusItem.Text = (conn != null && !conn.IsOnline)
                               ? "Sin conexión a internet"
                               : (login != null && login.IsLogged ? "Estado: Online" : "Estado: Offline");
            _loginItem.Enabled = (conn == null || conn.IsOnline) && (login == null || !login.IsLogged);

            // 4) Forzar login si NO hay usuario recordado (primer inicio sin XML)
            bool hasSavedUser = !string.IsNullOrWhiteSpace(_core.Config.User.UserName) && _core.Config.User.Remembered;
            if (!hasSavedUser)
            {
                if (conn != null && !conn.IsOnline)
                {
                    _core.Logger.Info("[Tray] Primer inicio SIN usuario recordado y SIN internet → pantalla de conexión.");
                    _loginItem.Enabled = false;
                    ShowConnectingUntilOnline();   // solo visual
                }
                else
                {
                    _core.Logger.Info("[Tray] Primer inicio SIN usuario recordado → mostrando Login.");
                    ShowLogin();
                }
            }
            else
            {
                // Flujo normal si hay usuario recordado
                if (login != null && !login.IsLogged)
                {
                    if (conn != null && !conn.IsOnline)
                    {
                        _core.Logger.Info("[Tray] Arranque sin internet: mostrando pantalla de conexión.");
                        _loginItem.Enabled = false;
                        ShowConnectingUntilOnline();
                    }
                    else
                    {
                        ShowLogin();
                    }
                }
                else if (login != null && login.IsLogged)
                {
                    _core.Logger.Info("[Tray] Autologin detectado: splash diferido");
                    ShowSplashDeferred();
                }
            }

            // 5) Seguro diferido: si a los 300ms no hay login, abre el formulario
            var ensureLoginTimer = new System.Windows.Forms.Timer { Interval = 300 };
            ensureLoginTimer.Tick += (s, e) =>
            {
                ensureLoginTimer.Stop();
                try
                {
                    if (login != null && !login.IsLogged)
                    {
                        bool opened = false;
                        foreach (Form f in Application.OpenForms)
                            if (f is LoginForm) { opened = true; break; }

                        if (!opened)
                        {
                            _core.Logger.Info("[Tray] Reintento diferido: mostrando Login.");
                            ShowLogin();
                        }
                    }
                }
                catch { }
            };
            ensureLoginTimer.Start();

            // 6) Cambios de conectividad: SOLO UI
            if (conn != null)
            {
                DateTime? offlineSince = null;

                conn.ConnectivityChanged += online =>
                {
                    if (!online)
                    {
                        _statusItem.Text = "Sin conexión a internet";
                        _loginItem.Enabled = false;
                        offlineSince = DateTime.Now;
                        return;
                    }

                    _statusItem.Text = (login != null && login.IsLogged) ? "Estado: Online" : "Estado: Offline";
                    _loginItem.Enabled = (login == null || !login.IsLogged);

                    if (offlineSince.HasValue)
                    {
                        try
                        {
                            var dur = DateTime.Now - offlineSince.Value;
                            var who = (login != null && !string.IsNullOrEmpty(login.FullName)) ? login.FullName
                                     : (!string.IsNullOrEmpty(_core.Config.User.DisplayName)) ? _core.Config.User.DisplayName
                                     : (login != null ? login.UserName : "usuario");
                            _core.Logger.Warn("[Connectivity] " + who + " estuvo sin internet por " + dur.ToString("c"));
                        }
                        catch (Exception ex)
                        {
                            _core.Logger.Warn("[Connectivity] offline duration log failed: " + ex.Message);
                        }
                        offlineSince = null;
                    }

                    // Nada de autologin aquí: lo maneja LoginModule al reconectar
                };
            }
        }

        private void ShowLogin()
        {
            var login = _core.Resolve<ILoginService>();
            if (login == null) return;

            var form = new LoginForm(login, _core.Logger);
            form.Show();
            form.BringToFront();
            form.Activate();
        }

        private void ShowLogs()
        {
            var lines = MemoryLogSink.Instance.Snapshot();
            var form = new Form { Width = 900, Height = 600, Text = "AZC Keeper - Logs" };
            var box = new TextBox { Multiline = true, ReadOnly = true, Dock = DockStyle.Fill, ScrollBars = ScrollBars.Both, WordWrap = false };
            box.Lines = lines;
            form.Controls.Add(box);
            form.Show();
        }

        private void ShowSplash()
        {
            var login = _core.Resolve<ModularApp.Modules.Login.ILoginService>();
            string full = (login != null && !string.IsNullOrEmpty(login.FullName))
                          ? login.FullName
                          : (!string.IsNullOrEmpty(_core.Config.User.DisplayName)
                                ? _core.Config.User.DisplayName
                                : (login != null ? login.UserName : "Usuario"));

            string logoPath = ResolveLogoPath();

            var splash = new SplashForm(full, logoPath, false);
            splash.Show();
            splash.Refresh();

            var killer = new System.Windows.Forms.Timer { Interval = 6500 };
            killer.Tick += delegate { killer.Stop(); killer.Dispose(); try { if (!splash.IsDisposed) splash.ForceClose(); } catch { } };
            killer.Start();
        }

        private void ShowSplashDeferred()
        {
            var t = new System.Windows.Forms.Timer { Interval = 50 };
            t.Tick += delegate { t.Stop(); t.Dispose(); try { ShowSplash(); } catch { } };
            t.Start();
        }

        private void DoLogout()
        {
            var login = _core.Resolve<ILoginService>();
            if (login != null) login.Logout();
            _statusItem.Text = "Estado: Offline";
            _loginItem.Enabled = true;
        }

        protected override void Dispose(bool disposing)
        {
            if (disposing)
            {
                _tray.Visible = false;
                _tray.Dispose();
            }
            base.Dispose(disposing);
        }

        private string ResolveLogoPath()
        {
            string baseDir = AppContext.BaseDirectory;
            string[] candidates =
            {
                Path.Combine(baseDir, "branding", "logo.png"),
                Path.Combine(baseDir, "logo.png"),
                Path.Combine(baseDir, "assets", "logo.png")
            };
            foreach (var p in candidates) if (File.Exists(p)) return p;
            _core.Logger.Warn("[UI] Logo no encontrado en: " + string.Join(" | ", candidates));
            return null;
        }

        private void ShowConnectingUntilOnline()
        {
            var conn = _core.Resolve<ModularApp.Modules.Connectivity.IConnectivityService>();
            if (conn == null || conn.IsOnline) return;

            _loginItem.Enabled = false;

            string logoPath = ResolveLogoPath();
            var form = new ConnectForm(logoPath);
            form.Show();
            form.Refresh();

            Action<bool> handler = null;
            handler = online =>
            {
                if (!online) return;

                try { form.ForceClose(); } catch { }
                conn.ConnectivityChanged -= handler;

                // Al volver internet: solo habilitar login (sin autologin aquí).
                _loginItem.Enabled = true;
                _statusItem.Text = "Estado: Offline";
            };

            conn.ConnectivityChanged += handler;
        }
    }
}
