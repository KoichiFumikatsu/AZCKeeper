using System.Diagnostics;
using System.Runtime.InteropServices;
using System.Runtime.Versioning;
using System.Text;
using AZCKeeper.K4.Contracts;

namespace AZCKeeper.K4.Platform;

/// <summary>Ventana activa vía user32. Windows-only. Ante cualquier error devuelve null.</summary>
[SupportedOSPlatform("windows")]
public sealed class WinForegroundWindow : IForegroundWindow
{
    [DllImport("user32.dll")] private static extern IntPtr GetForegroundWindow();
    [DllImport("user32.dll")] private static extern int GetWindowText(IntPtr hWnd, StringBuilder text, int count);
    [DllImport("user32.dll")] private static extern uint GetWindowThreadProcessId(IntPtr hWnd, out uint processId);

    public string? ProcessName
    {
        get
        {
            try
            {
                var hwnd = GetForegroundWindow();
                if (hwnd == IntPtr.Zero) return null;
                GetWindowThreadProcessId(hwnd, out uint pid);
                if (pid == 0) return null;
                using var p = Process.GetProcessById((int)pid);
                return p.ProcessName + ".exe";
            }
            catch { return null; }
        }
    }

    public string? Title
    {
        get
        {
            try
            {
                var hwnd = GetForegroundWindow();
                if (hwnd == IntPtr.Zero) return null;
                var sb = new StringBuilder(512);
                int len = GetWindowText(hwnd, sb, sb.Capacity);
                return len > 0 ? sb.ToString() : null;
            }
            catch { return null; }
        }
    }
}
