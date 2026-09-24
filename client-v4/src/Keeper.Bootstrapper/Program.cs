using Keeper.Bootstrapper;
using Keeper.Bootstrapper.Hardening;

if (args.Contains("--help", StringComparer.Ordinal))
{
    Console.WriteLine("Keeper.Bootstrapper.exe [--harden | --unharden | --system-install | --system-uninstall | --uninstall] [--dry-run] [--config installation.json] [--payload agent] [--hardening-config hardening.json]");
    Console.WriteLine("--harden/--unharden requieren token ya elevado salvo --dry-run. Nunca reciben claves por argumentos.");
    Console.WriteLine("Modos SYSTEM: requieren token ya elevado, incluso en --dry-run; nunca solicitan UAC ni input. Sin modo SYSTEM: auto-elevacion UAC (fallback K3).");
    Console.WriteLine("Instalacion real: exclusivamente en el equipo desechable de Etapa 0. --dry-run nunca solicita UAC.");
    return 0;
}
if (!OperatingSystem.IsWindows() || !Environment.Is64BitProcess)
{
    Console.Error.WriteLine("Se requiere Windows x64.");
    return 1;
}
try
{
    var options = BootstrapOptions.Parse(args, AppContext.BaseDirectory);
    var paths = new WindowsSystemPaths();
    return new BootstrapApplication(new WindowsElevation(), new WindowsServiceControl(), paths,
        new WindowsRegistryStore(), Console.WriteLine, new WindowsHardeningRunner(paths, Console.WriteLine)).Run(options, args);
}
catch (Exception ex)
{
    Console.Error.WriteLine(args.Contains("--harden") || args.Contains("--unharden")
        ? $"Bootstrapper: {ex.GetType().Name}" : $"Bootstrapper: {ex.Message}");
    return ex is ArgumentException ? 2 : 1;
}
