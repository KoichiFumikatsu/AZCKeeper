using System.Security.Cryptography;

namespace Keeper.Agent.Storage;

public static class DeviceKeyStore
{
    public static async Task<ECDsa> LoadOrCreateAsync(string path, CancellationToken ct)
    {
        if (!OperatingSystem.IsWindows()) throw new PlatformNotSupportedException("Windows DPAPI required");
        var key = ECDsa.Create(ECCurve.NamedCurves.nistP256);
        byte[]? plain = null;
        try
        {
            if (File.Exists(path))
            {
                plain = ProtectedData.Unprotect(await File.ReadAllBytesAsync(path, ct), null, DataProtectionScope.CurrentUser);
                key.ImportPkcs8PrivateKey(plain, out _);
            }
            else
            {
                plain = key.ExportPkcs8PrivateKey();
                var encrypted = ProtectedData.Protect(plain, null, DataProtectionScope.CurrentUser);
                await AtomicFile.WriteAsync(path, encrypted, ct);
            }
            return key;
        }
        catch { key.Dispose(); throw; }
        finally { if (plain is not null) CryptographicOperations.ZeroMemory(plain); }
    }
}
