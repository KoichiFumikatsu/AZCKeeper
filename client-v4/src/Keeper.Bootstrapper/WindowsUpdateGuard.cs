using System.Runtime.Versioning;
using System.Text.Json;
using Keeper.Shared.Diagnostics;
using Microsoft.Win32;

namespace Keeper.Bootstrapper;

// Vuelta atras del auto-update en Windows. Respaldo en {instalacion}\bin.previous; salud leida de
// {instalacion}\v4\health.json (la escribe el agente); bloqueo en {instalacion}\v4\update-blocked.json (lo lee el
// UpdateManager para no reintentar la release revertida).
[SupportedOSPlatform("windows")]
public sealed class WindowsUpdateGuard(string installDirectory, TimeSpan timeout, Action<string> log) : IUpdateGuard
{
    private string Data => Path.Combine(installDirectory, "v4");

    public void Backup(string bin)
    {
        var previous = bin + ".previous";
        if (Directory.Exists(previous)) Directory.Delete(previous, recursive: true);
        CopyTree(bin, previous);
    }

    public void Restore(string bin)
    {
        var previous = bin + ".previous";
        if (!Directory.Exists(previous)) throw new IOException("backup_missing");
        CopyTree(previous, bin);
        // Archivos que solo trajo la version nueva: fuera, para que bin quede identico al respaldo.
        foreach (var file in Directory.EnumerateFiles(bin, "*", SearchOption.AllDirectories))
            if (!File.Exists(Path.Combine(previous, Path.GetRelativePath(bin, file)))) File.Delete(file);
    }

    public UpdateHealth WaitHealthy(DateTimeOffset since)
    {
        var healthPath = Path.Combine(Data, "health.json");
        var deadline = DateTimeOffset.UtcNow + timeout;
        while (DateTimeOffset.UtcNow < deadline)
        {
            if (AgentHealth.Read(healthPath)?.LastSyncOk > since) return UpdateHealth.Healthy;
            Thread.Sleep(TimeSpan.FromSeconds(5));
        }
        if (AgentHealth.Read(healthPath)?.LastSyncOk > since) return UpdateHealth.Healthy;
        var alive = AgentHealth.Read(healthPath)?.AliveAt > since.AddSeconds(30);
        var reachable = ServerReachable();
        log($"Salud tras {timeout.TotalSeconds:0} s: agente {(alive ? "vivo" : "sin senal")}, servidor {(reachable ? "alcanzable" : "inalcanzable")}.");
        // Un agente que no arranca es fallo de la version aunque no haya red; uno vivo sin red no lo es.
        return alive && !reachable ? UpdateHealth.NetworkUnknown : UpdateHealth.Unhealthy;
    }

    public long? PayloadSequence(string payload)
    {
        try
        {
            using var document = JsonDocument.Parse(File.ReadAllBytes(Path.Combine(payload, "installation-trust.json")));
            return document.RootElement.TryGetProperty("InstalledSequence", out var value) && value.TryGetInt64(out var sequence) ? sequence : null;
        }
        catch (Exception ex) when (ex is IOException or UnauthorizedAccessException or JsonException) { return null; }
    }

    public void BlockRelease(long sequence) =>
        File.WriteAllText(Path.Combine(Data, "update-blocked.json"), JsonSerializer.Serialize(new { sequence }));

    private bool ServerReachable()
    {
        try
        {
            using var key = Registry.LocalMachine.OpenSubKey($@"SYSTEM\CurrentControlSet\Services\{BootstrapApplication.ServiceName}");
            var api = (key?.GetValue("Environment") as string[])?.FirstOrDefault(v => v.StartsWith("KEEPER_API_BASE=", StringComparison.Ordinal))?[16..];
            if (api is null || !Uri.TryCreate(api, UriKind.Absolute, out var root)) return false;
            using var http = new HttpClient { Timeout = TimeSpan.FromSeconds(15) };
            using var response = http.GetAsync(new Uri(root, "auth/csrf")).GetAwaiter().GetResult();
            return (int)response.StatusCode < 500;
        }
        catch (Exception ex) when (ex is HttpRequestException or TaskCanceledException or IOException or UnauthorizedAccessException or System.Security.SecurityException) { return false; }
    }

    private static void CopyTree(string source, string destination)
    {
        foreach (var file in Directory.EnumerateFiles(source, "*", SearchOption.AllDirectories))
        {
            var target = Path.Combine(destination, Path.GetRelativePath(source, file));
            Directory.CreateDirectory(Path.GetDirectoryName(target)!);
            File.Copy(file, target, overwrite: true);
        }
    }
}
