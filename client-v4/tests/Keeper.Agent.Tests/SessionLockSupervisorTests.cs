using System.ComponentModel;
using Keeper.Agent.Hosting;
using Keeper.Agent.Modules.Security;
using Keeper.Shared.Contracts;
using Microsoft.Win32;

namespace Keeper.Agent.Tests;

public sealed class SessionLockSupervisorTests
{
    [Theory]
    [InlineData(false)]
    [InlineData(true)]
    public async Task RegistryFailureDoesNotDelayNewSessionsLockUnlockOrPin(bool failKill)
    {
        using var directory = new TestDirectory();
        var clock = new TestClock();
        var log = new List<string>();
        var context = Samples.Context(clock) with { Log = log.Add };
        var deviceLock = new DeviceLock(directory.File("lock"), PinVerifier.Create("625184"));
        await deviceLock.InitAsync(context);
        var platform = new FakePlatform { FailKill = failKill };
        var registry = new ScreenLockTests.FakeRegistry();
        // Un respaldo incoherente de un hive cargado ajeno a las sesiones interactivas.
        registry.Write(TaskManagerPolicy.BackupPath, "Saved", new(RegistryValueKind.DWord, 1));
        var policies = new SessionTaskManagerPolicies(() => ["broken"],
            sid => sid == "broken" ? registry : throw new UnauthorizedAccessException("hive_access_denied"));
        var supervisor = new SessionSupervisor(platform, deviceLock, () => null, policies);
        await using var host = new ModuleHost([supervisor], context);
        await host.InitAsync();
        for (var i = 0; i < 12; i++)
        {
            await host.TickAsync(default);
            Assert.Equal(i + 1, platform.Connection.Requests.Count);
            Assert.False(platform.Connection.Requests[^1].Locked);
            clock.Advance(TimeSpan.FromSeconds(1));
        }
        Assert.Equal(1, platform.Connects);
        Assert.True(platform.Connection.Verified);
        Assert.Contains("AggregateException", Assert.Single(log));
        Assert.Equal("session_taskmgr_policy_failed", supervisor.Snapshot().ErrorCode);
        Assert.Equal("degraded", Assert.Single(host.Snapshot()).State);

        await deviceLock.SetLockedAsync(true, default);
        await host.TickAsync(default);
        Assert.True(platform.Connection.Requests[^1].Locked);
        Assert.True(platform.Connection.Requests[^1].PinAllowed);
        if (failKill) Assert.Contains(log, s => s.Contains("Win32Exception"));
        platform.Connection.Pin = "625184";
        await host.TickAsync(default);
        using var timeout = new CancellationTokenSource(TimeSpan.FromSeconds(5));
        while (deviceLock.Locked) await Task.Delay(10, timeout.Token);
        platform.Connection.Pin = null;
        while (platform.Connection.Requests[^1].Locked)
        {
            await host.TickAsync(default);
            await Task.Delay(10, timeout.Token);
        }
        Assert.False(platform.Connection.Requests[^1].Locked);
        Assert.False(platform.Connection.Disposed);

        platform.FailKill = false;
        registry.Delete(TaskManagerPolicy.BackupPath, "Saved");
        platform.Sessions = [];
        clock.Advance(TimeSpan.FromSeconds(10));
        await host.TickAsync(default);
        Assert.Null(supervisor.Snapshot().ErrorCode);
        Assert.Equal("no_interactive_session", supervisor.Snapshot().State);
        await deviceLock.ShutdownAsync();
    }

    [Fact]
    public async Task KillFailureAloneDoesNotBackOffSessionExchangesAndRecoveryClearsError()
    {
        using var directory = new TestDirectory();
        var clock = new TestClock();
        var log = new List<string>();
        var context = Samples.Context(clock) with { Log = log.Add };
        var deviceLock = new DeviceLock(directory.File("lock"));
        await deviceLock.InitAsync(context);
        await deviceLock.SetLockedAsync(true, default);
        var registry = new ScreenLockTests.FakeRegistry();
        var platform = new FakePlatform { FailKill = true };
        var supervisor = new SessionSupervisor(platform, deviceLock, () => null,
            new SessionTaskManagerPolicies(() => ["user"], _ => registry));
        await using var host = new ModuleHost([supervisor], context);
        await host.InitAsync();
        for (var i = 0; i < 6; i++)
        {
            await host.TickAsync(default);
            Assert.Equal(i + 1, platform.Connection.Requests.Count);
            clock.Advance(TimeSpan.FromSeconds(1));
        }
        Assert.Equal("session_taskmgr_close_failed", supervisor.Snapshot().ErrorCode);
        Assert.Single(log);
        await deviceLock.SetLockedAsync(false, default);
        await host.TickAsync(default);
        Assert.False(platform.Connection.Requests[^1].Locked);
        Assert.Null(supervisor.Snapshot().ErrorCode);
        Assert.Equal("ready", supervisor.Snapshot().State);
        await deviceLock.ShutdownAsync();
    }

    private sealed class FakePlatform : ISessionSupervisorPlatform
    {
        public IReadOnlyList<InteractiveSession> Sessions { get; set; } = [new(1, "user")];
        public FakeConnection Connection { get; } = new();
        public bool FailKill { get; set; }
        public int Connects { get; private set; }
        public IReadOnlyList<InteractiveSession> Enumerate() => Sessions;
        public ISupervisedSession Connect(InteractiveSession session, CancellationToken ct) { Connects++; return Connection; }
        public void CloseExistingTaskManagers(IReadOnlyList<InteractiveSession> sessions)
        {
            if (FailKill) throw new Win32Exception(5);
        }
    }

    private sealed class FakeConnection : ISupervisedSession
    {
        public bool HasExited => false;
        public Task Connecting => Task.CompletedTask;
        public List<SessionRequest> Requests { get; } = [];
        public bool Verified { get; private set; }
        public bool Disposed { get; private set; }
        public string? Pin { get; set; }
        public void Verify() => Verified = true;
        public Task<SessionResponse> ExchangeAsync(SessionRequest request, CancellationToken ct)
        {
            Assert.True(Verified);
            Requests.Add(request);
            return Task.FromResult(new SessionResponse(1, request.CorrelationId, [], [], Pin));
        }
        public Task SendAsync(SessionRequest request, CancellationToken ct) => Task.CompletedTask;
        public void Dispose() => Disposed = true;
    }
}
