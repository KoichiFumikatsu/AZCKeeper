using System.Text.Json;
using Keeper.Agent.Storage;
using Keeper.Agent.Transport;
using Keeper.Shared.Diagnostics;

namespace Keeper.Agent.Hosting;

public sealed class Scheduler(ModuleHost host, ISyncCycle? sync, SyncSchedule schedule, TimeProvider clock,
    string statePath, Action<string> log, AgentHealthFile? health = null) : IDisposable
{
    private readonly SemaphoreSlim _gate = new(1, 1);
    private long? _dueTimestamp;

    public async Task RunAsync(CancellationToken ct)
    {
        Task? network = null;
        try
        {
            while (!ct.IsCancellationRequested)
            {
                await host.TickAsync(ct);
                health?.Alive(clock.GetUtcNow());
                if (network is null || network.IsCompleted)
                {
                    if (network is not null) await network;
                    network = StepAsync(ct, tickModules: false);
                }
                await Task.Delay(TimeSpan.FromSeconds(1), clock, ct);
            }
        }
        finally { if (network is not null) await network; }
    }

    public async Task StepAsync(CancellationToken ct, bool tickModules = true)
    {
        await _gate.WaitAsync(ct);
        try
        {
            if (tickModules) await host.TickAsync(ct);
            if (sync is null) return;
            if (_dueTimestamp is null)
            {
                var delay = schedule.InitialDelay;
                if (File.Exists(statePath))
                {
                    var due = JsonSerializer.Deserialize<DateTimeOffset>(await File.ReadAllBytesAsync(statePath, ct));
                    delay = due - clock.GetUtcNow();
                    if (delay < TimeSpan.Zero) delay = schedule.InitialDelay;
                }
                await SetNextAsync(delay, ct);
            }
            if (clock.GetTimestamp() < _dueTimestamp) return;
            // Reserve challenge, login, sync, policy and hardening GETs across a process restart.
            await SetNextAsync(TimeSpan.FromSeconds(schedule.IntervalSeconds * 5), ct);
            var before = sync.RequestCount;
            var success = false;
            var serverSeconds = 120;
            TimeSpan? retryAfter = null;
            var failureKind = NetworkFailureKind.Transient;
            try
            {
                serverSeconds = await sync.ExecuteAsync(ct);
                success = true;
                health?.SyncOk(clock.GetUtcNow());
            }
            catch (OperationCanceledException) when (ct.IsCancellationRequested) { throw; }
            catch (Exception ex)
            {
                if (ex is TransportException transport) retryAfter = transport.RetryAfter;
                failureKind = NetworkBackoffPolicy.Classify(ex);
                log($"sync_failed: {(ex is EnrollmentException ? ex.Message : ex.GetType().Name)}");
            }
            var requests = checked((int)(sync.RequestCount - before));
            var next = schedule.AfterAttempt(success, requests, serverSeconds, retryAfter, failureKind);
            if (success) log($"sync_ok: {requests} peticion(es); proximo en {(int)next.TotalSeconds}s");
            else log($"sync_retry: {failureKind}; proximo en {(int)next.TotalSeconds}s");
            await SetNextAsync(next, ct);
        }
        finally { _gate.Release(); }
    }

    private async Task SetNextAsync(TimeSpan delay, CancellationToken ct)
    {
        await AtomicFile.WriteAsync(statePath, JsonSerializer.SerializeToUtf8Bytes(clock.GetUtcNow() + delay), ct);
        _dueTimestamp = clock.GetTimestamp() + checked((long)(delay.TotalSeconds * clock.TimestampFrequency));
    }

    public void Dispose() => _gate.Dispose();
}
