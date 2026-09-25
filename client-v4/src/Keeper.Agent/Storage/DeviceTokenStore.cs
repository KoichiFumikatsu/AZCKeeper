using System.Security.Cryptography;
using System.Text;
using System.Text.Json;
using Keeper.Shared.Protocol;

namespace Keeper.Agent.Storage;

public sealed record StoredDeviceToken(DeviceToken Token, DateTimeOffset IssuedAt);

public interface IDeviceTokenStore
{
    Task<StoredDeviceToken?> LoadAsync(CancellationToken ct);
    Task SaveAsync(StoredDeviceToken token, CancellationToken ct);
    Task DeleteAsync(CancellationToken ct);
}

public sealed class DeviceTokenStore(string path, string identityScope) : IDeviceTokenStore
{
    private readonly byte[] _entropy = SHA256.HashData(Encoding.UTF8.GetBytes(identityScope));

    public Task DeleteAsync(CancellationToken ct)
    {
        ct.ThrowIfCancellationRequested();
        File.Delete(path);
        return Task.CompletedTask;
    }

    public async Task<StoredDeviceToken?> LoadAsync(CancellationToken ct)
    {
        if (!OperatingSystem.IsWindows()) throw new PlatformNotSupportedException("Windows DPAPI required");
        if (!File.Exists(path)) return null;
        var plain = ProtectedData.Unprotect(await File.ReadAllBytesAsync(path, ct), _entropy, DataProtectionScope.CurrentUser);
        try
        {
            return JsonSerializer.Deserialize<StoredDeviceToken>(plain, ProtocolJson.Options)
                ?? throw new InvalidDataException("invalid_stored_device_token");
        }
        finally { CryptographicOperations.ZeroMemory(plain); }
    }

    public async Task SaveAsync(StoredDeviceToken token, CancellationToken ct)
    {
        if (!OperatingSystem.IsWindows()) throw new PlatformNotSupportedException("Windows DPAPI required");
        var plain = JsonSerializer.SerializeToUtf8Bytes(token, ProtocolJson.Options);
        try
        {
            await AtomicFile.WriteAsync(path, ProtectedData.Protect(plain, _entropy, DataProtectionScope.CurrentUser), ct);
        }
        finally { CryptographicOperations.ZeroMemory(plain); }
    }
}
