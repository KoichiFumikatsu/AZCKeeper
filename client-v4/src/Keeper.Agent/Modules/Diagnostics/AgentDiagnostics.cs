using Keeper.Shared.Contracts;
using Keeper.Shared.Protocol;
using System.Text.Json;
using System.Security.Cryptography;

namespace Keeper.Agent.Modules.Diagnostics;

public sealed class AgentDiagnostics(Func<IReadOnlyList<ModuleSnapshot>> snapshots) : ModuleBase
{
    public override string Name => "AgentDiagnostics";
    private DateTimeOffset _next;
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
        await ReportAsync(State, ct);
        await Context.Outbox.EnqueueAsync(new SecurityReport
        {
            EventId = Guid.NewGuid(), ObservedAt = Context.Clock.GetUtcNow(), Controls = controls,
            ReportHash = Convert.ToHexString(SHA256.HashData(JsonSerializer.SerializeToUtf8Bytes(controls, ProtocolJson.Options))).ToLowerInvariant()
        }, ct);
    }
}
