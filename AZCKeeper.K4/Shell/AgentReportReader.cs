using System.Text.Json.Nodes;

namespace AZCKeeper.K4.Shell;

/// <summary>
/// Estado del courier del agente elevado, leído del archivo local.
/// - Present=false: no hay archivo (agente no instalado / nunca reportó).
/// - Stale=true: el reporte es viejo (el agente pudo morir/colgarse). El panel igual lo
///   detecta por reportedAt, pero el cliente marca la señal.
/// - Enforcement: el bloque tal cual, para reenviarlo a client/security/report.
/// </summary>
public sealed record AgentCourierState(bool Present, bool Stale, JsonNode? Enforcement);

/// <summary>
/// Lee el reporte que el agente elevado (proceso SYSTEM) deja en
/// %ProgramData%\AZCKeeper\agent-report.json y lo entrega para que el cliente lo
/// transporte al servidor. El cliente NO produce este dato: solo lo transporta. Si el
/// archivo falta, está corrupto, o es viejo, lo refleja sin reventar — un cliente no debe
/// caerse porque el agente no esté.
/// </summary>
public sealed class AgentReportReader
{
    private readonly string _path;
    private readonly TimeSpan _maxAge;
    private readonly Func<DateTime> _nowUtc;

    public AgentReportReader(string? path = null, TimeSpan? maxAge = null, Func<DateTime>? nowUtc = null)
    {
        _path = path ?? K4Paths.AgentReportFile;
        _maxAge = maxAge ?? TimeSpan.FromMinutes(15);
        _nowUtc = nowUtc ?? (() => DateTime.UtcNow);
    }

    public AgentCourierState Read()
    {
        JsonNode? node;
        try
        {
            if (!File.Exists(_path)) return new AgentCourierState(false, false, null);
            var text = File.ReadAllText(_path);
            node = JsonNode.Parse(text);
        }
        catch (Exception ex) when (ex is System.Text.Json.JsonException or IOException)
        {
            return new AgentCourierState(false, false, null); // corrupto/ilegible = como ausente
        }
        if (node is null) return new AgentCourierState(false, false, null);

        bool stale = true; // sin reportedAt válido se trata como viejo (sospechoso)
        var reportedAt = node["reportedAt"]?.GetValue<string>();
        if (!string.IsNullOrWhiteSpace(reportedAt)
            && DateTime.TryParse(reportedAt, null, System.Globalization.DateTimeStyles.AdjustToUniversal | System.Globalization.DateTimeStyles.AssumeUniversal, out var when))
        {
            stale = (_nowUtc() - when) > _maxAge;
        }

        return new AgentCourierState(true, stale, node);
    }
}
