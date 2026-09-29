using System.Security.Cryptography;
using System.Text;
using System.Text.Json;
using System.Text.Json.Nodes;
using Keeper.Agent.Modules.Security;
using Keeper.Agent.Modules.Update;
using Keeper.Agent.Modules.Devices;
using Keeper.Agent.Storage;
using Keeper.Shared.Contracts;
using Keeper.Shared.Protocol;

namespace Keeper.Agent.Tests;

public sealed class SecurityModuleTests
{
    [Fact]
    public async Task LockAndPinRateLimitSurviveRestartAndNeverReachOutbox()
    {
        using var directory = new TestDirectory();
        var clock = new TestClock();
        var context = Samples.Context(clock);
        var verifier = PinVerifier.Create("625184");
        var deviceLock = new DeviceLock(directory.File("lock"), verifier);
        await deviceLock.InitAsync(context); await deviceLock.SetLockedAsync(true, default);
        Assert.False(await deviceLock.ValidatePinAsync("111111", default));
        var restarted = new DeviceLock(directory.File("lock"), verifier);
        await restarted.InitAsync(context);
        Assert.True(restarted.Locked);
        Assert.False(await restarted.ValidatePinAsync("625184", default));
        clock.Advance(TimeSpan.FromSeconds(3));
        Assert.True(await restarted.ValidatePinAsync("625184", default));
        Assert.False(restarted.Locked);
        Assert.DoesNotContain("625184", await File.ReadAllTextAsync(directory.File("lock")));
        Assert.Empty(((MemoryEvents)context.Outbox).Logs);
        await deviceLock.ShutdownAsync(); await restarted.ShutdownAsync();
    }

    [Fact]
    public async Task CorruptLockStateFailsClosedAndCannotBeOverwrittenByCommands()
    {
        using var directory = new TestDirectory();
        await File.WriteAllTextAsync(directory.File("lock"), "invalid");
        var deviceLock = new DeviceLock(directory.File("lock"));
        await Assert.ThrowsAsync<JsonException>(() => deviceLock.InitAsync(Samples.Context()));
        Assert.True(deviceLock.Locked);
        await Assert.ThrowsAsync<InvalidOperationException>(() => deviceLock.SetLockedAsync(false, default));
        await deviceLock.ShutdownAsync();
    }

    [Fact]
    public async Task MissingVerifierCannotUnlockOffline()
    {
        using var directory = new TestDirectory();
        var deviceLock = new DeviceLock(directory.File("lock"));
        await deviceLock.InitAsync(Samples.Context()); await deviceLock.SetLockedAsync(true, default);
        Assert.False(deviceLock.PinAllowed);
        Assert.False(await deviceLock.ValidatePinAsync("123456", default));
        Assert.True(deviceLock.Locked);
        await deviceLock.ShutdownAsync();
    }

    [Fact]
    public async Task CommandInboxDeduplicatesAcrossRestartAndRejectsForeignDevice()
    {
        using var directory = new TestDirectory();
        using var outbox = new DurableOutbox(directory.File("outbox"));
        var clock = new TestClock();
        var context = new ModuleContext(outbox, clock, _ => { }, default);
        var deviceLock = new DeviceLock(directory.File("lock")); await deviceLock.InitAsync(context);
        var actions = new FakeActions();
        var executor = new CommandExecutor(directory.File("inbox"), Samples.Device, deviceLock, actions);
        await executor.InitAsync(context);
        var command = new Command { Id = Guid.NewGuid(), DeviceId = Samples.Device, TenantId = Samples.Tenant,
            Type = CommandType.Restart, Status = CommandStatus.Pending, CreatedAt = clock.GetUtcNow(), ExpiresAt = clock.GetUtcNow().AddHours(1), Result = null };
        await executor.AcceptAsync([command, command], Samples.Tenant, default);
        await executor.TickAsync(default); await executor.TickAsync(default);
        await executor.ShutdownAsync();
        var restarted = new CommandExecutor(directory.File("inbox"), Samples.Device, deviceLock, actions);
        await restarted.InitAsync(context); await restarted.AcceptAsync([command], Samples.Tenant, default); await restarted.TickAsync(default);
        Assert.Equal(1, actions.Executions);
        Assert.Single((await outbox.InspectAsync()).Events);
        await restarted.AcceptAsync([command with { DeviceId = Guid.NewGuid() }], Samples.Tenant, default);
        Assert.Contains((await outbox.InspectAsync()).Events, e => e.Command?.Result.Code == "invalid_command_destination");
        await restarted.ShutdownAsync(); await deviceLock.ShutdownAsync();
    }

    [Theory]
    [InlineData("valid")]
    [InlineData("tampered_package")]
    [InlineData("tampered_metadata")]
    [InlineData("untrusted_key")]
    [InlineData("rollback")]
    [InlineData("wrong_channel")]
    public async Task UpdatesRequireTrustedSignatureBoundMetadataAndPackageHash(string scenario)
    {
        using var key = ECDsa.Create(ECCurve.NamedCurves.nistP256);
        var bytes = Encoding.UTF8.GetBytes("signed test package");
        var release = SignedRelease(key, bytes);
        var keys = new Dictionary<string, ECDsa> { [release.KeyId] = key };
        if (scenario == "tampered_package") bytes[0] ^= 1;
        if (scenario == "tampered_metadata") release = release with { Version = "4.9.0" };
        if (scenario == "untrusted_key") keys.Clear();
        using var stream = new MemoryStream(bytes);
        Task Verify() => ReleaseVerifier.VerifyAsync(release, stream, keys, scenario == "rollback" ? 2 : 0,
            scenario == "wrong_channel" ? "other" : "stable", ReleaseArchitecture.X64, new Version(4, 0, 0), default);
        if (scenario == "valid") await Verify();
        else await Assert.ThrowsAsync<CryptographicException>(Verify);
    }

    [Fact]
    public async Task RemoteUnlockAndRelockResetPinWindowButRepeatedLockDoesNot()
    {
        using var directory = new TestDirectory();
        var context = Samples.Context(new TestClock());
        var deviceLock = new DeviceLock(directory.File("lock"), PinVerifier.Create("625184"));
        await deviceLock.InitAsync(context);
        await deviceLock.SetLockedAsync(true, default);
        Assert.False(await deviceLock.ValidatePinAsync("111111", default));
        await deviceLock.SetLockedAsync(true, default);
        Assert.False(await deviceLock.ValidatePinAsync("625184", default));
        await deviceLock.SetLockedAsync(false, default);
        await deviceLock.SetLockedAsync(true, default);
        Assert.True(await deviceLock.ValidatePinAsync("625184", default));
        await deviceLock.ShutdownAsync();
    }

    [Theory]
    [InlineData(false)]
    [InlineData(true)]
    public async Task TamperEventsUseErrorSeverity(bool missing)
    {
        using var directory = new TestDirectory();
        var path = directory.File("binary");
        if (!missing) await File.WriteAllTextAsync(path, "modified");
        var context = Samples.Context(new TestClock());
        var guard = new TamperGuard(new Dictionary<string, string> { [path] = new string('0', 64) });
        await guard.InitAsync(context);
        await guard.TickAsync(default);
        var log = Assert.Single(((MemoryEvents)context.Outbox).Logs);
        Assert.Equal(missing ? "binary_missing" : "binary_modified", log.Code);
        Assert.Equal(LogEntryLevel.Error, log.Level);
    }

    [Fact]
    public async Task FailedReleaseIsReportedOnceAndNotReadAgainEvenWhenReoffered()
    {
        using var directory = new TestDirectory();
        using var key = ECDsa.Create(ECCurve.NamedCurves.nistP256);
        var bytes = Encoding.UTF8.GetBytes("signed test package");
        var release = SignedRelease(key, bytes);
        var path = directory.File(release.Id.ToString("N") + ".zip");
        var clock = new TestClock();
        var context = Samples.Context(clock);
        var updater = new UpdateManager(directory.Root, new Dictionary<string, ECDsa> { [release.KeyId] = key });
        await updater.InitAsync(context);
        updater.Offer(release);
        await updater.TickAsync(default);
        Assert.Equal("awaiting_package", updater.Snapshot().State);
        await File.WriteAllTextAsync(path, "corrupt");
        for (var i = 0; i < 299; i++)
        {
            clock.Advance(TimeSpan.FromSeconds(1));
            updater.Offer(release);
            await updater.TickAsync(default);
        }
        Assert.Equal("awaiting_package", updater.Snapshot().State);
        clock.Advance(TimeSpan.FromSeconds(1));
        await updater.TickAsync(default);
        Assert.Equal("failed", updater.Snapshot().State);
        Assert.Equal("release_verification_failed", updater.Snapshot().ErrorCode);
        var failure = Assert.Single(((MemoryEvents)context.Outbox).Logs);
        Assert.Equal(LogEntryLevel.Error, failure.Level);
        await File.WriteAllBytesAsync(path, bytes);
        clock.Advance(TimeSpan.FromDays(1));
        updater.Offer(release);
        await updater.TickAsync(default);
        Assert.Equal("failed", updater.Snapshot().State);
        Assert.Single(((MemoryEvents)context.Outbox).Logs);
        var next = SignedRelease(key, bytes);
        await File.WriteAllBytesAsync(directory.File(next.Id.ToString("N") + ".zip"), bytes);
        updater.Offer(next);
        await updater.TickAsync(default);
        Assert.Equal("verified_pending_install", updater.Snapshot().State);
        updater.Offer(release);
        await updater.TickAsync(default);
        Assert.Equal("failed", updater.Snapshot().State);
        Assert.Equal(2, ((MemoryEvents)context.Outbox).Logs.Count);
    }

    [Fact]
    public async Task TamperGuardReportsChangedBinary()
    {
        using var directory = new TestDirectory();
        var path = directory.File("binary");
        await File.WriteAllTextAsync(path, "original");
        var expected = Convert.ToHexString(SHA256.HashData(await File.ReadAllBytesAsync(path)));
        var guard = new TamperGuard(new Dictionary<string, string> { [path] = expected });
        var clock = new TestClock(); await guard.InitAsync(Samples.Context(clock));
        await guard.TickAsync(default); Assert.Equal("applied", guard.Snapshot().State);
        await File.WriteAllTextAsync(path, "modified"); clock.Advance(TimeSpan.FromMinutes(6));
        await guard.TickAsync(default); Assert.Equal("failed", guard.Snapshot().State);
    }

    private static Release SignedRelease(ECDsa key, byte[] package)
    {
        var release = new Release { Id = Guid.NewGuid(), Version = "4.0.1", Channel = "stable", Sequence = 2,
            MinAgentVersion = "4.0.0", Architecture = ReleaseArchitecture.X64, ArtifactUrl = "https://example.test/package.msi",
            SizeBytes = package.Length, Sha256 = Convert.ToHexString(SHA256.HashData(package)).ToLowerInvariant(), KeyId = "test-key",
            ManifestJws = "", PublishedAt = DateTimeOffset.Parse("2026-09-17T00:00:00Z") };
        var payload = JsonSerializer.SerializeToNode(release, ProtocolJson.Options)!.AsObject();
        payload.Remove("manifest_jws");
        var input = Encode(Encoding.UTF8.GetBytes("{\"alg\":\"ES256\",\"kid\":\"test-key\"}")) + "." + Encode(JsonSerializer.SerializeToUtf8Bytes(payload));
        return release with { ManifestJws = input + "." + Encode(key.SignData(Encoding.ASCII.GetBytes(input), HashAlgorithmName.SHA256, DSASignatureFormat.IeeeP1363FixedFieldConcatenation)) };
    }
    private static string Encode(byte[] bytes) => Convert.ToBase64String(bytes).TrimEnd('=').Replace('+', '-').Replace('/', '_');
    private sealed class FakeActions : IDeviceActions
    {
        public int Executions { get; private set; }
        public Task ExecuteAsync(DeviceAction action, CancellationToken ct) { Executions++; return Task.CompletedTask; }
    }

    private sealed class FakeDownloader(byte[] package) : IReleaseDownloader
    {
        public int Calls;
        public bool Fail;
        public Task DownloadAsync(string url, string destination, long maxBytes, CancellationToken ct)
        {
            Calls++;
            if (Fail) return Task.FromException(new IOException("download boom"));
            File.WriteAllBytes(destination, package);
            return Task.CompletedTask;
        }
    }

    private sealed class FakeInstaller : IReleaseInstaller
    {
        public int Calls;
        public string? Package;
        public void Install(string packagePath) { Calls++; Package = packagePath; }
    }

    [Fact]
    public async Task DescargaVerificaYAplicaLaReleaseUnaSolaVez()
    {
        using var directory = new TestDirectory();
        using var key = ECDsa.Create(ECCurve.NamedCurves.nistP256);
        var bytes = Encoding.UTF8.GetBytes("signed test package");
        var release = SignedRelease(key, bytes);
        var clock = new TestClock();
        var downloader = new FakeDownloader(bytes);
        var installer = new FakeInstaller();
        var updater = new UpdateManager(directory.Root, new Dictionary<string, ECDsa> { [release.KeyId] = key },
            0, "stable", downloader, installer, new Version(4, 0, 0));
        await updater.InitAsync(Samples.Context(clock));
        updater.Offer(release);
        await updater.TickAsync(default);
        Assert.Equal("downloading", updater.Snapshot().State);
        Assert.Equal(1, downloader.Calls);
        await updater.TickAsync(default);                       // sondea la descarga -> downloaded
        Assert.Equal("downloaded", updater.Snapshot().State);
        Assert.True(File.Exists(directory.File(release.Id.ToString("N") + ".zip")));
        clock.Advance(TimeSpan.FromMinutes(6));
        await updater.TickAsync(default);                       // verifica firma+hash
        Assert.Equal("verified_pending_install", updater.Snapshot().State);
        clock.Advance(TimeSpan.FromMinutes(6));
        await updater.TickAsync(default);                       // aplica (lanza bootstrapper)
        Assert.Equal("applying", updater.Snapshot().State);
        Assert.Equal(1, installer.Calls);
        Assert.Equal(directory.File(release.Id.ToString("N") + ".zip"), installer.Package);
        clock.Advance(TimeSpan.FromMinutes(6));
        await updater.TickAsync(default);                       // no relanza
        Assert.Equal(1, installer.Calls);
    }

    [Theory]
    [InlineData(2, "stable")]   // agente ya actualizado a la release ofrecida (sequence 2)
    [InlineData(5, "stable")]   // agente por delante (rollback ofrecido)
    [InlineData(0, "beta")]     // canal distinto
    public async Task ReleaseNoAplicableNoSeDescarga(long installedSequence, string channel)
    {
        using var directory = new TestDirectory();
        using var key = ECDsa.Create(ECCurve.NamedCurves.nistP256);
        var release = SignedRelease(key, Encoding.UTF8.GetBytes("pkg"));   // sequence 2, stable
        var downloader = new FakeDownloader([]);
        var updater = new UpdateManager(directory.Root, new Dictionary<string, ECDsa> { [release.KeyId] = key },
            installedSequence, channel, downloader, new FakeInstaller(), new Version(4, 0, 0));
        var context = Samples.Context(new TestClock());
        await updater.InitAsync(context);
        updater.Offer(release);
        await updater.TickAsync(default);
        Assert.Equal(0, downloader.Calls);
        Assert.Equal("current", updater.Snapshot().State);
        Assert.Null(updater.Snapshot().ErrorCode);
        Assert.Empty(((MemoryEvents)context.Outbox).Logs);
    }

    [Fact]
    public async Task ReleaseRevertidaPorElBootstrapperNoSeReintentaPeroUnaPosteriorSi()
    {
        // Sin esto, el agente restaurado volveria a bajar e instalar la release rota cada ~12 minutos.
        using var directory = new TestDirectory();
        using var key = ECDsa.Create(ECCurve.NamedCurves.nistP256);
        var bytes = Encoding.UTF8.GetBytes("pkg");
        var broken = SignedRelease(key, bytes);   // sequence 2
        File.WriteAllText(directory.File("update-blocked.json"), "{\"sequence\":2}");
        var downloader = new FakeDownloader(bytes);
        var updater = new UpdateManager(Path.Combine(directory.Root, "staging"), new Dictionary<string, ECDsa> { [broken.KeyId] = key },
            1, "stable", downloader, new FakeInstaller(), new Version(4, 0, 0), directory.File("update-blocked.json"));
        var context = Samples.Context(new TestClock());
        await updater.InitAsync(context);
        updater.Offer(broken);
        await updater.TickAsync(default);
        Assert.Equal(0, downloader.Calls);
        Assert.Equal("failed", updater.Snapshot().State);
        Assert.Equal("release_rolled_back", updater.Snapshot().ErrorCode);
        var fix = SignedRelease(key, bytes) with { Sequence = 3 };
        updater.Offer(fix);
        await updater.TickAsync(default);
        Assert.Equal(1, downloader.Calls);
    }

    [Fact]
    public async Task StagingSeLimpiaSinTocarLoRecienteNiLaReleaseEnCurso()
    {
        using var directory = new TestDirectory();
        var staging = Path.Combine(directory.Root, "staging");
        Directory.CreateDirectory(Path.Combine(staging, "old.zip.d", "agent"));
        File.WriteAllText(Path.Combine(staging, "old.zip"), "x");
        File.WriteAllText(Path.Combine(staging, "old.zip.d", "agent", "a.dll"), "x");
        File.WriteAllText(Path.Combine(staging, "fresh.zip"), "x");
        var clock = new TestClock();
        var past = clock.GetUtcNow().UtcDateTime.AddHours(-3);
        File.SetLastWriteTimeUtc(Path.Combine(staging, "old.zip"), past);
        Directory.SetLastWriteTimeUtc(Path.Combine(staging, "old.zip.d"), past);
        File.SetLastWriteTimeUtc(Path.Combine(staging, "fresh.zip"), clock.GetUtcNow().UtcDateTime);
        var updater = new UpdateManager(staging, new Dictionary<string, ECDsa>());
        await updater.InitAsync(Samples.Context(clock));
        await updater.TickAsync(default);
        Assert.False(File.Exists(Path.Combine(staging, "old.zip")));
        Assert.False(Directory.Exists(Path.Combine(staging, "old.zip.d")));
        Assert.True(File.Exists(Path.Combine(staging, "fresh.zip")));
    }

    [Fact]
    public async Task DescargaFallidaSeReportaYNoAplica()
    {
        using var directory = new TestDirectory();
        using var key = ECDsa.Create(ECCurve.NamedCurves.nistP256);
        var release = SignedRelease(key, Encoding.UTF8.GetBytes("pkg"));
        var downloader = new FakeDownloader([]) { Fail = true };
        var installer = new FakeInstaller();
        var updater = new UpdateManager(directory.Root, new Dictionary<string, ECDsa> { [release.KeyId] = key },
            0, "stable", downloader, installer, new Version(4, 0, 0));
        await updater.InitAsync(Samples.Context(new TestClock()));
        updater.Offer(release);
        await updater.TickAsync(default);   // dispara
        await updater.TickAsync(default);   // sondea -> falla
        Assert.Equal("download_failed", updater.Snapshot().State);
        Assert.Equal(0, installer.Calls);
        Assert.False(File.Exists(directory.File(release.Id.ToString("N") + ".zip")));
    }
}
