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

    public LockScreen(Control dispatcher)
    {
        _dispatcher = dispatcher;
        _protection = new(new LockKeyboard(), this);
        _watchdog.Tick += (_, _) => MaintainWindows();
    }

    public string? TakePin() => Interlocked.Exchange(ref _pin, null);

    public Task SetStateAsync(bool locked, bool pinAllowed)
    {
        var done = new TaskCompletionSource(TaskCreationOptions.RunContinuationsAsynchronously);
        _dispatcher.BeginInvoke(() =>
        {
            try { _protection.SetState(locked, pinAllowed); done.SetResult(); }
            catch (Exception ex) { done.SetException(ex); }
        });
        return done.Task;
    }

    void ILockWindows.Show(bool pinAllowed)
    {
        _pinAllowed = pinAllowed;
        RebuildWindows();
        _watchdog.Start();
        RecoverFocus();
    }

    private void RebuildWindows()
    {
        CloseWindows();
        _visible = true;
        var screens = Screen.AllScreens;
        _displays = screens.Select(s => (s.DeviceName, s.Bounds)).ToArray();
        foreach (var screen in screens)
        {
            var form = new Form
            {
                FormBorderStyle = FormBorderStyle.None, TopMost = true, ShowInTaskbar = false,
                StartPosition = FormStartPosition.Manual, Bounds = screen.Bounds,
                BackColor = Color.FromArgb(0, 58, 93), Text = "AZCKeeper",
                AutoScaleMode = AutoScaleMode.None
            };
            _forms.Add(form);
            var panel = new FlowLayoutPanel { Dock = DockStyle.Fill, FlowDirection = FlowDirection.TopDown, Padding = new Padding(80), WrapContents = false };
            panel.Controls.Add(new Label { Text = "Equipo bloqueado por IT", ForeColor = Color.White, AutoSize = true, Font = new Font("Segoe UI", 24) });
            if (_pinAllowed)
            {
                var pin = new TextBox { UseSystemPasswordChar = true, MaxLength = 32, Width = 240 };
                var button = new Button { Text = "Validar PIN", AutoSize = true };
                button.Click += (_, _) => { Interlocked.Exchange(ref _pin, pin.Text); pin.Clear(); };
                panel.Controls.Add(pin); panel.Controls.Add(button);
                form.AcceptButton = button;
            }
            form.Controls.Add(panel);
            form.FormClosing += (_, e) => { if (_visible && e.CloseReason == CloseReason.UserClosing) e.Cancel = true; };
            form.Show();
            form.Bounds = screen.Bounds;
        }
    }

    private void MaintainWindows()
    {
        if (!_visible) return;
        if (!_displays.SequenceEqual(Screen.AllScreens.Select(s => (s.DeviceName, s.Bounds))) || _forms.Any(f => f.IsDisposed))
            RebuildWindows();
        foreach (var form in _forms)
        {
            if (form.WindowState != FormWindowState.Normal) form.WindowState = FormWindowState.Normal;
            SetWindowPos(form.Handle, new IntPtr(-1), 0, 0, 0, 0, 0x53);
        }
        RecoverFocus();
    }

    private void RecoverFocus()
    {
        var foreground = GetForegroundWindow();
        if (_forms.Count == 0 || _forms.Any(f => f.Handle == foreground)) return;
        var target = _forms.FirstOrDefault(f => f.Bounds.Contains(Cursor.Position)) ?? _forms[0];
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
