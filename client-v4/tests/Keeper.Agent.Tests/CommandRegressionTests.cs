using System.Text.Json;
using Keeper.Agent.Modules.Devices;
using Keeper.Agent.Modules.Security;
using Keeper.Shared.Contracts;
using Keeper.Shared.Protocol;

namespace Keeper.Agent.Tests;

public sealed class CommandRegressionTests
{
    [Theory]
    [InlineData("device")]
    [InlineData("tenant")]
    [InlineData("empty_id")]
    [InlineData("type")]
    public async Task BadCommandDoesNotAbortBatchAndAcceptedCommandsSurviveRestart(string invalid)
    {
        using var directory = new TestDirectory();
        var clock = new TestClock();
        var context = Samples.Context(clock);
        var deviceLock = new DeviceLock(directory.File("lock"));
        await deviceLock.InitAsync(context);
        var actions = new CountingActions();
        var before = Command(clock);
        var after = Command(clock);
        var bad = Command(clock);
        bad = invalid switch
        {
            "device" => bad with { DeviceId = Guid.NewGuid() },
            "tenant" => bad with { TenantId = Guid.NewGuid() },
            "empty_id" => bad with { Id = Guid.Empty },
            _ => bad with { Type = (CommandType)999 }
        };
        var executor = new CommandExecutor(directory.File("inbox"), Samples.Device, deviceLock, actions);
        await executor.InitAsync(context);
        await executor.AcceptAsync([before, bad, after], Samples.Tenant, default);
        var rejection = Assert.Single(((MemoryEvents)context.Outbox).CommandResults);
        Assert.Equal(bad.Id, rejection.CommandId);
        Assert.Equal(CommandResultStatus.Failed, rejection.Result.Status);
        Assert.Equal("invalid_command_destination", rejection.Result.Code);
        await executor.ShutdownAsync();
        var restarted = new CommandExecutor(directory.File("inbox"), Samples.Device, deviceLock, actions);
        await restarted.InitAsync(context);
        await restarted.TickAsync(default);
        Assert.Equal(2, actions.Executions);
        Assert.Equal(3, ((MemoryEvents)context.Outbox).CommandResults.Count);
        await restarted.ShutdownAsync();
        await deviceLock.ShutdownAsync();
    }

    [Fact]
    public async Task FullInboxReportsEachRejectionAndKeepsPreviouslyAcceptedWork()
    {
        using var directory = new TestDirectory();
        var clock = new TestClock();
        var context = Samples.Context(clock);
        var deviceLock = new DeviceLock(directory.File("lock"));
        var executor = new CommandExecutor(directory.File("inbox"), Samples.Device, deviceLock, new CountingActions());
        await executor.InitAsync(context);
        var commands = Enumerable.Range(0, 1002).Select(_ => Command(clock)).ToArray();
        await executor.AcceptAsync(commands, Samples.Tenant, default);
        var saved = JsonSerializer.Deserialize<List<InboxEntry>>(await File.ReadAllBytesAsync(directory.File("inbox")), ProtocolJson.Options)!;
        Assert.Equal(1000, saved.Count);
        Assert.Equal(commands.Take(1000).Select(c => c.Id), saved.Select(e => e.Command.Id));
        var results = ((MemoryEvents)context.Outbox).CommandResults;
        Assert.Equal(commands.Skip(1000).Select(c => c.Id), results.Select(r => r.CommandId));
        Assert.All(results, r => Assert.Equal("command_inbox_full", r.Result.Code));
        await executor.ShutdownAsync();
        await deviceLock.ShutdownAsync();
    }

    [Fact]
    public async Task PublishedLongLivedReceiptsDoNotConsumeQuotaAndStillDeduplicate()
    {
        using var directory = new TestDirectory();
        var clock = new TestClock();
        var context = Samples.Context(clock);
        var deviceLock = new DeviceLock(directory.File("lock"));
        var actions = new CountingActions();
        var entries = Enumerable.Range(0, 1000).Select(_ => new InboxEntry(Command(clock),
            new CommandResult { EventId = Guid.NewGuid(), At = clock.GetUtcNow().AddDays(-2), Status = CommandResultStatus.Succeeded }, true, true)).ToArray();
        await File.WriteAllBytesAsync(directory.File("inbox"), JsonSerializer.SerializeToUtf8Bytes(entries, ProtocolJson.Options));
        var executor = new CommandExecutor(directory.File("inbox"), Samples.Device, deviceLock, actions);
        await executor.InitAsync(context);
        await executor.AcceptAsync([entries[0].Command, Command(clock)], Samples.Tenant, default);
        await executor.TickAsync(default);
        Assert.Equal(1, actions.Executions);
        Assert.Single(((MemoryEvents)context.Outbox).CommandResults);
        clock.Advance(TimeSpan.FromDays(31));
        await executor.TickAsync(default);
        Assert.Empty(JsonSerializer.Deserialize<List<InboxEntry>>(await File.ReadAllBytesAsync(directory.File("inbox")), ProtocolJson.Options)!);
        await executor.AcceptAsync([entries[0].Command], Samples.Tenant, default);
        await executor.TickAsync(default);
        Assert.Equal(1, actions.Executions);
        Assert.Equal("expired", ((MemoryEvents)context.Outbox).CommandResults.Last().Result.Code);
        await executor.ShutdownAsync();
        await deviceLock.ShutdownAsync();
    }

    [Fact]
    public async Task AcceptedCommandsAreSavedEvenIfPublishingRejectionFails()
    {
        using var directory = new TestDirectory();
        var clock = new TestClock();
        var deviceLock = new DeviceLock(directory.File("lock"));
        var executor = new CommandExecutor(directory.File("inbox"), Samples.Device, deviceLock, new CountingActions());
        await executor.InitAsync(new(new UnavailableOutbox(), clock, _ => { }, default));
        var good = Command(clock);
        await Assert.ThrowsAsync<IOException>(() => executor.AcceptAsync([Command(clock) with { DeviceId = Guid.NewGuid() }, good], Samples.Tenant, default));
        var saved = JsonSerializer.Deserialize<List<InboxEntry>>(await File.ReadAllBytesAsync(directory.File("inbox")), ProtocolJson.Options)!;
        Assert.Equal(good.Id, Assert.Single(saved).Command.Id);
        await executor.ShutdownAsync();
        await deviceLock.ShutdownAsync();
    }

    internal static Command Command(TestClock clock) => new()
    {
        Id = Guid.NewGuid(), DeviceId = Samples.Device, TenantId = Samples.Tenant, Type = CommandType.Restart,
        Status = CommandStatus.Pending, CreatedAt = clock.GetUtcNow(), ExpiresAt = clock.GetUtcNow().AddDays(30), Result = null
    };

    private sealed class CountingActions : IDeviceActions
    {
        public int Executions { get; private set; }
        public Task ExecuteAsync(DeviceAction action, CancellationToken ct) { Executions++; return Task.CompletedTask; }
    }

    private sealed class UnavailableOutbox : IEventSink
    {
        public Task EnqueueAsync(Episode value, CancellationToken ct) => throw new IOException();
        public Task EnqueueAsync(LogEntry value, CancellationToken ct) => throw new IOException();
        public Task EnqueueAsync(SecurityReport value, CancellationToken ct) => throw new IOException();
        public Task EnqueueAsync(SyncRequestCommandResultsItem value, CancellationToken ct) => throw new IOException();
        public Task EnqueueAsync(ActivitySnapshot value, CancellationToken ct) => throw new IOException();
    }
}
