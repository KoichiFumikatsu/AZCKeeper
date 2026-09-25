using System.Security.Cryptography;
using System.Text.Json;
using Keeper.Agent.Migration;
using Keeper.Agent.Storage;
using Keeper.Agent.Transport;
using Keeper.Shared.Protocol;
using Microsoft.Win32;

namespace Keeper.Agent.Hosting;

public static class EnrollmentIdentityCommand
{
    public static async Task PrintAsync(TextWriter output, CancellationToken ct,
        Func<string, string?>? readSetting = null,
        Func<string, CancellationToken, Task<ECDsa>>? loadKey = null)
    {
        readSetting ??= ReadSetting;
        var directory = readSetting("KEEPER_DATA_DIR") ??
            Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.CommonApplicationData), "AZCKeeper", "v4");
        if (!Guid.TryParse(readSetting("KEEPER_DEVICE_ID"), out var deviceId) || deviceId == Guid.Empty)
            throw new InvalidOperationException("KEEPER_DEVICE_ID is required to print enrollment identity.");
        using var signer = new HttpMessageSigner(await (loadKey ?? DeviceKeyStore.LoadAsync)(Path.Combine(directory, "device-key.dpapi"), ct));
        await output.WriteLineAsync(JsonSerializer.Serialize(new
        {
            public_key_thumbprint = MigrationEnrollment.Thumbprint(signer),
            public_key = signer.PublicKey,
            device_id = deviceId,
            hostname = Environment.MachineName
        }, ProtocolJson.Options));
    }

    private static string? ReadSetting(string name)
    {
        if (Environment.GetEnvironmentVariable(name) is { } value) return value;
        if (!OperatingSystem.IsWindows()) return null;
        // A SYSTEM console does not inherit the SCM service environment.
        using var machine = RegistryKey.OpenBaseKey(RegistryHive.LocalMachine, RegistryView.Registry64);
        using var service = machine.OpenSubKey(@"SYSTEM\CurrentControlSet\Services\KeeperAgent", writable: false);
        return (service?.GetValue("Environment") as string[])?
            .FirstOrDefault(entry => entry.StartsWith(name + "=", StringComparison.OrdinalIgnoreCase))?[(name.Length + 1)..];
    }
}
