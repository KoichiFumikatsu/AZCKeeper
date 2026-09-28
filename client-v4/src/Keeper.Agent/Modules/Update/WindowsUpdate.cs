using System.Diagnostics;
using System.IO.Compression;
using System.Runtime.Versioning;

namespace Keeper.Agent.Modules.Update;

public sealed class WindowsReleaseDownloader(HttpClient http) : IReleaseDownloader
{
    public async Task DownloadAsync(string url, string destination, long maxBytes, CancellationToken ct)
    {
        using var response = await http.GetAsync(url, HttpCompletionOption.ResponseHeadersRead, ct);
        response.EnsureSuccessStatusCode();
        if (response.Content.Headers.ContentLength is { } declared && declared > maxBytes) throw new IOException("package_too_large");
        await using var source = await response.Content.ReadAsStreamAsync(ct);
        await using var file = new FileStream(destination, FileMode.Create, FileAccess.Write, FileShare.None);
        var buffer = new byte[64 * 1024];
        long total = 0;
        int count;
        while ((count = await source.ReadAsync(buffer, ct)) > 0)
        {
            total += count;
            if (total > maxBytes) throw new IOException("package_too_large");
            await file.WriteAsync(buffer.AsMemory(0, count), ct);
        }
    }
}

[SupportedOSPlatform("windows")]
public sealed class WindowsReleaseInstaller : IReleaseInstaller
{
    // Extrae el ZIP verificado y lanza el bootstrapper en modo --system-update como proceso
    // INDEPENDIENTE: reemplaza los binarios preservando el entorno del servicio. Debe sobrevivir a que
    // el bootstrapper detenga este servicio (un servicio Windows no encierra a sus hijos en un Job).
    public void Install(string packagePath)
    {
        var extract = packagePath + ".d";
        if (Directory.Exists(extract)) Directory.Delete(extract, recursive: true);
        ZipFile.ExtractToDirectory(packagePath, extract);
        var bootstrapper = Path.Combine(extract, "Keeper.Bootstrapper.exe");
        var payload = Path.Combine(extract, "agent");
        if (!File.Exists(bootstrapper) || !Directory.Exists(payload) || !File.Exists(Path.Combine(payload, "Keeper.Agent.exe")))
            throw new FileNotFoundException("update_package_incomplete");
        var start = new ProcessStartInfo(bootstrapper)
        {
            UseShellExecute = false,
            CreateNoWindow = true,
            WorkingDirectory = extract
        };
        foreach (var argument in new[] { "--system-update", "--payload", payload }) start.ArgumentList.Add(argument);
        if (Process.Start(start) is null) throw new IOException("update_launch_failed");
    }
}
