namespace Keeper.Session.Modules.UI;

public interface ILockKeyboard
{
    void Install();
    void Remove();
}

public interface ILockWindows
{
    void Show(bool pinAllowed);
    void Close();
}

public sealed class ScreenLockController(ILockKeyboard keyboard, ILockWindows windows, Action<string>? log = null) : IDisposable
{
    public bool Locked { get; private set; }
    public string? ErrorCode => _errors.Count == 0 ? null : string.Join(";", _errors.Keys);
    private readonly Dictionary<string, string> _errors = [];
    private bool _pinAllowed;
    private bool _engaged;
    private bool _hookInstalled;
    private bool _windowsReady;

    public void SetState(bool locked, bool pinAllowed)
    {
        if (!locked) { Dispose(); return; }
        _engaged = true;
        Locked = true;
        if (!_hookInstalled)
        {
            _hookInstalled = TryProtect("keyboard_hook_failed", () =>
            {
                var installed = false;
                try { keyboard.Install(); installed = true; }
                finally { if (!installed) keyboard.Remove(); }
            });
        }
        if (!_windowsReady || _pinAllowed != pinAllowed)
        {
            _windowsReady = TryProtect("lock_windows_failed", () => windows.Show(pinAllowed));
            if (_windowsReady) _pinAllowed = pinAllowed;
        }
    }

    public bool TryProtect(string code, Action action)
    {
        try { action(); _errors.Remove(code); return true; }
        catch (Exception ex)
        {
            var detail = $"{ex.GetType().Name}: {ex.Message}";
            if (!_errors.TryGetValue(code, out var previous) || previous != detail)
                log?.Invoke($"LockScreen {code}: {detail}");
            _errors[code] = detail;
            return false;
        }
    }

    public void Dispose()
    {
        if (!_engaged) return;
        Locked = false;
        try { keyboard.Remove(); }
        finally { windows.Close(); }
        _engaged = _hookInstalled = _windowsReady = false;
        _errors.Clear();
    }
}
