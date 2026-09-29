using System.Runtime.InteropServices;
using System.Text;

namespace Keeper.Agent.Modules.Devices;

// Serie del fabricante desde SMBIOS (estructura tipo 1, "System Information", campo Serial Number en el offset 7).
// Sirve para cruzar el equipo con la placa de activo del Portal AZC. Sin WMI ni paquetes: GetSystemFirmwareTable.
public static class Smbios
{
    // Valores de relleno de fabricantes genericos/VM: no identifican el equipo y no deben cruzarse con el portal.
    private static readonly string[] Placeholders =
        ["to be filled by o.e.m.", "default string", "system serial number", "not specified", "none", "n/a", "0", "0000000000", "123456789", "serial number"];

    // raw = RawSMBIOSData: 8 bytes de cabecera (metodo, version mayor/menor, revision DMI, longitud UInt32) + tabla.
    public static string? SystemSerial(ReadOnlySpan<byte> raw)
    {
        if (raw.Length < 8) return null;
        var length = BitConverter.ToInt32(raw.Slice(4, 4));
        var table = raw.Slice(8, Math.Min(length, raw.Length - 8));
        var offset = 0;
        while (offset + 4 <= table.Length)
        {
            var type = table[offset];
            var formatted = table[offset + 1];
            if (formatted < 4 || offset + formatted > table.Length) return null;
            var strings = offset + formatted;
            var end = strings;
            while (end + 1 < table.Length && !(table[end] == 0 && table[end + 1] == 0)) end++;
            if (type == 1 && formatted > 7)
            {
                var index = table[offset + 7];
                return index == 0 ? null : Clean(StringAt(table[strings..Math.Min(end + 1, table.Length)], index));
            }
            if (type == 127) return null;
            offset = end + 2;
        }
        return null;
    }

    private static string? StringAt(ReadOnlySpan<byte> area, int index)
    {
        var current = 1; var start = 0;
        for (var i = 0; i <= area.Length; i++)
        {
            if (i < area.Length && area[i] != 0) continue;
            if (current == index) return Encoding.ASCII.GetString(area[start..i]);
            current++; start = i + 1;
            if (start >= area.Length) break;
        }
        return null;
    }

    private static string? Clean(string? value)
    {
        var trimmed = value?.Trim();
        if (string.IsNullOrEmpty(trimmed) || trimmed.Length > 120) return null;
        return Placeholders.Contains(trimmed.ToLowerInvariant()) || trimmed.All(c => c == '0' || c == ' ' || c == '.') ? null : trimmed;
    }

    [System.Runtime.Versioning.SupportedOSPlatform("windows")]
    public static string? ReadSystemSerial()
    {
        const uint Rsmb = 0x52534D42; // 'RSMB'
        var size = GetSystemFirmwareTable(Rsmb, 0, null, 0);
        if (size == 0) return null;
        var buffer = new byte[size];
        return GetSystemFirmwareTable(Rsmb, 0, buffer, size) == size ? SystemSerial(buffer) : null;
    }

    [DllImport("kernel32.dll", SetLastError = true)]
    private static extern uint GetSystemFirmwareTable(uint provider, uint tableId, byte[]? buffer, uint bufferSize);
}
