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

public sealed class ScreenLockController(ILockKeyboard keyboard, ILockWindows windows) : IDisposable
{
    public bool Locked { get; private set; }
    private bool _pinAllowed;
    private bool _engaged;

    public void SetState(bool locked, bool pinAllowed)
    {
        if (!locked) { Dispose(); return; }
        if (Locked && _pinAllowed == pinAllowed) return;
        var completed = false;
        _engaged = true;
        try
        {
            if (!Locked) keyboard.Install();
            windows.Show(pinAllowed);
            _pinAllowed = pinAllowed;
            Locked = true;
            completed = true;
        }
        finally
        {
            if (!completed) Dispose();
        }
    }

    public void Dispose()
    {
        if (!_engaged) return;
        Locked = false;
        try { keyboard.Remove(); }
        finally { windows.Close(); }
        _engaged = false;
    }
}
