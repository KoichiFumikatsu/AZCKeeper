using System.ComponentModel;
using System.Runtime.InteropServices;

namespace Keeper.Session.Modules.UI;

public sealed class LockKeyFilter
{
    private readonly bool[] _suppressed = new bool[256];

    public bool Suppress(int key, bool keyUp, bool alt, bool control)
    {
        if ((uint)key >= _suppressed.Length) return false;
        var blocked = key is 0x5B or 0x5C || (alt && key is 0x09 or 0x73 or 0x1B) || (control && key == 0x1B);
        blocked |= _suppressed[key];
        if (keyUp)
        {
            _suppressed[key] = false;
        }
        else _suppressed[key] = blocked;
        return blocked;
    }
}

public sealed class LockKeyboard : ILockKeyboard
{
    private Thread? _thread;
    private uint _threadId;

    public void Install()
    {
        if (_thread is not null) return;
        var started = new TaskCompletionSource(TaskCreationOptions.RunContinuationsAsynchronously);
        _thread = new Thread(() => Run(started)) { IsBackground = true, Name = "Keeper lock keyboard" };
        _thread.Start();
        try { started.Task.GetAwaiter().GetResult(); }
        catch { _thread.Join(); _thread = null; throw; }
    }

    private void Run(TaskCompletionSource started)
    {
        var filter = new LockKeyFilter();
        HookProc callback = (code, message, data) =>
        {
            if (code >= 0 && message is 0x100 or 0x101 or 0x104 or 0x105)
            {
                var input = Marshal.PtrToStructure<KeyboardInput>(data);
                if (filter.Suppress((int)input.Key, message is 0x101 or 0x105,
                    (input.Flags & 0x20) != 0, (GetAsyncKeyState(0x11) & 0x8000) != 0)) return 1;
            }
            return CallNextHookEx(IntPtr.Zero, code, message, data);
        };
        var hook = IntPtr.Zero;
        try
        {
            _threadId = GetCurrentThreadId();
            PeekMessage(out _, IntPtr.Zero, 0, 0, 0);
            hook = SetWindowsHookEx(13, callback, GetModuleHandle(null), 0);
            if (hook == IntPtr.Zero) throw new Win32Exception(Marshal.GetLastWin32Error());
            started.SetResult();
            while (GetMessage(out var message, IntPtr.Zero, 0, 0) > 0)
            {
                TranslateMessage(ref message);
                DispatchMessage(ref message);
            }
        }
        catch (Exception ex) { started.TrySetException(ex); }
        finally
        {
            if (hook != IntPtr.Zero) UnhookWindowsHookEx(hook);
            GC.KeepAlive(callback);
        }
    }

    public void Remove()
    {
        if (_thread is null) return;
        if (_thread.IsAlive && !PostThreadMessage(_threadId, 0x12, IntPtr.Zero, IntPtr.Zero))
            throw new Win32Exception(Marshal.GetLastWin32Error());
        _thread.Join();
        _thread = null;
    }

    private delegate nint HookProc(int code, nint message, nint data);
    [StructLayout(LayoutKind.Sequential)] private struct KeyboardInput { public uint Key, Scan, Flags, Time; public nuint Extra; }
    [StructLayout(LayoutKind.Sequential)] private struct Message
    {
        public nint Window; public uint Id; public nuint WParam; public nint LParam;
        public uint Time; public int X, Y; public uint Private;
    }
    [DllImport("user32.dll", SetLastError = true)] private static extern nint SetWindowsHookEx(int id, HookProc callback, nint module, uint thread);
    [DllImport("user32.dll")] [return: MarshalAs(UnmanagedType.Bool)] private static extern bool UnhookWindowsHookEx(nint hook);
    [DllImport("user32.dll")] private static extern nint CallNextHookEx(nint hook, int code, nint message, nint data);
    [DllImport("user32.dll")] private static extern short GetAsyncKeyState(int key);
    [DllImport("kernel32.dll", CharSet = CharSet.Unicode)] private static extern nint GetModuleHandle(string? name);
    [DllImport("kernel32.dll")] private static extern uint GetCurrentThreadId();
    [DllImport("user32.dll")] [return: MarshalAs(UnmanagedType.Bool)] private static extern bool PeekMessage(out Message message, nint window, uint min, uint max, uint remove);
    [DllImport("user32.dll", SetLastError = true)] private static extern int GetMessage(out Message message, nint window, uint min, uint max);
    [DllImport("user32.dll")] [return: MarshalAs(UnmanagedType.Bool)] private static extern bool TranslateMessage(ref Message message);
    [DllImport("user32.dll")] private static extern nint DispatchMessage(ref Message message);
    [DllImport("user32.dll", SetLastError = true)] [return: MarshalAs(UnmanagedType.Bool)] private static extern bool PostThreadMessage(uint thread, uint message, nint wParam, nint lParam);
}
