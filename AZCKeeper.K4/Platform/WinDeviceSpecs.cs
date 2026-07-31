using System.Management;
using System.Net.NetworkInformation;
using System.Net.Sockets;
using System.Runtime.InteropServices;
using System.Runtime.Versioning;

namespace AZCKeeper.K4.Platform;

/// <summary>
/// Recoge las especificaciones del equipo UNA vez (al arrancar) para mostrarlas en el panel:
/// SO/CPU/RAM/disco, nombre/fabricante/modelo/serial, IP/MAC, GPU y monitores. Cada bloque va
/// en su try/catch: si WMI falla para uno, los demas igual salen (nunca tumba el cliente).
///
/// Devuelve un diccionario serializable (lo envia el handshake tal cual). Todo aqui es de solo
/// lectura y no requiere admin.
/// </summary>
[SupportedOSPlatform("windows")]
public static class WinDeviceSpecs
{
    public static Dictionary<string, object?> Collect()
    {
        var s = new Dictionary<string, object?>();

        Try(() => s["os"] = RuntimeInformation.OSDescription.Trim());
        Try(() => s["hostname"] = Environment.MachineName);
        Try(() => s["arch"] = RuntimeInformation.OSArchitecture.ToString());

        // CPU + RAM + fabricante/modelo/serial via WMI.
        Try(() => s["cpu"] = WmiFirst("Win32_Processor", "Name")?.Trim());
        Try(() =>
        {
            var cs = WmiFirstRow("Win32_ComputerSystem");
            if (cs is not null)
            {
                s["manufacturer"] = (cs["Manufacturer"] as string)?.Trim();
                s["model"] = (cs["Model"] as string)?.Trim();
                if (cs["TotalPhysicalMemory"] is not null &&
                    ulong.TryParse(cs["TotalPhysicalMemory"].ToString(), out var bytes))
                    s["ramGB"] = Math.Round(bytes / 1073741824.0, 1);
            }
        });
        Try(() => s["serial"] = WmiFirst("Win32_BIOS", "SerialNumber")?.Trim());
        Try(() => s["gpu"] = WmiFirst("Win32_VideoController", "Name")?.Trim());

        // Disco del sistema (donde corre Windows).
        Try(() =>
        {
            var sysRoot = Path.GetPathRoot(Environment.GetFolderPath(Environment.SpecialFolder.Windows)) ?? "C:\\";
            var di = new DriveInfo(sysRoot);
            if (di.IsReady)
            {
                s["diskTotalGB"] = Math.Round(di.TotalSize / 1073741824.0, 0);
                s["diskFreeGB"] = Math.Round(di.AvailableFreeSpace / 1073741824.0, 0);
            }
        });

        // Red: primera interfaz operativa no-loopback (IP local + MAC).
        Try(() =>
        {
            var nic = NetworkInterface.GetAllNetworkInterfaces()
                .Where(n => n.OperationalStatus == OperationalStatus.Up
                         && n.NetworkInterfaceType != NetworkInterfaceType.Loopback
                         && n.NetworkInterfaceType != NetworkInterfaceType.Tunnel)
                .OrderByDescending(n => n.Speed)
                .FirstOrDefault();
            if (nic is not null)
            {
                var ip = nic.GetIPProperties().UnicastAddresses
                    .FirstOrDefault(a => a.Address.AddressFamily == AddressFamily.InterNetwork)?.Address.ToString();
                if (ip is not null) s["ip"] = ip;
                var mac = nic.GetPhysicalAddress().ToString();
                if (!string.IsNullOrEmpty(mac))
                    s["mac"] = string.Join(":", Enumerable.Range(0, mac.Length / 2).Select(i => mac.Substring(i * 2, 2)));
            }
        });

        // Monitores (cantidad + resoluciones). Usa WinForms Screen.
        Try(() =>
        {
            var screens = System.Windows.Forms.Screen.AllScreens;
            s["monitors"] = screens.Length;
            s["resolutions"] = screens.Select(sc => $"{sc.Bounds.Width}x{sc.Bounds.Height}").ToArray();
        });

        return s;
    }

    private static void Try(Action a) { try { a(); } catch { /* un bloque que falle no arrastra al resto */ } }

    private static string? WmiFirst(string cls, string prop)
        => WmiFirstRow(cls)?[prop]?.ToString();

    private static ManagementBaseObject? WmiFirstRow(string cls)
    {
        using var searcher = new ManagementObjectSearcher($"SELECT * FROM {cls}");
        foreach (ManagementObject o in searcher.Get()) return o;
        return null;
    }
}
