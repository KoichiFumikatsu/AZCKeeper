using Keeper.Shared.Contracts;

namespace Keeper.Session.Modules.UI;

// Textos del aviso de reinicio/apagado. Separados de la ventana para probarlos sin WinForms.
public static class PowerNoticeText
{
    // message: texto de TI (opcional), va de primera linea antes de la frase de la cuenta regresiva.
    public static string Message(PowerNoticeAction action, TimeSpan remaining, string? message = null)
    {
        var sentence = Sentence(action, remaining);
        return string.IsNullOrWhiteSpace(message) ? sentence : $"{message.Trim()}{Environment.NewLine}{sentence}";
    }

    private static string Sentence(PowerNoticeAction action, TimeSpan remaining)
    {
        var verb = action == PowerNoticeAction.Restart ? "reiniciará" : "apagará";
        if (remaining <= TimeSpan.Zero) return $"El equipo se {verb} ahora por orden de IT.";
        var minutes = Math.Max(1, (int)Math.Ceiling(remaining.TotalMinutes));
        return $"El equipo se {verb} en {minutes} {(minutes == 1 ? "minuto" : "minutos")} por orden de IT. Guarda tu trabajo.";
    }

    public static string PostponeLabel(long minutes) =>
        minutes >= 60 && minutes % 60 == 0 ? $"Posponer {minutes / 60} h" : $"Posponer {minutes} min";
    public static string NowLabel(PowerNoticeAction action) => action == PowerNoticeAction.Restart ? "Reiniciar ahora" : "Apagar ahora";

    public static string Countdown(TimeSpan remaining)
    {
        var seconds = Math.Max(0, (int)Math.Ceiling(remaining.TotalSeconds));
        return $"{seconds / 60:00}:{seconds % 60:00}";
    }

    // IT cancelo la orden (4.0.27): sin cuenta regresiva.
    public static string CancelledTitle(PowerNoticeAction action) => action == PowerNoticeAction.Restart ? "Reinicio cancelado" : "Apagado cancelado";
    public static string CancelledMessage(PowerNoticeAction action) =>
        $"IT canceló el {(action == PowerNoticeAction.Restart ? "reinicio" : "apagado")}. Puedes seguir trabajando con normalidad.";
}

// Estado de «Posponer»/«ahora» de un aviso, sin WinForms para poder probarlo. La opcion se ofrece una sola vez por RunId
// (Pick la consume; Peek/Commit la entregan); si el aviso cambia de orden, se cancela o pasa a tarjeta compacta, se
// descarta cualquier eleccion pendiente. Pick corre en el hilo de UI y Peek/Commit en el del canal de sesion.
public sealed class PowerNoticeChoices
{
    private readonly object _gate = new();
    private Guid? _run;
    private bool _used;
    private PowerChoice? _pending;
    private bool _offered;

    public bool ButtonsVisible { get { lock (_gate) return _offered && !_used; } }

    public void Update(PowerNotice? notice)
    {
        lock (_gate)
        {
            var run = notice?.RunId;
            if (run != _run) { _pending = null; _used = false; _run = run; }
            _offered = notice is { Cancelled: false, Compact: false, PostponeMinutes: not null, RunId: not null };
            if (!_offered) _pending = null;
        }
    }

    public void Pick(PowerChoiceKind kind)
    {
        lock (_gate)
        {
            if (!_offered || _used || _run is not { } run) return;
            _used = true;
            _pending = new PowerChoice(run, kind);
        }
    }

    // Entrega en dos tiempos: Peek no consume y Commit descarta la eleccion solo cuando el envio al agente termino bien;
    // si la escritura falla, la eleccion sigue pendiente para el siguiente intercambio (los botones no se vuelven a ofrecer).
    public PowerChoice? Peek()
    {
        lock (_gate) return _pending;
    }

    public void Commit(PowerChoice sent)
    {
        lock (_gate) { if (_pending == sent) _pending = null; }
    }
}

// Altura del texto del aviso, sin Form para poder probarla. Mide con el mismo ajuste de linea que el Label.
public static class PowerNoticeLayout
{
    private const TextFormatFlags Flags = TextFormatFlags.WordBreak | TextFormatFlags.NoPadding | TextFormatFlags.NoPrefix | TextFormatFlags.Left;

    public static int MessageHeight(string text, Font font, int width) =>
        TextRenderer.MeasureText(text, font, new Size(Math.Max(1, width), int.MaxValue), Flags).Height + 6;
}

// Aviso previo a un reinicio o apagado ordenado por IT (agente 4.0.17+): siempre encima, grande y centrado en la pantalla
// principal (4.0.18+), minimizable a la esquina inferior derecha y sin robar el foco. Sin boton de cerrar; si la orden trae
// PostponeMinutes ofrece «Posponer N» y «Apagar/Reiniciar ahora» (una vez); con Compact abre directo como tarjeta chica
// sin posponer. La cuenta regresiva corre aqui contra el plazo absoluto que manda el agente; la ventana se cierra cuando
// el agente deja de enviarlo.
public sealed class PowerNoticeWindow(Control dispatcher) : IDisposable
{
    private readonly PowerNoticeChoices _choices = new();

    // Lo llama el canal de sesion: PeekChoice antes de escribir la respuesta y CommitChoice solo si la escritura termino.
    public PowerChoice? PeekChoice() => _choices.Peek();
    public void CommitChoice(PowerChoice sent) => _choices.Commit(sent);

    private NoticeForm? _form;
    private PowerNotice? _notice;
    // Aviso de cancelacion que la persona cerro: no se vuelve a abrir aunque el agente lo siga enviando un rato.
    private PowerNotice? _dismissed;

    public void Set(PowerNotice? notice) => dispatcher.BeginInvoke(() => Apply(notice));

    private void Apply(PowerNotice? notice)
    {
        _choices.Update(notice);
        if (notice == _notice && (notice is null || notice == _dismissed || _form is { IsDisposed: false })) return;
        _notice = notice;
        if (notice is null || notice == _dismissed) { Close(); return; }
        if (_form is null || _form.IsDisposed) _form = new NoticeForm(_choices, () => { _dismissed = _notice; Close(); });
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

    // Grande y centrada al aparecer; "Minimizar" la pasa a la esquina inferior derecha y "Ampliar" la devuelve. Si IT cancela
    // la orden, el mismo boton dice "Cerrar" y la cierra.
    private sealed class NoticeForm : Form
    {
        private static readonly Font TitleFont = new("Segoe UI", 18, FontStyle.Bold);
        private static readonly Font LargeText = new("Segoe UI", 14), SmallText = new("Segoe UI", 10);
        private static readonly Font LargeCount = new("Segoe UI", 48, FontStyle.Bold), SmallCount = new("Segoe UI", 20, FontStyle.Bold);
        private static readonly Font LargeButton = new("Segoe UI", 11), SmallButton = new("Segoe UI", 9);
        private readonly Label _title = new() { AutoSize = false, Dock = DockStyle.Top, Height = 44, ForeColor = Color.White, Font = TitleFont };
        private readonly Label _message = new() { AutoSize = false, AutoEllipsis = false, UseMnemonic = false, Dock = DockStyle.Fill, ForeColor = Color.White };
        private readonly Label _countdown = new() { AutoSize = false, Dock = DockStyle.Bottom, TextAlign = ContentAlignment.MiddleLeft, ForeColor = Color.White };
        private readonly Button _toggle = new() { Dock = DockStyle.Right, FlatStyle = FlatStyle.Flat, ForeColor = Color.White, BackColor = Color.FromArgb(0, 84, 133), Cursor = Cursors.Hand, TabStop = false };
        private readonly System.Windows.Forms.Timer _timer = new() { Interval = 500 };
        private PowerNotice? _notice;
        private bool _compact;
        private readonly Action _dismiss;
        private readonly PowerNoticeChoices _choices;
        private readonly Button _postpone = ChoiceButton(), _now = ChoiceButton();

        private static Button ChoiceButton() => new() { Dock = DockStyle.Left, FlatStyle = FlatStyle.Flat, ForeColor = Color.White, BackColor = Color.FromArgb(0, 84, 133), Cursor = Cursors.Hand, TabStop = false, Visible = false };

        public NoticeForm(PowerNoticeChoices choices, Action dismiss)
        {
            _choices = choices;
            _dismiss = dismiss;
            FormBorderStyle = FormBorderStyle.None; TopMost = true; ShowInTaskbar = false; ControlBox = false;
            StartPosition = FormStartPosition.Manual; BackColor = Color.FromArgb(0, 58, 93); Text = "AZCKeeper";
            _toggle.FlatAppearance.BorderColor = Color.White;
            var bottom = new Panel { Dock = DockStyle.Bottom, Height = 40 };
            _postpone.FlatAppearance.BorderColor = Color.White; _now.FlatAppearance.BorderColor = Color.White;
            bottom.Controls.Add(_toggle); bottom.Controls.Add(_now); bottom.Controls.Add(_postpone);
            _postpone.Click += (_, _) => { _choices.Pick(PowerChoiceKind.Postpone); ApplyChoices(); };
            _now.Click += (_, _) => { _choices.Pick(PowerChoiceKind.Now); ApplyChoices(); };
            Controls.Add(_message); Controls.Add(_countdown); Controls.Add(bottom); Controls.Add(_title);
            _toggle.Click += (_, _) => { if (_notice?.Cancelled == true) { _dismiss(); return; } _compact = !_compact; ApplyMode(); };
            _timer.Tick += (_, _) => Render();
            FormClosing += (_, e) => { if (e.CloseReason == CloseReason.UserClosing) e.Cancel = true; };
            ApplyMode();
        }

        // Los botones de eleccion salen solo mientras la orden los ofrece y la persona no ha elegido.
        private void ApplyChoices()
        {
            var visible = _choices.ButtonsVisible && _notice is not null;
            _postpone.Visible = _now.Visible = visible;
            if (!visible || _notice is null) return;
            _postpone.Text = PowerNoticeText.PostponeLabel(_notice.PostponeMinutes ?? 0);
            _now.Text = PowerNoticeText.NowLabel(_notice.Action);
            _postpone.Width = _compact ? 120 : 170; _now.Width = _compact ? 110 : 150;
            _postpone.Font = _now.Font = _compact ? SmallButton : LargeButton;
        }

        private void ApplyMode()
        {
            SuspendLayout();
            _title.Visible = !_compact;
            _message.Font = _compact ? SmallText : LargeText;
            _countdown.Font = _compact ? SmallCount : LargeCount; _countdown.Height = _compact ? 44 : 84;
            _toggle.Text = _notice?.Cancelled == true ? "Cerrar" : _compact ? "Ampliar" : "Minimizar"; _toggle.Width = _compact ? 96 : 130;
            _toggle.Font = _compact ? SmallButton : LargeButton;
            Padding = _compact ? new Padding(14, 10, 14, 10) : new Padding(28, 22, 28, 20);
            Fit();
            ResumeLayout();
            ApplyChoices();
        }

        // Ajusta el alto al texto medido (sin recortarlo) dentro de un maximo; compacta ancla abajo a la derecha, grande centra.
        private void Fit()
        {
            var width = _compact ? 380 : 640;
            var fixedHeight = Padding.Vertical + (_compact ? 0 : _title.Height) + _countdown.Height + 40;
            var minHeight = _compact ? 160 : 320;
            var maxHeight = _compact ? 300 : 520;
            var text = PowerNoticeLayout.MessageHeight(_message.Text, _message.Font, width - Padding.Horizontal);
            var size = new Size(width, Math.Clamp(fixedHeight + text, minHeight, maxHeight));
            var area = (Screen.PrimaryScreen ?? Screen.AllScreens[0]).WorkingArea;
            var location = _compact
                ? new Point(area.Right - size.Width - 16, area.Bottom - size.Height - 16)
                : new Point(area.Left + (area.Width - size.Width) / 2, area.Top + (area.Height - size.Height) / 2);
            if (Size != size || Location != location) { Size = size; Location = location; }
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
            var mode = _notice?.Cancelled != notice.Cancelled;
            // Aviso compacto (tras posponer): abre directo como tarjeta chica; la persona aun puede ampliarla.
            if (_notice?.Compact != notice.Compact && notice.Compact) { _compact = true; mode = true; }
            _notice = notice;
            if (mode) ApplyMode();
            ApplyChoices();
            Render();
            if (!Visible) Show();
            if (notice.Cancelled) _timer.Stop(); else _timer.Start();
        }

        private void Render()
        {
            if (_notice is null) return;
            if (_notice.Cancelled)
            {
                _title.Text = PowerNoticeText.CancelledTitle(_notice.Action);
                var cancelled = PowerNoticeText.CancelledMessage(_notice.Action);
                if (_message.Text != cancelled) { _message.Text = cancelled; Fit(); }
                _countdown.Text = "";
                return;
            }
            var remaining = _notice.Deadline - DateTimeOffset.UtcNow;
            _title.Text = _notice.Action == PowerNoticeAction.Restart ? "Reinicio programado" : "Apagado programado";
            var text = PowerNoticeText.Message(_notice.Action, remaining, _notice.Message);
            if (_message.Text != text) { _message.Text = text; Fit(); }
            _countdown.Text = PowerNoticeText.Countdown(remaining);
        }

        protected override void Dispose(bool disposing)
        {
            if (disposing) _timer.Dispose();
            base.Dispose(disposing);
        }
    }
}
