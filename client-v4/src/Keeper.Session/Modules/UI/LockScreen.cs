using Keeper.Shared.Contracts;

namespace Keeper.Session.Modules.UI;

public sealed class LockScreen(Control dispatcher) : ModuleBase
{
    public override string Name => "LockScreen";
    private readonly List<Form> _forms = [];
    private string? _pin;
    private bool _locked;
    private bool _pinAllowed;
    public string? TakePin() => Interlocked.Exchange(ref _pin, null);
    public Task SetStateAsync(bool locked, bool pinAllowed)
    {
        var done = new TaskCompletionSource(TaskCreationOptions.RunContinuationsAsynchronously);
        dispatcher.BeginInvoke(() =>
        {
            try
            {
                if (_locked == locked && _pinAllowed == pinAllowed) { done.SetResult(); return; }
                _locked = false;
                foreach (var form in _forms) { form.Close(); form.Dispose(); }
                _forms.Clear(); _locked = locked; _pinAllowed = pinAllowed;
                if (locked) foreach (var screen in Screen.AllScreens)
                {
                    var form = new Form { FormBorderStyle = FormBorderStyle.None, TopMost = true,
                        StartPosition = FormStartPosition.Manual, Bounds = screen.Bounds, BackColor = Color.FromArgb(0, 58, 93), Text = "AZCKeeper" };
                    var panel = new FlowLayoutPanel { Dock = DockStyle.Fill, FlowDirection = FlowDirection.TopDown, Padding = new Padding(80), WrapContents = false };
                    panel.Controls.Add(new Label { Text = "Equipo bloqueado por IT", ForeColor = Color.White, AutoSize = true, Font = new Font("Segoe UI", 24) });
                    if (pinAllowed)
                    {
                        var pin = new TextBox { UseSystemPasswordChar = true, MaxLength = 32, Width = 240 };
                        var button = new Button { Text = "Validar PIN", AutoSize = true };
                        button.Click += (_, _) => { Interlocked.Exchange(ref _pin, pin.Text); pin.Clear(); };
                        panel.Controls.Add(pin); panel.Controls.Add(button);
                        form.AcceptButton = button;
                    }
                    form.Controls.Add(panel);
                    form.FormClosing += (_, e) => { if (_locked && e.CloseReason == CloseReason.UserClosing) e.Cancel = true; };
                    _forms.Add(form); form.Show();
                }
                done.SetResult();
            }
            catch (Exception ex) { done.SetException(ex); }
        });
        return done.Task;
    }
    public override Task ShutdownAsync() => SetStateAsync(false, false);
}
