namespace AZCKeeper.K4.Shell;

/// <summary>
/// Rutas per-user del cliente Keeper 4. Todo cuelga de %APPDATA%\AZCKeeper4 (NO
/// "AZCKeeper" a secas) para NO chocar con Keeper 3, que sigue en producción (3.0.3.2)
/// durante la migración. Nada aquí requiere admin.
///
/// Las rutas se calculan una vez; los componentes que escriben aceptan además una ruta
/// explícita para poder testear contra un directorio temporal.
/// </summary>
public static class K4Paths
{
    /// <summary>%APPDATA%\AZCKeeper4 — datos de roaming (config, auth, cola).</summary>
    public static string AppData { get; } =
        Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.ApplicationData), "AZCKeeper4");

    /// <summary>%LOCALAPPDATA%\AZCKeeper4 — datos locales (descargas de update, logs).</summary>
    public static string LocalAppData { get; } =
        Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData), "AZCKeeper4");

    public static string ConfigFile => Path.Combine(AppData, "Config", "client_config.json");
    public static string AuthDir    => Path.Combine(AppData, "Auth");
    public static string QueueDir   => Path.Combine(AppData, "Queue");
    public static string UpdatesDir => Path.Combine(LocalAppData, "Updates");

    /// <summary>Reporte que el agente elevado (proceso SYSTEM) deja para el courier.</summary>
    public static string AgentReportFile { get; } = Path.Combine(
        Environment.GetFolderPath(Environment.SpecialFolder.CommonApplicationData), "AZCKeeper", "agent-report.json");
}
