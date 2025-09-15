using System;
using System.Drawing;
using System.Windows.Forms;

namespace ModularApp.UI
{
    public class ConnectForm : Form
    {
        private Label _title;
        private Label _msg;
        private PictureBox _logo;
        private ProgressBar _bar;
        private System.Windows.Forms.Timer _dots;
        private int _dotCount = 0;
        private bool _forceClose;

        public ConnectForm(string logoPath)
        {
            FormBorderStyle = FormBorderStyle.None;
            StartPosition = FormStartPosition.CenterScreen;
            TopMost = true;
            BackColor = Color.White;
            ClientSize = new Size(520, 260);

            _title = new Label
            {
                Text = "AZC Keeper",
                Font = new Font("Segoe UI", 16, FontStyle.Bold),
                ForeColor = Color.FromArgb(30, 30, 30),
                AutoSize = false,
                TextAlign = ContentAlignment.MiddleCenter,
                Dock = DockStyle.Top,
                Height = 60
            };

            _msg = new Label
            {
                Text = "Intentando conectar a internet",
                Font = new Font("Segoe UI", 11, FontStyle.Regular),
                ForeColor = Color.FromArgb(60, 60, 60),
                AutoSize = false,
                TextAlign = ContentAlignment.MiddleCenter,
                Dock = DockStyle.Bottom,
                Height = 40
            };

            _bar = new ProgressBar
            {
                Style = ProgressBarStyle.Marquee,
                Dock = DockStyle.Bottom,
                Height = 20,
                MarqueeAnimationSpeed = 25
            };

            _logo = new PictureBox
            {
                SizeMode = PictureBoxSizeMode.Zoom,
                Dock = DockStyle.Fill,
                BackColor = Color.White
            };

            try
            {
                if (!string.IsNullOrEmpty(logoPath) && System.IO.File.Exists(logoPath))
                    _logo.Image = Image.FromFile(logoPath);
            }
            catch { }
            if (_logo.Image == null)
            {
                try { _logo.Image = Icon.ExtractAssociatedIcon(Application.ExecutablePath).ToBitmap(); } catch { }
            }

            Controls.Add(_logo);
            Controls.Add(_bar);
            Controls.Add(_msg);
            Controls.Add(_title);

            // Animación de puntitos "..."
            _dots = new System.Windows.Forms.Timer { Interval = 500 };
            _dots.Tick += (s, e) =>
            {
                _dotCount = (_dotCount + 1) % 4;
                _msg.Text = "Intentando conectar a internet" + new string('.', _dotCount);
            };
            Shown += (s, e) => _dots.Start();

            // Evitar cierre manual
            FormClosing += (s, e) =>
            {
                if (!_forceClose && e.CloseReason == CloseReason.UserClosing) e.Cancel = true;
            };
        }

        public void ForceClose()
        {
            _forceClose = true;
            try { Close(); } catch { }
        }
    }
}
