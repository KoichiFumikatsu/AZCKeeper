using System.Diagnostics;
using Keeper.Shared.Contracts;
using Keeper.Shared.Protocol;

namespace Keeper.Agent.Modules.Devices;

// Keeper 3 (cliente K3) que sigue en el equipo tras pasar a v4. Cada lectura devuelve null si no se pudo hacer
// (desconocido); nunca lanza hacia el modulo. Detras de una interfaz para probar sin Windows real.
public interface ILegacyK3Probe
{
    bool? Running();     // algun proceso AZCKeeper_Client, en cualquier sesion
    bool? Autostart();   // valor AZCKeeper_Cliente en HKU\<SID>\...\Run de algun perfil cargado
    bool? Installed();   // AZCKeeper_Client.exe en AppData\Local|Roaming\AZCKeeper\app de algun perfil de C:\Users
}

public static class LegacyK3Layout
{
    public const string ProcessName = "AZCKeeper_Client";
    public const string RunKey = @"Software\Microsoft\Windows\CurrentVersion\Run";
    public const string RunValue = "AZCKeeper_Cliente";
    public const string ExeName = "AZCKeeper_Client.exe";

    // HKEY_USERS: solo perfiles reales (S-1-5-21-..., S-1-12-1-... de Azure AD); fuera .DEFAULT y las colmenas *_Classes.
    public static bool IsUserHive(string name) =>
        !string.Equals(name, ".DEFAULT", StringComparison.OrdinalIgnoreCase) &&
        !name.EndsWith("_Classes", StringComparison.OrdinalIgnoreCase) && name.StartsWith("S-", StringComparison.OrdinalIgnoreCase);

    // K3 se instala por usuario: StartupManager prefiere Roaming; el diagnostico real lo encontro en Local.
    public static IEnumerable<string> ExecutableCandidates(string profile) =>
    [
        Path.Combine(profile, "AppData", "Local", "AZCKeeper", "app", ExeName),
        Path.Combine(profile, "AppData", "Roaming", "AZCKeeper", "app", ExeName)
    ];

    // true si alguno lo tiene; null si ninguno lo tiene pero alguno no se pudo leer; false si todos se leyeron.
    public static bool? Any(IEnumerable<Func<bool>> checks)
    {
        var unknown = false;
        foreach (var check in checks)
        {
            try { if (check()) return true; }
            catch (Exception ex) when (ex is IOException or UnauthorizedAccessException or System.Security.SecurityException or InvalidOperationException) { unknown = true; }
        }
        return unknown ? null : false;
    }
}

[System.Runtime.Versioning.SupportedOSPlatform("windows")]
public sealed class WindowsLegacyK3Probe(string? usersRoot = null) : ILegacyK3Probe
{
    private readonly string _usersRoot = usersRoot ?? Path.Combine(Path.GetPathRoot(Environment.SystemDirectory) ?? @"C:\", "Users");

    public bool? Running()
    {
        try
        {
            // Como SYSTEM ve los procesos de todas las sesiones; no abre handles con permisos, solo enumera.
            var processes = Process.GetProcessesByName(LegacyK3Layout.ProcessName);
            foreach (var process in processes) process.Dispose();
            return processes.Length > 0;
        }
        catch (Exception ex) when (ex is InvalidOperationException or System.ComponentModel.Win32Exception or UnauthorizedAccessException) { return null; }
    }

    public bool? Autostart()
    {
        string[] hives;
        try { hives = Microsoft.Win32.Registry.Users.GetSubKeyNames(); }
        catch (Exception ex) when (ex is System.Security.SecurityException or UnauthorizedAccessException or IOException) { return null; }
        return LegacyK3Layout.Any(hives.Where(LegacyK3Layout.IsUserHive).Select<string, Func<bool>>(sid => () =>
        {
            using var run = Microsoft.Win32.Registry.Users.OpenSubKey($@"{sid}\{LegacyK3Layout.RunKey}");
            return run?.GetValue(LegacyK3Layout.RunValue) is not null;
        }));
    }

    public bool? Installed()
    {
        IEnumerable<string> profiles;
        try
        {
            // Sin seguir reparse points: "All Users", "Default User" y similares son uniones a otras carpetas.
            profiles = new DirectoryInfo(_usersRoot).EnumerateDirectories("*", new EnumerationOptions
            {
                RecurseSubdirectories = false, IgnoreInaccessible = true, AttributesToSkip = FileAttributes.ReparsePoint | FileAttributes.System
            }).Select(d => d.FullName).ToArray();
        }
        catch (Exception ex) when (ex is IOException or UnauthorizedAccessException or System.Security.SecurityException) { return null; }
        return LegacyK3Layout.Any(profiles.SelectMany(LegacyK3Layout.ExecutableCandidates).Select<string, Func<bool>>(path => () => File.Exists(path)));
    }
}

// Detector de K3 residual (equivale al tasklist manual del piloto): al arrancar y cada 15 min. Solo detecta y reporta;
// nunca cierra ni desinstala K3. Si el estado cambia pide republicar el inventario (onChanged -> Inventory.UpdateLegacyK3Async,
// que reencola el inventario completo en el outbox; el outbox se queda solo con el ultimo sin enviar).
// Logs: warn legacy_k3_running al pasar a corriendo, info legacy_k3_autostart al aparecer el autoarranque e info
// legacy_k3_gone cuando deja de correr y no queda autoarranque (cada uno una vez por cambio).
public sealed class LegacyK3(ILegacyK3Probe? probe, Func<LegacyK3Status, CancellationToken, Task>? onChanged = null, TimeSpan? interval = null) : ModuleBase
{
    public static readonly TimeSpan DefaultInterval = TimeSpan.FromMinutes(15);
    public override string Name => "LegacyK3";
    public LegacyK3Status? Current { get; private set; }
    private readonly TimeSpan _interval = interval ?? DefaultInterval;
    private DateTimeOffset _due;

    public override async Task InitAsync(ModuleContext ctx)
    {
        await base.InitAsync(ctx);
        await CheckAsync(ctx.StoppingToken);
    }

    public override async Task TickAsync(CancellationToken ct)
    {
        ct.ThrowIfCancellationRequested();
        if (Context.Clock.GetUtcNow() < _due) return;
        await CheckAsync(ct);
    }

    private async Task CheckAsync(CancellationToken ct)
    {
        _due = Context.Clock.GetUtcNow() + _interval;
        var status = new LegacyK3Status { Running = Read(p => p.Running()), Autostart = Read(p => p.Autostart()), Installed = Read(p => p.Installed()) };
        var previous = Current;
        if (status == previous) return;
        Current = status;
        if (status.Running == true && previous?.Running != true) await ReportAsync("legacy_k3_running", ct, LogEntryLevel.Warn);
        if (status.Autostart == true && previous?.Autostart != true) await ReportAsync("legacy_k3_autostart", ct);
        if (status is { Running: false, Autostart: false } && previous is not null && (previous.Running == true || previous.Autostart == true))
            await ReportAsync("legacy_k3_gone", ct);
        if (onChanged is not null) await onChanged(status, ct);
    }

    // Un lector que falla de forma inesperada deja ese dato como desconocido; el agente sigue.
    private bool? Read(Func<ILegacyK3Probe, bool?> read)
    {
        if (probe is null) return null;
        try { return read(probe); }
        catch (Exception ex) when (ex is not OperationCanceledException) { Context.Log($"legacy_k3_probe_failed: {ex.GetType().Name}"); return null; }
    }
}
