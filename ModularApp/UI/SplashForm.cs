using System;
using System.Drawing;
using System.Windows.Forms;

namespace ModularApp.UI
{
    public class SplashForm : Form
    {
        private System.Windows.Forms.Timer _closeTimer;
        private Label _lblWelcome;
        private Label _lblOffline;
        private PictureBox _logo;
        private bool _forceClose;

        public SplashForm(string fullName, string logoPath, bool isOffline)
        {
            this.FormBorderStyle = FormBorderStyle.None;
            this.StartPosition = FormStartPosition.CenterScreen;
            this.TopMost = true;
            this.BackColor = Color.White;
            this.ClientSize = new Size(520, 260);

            _lblWelcome = new Label
            {
                Text = "Bienvenido " + (string.IsNullOrEmpty(fullName) ? "Usuario" : fullName),
                Font = new Font("Segoe UI", 18, FontStyle.Bold),
                ForeColor = Color.FromArgb(30, 30, 30),
                AutoSize = false,
                TextAlign = ContentAlignment.MiddleCenter,
                Dock = DockStyle.Top,
                Height = 80 // un poco más bajo para dar más espacio al logo
            };

            _logo = new PictureBox
            {
                SizeMode = PictureBoxSizeMode.Zoom,
                Dock = DockStyle.None,         // <-- importante: NO usar Fill
                BackColor = Color.White
            };
            try
            {
                if (!string.IsNullOrEmpty(logoPath) && System.IO.File.Exists(logoPath))
                    _logo.Image = Image.FromFile(logoPath);
            }
            catch { }
            // Fallback: icono del exe si no hay PNG
            if (_logo.Image == null)
            {
                try { _logo.Image = Icon.ExtractAssociatedIcon(Application.ExecutablePath).ToBitmap(); }
                catch { }
            }

            _lblOffline = new Label
            {
                Text = "SIN CONEXIÓN A INTERNET",
                Font = new Font("Segoe UI", 11, FontStyle.Bold),
                ForeColor = Color.White,
                BackColor = Color.Firebrick,
                AutoSize = false,
                TextAlign = ContentAlignment.MiddleCenter,
                Dock = DockStyle.Bottom,
                Height = 36,
                Visible = isOffline
            };

            this.Controls.Add(_logo);       // el logo sin dock, lo posicionamos nosotros
            this.Controls.Add(_lblWelcome);
            this.Controls.Add(_lblOffline);

            this.Shown += delegate { StartAutoCloseTimer(); LayoutLogo(); };
            this.Resize += delegate { LayoutLogo(); };

            this.FormClosing += delegate (object s, FormClosingEventArgs e)
            {
                if (!_forceClose && e.CloseReason == CloseReason.UserClosing)
                    e.Cancel = true;
            };
        }

        /// <summary>
        /// Calcula un rectángulo disponible y dibuja el logo al 80% (20% más pequeño) centrado.
        /// </summary>
        private void LayoutLogo()
        {
            // Área libre entre el bottom del título y el top de la banda offline
            int top = _lblWelcome.Bottom;
            int bottom = this.ClientSize.Height - (_lblOffline.Visible ? _lblOffline.Height : 0);
            int availW = this.ClientSize.Width;
            int availH = bottom - top;

            if (availW <= 0 || availH <= 0) return;

            // 80% del área disponible
            int targetW = (int)(availW * 0.8);
            int targetH = (int)(availH * 0.8);

            // Que no exceda el área
            if (targetW > availW) targetW = availW;
            if (targetH > availH) targetH = availH;

            // Centramos (horizontal + vertical para estética)
            int x = (availW - targetW) / 2;
            int y = top + (availH - targetH) / 2;

            _logo.Bounds = new Rectangle(x, y, targetW, targetH);
            _logo.BringToFront();
        }

        private void StartAutoCloseTimer()
        {
            if (_closeTimer != null) return;
            _closeTimer = new System.Windows.Forms.Timer();
            _closeTimer.Interval = 5000; // 5s
            _closeTimer.Tick += delegate
            {
                _closeTimer.Stop();
                _forceClose = true;
                try { this.Close(); } catch { }
            };
            _closeTimer.Start();
        }

        public void SetOffline(bool offline)
        {
            _lblOffline.Visible = offline;
            _lblOffline.Refresh();
            LayoutLogo(); // re-calcular por si cambia el alto disponible
        }

        public void ForceClose()
        {
            _forceClose = true;
            try { this.Close(); } catch { }
        }
    }
}
