using Keeper.Agent.Hosting;
using Keeper.Agent.Transport;

namespace Keeper.Agent.Tests;

public sealed class SchedulerTests
{
    [Fact]
    public void StableJitterBackoffAndRetryAfterRespectRequestBudget()
    {
        var schedule = new SyncSchedule(Samples.Device);
        var same = new SyncSchedule(Samples.Device);
        Assert.InRange(schedule.InitialDelay.TotalSeconds, 0, 120);
        Assert.Equal(schedule.InitialDelay, same.InitialDelay);
        for (var requests = 1; requests <= 4; requests++)
        {
            var delay = schedule.AfterAttempt(true, requests);
            Assert.Equal(delay, same.AfterAttempt(true, requests));
            Assert.InRange(delay.TotalSeconds, requests * 120, requests * 120 + 12);
        }
        for (var failure = 1; failure <= 8; failure++)
        {
            var floor = Math.Max(120, Math.Min(900, 5 * Math.Pow(2, failure - 1)));
            Assert.InRange(schedule.AfterAttempt(false, 1).TotalSeconds, floor, floor + 12);
        }
        Assert.InRange(schedule.AfterAttempt(false, 1, retryAfter: TimeSpan.FromHours(2)).TotalSeconds, 7200, 7212);
        Assert.InRange(schedule.AfterAttempt(true, 1).TotalSeconds, 120, 132);
        Assert.InRange(schedule.AfterAttempt(true, 1, 900).TotalSeconds, 900, 912);
        var fleet = Enumerable.Range(0, 30).Select(_ => new SyncSchedule(Guid.NewGuid()).InitialDelay).Distinct();
        Assert.True(fleet.Count() > 1);
    }

    [Fact]
    public async Task SchedulerChargesEveryHttpRequestAndPersistsDeadlineAcrossRestart()
    {
        using var directory = new TestDirectory();
        var clock = new TestClock();
        var module = new TestModule();
        await using var host = new ModuleHost([module], Samples.Context(clock));
        var cycle = new FakeCycle { Cost = 4 };
        var schedule = new SyncSchedule(Samples.Device);
        using (var scheduler = new Scheduler(host, cycle, schedule, clock, directory.File("deadline.json"), _ => { }))
        {
            await scheduler.StepAsync(default);
            clock.Advance(schedule.InitialDelay);
            await scheduler.StepAsync(default);
            Assert.Equal(1, cycle.Calls);
        }
        using var restarted = new Scheduler(host, cycle, new SyncSchedule(Samples.Device), clock, directory.File("deadline.json"), _ => { });
        clock.Advance(TimeSpan.FromSeconds(479));
        await restarted.StepAsync(default);
        Assert.Equal(1, cycle.Calls);
        clock.Advance(TimeSpan.FromSeconds(14));
        await restarted.StepAsync(default);
        Assert.Equal(2, cycle.Calls);
        Assert.True(module.Ticks >= 4);
    }

    [Fact]
    public async Task OverlappingStepsCannotOverlapSyncOrBypassBudget()
    {
        using var directory = new TestDirectory();
        var clock = new TestClock();
        await using var host = new ModuleHost([], Samples.Context(clock));
        var entered = new TaskCompletionSource(TaskCreationOptions.RunContinuationsAsynchronously);
        var release = new TaskCompletionSource(TaskCreationOptions.RunContinuationsAsynchronously);
        var cycle = new FakeCycle { OnExecute = async () => { entered.SetResult(); await release.Task; } };
        var schedule = new SyncSchedule(Samples.Device);
        using var scheduler = new Scheduler(host, cycle, schedule, clock, directory.File("deadline.json"), _ => { });
        await scheduler.StepAsync(default);
        clock.Advance(schedule.InitialDelay);
        var first = scheduler.StepAsync(default);
        await entered.Task.WaitAsync(TimeSpan.FromSeconds(5));
        var second = scheduler.StepAsync(default);
        Assert.False(second.IsCompleted);
        release.SetResult();
        await Task.WhenAll(first, second);
        Assert.Equal(1, cycle.Calls);
    }

    [Fact]
    public async Task FailedSyncWaitsForRetryAfterWhileModulesContinueTicking()
    {
        using var directory = new TestDirectory();
        var clock = new TestClock();
        var module = new TestModule();
        await using var host = new ModuleHost([module], Samples.Context(clock));
        var cycle = new FakeCycle { OnExecute = () => throw new TransportException(System.Net.HttpStatusCode.TooManyRequests, TimeSpan.FromSeconds(900)) };
        var schedule = new SyncSchedule(Samples.Device);
        using var scheduler = new Scheduler(host, cycle, schedule, clock, directory.File("deadline.json"), _ => { });
        await scheduler.StepAsync(default);
        clock.Advance(schedule.InitialDelay);
        await scheduler.StepAsync(default);
        clock.Advance(TimeSpan.FromSeconds(899));
        await scheduler.StepAsync(default);
        Assert.Equal(1, cycle.Calls);
        clock.Advance(TimeSpan.FromSeconds(14));
        await scheduler.StepAsync(default);
        Assert.Equal(2, cycle.Calls);
        Assert.Equal(4, module.Ticks);
    }

    [Fact]
    public async Task RunKeepsLocalCaptureTickingDuringSlowNetworkRequest()
    {
        using var directory = new TestDirectory();
        var clock = TimeProvider.System;
        var module = new TestModule();
        await using var host = new ModuleHost([module], Samples.Context(clock));
        var entered = new TaskCompletionSource(TaskCreationOptions.RunContinuationsAsynchronously);
        var release = new TaskCompletionSource(TaskCreationOptions.RunContinuationsAsynchronously);
        var cycle = new FakeCycle { OnExecute = async () => { entered.TrySetResult(); await release.Task; } };
        var statePath = directory.File("deadline");
        await Keeper.Agent.Storage.AtomicFile.WriteAsync(statePath, System.Text.Json.JsonSerializer.SerializeToUtf8Bytes(clock.GetUtcNow().AddMilliseconds(500)), default);
        using var scheduler = new Scheduler(host, cycle, new SyncSchedule(Samples.Device), clock, statePath, _ => { });
        using var stopping = new CancellationTokenSource(TimeSpan.FromSeconds(10));
        var running = scheduler.RunAsync(stopping.Token);
        try
        {
            await entered.Task.WaitAsync(TimeSpan.FromSeconds(5));
            var ticks = module.Ticks;
            while (module.Ticks == ticks) await Task.Delay(20, stopping.Token);
            Assert.False(release.Task.IsCompleted);
            Assert.Equal(1, cycle.Calls);
        }
        finally
        {
            stopping.Cancel(); release.TrySetResult();
            try { await running; } catch (OperationCanceledException) { }
        }
    }

    [Fact]
    public async Task ModuleFailureIsIsolatedAndShutdownRunsForAllModules()
    {
        var bad = new TestModule { Name = "bad", Fail = true };
        var good = new TestModule { Name = "good" };
        await using (var host = new ModuleHost([bad, good], Samples.Context()))
        {
            await host.InitAsync();
            await host.TickAsync(default);
            Assert.Equal(1, good.Ticks);
            Assert.Equal("failed", host.Snapshot()[0].State);
        }
        Assert.Equal(1, bad.Shutdowns);
        Assert.Equal(1, good.Shutdowns);
    }

    private sealed class FakeCycle : ISyncCycle
    {
        public long RequestCount { get; private set; }
        public int Calls { get; private set; }
        public int Cost { get; init; } = 1;
        public Func<Task>? OnExecute { get; init; }
        public async Task<int> ExecuteAsync(CancellationToken ct)
        {
            Calls++;
            RequestCount += Cost;
            if (OnExecute is not null) await OnExecute();
            return 120;
        }
    }
}
