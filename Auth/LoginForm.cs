using System;
using System.Drawing;
using System.Windows.Forms;

namespace AZCKeeper_Cliente.Auth
{
    /// <summary>
    /// UI mínima de autenticación.
    /// 
    /// Objetivo:
    /// - Pedir username/password cuando no exista token o cuando la sesión sea inválida.
    /// - NO persiste password.
    /// - Emite evento OnLoginSubmitted para que CoreService ejecute el login vía ApiClient.
    /// 
    /// Importante:
    /// - Esta ventana es temporal para el cliente; luego puede hacerse invisible o integrada.
    /// </summary>
    internal sealed class LoginForm : Form
    {
        private readonly TextBox _txtUser;
        private readonly TextBox _txtPass;
        private readonly Button _btnLogin;
        private readonly Label _lblStatus;

        public event Action<string, string> OnLoginSubmitted;

        public LoginForm()
        {
            Text = "AZCKeeper - Login";
            StartPosition = FormStartPosition.CenterScreen;
            Size = new Size(420, 220);
            FormBorderStyle = FormBorderStyle.FixedDialog;
            MaximizeBox = false;
            MinimizeBox = false;

            var panel = new TableLayoutPanel
            {
                Dock = DockStyle.Fill,
                ColumnCount = 2,
                RowCount = 5,
                Padding = new Padding(12),
                AutoSize = true
            };
            panel.ColumnStyles.Add(new ColumnStyle(SizeType.Absolute, 110));
            panel.ColumnStyles.Add(new ColumnStyle(SizeType.Percent, 100));

            panel.Controls.Add(new Label { Text = "Usuario", AutoSize = true, Anchor = AnchorStyles.Left }, 0, 0);
            _txtUser = new TextBox { Anchor = AnchorStyles.Left | AnchorStyles.Right };
            panel.Controls.Add(_txtUser, 1, 0);

            panel.Controls.Add(new Label { Text = "Contraseña", AutoSize = true, Anchor = AnchorStyles.Left }, 0, 1);
            _txtPass = new TextBox { Anchor = AnchorStyles.Left | AnchorStyles.Right, UseSystemPasswordChar = true };
            panel.Controls.Add(_txtPass, 1, 1);

            _btnLogin = new Button { Text = "Iniciar sesión", Anchor = AnchorStyles.Right, Width = 120 };
            _btnLogin.Click += (_, __) => Submit();
            panel.Controls.Add(_btnLogin, 1, 2);

            _lblStatus = new Label { Text = "", AutoSize = true, ForeColor = Color.DimGray, Anchor = AnchorStyles.Left };
            panel.Controls.Add(_lblStatus, 0, 3);
            panel.SetColumnSpan(_lblStatus, 2);

            var hint = new Label
            {
                Text = "Nota: el cliente no guarda la contraseña. Solo se almacena un token seguro si el backend lo entrega.",
                AutoSize = true,
                ForeColor = Color.DimGray
            };
            panel.Controls.Add(hint, 0, 4);
            panel.SetColumnSpan(hint, 2);

            Controls.Add(panel);

            AcceptButton = _btnLogin;
        }

        public void SetBusy(bool busy, string status = null)
        {
            _txtUser.Enabled = !busy;
            _txtPass.Enabled = !busy;
            _btnLogin.Enabled = !busy;

            if (status != null)
                _lblStatus.Text = status;
        }

        public void SetStatus(string status)
        {
            _lblStatus.Text = status ?? "";
        }

        private void Submit()
        {
            string user = _txtUser.Text?.Trim() ?? "";
            string pass = _txtPass.Text ?? "";

            if (string.IsNullOrWhiteSpace(user) || string.IsNullOrWhiteSpace(pass))
            {
                SetStatus("Usuario y contraseña son obligatorios.");
                return;
            }

            SetBusy(true, "Validando credenciales...");
            OnLoginSubmitted?.Invoke(user, pass);
        }
    }
}
