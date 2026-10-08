using System.Runtime.Versioning;
using Microsoft.Win32;

namespace Keeper.Agent.Transport;

// Cedula que declara el instalador (release puente de K3: la CC del login de K3) en installation.json. El bootstrapper
// la deja en el entorno del servicio (KEEPER_CLAIMED_DOCUMENT); el agente la manda en su solicitud de alta y, una vez
// inscrito, la borra del registro: es un dato personal y no se vuelve a enviar. Nunca se escribe en el log.
public static class InstallerClaimedDocument
{
    public const string Variable = "KEEPER_CLAIMED_DOCUMENT";
    private static readonly string[] ServiceNames = ["KeeperAgent", "AZCKeeper v4"];

    // Solo digitos ASCII, 5 a 20 (mismo criterio que installation.json). Cualquier otra cosa se ignora.
    public static string? Parse(string? raw) =>
        raw is { Length: >= 5 and <= 20 } && raw.All(char.IsAsciiDigit) ? raw : null;

    public static string[] Without(IEnumerable<string> environment) =>
        environment.Where(value => !value.StartsWith(Variable + "=", StringComparison.OrdinalIgnoreCase)).ToArray();

    // Borra la variable del entorno del servicio (HKLM\...\Services\<servicio>\Environment) y del proceso. Devuelve
    // true si ya no queda en el registro. Si falla, el siguiente arranque lo reintenta (el agente ya inscrito no la manda).
    [SupportedOSPlatform("windows")]
    public static bool Scrub(Action<string> log)
    {
        Environment.SetEnvironmentVariable(Variable, null);
        var clean = true;
        foreach (var name in ServiceNames)
        {
            try
            {
                using var machine = RegistryKey.OpenBaseKey(RegistryHive.LocalMachine, RegistryView.Registry64);
                using var key = machine.OpenSubKey(@"SYSTEM\CurrentControlSet\Services\" + name, writable: true);
                if (key?.GetValue("Environment") is not string[] values) continue;
                var kept = Without(values);
                if (kept.Length == values.Length) continue;
                key.SetValue("Environment", kept, RegistryValueKind.MultiString);
                log($"alta: cedula declarada por el instalador borrada del entorno del servicio {name}");
            }
            catch (Exception ex) when (ex is UnauthorizedAccessException or System.Security.SecurityException or IOException)
            {
                clean = false;
                log($"alta warn: no se pudo borrar la cedula declarada del entorno del servicio {name} ({ex.GetType().Name}); se reintenta al reiniciar");
            }
        }
        return clean;
    }
}
