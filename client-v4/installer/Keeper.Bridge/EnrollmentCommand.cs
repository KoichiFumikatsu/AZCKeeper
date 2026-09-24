using System.Security.Principal;
using System.ServiceProcess;
using System.Text.Json;
using Keeper.Agent.Migration;
using Keeper.Agent.Storage;
using Keeper.Agent.Transport;
using Microsoft.Win32;

namespace Keeper.Bridge;

internal sealed record EnrollmentInput(Guid TenantId, Guid DeviceId, Uri ApiBase, string? Ticket);

internal static class EnrollmentCommand
{
    public static async Task<int> RunAsync(string mode)
    {
        using var identity = WindowsIdentity.GetCurrent();
        if (!identity.IsSystem) throw new UnauthorizedAccessException("enrollment_requires_SYSTEM");
        var line = await Console.In.ReadLineAsync();
        if (line is null || line.Length > 16384) throw new InvalidDataException("enrollment_input_invalid");
        var input = JsonSerializer.Deserialize<EnrollmentInput>(line) ?? throw new InvalidDataException("enrollment_input_invalid");
        if (input.TenantId == Guid.Empty || input.DeviceId == Guid.Empty || !input.ApiBase.IsAbsoluteUri || input.ApiBase.Scheme != "https" ||
            input.ApiBase.UserInfo.Length != 0 || input.ApiBase.Query.Length != 0 || input.ApiBase.Fragment.Length != 0 || !input.ApiBase.AbsolutePath.EndsWith("/v1/", StringComparison.Ordinal))
            throw new InvalidDataException("enrollment_identity_invalid");
        var keyPath = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.CommonApplicationData), "AZCKeeper", "v4", "device-key.dpapi");
        // Install starts the service, which owns creation of this SYSTEM DPAPI key.
        if (!File.Exists(keyPath)) throw new InvalidOperationException("agent_key_not_ready");
        using var key = await DeviceKeyStore.LoadOrCreateAsync(keyPath, default);
        using var signer = new HttpMessageSigner(key);
        if (mode == "--enrollment-key")
        {
            Console.WriteLine(JsonSerializer.Serialize(new { public_key_thumbprint = MigrationEnrollment.Thumbprint(signer) }));
            return 0;
        }
        using var handler = new HttpClientHandler { AllowAutoRedirect = false, UseCookies = false };
        using var http = new HttpClient(handler) { BaseAddress = input.ApiBase, Timeout = TimeSpan.FromSeconds(45) };
        var token = await MigrationEnrollment.LoginAsync(http, signer, input.TenantId, input.DeviceId, input.Ticket, default);
        using (var serviceKey = Registry.LocalMachine.OpenSubKey(@"SYSTEM\CurrentControlSet\Services\AZCKeeper v4", writable: true)
            ?? throw new InvalidOperationException("agent_service_missing"))
        {
            var values = (serviceKey.GetValue("Environment") as string[] ?? []).Where(v => !v.StartsWith("KEEPER_DEVICE_ID=", StringComparison.OrdinalIgnoreCase) && !v.StartsWith("KEEPER_API_BASE=", StringComparison.OrdinalIgnoreCase)).ToList();
            values.Add("KEEPER_DEVICE_ID=" + token.DeviceId);
            values.Add("KEEPER_API_BASE=" + input.ApiBase.AbsoluteUri);
            serviceKey.SetValue("Environment", values.ToArray(), RegistryValueKind.MultiString);
        }
        using var service = new ServiceController("AZCKeeper v4");
        if (service.Status != ServiceControllerStatus.Stopped)
        {
            service.Stop();
            service.WaitForStatus(ServiceControllerStatus.Stopped, TimeSpan.FromSeconds(45));
        }
        service.Start();
        service.WaitForStatus(ServiceControllerStatus.Running, TimeSpan.FromSeconds(45));
        // The device bearer is never returned to the caller; the service obtains its own using the same PoP key.
        Console.WriteLine(JsonSerializer.Serialize(new { proof_verified = true, device_id = token.DeviceId, tenant_id = token.TenantId }));
        return 0;
    }
}
