using System.Diagnostics;
using System.Runtime.Versioning;
using Microsoft.Win32;

namespace AZCKeeper.K4.Shell;

/// <summary>
/// Limpia instalaciones previas de Keeper (la 3 u otra 4) ANTES de instalar la nueva, para
/// que no corran dos a la vez y se pisen. Replica lo que hacía azc-killer.ps1 en K3:
/// mata procesos AZCKeeper*, quita entradas de arranque (Run + accesos directos), y borra
/// binarios viejos conocidos.
///
/// NO borra la data (%APPDATA%\AZCKeeper\{Config,Auth,Queue}); solo saca lo que hace que
/// algo VIEJO siga corriendo o arrancando. Todo per-user (HKCU), sin admin.
///
/// Las partes de registro y de archivos son testeables (rutas inyectables); matar procesos
/// y borrar .lnk es best-effort dependiente del SO.
/// </summary>
[SupportedOSPlatform("windows")]
public sealed class LegacyCleaner
{
    private const string RunSubkey = @"SOFTWARE\Microsoft\Windows\CurrentVersion\Run";
    private static readonly string[] OldExeNames = { "AZCKeeper_Client.exe", "AZCKeeper_Cliente.exe" };

    private readonly Action<string>? _log;
    public LegacyCleaner(Action<string>? log = null) => _log = log;

    /// <summary>Todo el barrido. excludePid = el proceso actual (para no matarse a sí mismo).</summary>
    public void CleanExisting(int excludePid)
    {
        var killed = KillKeeperProcesses(excludePid);
        int runs;
        using (var key = Registry.CurrentUser.OpenSubKey(RunSubkey, writable: true))
            runs = key is null ? 0 : RemoveRunEntries(key);
        var lnks = RemoveStartupShortcuts();
        var bins = RemoveOldBinaries();
        _log?.Invoke($"limpieza previa: {killed} procesos, {runs} arranques, {lnks} accesos, {bins} binarios");
    }

    /// <summary>Mata los procesos AZCKeeper* salvo el actual. Devuelve cuántos cerró.</summary>
    public int KillKeeperProcesses(int excludePid)
    {
        int killed = 0;
        foreach (var p in Process.GetProcesses())
        {
            try
            {
                if (p.Id == excludePid) continue;
                if (!p.ProcessName.StartsWith("AZCKeeper", StringComparison.OrdinalIgnoreCase)) continue;
                p.Kill(entireProcessTree: true);
                p.WaitForExit(3000);
                killed++;
            }
            catch { /* sin permiso (otro usuario) u otra carrera: best-effort */ }
        }
        return killed;
    }

    /// <summary>
    /// Quita del Run key todo valor cuyo nombre O data mencione AZCKeeper. Testeable: se le
    /// pasa la clave abierta. Devuelve cuántos borró. (StartupManager re-agrega el de K4.)
    /// </summary>
    public int RemoveRunEntries(RegistryKey runKey)
    {
        int removed = 0;
        foreach (var name in runKey.GetValueNames())
        {
            var val = runKey.GetValue(name) as string ?? "";
            if (name.Contains("AZCKeeper", StringComparison.OrdinalIgnoreCase)
                || val.Contains("AZCKeeper", StringComparison.OrdinalIgnoreCase))
            {
                try { runKey.DeleteValue(name, throwOnMissingValue: false); removed++; } catch { }
            }
        }
        return removed;
    }

    /// <summary>
    /// Borra binarios viejos conocidos (el exe de K3) de los dirs de app. Testeable con dirs
    /// inyectados. NO toca AZCKeeper4.exe (lo sobrescribe la instalación). Devuelve cuántos borró.
    /// </summary>
    public int RemoveOldBinaries(IEnumerable<string>? appDirs = null)
    {
        int removed = 0;
        foreach (var dir in appDirs ?? DefaultAppDirs())
        {
            foreach (var exe in OldExeNames)
            {
                var p = Path.Combine(dir, exe);
                try { if (File.Exists(p)) { File.Delete(p); removed++; } } catch { }
            }
        }
        return removed;
    }

    /// <summary>Borra accesos directos .lnk de arranque cuyo nombre mencione AZCKeeper. Best-effort.</summary>
    private int RemoveStartupShortcuts()
    {
        int removed = 0;
        foreach (var folder in new[]
        {
            Environment.GetFolderPath(Environment.SpecialFolder.Startup),
            Environment.GetFolderPath(Environment.SpecialFolder.CommonStartup),
        })
        {
            try
            {
                if (!Directory.Exists(folder)) continue;
                foreach (var lnk in Directory.GetFiles(folder, "*AZCKeeper*.lnk"))
                {
                    try { File.Delete(lnk); removed++; } catch { }
                }
            }
            catch { }
        }
        return removed;
    }

    /// <summary>Dirs donde K3/K4 pudieron instalar el exe (per-user, Roaming y Local).</summary>
    private static IEnumerable<string> DefaultAppDirs()
    {
        yield return Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.ApplicationData), "AZCKeeper", "app");
        yield return Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData), "AZCKeeper", "app");
    }
}
