using Keeper.Shared.Protocol;

namespace Keeper.Shared.Contracts;

public interface IModule
{
    string Name { get; }
    Task InitAsync(ModuleContext ctx);
    Task ApplyPolicyAsync(EffectivePolicy p);
    Task TickAsync(CancellationToken ct);
    ModuleSnapshot Snapshot();
    Task ShutdownAsync();
}

public sealed record ModuleSnapshot(string Name, string? DesiredVersion, string? AppliedVersion,
    string State, string? ErrorCode = null);

public sealed record ModuleContext(IEventSink Outbox, TimeProvider Clock,
    Action<string> Log, CancellationToken StoppingToken);

public interface IEventSink
{
    Task EnqueueAsync(Episode episode, CancellationToken ct);
    Task EnqueueAsync(LogEntry log, CancellationToken ct);
    Task EnqueueAsync(SyncRequestCommandResultsItem result, CancellationToken ct);
    Task EnqueueAsync(SecurityReport report, CancellationToken ct);
    /// Contadores absolutos del dia: el servidor hace upsert monotonico, asi que reenviar
    /// el mismo dia corrige en vez de duplicar.
    Task EnqueueAsync(ActivitySnapshot activity, CancellationToken ct);
}

