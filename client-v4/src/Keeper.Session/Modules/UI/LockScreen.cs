using System.Runtime.InteropServices;
using Keeper.Shared.Contracts;

namespace Keeper.Session.Modules.UI;

public sealed class LockScreen : ModuleBase, IDisposable, ILockWindows
{
    public override string Name => "LockScreen";
    private readonly Control _dispatcher;
    private readonly ScreenLockController _protection;
    private readonly System.Windows.Forms.Timer _watchdog = new() { Interval = 500 };
    private readonly List<Form> _forms = [];
    private (string Name, Rectangle Bounds)[] _displays = [];
    private string? _pin;
    private bool _visible;
    private bool _pinAllowed;
    private PinStatus? _pinStatus;

    public LockScreen(Control dispatcher, Action<string> log)
    {
        _dispatcher = dispatcher;
        _protection = new(new LockKeyboard(), this, log);
        _watchdog.Tick += (_, _) => _protection.TryProtect("lock_windows_maintenance_failed", MaintainWindows);
    }

    public string? TakePin() => Interlocked.Exchange(ref _pin, null);

    public Task SetStateAsync(bool locked, bool pinAllowed, PinStatus? pinStatus = null)
    {
        var done = new TaskCompletionSource(TaskCreationOptions.RunContinuationsAsynchronously);
        _dispatcher.BeginInvoke(() =>
        {
            try { _pinStatus = pinStatus; _protection.SetState(locked, pinAllowed); UpdatePinStatus(); done.SetResult(); }
            catch (Exception ex) { done.SetException(ex); }
        });
        return done.Task;
    }

    void ILockWindows.Show(bool pinAllowed)
    {
        _pinAllowed = pinAllowed;
        _watchdog.Start();
        RebuildWindows();
        RecoverFocus();
    }

    private void RebuildWindows()
    {
        _visible = true;
        var screens = Screen.AllScreens;
        var failures = new List<Exception>();
        foreach (var screen in screens)
        {
            var previous = _forms.Where(f => f.Tag is WindowSettings settings && settings.DeviceName == screen.DeviceName).ToArray();
            if (previous.Length == 1 && !previous[0].IsDisposed && previous[0].Visible &&
                previous[0].Bounds == screen.Bounds && previous[0].Tag is WindowSettings { PinAllowed: var pinMode } && pinMode == _pinAllowed) continue;
            Form? form = null;
            try
            {
                form = new Form
                {
                    FormBorderStyle = FormBorderStyle.None, TopMost = true, ShowInTaskbar = false,
                    StartPosition = FormStartPosition.Manual, Bounds = screen.Bounds,
                    BackColor = Color.FromArgb(0, 58, 93), Text = "AZCKeeper",
                    AutoScaleMode = AutoScaleMode.None, Tag = new WindowSettings(screen.DeviceName, _pinAllowed)
                };
                _forms.Add(form);
                var panel = new FlowLayoutPanel { Dock = DockStyle.Fill, FlowDirection = FlowDirection.TopDown, Padding = new Padding(80), WrapContents = false };
                panel.Controls.Add(new Label { Text = "Equipo bloqueado por IT", ForeColor = Color.White, AutoSize = true, Font = new Font("Segoe UI", 24) });
                if (_pinAllowed)
                {
                    var pin = new TextBox { Name = "pin", UseSystemPasswordChar = true, MaxLength = 32, Width = 240, AccessibleName = "PIN de desbloqueo" };
                    var button = new Button { Name = "submitPin", Text = "Desbloquear", AutoSize = true };
                    var status = new Label { Name = "pinStatus", ForeColor = Color.White, AutoSize = true };
                    pin.KeyPress += (_, e) => { if (!char.IsControl(e.KeyChar) && !char.IsAsciiDigit(e.KeyChar)) e.Handled = true; };
                    button.Click += (_, _) =>
                    {
                        if (pin.Text.Length < 6 || pin.Text.Any(c => !char.IsAsciiDigit(c))) { status.Text = "Escribe un PIN de 6 a 32 dígitos."; return; }
                        Interlocked.Exchange(ref _pin, pin.Text); pin.Clear(); status.Text = "Validando…";
                    };
                    panel.Controls.Add(pin); panel.Controls.Add(button); panel.Controls.Add(status);
                    form.AcceptButton = button;
                }
                form.Controls.Add(panel);
                form.FormClosing += (_, e) => { if (_visible && _forms.Contains(form) && e.CloseReason == CloseReason.UserClosing) e.Cancel = true; };
                form.Show();
                form.Bounds = screen.Bounds;
                foreach (var old in previous) { _forms.Remove(old); old.Dispose(); }
            }
            catch (Exception ex)
            {
                failures.Add(ex);
                if (form is not null && !form.Visible) { _forms.Remove(form); form.Dispose(); }
            }
        }
        if (failures.Count != 0) throw new AggregateException("lock_windows_rebuild_failed", failures);
        foreach (var obsolete in _forms.Where(f => !screens.Any(s => f.Tag is WindowSettings settings && settings.DeviceName == s.DeviceName)).ToArray())
        {
            _forms.Remove(obsolete); obsolete.Dispose();
        }
        _displays = screens.Select(s => (s.DeviceName, s.Bounds)).ToArray();
    }

    private sealed record WindowSettings(string DeviceName, bool PinAllowed);

    private void MaintainWindows()
    {
        if (!_visible) return;
        UpdatePinStatus();
        if (!_displays.SequenceEqual(Screen.AllScreens.Select(s => (s.DeviceName, s.Bounds))) || _forms.Any(f => f.IsDisposed))
            _protection.TryProtect("lock_windows_rebuild_failed", RebuildWindows);
        foreach (var form in _forms.Where(f => !f.IsDisposed))
        {
            if (form.WindowState != FormWindowState.Normal) form.WindowState = FormWindowState.Normal;
            SetWindowPos(form.Handle, new IntPtr(-1), 0, 0, 0, 0, 0x53);
        }
        RecoverFocus();
    }

    private void UpdatePinStatus()
    {
        var remaining = _pinStatus?.RetryAt is { } retry ? Math.Max(0, (int)Math.Ceiling((retry - DateTimeOffset.UtcNow).TotalSeconds)) : 0;
        foreach (var form in _forms.Where(f => !f.IsDisposed))
        {
            foreach (var input in form.Controls.Find("pin", true).Concat(form.Controls.Find("submitPin", true))) input.Enabled = remaining == 0;
            foreach (var label in form.Controls.Find("pinStatus", true)) label.Text = remaining > 0
                ? $"Demasiados intentos. Inténtalo en {remaining / 60:00}:{remaining % 60:00}."
                : _pinStatus is { Attempts: > 0 and < 5 } ? $"PIN incorrecto. Quedan {5 - _pinStatus.Attempts} intentos."
                : "Puedes desbloquear con el PIN del cliente, incluso sin conexión.";
        }
    }

    private void RecoverFocus()
    {
        var foreground = GetForegroundWindow();
        if (_forms.Count == 0 || _forms.Any(f => !f.IsDisposed && f.Handle == foreground)) return;
        var target = _forms.FirstOrDefault(f => !f.IsDisposed && f.Bounds.Contains(Cursor.Position)) ?? _forms.FirstOrDefault(f => !f.IsDisposed);
        if (target is null) return;
        var currentThread = GetCurrentThreadId();
        var foregroundThread = GetWindowThreadProcessId(foreground, out _);
        var attached = foregroundThread != 0 && foregroundThread != currentThread && AttachThreadInput(currentThread, foregroundThread, true);
        try
        {
            BringWindowToTop(target.Handle);
            SetForegroundWindow(target.Handle);
            target.Activate();
        }
        finally { if (attached) AttachThreadInput(currentThread, foregroundThread, false); }
    }

    private void CloseWindows()
    {
        _visible = false;
        foreach (var form in _forms) { form.Close(); form.Dispose(); }
        _forms.Clear();
    }

    void ILockWindows.Close()
    {
        _watchdog.Stop();
        Interlocked.Exchange(ref _pin, null);
        CloseWindows();
    }

    public override ModuleSnapshot Snapshot() => base.Snapshot() with
    {
        State = _protection.ErrorCode is not null ? "degraded" : _protection.Locked ? "locked" : "unlocked",
        ErrorCode = _protection.ErrorCode
    };

    public override Task ShutdownAsync() => SetStateAsync(false, false);

    public void Dispose()
    {
        try { _protection.Dispose(); }
        finally { _watchdog.Dispose(); }
    }

    [DllImport("user32.dll")] private static extern nint GetForegroundWindow();
    [DllImport("user32.dll")] private static extern uint GetWindowThreadProcessId(nint window, out uint process);
    [DllImport("kernel32.dll")] private static extern uint GetCurrentThreadId();
    [DllImport("user32.dll")] [return: MarshalAs(UnmanagedType.Bool)] private static extern bool AttachThreadInput(uint thread, uint other, bool attach);
    [DllImport("user32.dll")] [return: MarshalAs(UnmanagedType.Bool)] private static extern bool BringWindowToTop(nint window);
    [DllImport("user32.dll")] [return: MarshalAs(UnmanagedType.Bool)] private static extern bool SetForegroundWindow(nint window);
    [DllImport("user32.dll")] [return: MarshalAs(UnmanagedType.Bool)] private static extern bool SetWindowPos(nint window, nint after, int x, int y, int width, int height, uint flags);
}
