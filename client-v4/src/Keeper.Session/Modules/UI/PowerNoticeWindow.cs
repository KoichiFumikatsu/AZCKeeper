using Keeper.Shared.Contracts;

namespace Keeper.Session.Modules.UI;

// Textos del aviso de reinicio/apagado. Separados de la ventana para probarlos sin WinForms.
public static class PowerNoticeText
{
    public static string Message(PowerNoticeAction action, TimeSpan remaining)
    {
        var verb = action == PowerNoticeAction.Restart ? "reiniciará" : "apagará";
        if (remaining <= TimeSpan.Zero) return $"El equipo se {verb} ahora por orden de IT.";
        var minutes = Math.Max(1, (int)Math.Ceiling(remaining.TotalMinutes));
        return $"El equipo se {verb} en {minutes} {(minutes == 1 ? "minuto" : "minutos")} por orden de IT. Guarda tu trabajo.";
    }

    public static string Countdown(TimeSpan remaining)
    {
        var seconds = Math.Max(0, (int)Math.Ceiling(remaining.TotalSeconds));
        return $"{seconds / 60:00}:{seconds % 60:00}";
    }
}

// Aviso previo a un reinicio o apagado ordenado por IT (agente 4.0.17+): ventana pequena, siempre encima, en la esquina
// inferior derecha de la pantalla principal, sin boton de cerrar ni de posponer y sin robar el foco. La cuenta regresiva
// corre aqui contra el plazo absoluto que manda el agente; la ventana se cierra cuando el agente deja de enviarlo.
public sealed class PowerNoticeWindow(Control dispatcher) : IDisposable
{
    private NoticeForm? _form;
    private PowerNotice? _notice;

    public void Set(PowerNotice? notice) => dispatcher.BeginInvoke(() => Apply(notice));

    private void Apply(PowerNotice? notice)
    {
        if (notice == _notice && (notice is null || _form is { IsDisposed: false })) return;
        _notice = notice;
        if (notice is null) { Close(); return; }
        if (_form is null || _form.IsDisposed) _form = new NoticeForm();
        _form.Display(notice);
    }

    private void Close()
    {
        _form?.Dispose();
        _form = null;
    }

    public void Dispose()
    {
        if (dispatcher.IsDisposed || !dispatcher.IsHandleCreated) { Close(); return; }
        if (dispatcher.InvokeRequired) dispatcher.Invoke(Close); else Close();
    }

    private sealed class NoticeForm : Form
    {
        private readonly Label _message = new() { AutoSize = false, Dock = DockStyle.Top, Height = 64, ForeColor = Color.White, Font = new Font("Segoe UI", 11) };
        private readonly Label _countdown = new() { AutoSize = false, Dock = DockStyle.Fill, TextAlign = ContentAlignment.MiddleLeft, ForeColor = Color.White, Font = new Font("Segoe UI", 22, FontStyle.Bold) };
        private readonly System.Windows.Forms.Timer _timer = new() { Interval = 500 };
        private PowerNotice? _notice;

        public NoticeForm()
        {
            FormBorderStyle = FormBorderStyle.None; TopMost = true; ShowInTaskbar = false; ControlBox = false;
            StartPosition = FormStartPosition.Manual; BackColor = Color.FromArgb(0, 58, 93); Text = "AZCKeeper";
            Padding = new Padding(16, 12, 16, 12); Size = new Size(380, 130);
            Controls.Add(_countdown); Controls.Add(_message);
            _timer.Tick += (_, _) => Render();
            FormClosing += (_, e) => { if (e.CloseReason == CloseReason.UserClosing) e.Cancel = true; };
        }

        // Aparece sin quitarle el foco a lo que la persona esta escribiendo.
        protected override bool ShowWithoutActivation => true;
        protected override CreateParams CreateParams
        {
            get
            {
                var cp = base.CreateParams;
                cp.ExStyle |= 0x08 /* WS_EX_TOPMOST */ | 0x80 /* WS_EX_TOOLWINDOW */ | 0x08000000 /* WS_EX_NOACTIVATE */;
                return cp;
            }
        }

        public void Display(PowerNotice notice)
        {
            _notice = notice;
            var area = (Screen.PrimaryScreen ?? Screen.AllScreens[0]).WorkingArea;
            Location = new Point(area.Right - Width - 16, area.Bottom - Height - 16);
            Render();
            if (!Visible) Show();
            _timer.Start();
        }

        private void Render()
        {
            if (_notice is null) return;
            var remaining = _notice.Deadline - DateTimeOffset.UtcNow;
            _message.Text = PowerNoticeText.Message(_notice.Action, remaining);
            _countdown.Text = PowerNoticeText.Countdown(remaining);
        }

        protected override void Dispose(bool disposing)
        {
            if (disposing) _timer.Dispose();
            base.Dispose(disposing);
        }
    }
}
