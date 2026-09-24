using Keeper.Agent.Storage;
using System.Security.Cryptography;
using System.Text.Json;
using Keeper.Agent.Hosting;
using Keeper.Agent.Policy;
using Keeper.Shared.Protocol;

namespace Keeper.Agent.Tests;

public sealed class PolicyTests
{
    [Fact]
    public async Task OnlyIncreasingIntegerVersionIsPersistedAndApplied()
    {
        var module = new TestModule();
        await using var host = new ModuleHost([module], Samples.Context());
        var store = new MemoryPolicyStore();
        var coordinator = new PolicyCoordinator(store, host, Samples.Device);
        Assert.True(await coordinator.ApplyAsync(1, Samples.Policy(), default));
        Assert.False(await coordinator.ApplyAsync(1, Samples.Policy(), default));
        Assert.True(await coordinator.ApplyAsync(2, Samples.Policy() with { Version = "opaque-v2" }, default));
        Assert.False(await coordinator.ApplyAsync(1, Samples.Policy(), default));
        Assert.False(await coordinator.ApplyAsync(3, null, default));
        Assert.Equal(2, module.Applications);
        Assert.Equal(2, store.Saves);
        Assert.Equal(2L, coordinator.CurrentVersion);
    }

    [Fact]
    public async Task RejectsWrongDeviceTenantAndInvalidRulesBeforeWriting()
    {
        await using var host = new ModuleHost([], Samples.Context());
        var store = new MemoryPolicyStore();
        var coordinator = new PolicyCoordinator(store, host, Samples.Device);
        await Assert.ThrowsAsync<InvalidDataException>(() => coordinator.ApplyAsync(1, Samples.Policy() with { DeviceId = Guid.NewGuid() }, default));
        await coordinator.ApplyAsync(1, Samples.Policy(), default);
        await Assert.ThrowsAsync<InvalidDataException>(() => coordinator.ApplyAsync(2, Samples.Policy() with { TenantId = Guid.NewGuid() }, default));
        await Assert.ThrowsAsync<InvalidDataException>(() => coordinator.ApplyAsync(2, Samples.Policy(Samples.Rule(RuleKind.Usb, RuleEffect.Deny, "*") with { Priority = -1 }), default));
        Assert.Equal(1, store.Saves);
    }

    [Fact]
    public async Task SignedCacheRestoresOfflineAndRejectsTampering()
    {
        using var directory = new TestDirectory();
        using var key = ECDsa.Create(ECCurve.NamedCurves.nistP256);
        var path = directory.File("policy.json");
        var store = new SignedFilePolicyStore(path, key);
        await store.SaveAsync(new CachedPolicy(7, Samples.Policy()), default);
        var module = new TestModule();
        await using var host = new ModuleHost([module], Samples.Context());
        var coordinator = new PolicyCoordinator(new SignedFilePolicyStore(path, key), host, Samples.Device);
        await coordinator.RestoreAsync(default);
        Assert.Equal(7L, coordinator.CurrentVersion);
        Assert.Equal(1, module.Applications);
        var envelope = JsonSerializer.Deserialize<SignedPolicyCache>(await File.ReadAllBytesAsync(path), ProtocolJson.Options)!;
        envelope.Payload[0] ^= 1;
        await File.WriteAllBytesAsync(path, JsonSerializer.SerializeToUtf8Bytes(envelope, ProtocolJson.Options));
        await Assert.ThrowsAsync<CryptographicException>(() => store.LoadAsync(default));
    }

    [Fact]
    public void ContractUsesNumericInt64AndRejectsUnknownFields()
    {
        var request = new SyncRequest { ProtocolVersion = 1, Sequence = 1, PolicyVersion = (long)int.MaxValue + 1, ReleaseId = null };
        var json = JsonSerializer.Serialize(request, ProtocolJson.Options);
        using var document = JsonDocument.Parse(json);
        Assert.Equal(JsonValueKind.Number, document.RootElement.GetProperty("policy_version").ValueKind);
        Assert.Equal(JsonValueKind.Null, document.RootElement.GetProperty("release_id").ValueKind);
        Assert.Equal(request, JsonSerializer.Deserialize<SyncRequest>(json, ProtocolJson.Options));
        Assert.Throws<JsonException>(() => JsonSerializer.Deserialize<SyncRequest>(json[..^1] + ",\"extra\":true}", ProtocolJson.Options));
    }

    [Fact]
    public async Task CancelledAtomicWriteLeavesNoTemporaryFile()
    {
        using var directory = new TestDirectory();
        var path = directory.File("state.json");
        await AtomicFile.WriteAsync(path, "old"u8.ToArray(), default);
        await Assert.ThrowsAnyAsync<OperationCanceledException>(() => AtomicFile.WriteAsync(path, "new"u8.ToArray(), new CancellationToken(true)));
        Assert.Equal("old", await File.ReadAllTextAsync(path));
        Assert.False(File.Exists(path + ".tmp"));
    }
}
