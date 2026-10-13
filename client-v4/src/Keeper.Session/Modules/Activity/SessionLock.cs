using System.Runtime.InteropServices;
using Microsoft.Win32;

namespace Keeper.Session.Modules.Activity;

/// <summary>
/// Pantalla de bloqueo de Windows de esta sesion (4.0.24). El estado inicial se consulta con WTSQuerySessionInformation
/// (WTSSessionInfoEx, SessionFlags); si la consulta falla se asume desbloqueada. Despues lo mantiene SystemEvents.SessionSwitch
/// (SessionLock/SessionUnlock), que necesita crearse en un hilo con bomba de mensajes (el STA de Application.Run).
/// </summary>
public sealed class WindowsSessionLockSource : IDisposable
{
    private volatile bool _locked;
    public bool IsLocked => _locked;

    public WindowsSessionLockSource()
    {
        _locked = QueryLocked() ?? false;
        SystemEvents.SessionSwitch += OnSwitch;
    }

    private void OnSwitch(object? sender, SessionSwitchEventArgs e)
    {
        if (e.Reason == SessionSwitchReason.SessionLock) _locked = true;
        else if (e.Reason == SessionSwitchReason.SessionUnlock) _locked = false;
    }

    public void Dispose() => SystemEvents.SessionSwitch -= OnSwitch;

    // WTSINFOEXW: DWORD Level; WTSINFOEX_LEVEL1_W { ULONG SessionId; WTS_CONNECTSTATE_CLASS SessionState; LONG SessionFlags; ... }.
    // La union empieza en 8 (lleva LARGE_INTEGER, alineada a 8 en x86 y x64): SessionFlags queda en 8 + 4 + 4 = 16.
    // SessionFlags: 0 = bloqueada, 1 = desbloqueada, -1 = desconocido. (En Windows 7/2008 R2 venia invertido; Keeper 4 es 10+.)
    private static bool? QueryLocked()
    {
        const int WTSSessionInfoEx = 25, Level = 1, FlagsOffset = 16, Locked = 0, Unlocked = 1;
        if (!WTSQuerySessionInformationW(IntPtr.Zero, -1, WTSSessionInfoEx, out var buffer, out var bytes)) return null;
        try
        {
            if (bytes < FlagsOffset + 4 || Marshal.ReadInt32(buffer) != Level) return null;
            return Marshal.ReadInt32(buffer, FlagsOffset) switch { Locked => true, Unlocked => false, _ => null };
        }
        finally { WTSFreeMemory(buffer); }
    }

    [DllImport("wtsapi32.dll", SetLastError = true)] [return: MarshalAs(UnmanagedType.Bool)]
    private static extern bool WTSQuerySessionInformationW(IntPtr server, int sessionId, int infoClass, out IntPtr buffer, out int bytes);
    [DllImport("wtsapi32.dll")] private static extern void WTSFreeMemory(IntPtr memory);
}
