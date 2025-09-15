using System;
using System.Drawing;
using System.Windows.Forms;
using ModularApp.Core;
using ModularApp.Modules.Login;

namespace ModularApp.UI
{
    public class LoginForm : Form
    {
        private readonly ILoginService _login;
        private readonly ILogger _logger;

        private TextBox txtUser;
        private TextBox txtPass;
        private Button btnOk;
        private Button btnCancel;
        private Label lblMsg;

        public LoginForm(ILoginService login, ILogger logger)
        {
            _login = login;
            _logger = logger;

            this.Text = "Iniciar sesión - AZC Keeper";
            this.FormBorderStyle = FormBorderStyle.FixedDialog;
            this.MaximizeBox = false;
            this.MinimizeBox = false;
            this.StartPosition = FormStartPosition.CenterScreen;
            this.ClientSize = new Size(360, 180);
            this.TopMost = true;

            var lblUser = new Label { Text = "Usuario:", Left = 20, Top = 20, Width = 80 };
            txtUser = new TextBox { Left = 110, Top = 18, Width = 220 };

            var lblPass = new Label { Text = "Contraseña:", Left = 20, Top = 55, Width = 80 };
            txtPass = new TextBox { Left = 110, Top = 53, Width = 220, UseSystemPasswordChar = true };

            btnOk = new Button { Text = "Iniciar sesión", Left = 110, Top = 95, Width = 110 };
            btnCancel = new Button { Text = "Cancelar", Left = 220, Top = 95, Width = 110 };

            lblMsg = new Label { Left = 20, Top = 135, Width = 320, ForeColor = Color.Firebrick };

            btnOk.Click += OnLoginClick;
            btnCancel.Click += delegate { this.Hide(); };

            this.AcceptButton = btnOk;

            this.Controls.Add(lblUser);
            this.Controls.Add(txtUser);
            this.Controls.Add(lblPass);
            this.Controls.Add(txtPass);
            this.Controls.Add(btnOk);
            this.Controls.Add(btnCancel);
            this.Controls.Add(lblMsg);

            this.FormClosing += delegate (object sender, FormClosingEventArgs e)
            {
                // No cerramos la app; sólo ocultamos la ventana
                if (e.CloseReason == CloseReason.UserClosing)
                {
                    e.Cancel = true;
                    this.Hide();
                }
            };
        }

        private void OnLoginClick(object sender, EventArgs e)
        {
            try
            {
                var user = txtUser.Text.Trim();
                var pass = txtPass.Text;

                if (string.IsNullOrEmpty(user) || string.IsNullOrEmpty(pass))
                {
                    lblMsg.Text = "Ingresa usuario y contraseña.";
                    return;
                }

                bool ok = _login.Login(user, pass);
                if (ok)
                {
                    _logger.Info("[UI] Login exitoso para " + user);
                    this.Hide();
                }
                else
                {
                    lblMsg.Text = "Credenciales inválidas.";
                }
            }
            catch (Exception ex)
            {
                _logger.Error("[UI] Error al iniciar sesión: " + ex.Message);
                lblMsg.Text = "Error al iniciar sesión.";
            }
        }
    }
}
