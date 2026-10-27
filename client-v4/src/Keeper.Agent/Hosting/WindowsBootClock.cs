using System.Diagnostics.Eventing.Reader;
using System.Globalization;
using System.Runtime.Versioning;
using System.Xml.Linq;

namespace Keeper.Agent.Hosting;

/// <summary>Evento 27 de Microsoft-Windows-Kernel-Boot: BootType 0x0 frío, 0x1 híbrido (Inicio rápido), 0x2 reanudación de hibernación.</summary>
public sealed record BootEvent(DateTimeOffset TimeCreated, long? BootType);

/// <summary>
/// Hora del último encendido para el apagado programado. TickCount64 no basta: con Inicio rápido el «apagado» es una
/// hibernación del kernel y el contador sigue desde el arranque en frío anterior, así que un equipo encendido dentro de
/// la ventana parecería suspendido. Se toma el más reciente entre el arranque según TickCount64 y el último evento 27 frío
/// o híbrido del log System. La reanudación de hibernación (0x2) y la suspensión S3 siguen contando como suspensión.
/// El log se lee como mucho cada 60 s; cualquier error deja TickCount64.
/// </summary>
public sealed class WindowsBootClock(Func<DateTimeOffset> tickBoot, Func<IReadOnlyList<BootEvent>> readEvents, TimeProvider clock,
    Action<string>? log = null)
{
    public static readonly TimeSpan RefreshEvery = TimeSpan.FromSeconds(60);
    private const long ResumeFromHibernate = 2;
    private readonly object _lock = new();
    private DateTimeOffset? _readAt;
    private DateTimeOffset? _eventBoot;
    private bool _failureLogged;

    public DateTimeOffset BootTimeUtc()
    {
        var tick = tickBoot();
        var now = clock.GetUtcNow();
        lock (_lock)
        {
            if (_readAt is not { } read || now - read >= RefreshEvery || now < read)
            {
                _readAt = now;
                try
                {
                    // Un BootType ilegible cuenta como encendido: ante la duda no se trata como suspendido.
                    _eventBoot = readEvents().Where(e => e.BootType != ResumeFromHibernate).Select(e => (DateTimeOffset?)e.TimeCreated).Max();
                    _failureLogged = false;
                }
                catch (Exception ex)
                {
                    _eventBoot = null;
                    if (!_failureLogged) log?.Invoke($"WindowsBootClock warn: log System ilegible ({ex.GetType().Name}: {ex.Message}); se usa TickCount64");
                    _failureLogged = true;
                }
            }
            return _eventBoot is { } boot && boot > tick ? boot : tick;
        }
    }

    /// <summary>Los 5 eventos 27 de Kernel-Boot más recientes del log System, del más nuevo al más viejo.</summary>
    [SupportedOSPlatform("windows")]
    public static IReadOnlyList<BootEvent> ReadSystemLog()
    {
        var query = new EventLogQuery("System", PathType.LogName,
            "*[System[Provider[@Name='Microsoft-Windows-Kernel-Boot'] and (EventID=27)]]") { ReverseDirection = true };
        using var reader = new EventLogReader(query);
        var events = new List<BootEvent>();
        while (events.Count < 5 && reader.ReadEvent() is { } record)
        {
            using (record)
            {
                if (record.TimeCreated is not { } created) continue;
                events.Add(new(new DateTimeOffset(created).ToUniversalTime(), BootType(record.ToXml())));
            }
        }
        return events;
    }

    internal static long? BootType(string xml)
    {
        var value = XElement.Parse(xml).Descendants().FirstOrDefault(e => e.Name.LocalName == "Data" && (string?)e.Attribute("Name") == "BootType")?.Value.Trim();
        if (value is null) return null;
        if (value.StartsWith("0x", StringComparison.OrdinalIgnoreCase))
            return long.TryParse(value[2..], NumberStyles.HexNumber, CultureInfo.InvariantCulture, out var hex) ? hex : null;
        return long.TryParse(value, NumberStyles.Integer, CultureInfo.InvariantCulture, out var number) ? number : null;
    }
}
