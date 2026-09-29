using System.Diagnostics;
using System.Runtime.Versioning;
using System.Text.Json;
using System.Text.Json.Nodes;

namespace Keeper.Bootstrapper;

// Instala el rescate independiente: {instalacion}\recovery\Keeper-Recovery.ps1, recovery\trust.json (solo las claves
// publicas de release del paquete) y la tarea "AZCKeeper Recovery" (cada hora, SYSTEM). recovery\ hereda el ACL
// protegido de la instalacion (SYSTEM/Administradores): un usuario no puede alterar el script ni la clave.
[SupportedOSPlatform("windows")]
public sealed class WindowsRescueInstaller(string installDirectory, Action<string> log) : IRescueInstaller
{
    public const string TaskName = "AZCKeeper Recovery";
    private string Directory => Path.Combine(installDirectory, "recovery");
    private string Script => Path.Combine(Directory, "Keeper-Recovery.ps1");

    public bool IsInstalled => File.Exists(Script) && File.Exists(Path.Combine(Directory, "trust.json"));

    public void Install(string payload)
    {
        var source = Path.Combine(payload, "recovery", "Keeper-Recovery.ps1");
        var trustSource = Path.Combine(payload, "installation-trust.json");
        if (!File.Exists(source) || !File.Exists(trustSource))
        {
            log("Rescate omitido: el paquete no trae recovery\\Keeper-Recovery.ps1 o installation-trust.json.");
            return;
        }
        var keys = JsonNode.Parse(File.ReadAllText(trustSource))?["ReleasePublicKeys"] as JsonObject
            ?? throw new InvalidDataException("trust_sin_claves");
        System.IO.Directory.CreateDirectory(Directory);
        File.Copy(source, Script, overwrite: true);
        File.WriteAllText(Path.Combine(Directory, "trust.json"),
            new JsonObject { ["ReleasePublicKeys"] = JsonNode.Parse(keys.ToJsonString()) }.ToJsonString(new JsonSerializerOptions { WriteIndented = true }));
        var action = $"powershell.exe -NoProfile -NonInteractive -ExecutionPolicy Bypass -File \"{Script}\"";
        RunSchtasks(["/Create", "/F", "/TN", TaskName, "/SC", "HOURLY", "/RU", "SYSTEM", "/RL", "HIGHEST", "/TR", action]);
    }

    public void Remove()
    {
        try { RunSchtasks(["/Delete", "/F", "/TN", TaskName]); }
        catch (InvalidOperationException) { log("Tarea de rescate ausente; nada que borrar."); }
    }

    private static void RunSchtasks(string[] arguments)
    {
        var start = new ProcessStartInfo(Path.Combine(Environment.SystemDirectory, "schtasks.exe"))
        {
            UseShellExecute = false, CreateNoWindow = true, RedirectStandardOutput = true, RedirectStandardError = true
        };
        foreach (var argument in arguments) start.ArgumentList.Add(argument);
        using var process = Process.Start(start) ?? throw new InvalidOperationException("schtasks_no_arranco");
        var error = process.StandardError.ReadToEnd();
        process.StandardOutput.ReadToEnd();
        process.WaitForExit(60_000);
        if (process.ExitCode != 0) throw new InvalidOperationException($"schtasks {arguments[0]} fallo ({process.ExitCode}): {error.Trim()}");
    }
}
