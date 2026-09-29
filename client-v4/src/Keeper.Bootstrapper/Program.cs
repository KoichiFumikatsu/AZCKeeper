using Keeper.Bootstrapper;
using Keeper.Bootstrapper.Hardening;
using Keeper.Shared.Diagnostics;

if (args.Contains("--help", StringComparer.Ordinal))
{
    Console.WriteLine("Keeper.Bootstrapper.exe [--harden | --unharden | --system-install | --system-uninstall | --system-update | --uninstall] [--dry-run] [--config installation.json] [--payload agent] [--hardening-config hardening.json]");
    Console.WriteLine("--harden/--unharden requieren token ya elevado salvo --dry-run. Nunca reciben claves por argumentos.");
    Console.WriteLine("Modos SYSTEM: requieren token ya elevado, incluso en --dry-run; nunca solicitan UAC ni input. Sin modo SYSTEM: auto-elevacion UAC (fallback K3).");
    Console.WriteLine("--system-update: lo lanza el agente sin consola; su salida queda en ProgramData/AZCKeeper/v4/logs/bootstrapper-yyyyMMdd.log.");
    Console.WriteLine("Instalacion real: exclusivamente en el equipo desechable de Etapa 0. --dry-run nunca solicita UAC.");
    return 0;
}
if (!OperatingSystem.IsWindows() || !Environment.Is64BitProcess)
{
    Console.Error.WriteLine("Se requiere Windows x64.");
    return 1;
}
var paths = new WindowsSystemPaths();
// El auto-update lanza este proceso sin consola y detiene el servicio: sin archivo, su salida se perderia.
RollingFileLog? file = args.Contains("--system-update", StringComparer.Ordinal)
    ? new RollingFileLog(Path.Combine(paths.InstallDirectory, "v4", "logs"), "bootstrapper") : null;
void Log(string message) { Console.WriteLine(message); file?.Write("INFO", "Bootstrapper", message); }
void Fail(string message) { Console.Error.WriteLine(message); file?.Write("ERROR", "Bootstrapper", message); }
try
{
    Log($"Keeper.Bootstrapper {typeof(BootstrapApplication).Assembly.GetName().Version} pid {Environment.ProcessId}: {string.Join(' ', args.Where(a => a.StartsWith("--", StringComparison.Ordinal)))}");
    var options = BootstrapOptions.Parse(args, AppContext.BaseDirectory);
    var code = new BootstrapApplication(new WindowsElevation(), new WindowsServiceControl(), paths,
        new WindowsRegistryStore(), Log, new WindowsHardeningRunner(paths, Log),
        options.SystemUpdate ? new WindowsUpdateGuard(paths.InstallDirectory, TimeSpan.FromMinutes(5), Log) : null).Run(options, args);
    Log($"exit {code}");
    return code;
}
catch (Exception ex)
{
    Fail(args.Contains("--harden") || args.Contains("--unharden")
        ? $"Bootstrapper: {ex.GetType().Name}" : $"Bootstrapper: {ex.GetType().Name}: {ex.Message}");
    return ex is ArgumentException ? 2 : 1;
}
