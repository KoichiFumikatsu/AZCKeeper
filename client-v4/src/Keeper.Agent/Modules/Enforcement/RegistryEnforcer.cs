using Keeper.Shared.Contracts;
using Keeper.Shared.Protocol;

namespace Keeper.Agent.Modules.Enforcement;

public abstract class RegistryEnforcer(ISystemPolicyStore store) : IModule
{
    protected ISystemPolicyStore Store { get; } = store;
    protected ModuleContext Context { get; private set; } = null!;
    private EffectivePolicy? _desired;
    private DateTimeOffset _nextRetry;
    private int _failures;
    private (string State, string? Error)? _reported;
    private ModuleSnapshot _snapshot = new("uninitialized", null, null, "unknown");
    public abstract string Name { get; }

    public Task InitAsync(ModuleContext ctx)
    {
        Context = ctx;
        _snapshot = _snapshot with { Name = Name };
        return Task.CompletedTask;
    }

    public async Task ApplyPolicyAsync(EffectivePolicy p)
    {
        if (_desired?.Version == p.Version && _snapshot.State == "failed" && Context.Clock.GetUtcNow() < _nextRetry) return;
        if (_desired?.Version != p.Version) _failures = 0;
        _desired = p;
        _snapshot = _snapshot with { DesiredVersion = p.Version };
        await ReconcileAsync(Context.StoppingToken);
    }

    private async Task ReconcileAsync(CancellationToken ct)
    {
        ct.ThrowIfCancellationRequested();
        if (_desired is null) return;
        try
        {
            try
            {
                Apply(_desired);
                var dryRun = Store.IsDryRun;
                _snapshot = _snapshot with { AppliedVersion = dryRun ? null : _desired.Version,
                    State = dryRun ? "dry_run" : "applied", ErrorCode = dryRun ? "dry_run" : null };
            }
            catch (NotSupportedException)
            {
                // Also withdraw persisted restrictions left by a previous process or a partial write.
                Clear();
                _snapshot = _snapshot with { AppliedVersion = null, State = "unsupported",
                    ErrorCode = Store.IsDryRun ? "unsupported_cleanup_dry_run" : "unsupported_rule" };
            }
            _failures = 0;
        }
        catch (Exception ex) when (ex is not OperationCanceledException)
        {
            _snapshot = _snapshot with { AppliedVersion = null, State = "failed", ErrorCode = ex.GetType().Name };
            _failures = Math.Min(_failures + 1, 3);
            _nextRetry = Context.Clock.GetUtcNow().AddMinutes(_failures switch { 1 => 1, 2 => 5, _ => 15 });
        }
        var report = (_snapshot.State, _snapshot.ErrorCode);
        if (_reported == report) return;
        await Context.Outbox.EnqueueAsync(new LogEntry
        {
            EventId = Guid.NewGuid(), At = Context.Clock.GetUtcNow(), Component = Name,
            Level = _snapshot.State is "failed" or "unsupported" or "dry_run" ? LogEntryLevel.Warn : LogEntryLevel.Info,
            Code = _snapshot.State
        }, ct);
        _reported = report;
    }

    protected abstract void Apply(EffectivePolicy policy);
    protected abstract void Clear();
    public Task TickAsync(CancellationToken ct) => _snapshot.State == "failed" && Context.Clock.GetUtcNow() >= _nextRetry
        ? ReconcileAsync(ct) : Task.CompletedTask;
    public ModuleSnapshot Snapshot() => _snapshot;
    public Task ShutdownAsync() => Task.CompletedTask;
}
