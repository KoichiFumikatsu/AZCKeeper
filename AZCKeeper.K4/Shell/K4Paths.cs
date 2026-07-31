namespace AZCKeeper.K4.Shell;

/// <summary>
/// Rutas per-user del cliente Keeper. Todo cuelga de %APPDATA%\AZCKeeper (una sola carpeta
/// "AZCKeeper", sin sufijo de versión). Keeper 4 NO coexiste con Keeper 3: el instalador
/// limpia la instalación anterior antes de poner la nueva, así no corren dos a la vez.
/// Nada aquí requiere admin.
///
/// Las rutas se calculan una vez; los componentes que escriben aceptan además una ruta
/// explícita para poder testear contra un directorio temporal.
/// </summary>
public static class K4Paths
{
    /// <summary>%APPDATA%\AZCKeeper — datos de roaming (config, auth, cola).</summary>
    public static string AppData { get; } =
        Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.ApplicationData), "AZCKeeper");

    /// <summary>%LOCALAPPDATA%\AZCKeeper — datos locales (descargas de update, logs).</summary>
    public static string LocalAppData { get; } =
        Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData), "AZCKeeper");

    public static string ConfigFile => Path.Combine(AppData, "Config", "client_config.json");
    public static string AuthDir    => Path.Combine(AppData, "Auth");
    public static string QueueDir   => Path.Combine(AppData, "Queue");
    public static string UpdatesDir => Path.Combine(LocalAppData, "Updates");
    /// <summary>Carpeta de logs del cliente (LocalLogger). Local, no roaming.</summary>
    public static string LogsDir    => Path.Combine(LocalAppData, "Logs");

    /// <summary>Dónde vive el cliente instalado (per-user, sin admin): %APPDATA%\AZCKeeper4\app.</summary>
    public static string InstallDir => Path.Combine(AppData, "app");
    /// <summary>Ejecutable instalado. AssemblyName = AZCKeeper4.</summary>
    public static string AppExe     => Path.Combine(InstallDir, "AZCKeeper4.exe");

    /// <summary>Reporte que el agente elevado (proceso SYSTEM) deja para el courier.</summary>
    public static string AgentReportFile { get; } = Path.Combine(
        Environment.GetFolderPath(Environment.SpecialFolder.CommonApplicationData), "AZCKeeper", "agent-report.json");
}
