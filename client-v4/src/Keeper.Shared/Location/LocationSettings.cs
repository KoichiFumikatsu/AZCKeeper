using System.Runtime.Versioning;
using System.Text.Json;
using Microsoft.Win32;
using Keeper.Shared.Time;

namespace Keeper.Shared.Location;

public sealed record LocationSettings(string? Consent, string? DesktopConsent, int ServiceStart, bool Running);
public interface ILocationSettings
{
    Task<LocationSettings> ReadAsync(CancellationToken ct);
    Task WriteAsync(LocationSettings settings, CancellationToken ct);
}

public sealed class LocationSettingsStore(ILocationSettings system, string path)
{
    public async Task<bool> ApplyAsync(bool enabled, CancellationToken ct)
    {
        if (!enabled && !File.Exists(path)) return false;
        var current = await system.ReadAsync(ct);
        LocationSettings desired;
        if (enabled)
        {
            if (!File.Exists(path))
            {
                Directory.CreateDirectory(Path.GetDirectoryName(Path.GetFullPath(path))!);
                var temporary = path + ".tmp";
                await File.WriteAllTextAsync(temporary, JsonSerializer.Serialize(current), ct);
                File.Move(temporary, path, false);
            }
            desired = new("Allow", "Allow", current.ServiceStart == 4 ? 3 : current.ServiceStart, true);
        }
        else desired = JsonSerializer.Deserialize<LocationSettings>(await File.ReadAllTextAsync(path, ct))
            ?? throw new InvalidDataException("invalid_location_state");
        if (current != desired) await system.WriteAsync(desired, ct);
        if (!enabled) File.Delete(path);
        return current != desired;
    }
}

[SupportedOSPlatform("windows")]
public sealed class WindowsLocationSettings : ILocationSettings
{
    private const string Consent = @"SOFTWARE\Microsoft\Windows\CurrentVersion\CapabilityAccessManager\ConsentStore\location";
    private readonly WindowsTimeSystem _commands = new(TimeSpan.FromSeconds(10));
    public async Task<LocationSettings> ReadAsync(CancellationToken ct)
    {
        using var service = Registry.LocalMachine.OpenSubKey(@"SYSTEM\CurrentControlSet\Services\lfsvc");
        using var consent = Registry.LocalMachine.OpenSubKey(Consent);
        using var desktop = Registry.LocalMachine.OpenSubKey(Consent + @"\NonPackaged");
        var query = await _commands.RunAsync(new("location_query", "sc.exe", ["query", "lfsvc"]), ct);
        if (query.ExitCode != 0 || service?.GetValue("Start") is not int start) throw new IOException("location_service_unavailable");
        return new(consent?.GetValue("Value") as string, desktop?.GetValue("Value") as string, start, TimeSyncPlan.IsRunning(query.Output));
    }
    public async Task WriteAsync(LocationSettings settings, CancellationToken ct)
    {
        Set(Consent, settings.Consent); Set(Consent + @"\NonPackaged", settings.DesktopConsent);
        var start = settings.ServiceStart switch { 2 => "auto", 3 => "demand", 4 => "disabled", _ => throw new InvalidDataException("invalid_location_service_start") };
        await Run(["config", "lfsvc", "start=", start], ct);
        await Run([settings.Running ? "start" : "stop", "lfsvc"], ct, settings.Running ? 1056 : 1062);
    }
    private static void Set(string path, string? value)
    {
        if (value is null) { using var old = Registry.LocalMachine.OpenSubKey(path, true); old?.DeleteValue("Value", false); }
        else { using var key = Registry.LocalMachine.CreateSubKey(path); key.SetValue("Value", value, RegistryValueKind.String); }
    }
    private async Task Run(string[] args, CancellationToken ct, int allowed = 0)
    {
        var result = await _commands.RunAsync(new("location_service", "sc.exe", args), ct);
        if (result.ExitCode != 0 && result.ExitCode != allowed) throw new IOException("location_service_failed");
    }
}
