using Keeper.Shared.Contracts;
using Keeper.Shared.Protocol;

namespace Keeper.Agent.Hosting;

public sealed class ModuleHost(IEnumerable<IModule> modules, ModuleContext context) : IAsyncDisposable
{
    private readonly IModule[] _modules = modules.ToArray();
    private readonly Dictionary<(string Module, string Stage), string> _failures = new();
    private readonly HashSet<string> _initialized = [];
    private readonly SemaphoreSlim _gate = new(1, 1);
    // Un modulo que falla en cada tick (1/s) llenaba el log (6,8 MB en un dia con la cola llena): tras un fallo se
    // espera 2, 4, 8... hasta 300 s antes de volver a intentarlo, y el mismo error no se vuelve a escribir.
    private readonly Dictionary<string, (int Failures, DateTimeOffset NextTry)> _backoff = new();
    private bool _stopped;

    public Task InitAsync() => DispatchAsync(async m =>
    {
        if (_initialized.Contains(m.Name)) return;
        await m.InitAsync(context);
        _initialized.Add(m.Name);
    }, context.StoppingToken, "init");
    public Task ApplyPolicyAsync(EffectivePolicy p) => DispatchAsync(m => m.ApplyPolicyAsync(p), context.StoppingToken, "policy");
    public Task TickAsync(CancellationToken ct) => DispatchAsync(m => m.TickAsync(ct), ct, "tick");
    public IReadOnlyList<ModuleSnapshot> Snapshot() => _modules.Select(m =>
        _failures.FirstOrDefault(f => f.Key.Module == m.Name).Value is { } error
            ? m.Snapshot() with { State = "failed", ErrorCode = error } : m.Snapshot()).ToArray();

    private async Task DispatchAsync(Func<IModule, Task> action, CancellationToken ct, string stage)
    {
        await _gate.WaitAsync(ct);
        try
        {
            foreach (var module in stage == "shutdown" ? _modules.Reverse() : _modules)
            {
                ct.ThrowIfCancellationRequested();
                if (stage is not ("init" or "shutdown") && _failures.ContainsKey((module.Name, "init"))) continue;
                var now = context.Clock.GetUtcNow();
                if (stage == "tick" && _backoff.TryGetValue(module.Name, out var wait) && now < wait.NextTry) continue;
                try
                {
                    await action(module);
                    _failures.Remove((module.Name, stage));
                    if (stage == "tick") _backoff.Remove(module.Name);
                }
                catch (OperationCanceledException) when (ct.IsCancellationRequested) { throw; }
                catch (Exception ex)
                {
                    var repeated = _failures.TryGetValue((module.Name, stage), out var previous) && previous == ex.GetType().Name;
                    _failures[(module.Name, stage)] = ex.GetType().Name;
                    if (stage == "tick")
                    {
                        var failures = _backoff.TryGetValue(module.Name, out var b) ? b.Failures + 1 : 1;
                        _backoff[module.Name] = (failures, now + TimeSpan.FromSeconds(Math.Min(300, Math.Pow(2, Math.Min(failures, 9)))));
                    }
                    if (!repeated) context.Log($"{module.Name}: {ex.GetType().Name}");
                }
            }
        }
        finally { _gate.Release(); }
    }

    public async Task ShutdownAsync()
    {
        if (_stopped) return;
        await DispatchAsync(m => m.ShutdownAsync(), CancellationToken.None, "shutdown");
        _stopped = true;
    }
    public async ValueTask DisposeAsync()
    {
        await ShutdownAsync();
        _gate.Dispose();
    }
}
