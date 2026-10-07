using System.Text.Json;
using Keeper.Agent.Modules.Security;
using Keeper.Agent.Storage;
using Keeper.Shared.Contracts;
using Keeper.Shared.Protocol;

namespace Keeper.Agent.Tests;

public sealed class UnlockPinTests
{
    private const string Correct = "625184";
    [Fact]
    public void PhpVerifierAcceptsLeadingZeroPin()
    {
        var verifier = new PinVerifier(Enumerable.Repeat((byte)'s', 32).ToArray(),
            Convert.FromBase64String("AtPsP9xC4jfTG3OfjdXOAY3/h0LL00/BvRebLDPHAnE="));
        Assert.True(verifier.Verify("0625184"));
        Assert.False(verifier.Verify("625184"));
    }

    private static UnlockPinVerifier Settings(string pin, long revision = 1)
    {
        var v = PinVerifier.Create(pin);
        return new() { Revision = revision, Salt = Convert.ToBase64String(v.Salt), Hash = Convert.ToBase64String(v.Hash) };
    }

    [Fact]
    public async Task FirstFourFailuresAllowCorrectPinAndFifthStartsExactlyFifteenMinutes()
    {
        using var dir = new TestDirectory();
        var clock = new TestClock(); var ctx = Samples.Context(clock);
        var module = new DeviceLock(dir.File("lock"), PinVerifier.Create(Correct));
        await module.InitAsync(ctx); await module.SetLockedAsync(true, default);
        for (var i = 0; i < 4; i++) Assert.False(await module.ValidatePinAsync("999999", default));
        Assert.Null(module.PinStatus.RetryAt);
        Assert.True(await module.ValidatePinAsync(Correct, default));
        await module.SetLockedAsync(true, default);
        for (var i = 0; i < 5; i++) Assert.False(await module.ValidatePinAsync("999999", default));
        Assert.Equal(clock.GetUtcNow().AddMinutes(15), module.PinStatus.RetryAt);
        clock.Advance(TimeSpan.FromSeconds(899));
        Assert.False(await module.ValidatePinAsync(Correct, default));
        clock.Advance(TimeSpan.FromSeconds(1));
        Assert.False(await module.ValidatePinAsync("999999", default));
        Assert.Equal(1, module.PinStatus.Attempts);
        Assert.True(await module.ValidatePinAsync(Correct, default));
    }

    [Fact]
    public async Task ConcurrentAttemptsShareOneCounterAndRestartKeepsCooldown()
    {
        using var dir = new TestDirectory(); var clock = new TestClock(); var ctx = Samples.Context(clock);
        var verifier = PinVerifier.Create(Correct);
        var module = new DeviceLock(dir.File("lock"), verifier);
        await module.InitAsync(ctx); await module.SetLockedAsync(true, default);
        Assert.All(await Task.WhenAll(Enumerable.Range(0, 12).Select(_ => module.ValidatePinAsync("999999", default))), Assert.False);
        var retry = module.PinStatus.RetryAt;
        var restarted = new DeviceLock(dir.File("lock"), verifier); await restarted.InitAsync(ctx);
        Assert.Equal(5, restarted.PinStatus.Attempts); Assert.Equal(retry, restarted.PinStatus.RetryAt);
        Assert.False(await restarted.ValidatePinAsync(Correct, default));
    }

    [Fact]
    public async Task RotationReplacesVerifierWithoutResettingFailedAttemptsAndEmptySettingsRemoveIt()
    {
        using var dir = new TestDirectory(); var ctx = Samples.Context(new TestClock());
        var module = new DeviceLock(dir.File("lock")); await module.InitAsync(ctx);
        await module.ApplyVerifierAsync(Settings(Correct), Samples.Tenant, default);
        await module.SetLockedAsync(true, default);
        Assert.False(await module.ValidatePinAsync("999999", default));
        await module.ApplyVerifierAsync(Settings("735192", 2), Samples.Tenant, default);
        Assert.Equal(1, module.PinStatus.Attempts);
        Assert.False(await module.ValidatePinAsync(Correct, default));
        Assert.True(await module.ValidatePinAsync("735192", default));
        await module.ApplyVerifierAsync(new() { Revision = 0 }, Samples.Tenant, default);
        Assert.False(module.PinAllowed);
    }

    [Fact]
    public async Task OfflineVerifierSurvivesRestartAndDoesNotStorePin()
    {
        if (!OperatingSystem.IsWindows()) return;
        using var dir = new TestDirectory(); var ctx = Samples.Context(new TestClock());
        var module = new DeviceLock(dir.File("lock"), verifierPath: dir.File("verifier")); await module.InitAsync(ctx);
        await module.ApplyVerifierAsync(Settings(Correct), Samples.Tenant, default);
        await module.SetLockedAsync(true, default);
        var saved = PinVerifier.LoadProtected(dir.File("verifier"));
        Assert.NotNull(saved); Assert.Equal(Samples.Tenant, saved.TenantId);
        Assert.DoesNotContain(Correct, JsonSerializer.Serialize(saved));
        var restarted = new DeviceLock(dir.File("lock"), saved); await restarted.InitAsync(ctx);
        Assert.True(await restarted.ValidatePinAsync(Correct, default));
    }

    [Fact]
    public async Task PendingUnlockSurvivesRestartAndOutboxFailureWithoutRelocking()
    {
        using var dir = new TestDirectory(); var clock = new TestClock();
        var ctx = Samples.Context(clock); var verifier = PinVerifier.Create(Correct);
        var module = new DeviceLock(dir.File("lock"), verifier); await module.InitAsync(ctx);
        await module.SetLockedAsync(true, default); Assert.True(await module.ValidatePinAsync(Correct, default));
        var state = JsonSerializer.Deserialize<LockState>(await File.ReadAllTextAsync(dir.File("lock")))!;
        var original = Assert.Single(state.PendingUnlocks!);
        var broken = new DeviceLock(dir.File("lock"), verifier); await broken.InitAsync(ctx with { Outbox = new FailingSink() });
        await Assert.ThrowsAsync<IOException>(() => broken.TickAsync(default)); Assert.False(broken.Locked);
        var restarted = new DeviceLock(dir.File("lock"), verifier); await restarted.InitAsync(ctx);
        await restarted.TickAsync(default); await restarted.TickAsync(default);
        var entry = Assert.Single(((MemoryEvents)ctx.Outbox).Logs);
        Assert.Equal(original.EventId, entry.EventId); Assert.Equal(original.At, entry.At);
        Assert.Equal("local_unlock", entry.Code); Assert.Null(entry.Fields);
        Assert.DoesNotContain(Correct, JsonSerializer.Serialize(entry));
        Assert.Empty(JsonSerializer.Deserialize<LockState>(await File.ReadAllTextAsync(dir.File("lock")))!.PendingUnlocks!);
    }

    [Theory]
    [InlineData(-1, null, null)]
    [InlineData(0, "AA==", "AA==")]
    [InlineData(1, "bad", "bad")]
    [InlineData(1, null, null)]
    public async Task MalformedVerifierCannotReplaceWorkingPin(long revision, string? salt, string? hash)
    {
        using var dir = new TestDirectory();
        var module = new DeviceLock(dir.File("lock"), PinVerifier.Create(Correct)); await module.InitAsync(Samples.Context());
        await Assert.ThrowsAsync<InvalidDataException>(() => module.ApplyVerifierAsync(new() { Revision = revision, Salt = salt, Hash = hash }, Samples.Tenant, default));
        await module.SetLockedAsync(true, default); Assert.True(await module.ValidatePinAsync(Correct, default));
    }

    [Fact]
    public async Task UnlockReportSurvivesQueuePressureUntilServerAcknowledgesIt()
    {
        using var dir = new TestDirectory();
        using var outbox = new DurableOutbox(dir.File("outbox"), 60000);
        var entry = Samples.Log() with { Component = "DeviceLock", Code = "local_unlock" };
        await outbox.EnqueueAsync(entry, default);
        for (var i = 0; i < 400; i++) await outbox.EnqueueAsync(Samples.Log(), default);
        Assert.Contains((await outbox.InspectAsync()).Events, e => e.Id == entry.EventId);
        var batch = await outbox.PrepareAsync(1, default);
        Assert.Contains(entry.EventId, batch.EventIds);
    }

    private sealed class FailingSink : IEventSink
    {
        public Task EnqueueAsync(LogEntry log, CancellationToken ct) => throw new IOException("outbox_unavailable");
        public Task EnqueueAsync(Episode episode, CancellationToken ct) => throw new NotSupportedException();
        public Task EnqueueAsync(SyncRequestCommandResultsItem result, CancellationToken ct) => throw new NotSupportedException();
        public Task EnqueueAsync(SecurityReport report, CancellationToken ct) => throw new NotSupportedException();
        public Task EnqueueAsync(ActivitySnapshot activity, CancellationToken ct) => throw new NotSupportedException();
    }
}
