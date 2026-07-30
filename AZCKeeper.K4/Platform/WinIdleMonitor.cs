using System.Runtime.InteropServices;
using System.Runtime.Versioning;
using AZCKeeper.K4.Contracts;

namespace AZCKeeper.K4.Platform;

/// <summary>Segundos de inactividad (sin teclado/ratón) vía GetLastInputInfo. Windows-only.</summary>
[SupportedOSPlatform("windows")]
public sealed class WinIdleMonitor : IIdleMonitor
{
    [StructLayout(LayoutKind.Sequential)]
    private struct LASTINPUTINFO { public uint cbSize; public uint dwTime; }

    [DllImport("user32.dll")] private static extern bool GetLastInputInfo(ref LASTINPUTINFO plii);

    public int IdleSeconds
    {
        get
        {
            try
            {
                var info = new LASTINPUTINFO { cbSize = (uint)Marshal.SizeOf<LASTINPUTINFO>() };
                if (!GetLastInputInfo(ref info)) return 0;
                uint idleMs = (uint)Environment.TickCount - info.dwTime;
                return (int)(idleMs / 1000);
            }
            catch { return 0; }
        }
    }
}
