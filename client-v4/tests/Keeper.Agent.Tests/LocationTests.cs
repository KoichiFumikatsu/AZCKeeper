using System.Text.Json;
using Keeper.Agent.Modules.Location;
using Keeper.Agent.Storage;
using Keeper.Shared.Contracts;
using Keeper.Shared.Location;
using Keeper.Shared.Protocol;

namespace Keeper.Agent.Tests;

public sealed class LocationTests
{
    private sealed class Settings : ILocationSettings
    {
        public LocationSettings Value = new("Deny", null, 4, false);
        public int Writes;
        public bool Fail;
        public Task<LocationSettings> ReadAsync(CancellationToken ct) => Task.FromResult(Value);
        public Task WriteAsync(LocationSettings value, CancellationToken ct)
        {
            if (Fail) throw new IOException("test");
            Value = value; Writes++; return Task.CompletedTask;
        }
    }
    [Fact]
    public async Task RestoresOriginalSettingsAfterRestartAndKeepsBackupOnFailure()
    {
        using var dir = new TestDirectory();
        var system = new Settings(); var original = system.Value;
        var path = dir.File("location.json");
        var store = new LocationSettingsStore(system, path);
        Assert.False(await store.ApplyAsync(false, default));
        Assert.Equal(0, system.Writes);
        Assert.True(await store.ApplyAsync(true, default));
        Assert.Equal(new("Allow", "Allow", 3, true), system.Value);
        Assert.False(await store.ApplyAsync(true, default));
        system.Value = system.Value with { Consent = "Deny" };
        Assert.True(await store.ApplyAsync(true, default));
        system.Fail = true;
        await Assert.ThrowsAsync<IOException>(() => store.ApplyAsync(false, default));
        Assert.True(File.Exists(path));
        system.Fail = false;
        Assert.True(await new LocationSettingsStore(system, path).ApplyAsync(false, default));
        Assert.Equal(original, system.Value);
        Assert.False(File.Exists(path));
    }

    [Fact]
    public async Task CapturesOnIntervalAndNetworkChangeWithDurableFallbackWithoutSession()
    {
        using var dir = new TestDirectory();
        using var outbox = new DurableOutbox(dir.File("outbox.json"));
        var clock = new TestClock(); var system = new Settings(); var network = Guid.NewGuid();
        var module = new LocationModule(new(system, dir.File("state.json")), () => false, () => network, () => LocationPointNetworkType.Cable);
        await module.InitAsync(new(outbox, clock, _ => { }, default));
        await module.ApplyPolicyAsync(Samples.Policy());
        await module.TickAsync(default);
        Assert.Null(module.Pending); Assert.Equal(0, system.Writes);
        await module.ApplyPolicyAsync(Samples.Policy() with { LocationTracking = true });
        await module.TickAsync(default);
        var first = Assert.IsType<LocationCapture>(module.Pending);
        clock.Advance(TimeSpan.FromSeconds(21));
        await module.TickAsync(default);
        Assert.Null(module.Pending);
        var batch = await outbox.PrepareAsync(null, default);
        var request = JsonSerializer.Deserialize<SyncRequest>(batch.Body, ProtocolJson.Options)!;
        var point = Assert.Single(request.Locations!);
        Assert.Equal(first.EventId, point.EventId);
        Assert.Null(point.Latitude);
        Assert.Equal(LocationPointNetworkType.Cable, point.NetworkType);
        await outbox.CompleteAsync([new() { EventId = point.EventId, Status = AckStatus.Accepted, Retryable = false }], default);
        clock.Advance(TimeSpan.FromMinutes(14));
        await module.TickAsync(default); Assert.Null(module.Pending);
        network = Guid.NewGuid();
        await module.TickAsync(default); Assert.NotNull(module.Pending);
        await module.ApplyPolicyAsync(Samples.Policy());
        await module.TickAsync(default);
        Assert.Null(module.Pending); Assert.Equal(new("Deny", null, 4, false), system.Value);
    }

    [Fact]
    public async Task DomainAndDryRunNeverChangeSettingsOrCapture()
    {
        using var dir = new TestDirectory();
        using var outbox = new DurableOutbox(dir.File("outbox.json"));
        var system = new Settings();
        foreach (var module in new[] { new LocationModule(new(system, dir.File("state.json")), () => true), new LocationModule(null, () => false) })
        {
            await module.InitAsync(new(outbox, new TestClock(), _ => { }, default));
            await module.ApplyPolicyAsync(Samples.Policy() with { LocationTracking = true });
            await module.TickAsync(default);
            Assert.Null(module.Pending);
        }
        Assert.Equal(0, system.Writes);
    }

    [Fact]
    public async Task SessionResultMustMatchPendingRequestAndCurrentNetwork()
    {
        using var dir = new TestDirectory();
        using var outbox = new DurableOutbox(dir.File("outbox.json"));
        var network = Guid.NewGuid(); var clock = new TestClock();
        var module = new LocationModule(new(new Settings(), dir.File("state.json")), () => false, () => network);
        await module.InitAsync(new(outbox, clock, _ => { }, default));
        await module.ApplyPolicyAsync(Samples.Policy() with { LocationTracking = true });
        await module.TickAsync(default);
        var request = module.Pending!;
        var point = new LocationPoint { EventId = request.EventId, At = request.At, NetworkId = request.NetworkId,
            NetworkType = LocationPointNetworkType.Wifi, WifiSsid = "Oficina", Latitude = 4.6, Longitude = -74.1, AccuracyM = 20 };
        await module.AcceptAsync(point with { EventId = Guid.NewGuid() }, default); Assert.NotNull(module.Pending);
        network = Guid.NewGuid();
        await module.AcceptAsync(point, default); Assert.NotNull(module.Pending);
        network = request.NetworkId;
        await module.AcceptAsync(point, default); Assert.Null(module.Pending);
        var body = JsonSerializer.Deserialize<SyncRequest>((await outbox.PrepareAsync(null, default)).Body, ProtocolJson.Options)!;
        Assert.Equal(point, Assert.Single(body.Locations!));
    }

    [Fact]
    public async Task OfflinePointsSurviveRestartAndRejectionIsIsolated()
    {
        using var dir = new TestDirectory(); var path = dir.File("outbox.json");
        var point = new LocationPoint { EventId = Guid.NewGuid(), NetworkId = Guid.NewGuid(), At = DateTimeOffset.UtcNow, NetworkType = LocationPointNetworkType.Unknown };
        PendingBatch batch;
        using (var outbox = new DurableOutbox(path))
        {
            await outbox.EnqueueAsync(point, default);
            await outbox.EnqueueAsync(point with { EventId = Guid.NewGuid() }, default);
            batch = await outbox.PrepareAsync(null, default);
        }
        using var resumed = new DurableOutbox(path);
        var retry = await resumed.PrepareAsync(null, default);
        Assert.Equal(batch.IdempotencyKey, retry.IdempotencyKey);
        Assert.Equal(batch.Body, retry.Body);
        var result = await resumed.RejectPendingAsync("/locations/0/at", "format", default);
        Assert.Equal(RejectionKind.Quarantined, result.Kind);
        var request = JsonSerializer.Deserialize<SyncRequest>((await resumed.PrepareAsync(null, default)).Body, ProtocolJson.Options)!;
        Assert.NotEqual(point.EventId, Assert.Single(request.Locations!).EventId);
    }

    [Fact]
    public void InvalidCoordinatesAreRejectedAtIpcBoundary()
    {
        var p = new LocationPoint { EventId = Guid.NewGuid(), NetworkId = Guid.NewGuid(), At = DateTimeOffset.UtcNow, NetworkType = LocationPointNetworkType.Unknown };
        SessionProtocol.Validate(p);
        Assert.Throws<InvalidDataException>(() => SessionProtocol.Validate(p with { Latitude = 0 }));
        Assert.Throws<InvalidDataException>(() => SessionProtocol.Validate(p with { Latitude = double.NaN, Longitude = 0, AccuracyM = 1 }));
        Assert.Throws<InvalidDataException>(() => SessionProtocol.Validate(p with { Latitude = 0, Longitude = 0, AccuracyM = -1 }));
        Assert.Throws<InvalidDataException>(() => SessionProtocol.Validate(p with { WifiSsid = "No es wifi" }));
    }
}
