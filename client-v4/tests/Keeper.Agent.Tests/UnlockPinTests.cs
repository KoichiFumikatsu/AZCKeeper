using System.Text.Json;
using Keeper.Agent.Modules.Security;
using Keeper.Agent.Modules.Devices;
using Keeper.Agent.Hosting;
using Keeper.Agent.Storage;
using Keeper.Shared.Contracts;
using Keeper.Shared.Protocol;

namespace Keeper.Agent.Tests;

public sealed class UnlockPinTests
{
    private const string Correct = "00625184";
    [Fact]
    public void PhpVerifierAcceptsLeadingZeroPin()
    {
        var verifier = new PinVerifier(Enumerable.Repeat((byte)'s', 32).ToArray(),
            Convert.FromBase64String("6ceVITP22R/qoWisi6DvLYN1zhj96RIpV8RoRYfbUxc="));
        Assert.True(verifier.Verify("00625184"));
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
        for (var i = 0; i < 4; i++) Assert.False(await module.ValidatePinAsync("99999999", default));
        Assert.Null(module.PinStatus.RetryAt);
        Assert.True(await module.ValidatePinAsync(Correct, default));
        await module.SetLockedAsync(true, default);
        for (var i = 0; i < 5; i++) Assert.False(await module.ValidatePinAsync("99999999", default));
        Assert.Equal(clock.GetUtcNow().AddMinutes(15), module.PinStatus.RetryAt);
        clock.Advance(TimeSpan.FromSeconds(899));
        Assert.False(await module.ValidatePinAsync(Correct, default));
        clock.Advance(TimeSpan.FromSeconds(1));
        Assert.False(await module.ValidatePinAsync("99999999", default));
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
        Assert.All(await Task.WhenAll(Enumerable.Range(0, 12).Select(_ => module.ValidatePinAsync("99999999", default))), Assert.False);
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
        Assert.False(await module.ValidatePinAsync("99999999", default));
        await module.ApplyVerifierAsync(Settings("00735192", 2), Samples.Tenant, default);
        Assert.Equal(1, module.PinStatus.Attempts);
        Assert.False(await module.ValidatePinAsync(Correct, default));
        Assert.True(await module.ValidatePinAsync("00735192", default));
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
        var entry = Samples.Log() with { Component = "DeviceLock", Code = "local_unlock", At = DateTimeOffset.UtcNow.AddYears(1) };
        await outbox.EnqueueAsync(entry, default);
        for (var i = 0; i < 400; i++) await outbox.EnqueueAsync(Samples.Log(), default);
        Assert.Contains((await outbox.InspectAsync()).Events, e => e.Id == entry.EventId);
        var batch = await outbox.PrepareAsync(1, default);
        Assert.Contains(entry.EventId, batch.EventIds);
        await outbox.CompleteAsync([new Ack { EventId = entry.EventId, Status = AckStatus.Accepted, Retryable = false }], default);
        var delivered = await outbox.InspectAsync();
        Assert.DoesNotContain(delivered.Events, e => e.Id == entry.EventId);
        Assert.Empty(delivered.Quarantine);
    }

    [Theory]
    [InlineData(365)]
    [InlineData(-365)]
    public async Task CooldownIgnoresWallClockChangesAndDowntime(int days)
    {
        using var dir = new TestDirectory(); var clock = new TestClock();
        var verifier = PinVerifier.Create(Correct);
        var module = new DeviceLock(dir.File("lock"), verifier);
        await module.InitAsync(Samples.Context(clock)); await module.SetLockedAsync(true, default);
        for (var i = 0; i < 5; i++) Assert.False(await module.ValidatePinAsync("99999999", default));
        clock.ShiftWallClock(TimeSpan.FromDays(days));
        Assert.False(await module.ValidatePinAsync(Correct, default));
        Assert.Equal(clock.GetUtcNow().AddMinutes(15), module.PinStatus.RetryAt);
        clock.Advance(TimeSpan.FromMinutes(5));
        await module.TickAsync(default);
        var saved = JsonSerializer.Deserialize<LockState>(await File.ReadAllTextAsync(dir.File("lock")))!;
        Assert.Equal(TimeSpan.FromMinutes(10), saved.CooldownRemaining);
        await module.ShutdownAsync();
        var rebootClock = new TestClock(); rebootClock.ShiftWallClock(TimeSpan.FromDays(days * 2));
        var restarted = new DeviceLock(dir.File("lock"), verifier);
        await restarted.InitAsync(Samples.Context(rebootClock));
        Assert.Equal(rebootClock.GetUtcNow().AddMinutes(10), restarted.PinStatus.RetryAt);
        await restarted.SetLockedAsync(true, default);
        await restarted.ApplyVerifierAsync(Settings(Correct, 2), Samples.Tenant, default);
        rebootClock.Advance(TimeSpan.FromMinutes(10) - TimeSpan.FromSeconds(1));
        Assert.False(await restarted.ValidatePinAsync(Correct, default));
        rebootClock.ShiftWallClock(TimeSpan.FromDays(-days * 3));
        Assert.False(await restarted.ValidatePinAsync(Correct, default));
        rebootClock.Advance(TimeSpan.FromSeconds(1));
        Assert.True(await restarted.ValidatePinAsync(Correct, default));
        await restarted.ShutdownAsync();
    }

    [Fact]
    public async Task LegacyCooldownDoesNotTrustExpiredWallClockDeadline()
    {
        using var dir = new TestDirectory(); var clock = new TestClock();
        await File.WriteAllTextAsync(dir.File("lock"), JsonSerializer.Serialize(new LockState(true, 5, clock.GetUtcNow().AddYears(-1))));
        var module = new DeviceLock(dir.File("lock"), PinVerifier.Create(Correct));
        await module.InitAsync(Samples.Context(clock));
        Assert.False(await module.ValidatePinAsync(Correct, default));
        clock.Advance(TimeSpan.FromMinutes(15));
        Assert.True(await module.ValidatePinAsync(Correct, default));
    }

    [Theory]
    [InlineData("123456")]
    [InlineData("1234567")]
    [InlineData("1234567a")]
    [InlineData("12345678\n")]
    public async Task ShortOrNonNumericPinsCannotBeCreatedOrUnlock(string pin)
    {
        Assert.Throws<ArgumentException>(() => PinVerifier.Create(pin));
        using var dir = new TestDirectory();
        var salt = new byte[32];
        var legacy = new PinVerifier(salt, System.Security.Cryptography.Rfc2898DeriveBytes.Pbkdf2(
            pin, salt, 210000, System.Security.Cryptography.HashAlgorithmName.SHA256, 32));
        var module = new DeviceLock(dir.File("lock"), legacy);
        await module.InitAsync(Samples.Context()); await module.SetLockedAsync(true, default);
        Assert.False(await module.ValidatePinAsync(pin, default));
        Assert.Equal(1, module.PinStatus.Attempts);
    }

    [Theory]
    [InlineData(8)]
    [InlineData(32)]
    public void PinLengthBoundariesAreAccepted(int length)
    {
        var pin = new string('0', length);
        Assert.True(PinVerifier.Create(pin).Verify(pin));
    }

    [Theory]
    [InlineData("invalid")]
    [InlineData("write")]
    [InlineData("delete")]
    public async Task VerifierFailureIsLoggedAndExistingPinAndRemoteCommandsKeepWorking(string failure)
    {
        using var dir = new TestDirectory(); var clock = new TestClock(); var logs = new List<string>();
        var ctx = Samples.Context(clock) with { Log = logs.Add };
        var verifierPath = dir.File("verifier");
        Directory.CreateDirectory(verifierPath);
        var module = new DeviceLock(dir.File("lock"), PinVerifier.Create(Correct), verifierPath);
        await module.InitAsync(ctx);
        var actions = new RecordingActions();
        var commands = new CommandExecutor(dir.File("commands"), Samples.Device, module, actions);
        await commands.InitAsync(ctx);
        var settings = failure switch
        {
            "invalid" => new UnlockPinVerifier { Revision = 1, Salt = "bad", Hash = "bad" },
            "delete" => new UnlockPinVerifier { Revision = 0 },
            _ => Settings("00735192", 2)
        };
        foreach (var type in new[] { CommandType.Lock, CommandType.Unlock, CommandType.Restart, CommandType.Shutdown })
        {
            await module.TryApplyVerifierAsync(settings, Samples.Tenant, default);
            var command = CommandRegressionTests.Command(clock) with { Type = type };
            await commands.AcceptAsync([command], Samples.Tenant, default);
            await commands.TickAsync(default);
            Assert.Equal(type == CommandType.Lock, module.Locked);
            Assert.Equal(CommandResultStatus.Succeeded, ((MemoryEvents)ctx.Outbox).CommandResults.Last().Result.Status);
        }
        Assert.Equal(new[] { DeviceAction.Restart, DeviceAction.Shutdown }, actions.Executed);
        Assert.Equal(4, logs.Count);
        Assert.All(logs, log => { Assert.StartsWith("unlock_pin_apply_failed:", log); Assert.True(AgentLogLevel.IsWarning(log)); Assert.DoesNotContain(Correct, log); });
        await module.SetLockedAsync(true, default);
        Assert.True(await module.ValidatePinAsync(Correct, default));
        await commands.ShutdownAsync(); await module.ShutdownAsync();
    }

    [Fact]
    public async Task VerifierApplyHonorsServiceCancellation()
    {
        using var dir = new TestDirectory(); var logs = new List<string>();
        var module = new DeviceLock(dir.File("lock"));
        await module.InitAsync(Samples.Context() with { Log = logs.Add });
        using var canceled = new CancellationTokenSource(); canceled.Cancel();
        await Assert.ThrowsAnyAsync<OperationCanceledException>(() => module.TryApplyVerifierAsync(Settings(Correct), Samples.Tenant, canceled.Token));
        Assert.Empty(logs);
    }

    private sealed class RecordingActions : IDeviceActions
    {
        public List<DeviceAction> Executed { get; } = [];
        public Task ExecuteAsync(DeviceAction action, CancellationToken ct) { Executed.Add(action); return Task.CompletedTask; }
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
