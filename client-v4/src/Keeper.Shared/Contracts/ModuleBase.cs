using Keeper.Shared.Protocol;

namespace Keeper.Shared.Contracts;

public abstract class ModuleBase : IModule
{
    protected ModuleContext Context { get; private set; } = null!;
    protected EffectivePolicy? Policy { get; private set; }
    protected string State { get; set; } = "unknown";
    public abstract string Name { get; }
    public virtual Task InitAsync(ModuleContext ctx) { Context = ctx; State = "ready"; return Task.CompletedTask; }
    public virtual Task ApplyPolicyAsync(EffectivePolicy policy) { Policy = policy; return Task.CompletedTask; }
    public virtual Task TickAsync(CancellationToken ct) { ct.ThrowIfCancellationRequested(); return Task.CompletedTask; }
    public virtual ModuleSnapshot Snapshot() => new(Name, Policy?.Version, Policy?.Version, State);
    public virtual Task ShutdownAsync() => Task.CompletedTask;
    protected Task ReportAsync(string code, CancellationToken ct, LogEntryLevel level = LogEntryLevel.Info)
    {
        // Copia local (log de archivo del equipo); el LogEntry sigue viajando al servidor por el outbox.
        Context.Log($"{Name} {level.ToString().ToLowerInvariant()}: {code}");
        return Context.Outbox.EnqueueAsync(new LogEntry
        {
            EventId = Guid.NewGuid(), At = Context.Clock.GetUtcNow(), Level = level,
            Component = Name, Code = code
        }, ct);
    }
}
