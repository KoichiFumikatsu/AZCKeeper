using System.Text.Json;
using Keeper.Agent.Storage;
using Keeper.Shared.Protocol;

namespace Keeper.Agent.Tests;

public sealed class OutboxTests
{
    [Fact]
    public async Task BatchRespectsAggregateCollectionAndByteBudgets()
    {
        using var directory = new TestDirectory();
        using var outbox = new DurableOutbox(directory.File("outbox.json"));
        for (var i = 0; i < 205; i++) await outbox.EnqueueAsync(Samples.Episode(), default);
        for (var i = 0; i < 55; i++) await outbox.EnqueueAsync(Samples.Log(), default);
        for (var i = 0; i < 25; i++) await outbox.EnqueueAsync(new SyncRequestCommandResultsItem
        {
            CommandId = Guid.NewGuid(), Result = new CommandResult { EventId = Guid.NewGuid(), At = DateTimeOffset.UnixEpoch, Status = CommandResultStatus.Failed }
        }, default);
        var batch = await outbox.PrepareAsync(8, default);
        var request = JsonSerializer.Deserialize<SyncRequest>(batch.Body, ProtocolJson.Options)!;
        Assert.Equal(200, batch.EventIds.Count);
        Assert.Equal(20, request.CommandResults!.Count);
        Assert.InRange(request.Episodes!.Count, 0, 200);
        Assert.InRange(request.Logs!.Count, 0, 50);
        Assert.InRange(batch.Body.Length, 1, 128 * 1024);
        await outbox.CompleteAsync(batch.EventIds.Select(id => new Ack { EventId = id, Status = AckStatus.Accepted, Retryable = false }), default);
        var next = JsonSerializer.Deserialize<SyncRequest>((await outbox.PrepareAsync(8, default)).Body, ProtocolJson.Options)!;
        Assert.Equal(50, next.Logs!.Count);
        Assert.Equal(5, next.CommandResults!.Count);
    }

    [Fact]
    public async Task LargeEventsStopAtByteBudgetWithoutDroppingRemainder()
    {
        using var directory = new TestDirectory();
        using var outbox = new DurableOutbox(directory.File("outbox.json"));
        for (var i = 0; i < 60; i++) await outbox.EnqueueAsync(Samples.Episode(new string('\u754c', 512)), default);
        var batch = await outbox.PrepareAsync(null, default);
        Assert.InRange(batch.Body.Length, 1, 128 * 1024);
        Assert.InRange(batch.EventIds.Count, 1, 59);
        Assert.Equal(60, (await outbox.InspectAsync()).Events.Count);
    }

    [Fact]
    public async Task RetryAfterRestartKeepsExactBodyAndIdAndPartialAckRetainsUnconfirmedEvents()
    {
        using var directory = new TestDirectory();
        var path = directory.File("outbox.json");
        var first = Samples.Episode();
        var second = Samples.Episode();
        PendingBatch batch;
        using (var initial = new DurableOutbox(path))
        {
            await initial.EnqueueAsync(first, default);
            await initial.EnqueueAsync(first, default);
            await initial.EnqueueAsync(second, default);
            batch = await initial.PrepareAsync(1, default);
        }
        using var restarted = new DurableOutbox(path);
        var retry = await restarted.PrepareAsync(2, default);
        Assert.Equal(batch.IdempotencyKey, retry.IdempotencyKey);
        Assert.Equal(batch.Body, retry.Body);
        Assert.Equal(2, retry.EventIds.Count);
        await restarted.CompleteAsync([
            new Ack { EventId = first.EventId, Status = AckStatus.Duplicate, Retryable = false },
            new Ack { EventId = second.EventId, Status = AckStatus.Rejected, Retryable = true },
            new Ack { EventId = Guid.NewGuid(), Status = AckStatus.Accepted, Retryable = false }
        ], default);
        var next = await restarted.PrepareAsync(2, default);
        Assert.NotEqual(batch.IdempotencyKey, next.IdempotencyKey);
        Assert.Equal(second.EventId, Assert.Single(next.EventIds));
        await restarted.CompleteAsync([new Ack { EventId = second.EventId, Status = AckStatus.Rejected, Retryable = false }], default);
        var state = await restarted.InspectAsync();
        Assert.Empty(state.Events);
        Assert.Equal(second.EventId, Assert.Single(state.Quarantine).Id);
    }

    [Fact]
    public async Task SecurityAckCannotRemoveANewerReportNotInFlight()
    {
        using var directory = new TestDirectory();
        using var outbox = new DurableOutbox(directory.File("outbox.json"));
        var first = new SecurityReport { EventId = Guid.NewGuid(), ObservedAt = DateTimeOffset.UtcNow, ReportHash = new string('a', 64), Controls = [] };
        var second = first with { EventId = Guid.NewGuid() };
        await outbox.EnqueueAsync(first, default); await outbox.EnqueueAsync(second, default);
        var batch = await outbox.PrepareAsync(1, default);
        Assert.Equal(first.EventId, JsonSerializer.Deserialize<SyncRequest>(batch.Body, ProtocolJson.Options)!.Security!.EventId);
        await outbox.CompleteAsync([new Ack { EventId = first.EventId, Status = AckStatus.Accepted, Retryable = false },
            new Ack { EventId = second.EventId, Status = AckStatus.Accepted, Retryable = false }], default);
        Assert.Equal(second.EventId, Assert.Single((await outbox.InspectAsync()).Events).Id);
    }

    [Fact]
    public async Task DiskQuotaFailureKeepsPreviousState()
    {
        using var directory = new TestDirectory();
        using var outbox = new DurableOutbox(directory.File("outbox.json"), maxBytes: 1500);
        var episode = Samples.Episode();
        await outbox.EnqueueAsync(episode, default);
        await Assert.ThrowsAsync<IOException>(() => outbox.EnqueueAsync(Samples.Episode(new string('x', 1500)), default));
        Assert.Equal(episode.EventId, Assert.Single((await outbox.InspectAsync()).Events).Id);
    }
}
