using Keeper.Shared.Contracts;
using Keeper.Shared.Protocol;
using System.Text.Json;
using System.Security.Cryptography;

namespace Keeper.Agent.Modules.Diagnostics;

public sealed class AgentDiagnostics(Func<IReadOnlyList<ModuleSnapshot>> snapshots) : ModuleBase
{
    public override string Name => "AgentDiagnostics";
    private DateTimeOffset _next;
    // Se evalua cada 5 min, pero solo se registra/envia lo que cambio: antes salian un log "degraded" y un
    // SecurityReport cada 5 min aunque nada cambiara (288 filas/dia por equipo). El reporte se reenvia igual cada
    // hora como senal de vida, para que la ficha no muestre un "ultimo reporte" viejo en un equipo sano.
    private static readonly TimeSpan Heartbeat = TimeSpan.FromHours(1);
    private string? _lastState;
    private string? _lastSignature;
    private DateTimeOffset _lastReportAt;
    public IReadOnlyList<ModuleSnapshot> Health { get; private set; } = [];
    public override async Task TickAsync(CancellationToken ct)
    {
        if (Context.Clock.GetUtcNow() < _next) return;
        _next = Context.Clock.GetUtcNow().AddMinutes(5);
        Health = snapshots().Where(m => m.Name != Name).ToArray();
        var controls = Health.Select(m => new SecurityControl
        {
            ControlId = m.Name, ObservedAt = Context.Clock.GetUtcNow(), ErrorCode = m.ErrorCode ??
                (m.State is "applied" or "ready" or "locked" or "unlocked" or "no_interactive_session" or "current" ? null : m.State),
            State = m.State switch
            {
                "applied" or "ready" or "locked" or "unlocked" or "no_interactive_session" or "current" => SecurityControlState.Applied,
                "failed" or "degraded" or "dry_run" or "pending_restart" => SecurityControlState.Failed,
                "unsupported" => SecurityControlState.Unsupported,
                "unknown" or "audit" or "awaiting_package" or "verified_pending_install" => SecurityControlState.Unknown,
                _ when m.State.StartsWith("failed_step_", StringComparison.Ordinal) || m.State == "recovery_required" => SecurityControlState.Failed,
                _ => SecurityControlState.Unknown
            }
        }).ToArray();
        State = controls.Any(c => c.State != SecurityControlState.Applied) ? "degraded" : "ready";
        if (State != _lastState) { await ReportAsync(State, ct); _lastState = State; }
        var signature = string.Join('|', controls.Select(c => $"{c.ControlId}:{c.State}:{c.ErrorCode}"));
        var now = Context.Clock.GetUtcNow();
        if (signature == _lastSignature && now - _lastReportAt < Heartbeat) return;
        _lastSignature = signature; _lastReportAt = now;
        await Context.Outbox.EnqueueAsync(new SecurityReport
        {
            EventId = Guid.NewGuid(), ObservedAt = Context.Clock.GetUtcNow(), Controls = controls,
            ReportHash = Convert.ToHexString(SHA256.HashData(JsonSerializer.SerializeToUtf8Bytes(controls, ProtocolJson.Options))).ToLowerInvariant()
        }, ct);
    }
}
