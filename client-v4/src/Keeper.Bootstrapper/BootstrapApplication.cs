using System.Text.Json;
using Keeper.Bootstrapper.Hardening;

namespace Keeper.Bootstrapper;

public sealed class BootstrapApplication(IElevation elevation, IServiceControl services, ISystemPaths paths,
    IRegistryStore registry, Action<string> log, IHardeningRunner? hardening = null)
{
    public const string ServiceName = "KeeperAgent";
    public const int ElevationCancelled = 1223;
    public const int ElevationRequired = 740;
    public static IReadOnlyList<string> BrowserKeys { get; } =
        [@"SOFTWARE\Policies\Google\Chrome", @"SOFTWARE\Policies\Microsoft\Edge", @"SOFTWARE\Policies\BraveSoftware\Brave"];
    public const string UsbKey = @"SOFTWARE\Policies\Microsoft\Windows\RemovableStorageDevices";
    public const string InstallerKey = @"SOFTWARE\Policies\Microsoft\Windows\Installer";

    public int Run(BootstrapOptions options, string[] originalArguments)
    {
        if (options.Harden || options.Unharden)
        {
            var settings = options.HardeningConfigPath is { } file
                ? JsonSerializer.Deserialize<HardeningConfig>(paths.ReadText(file), InstallationConfig.Json)
                    ?? throw new ArgumentException("Configuracion vacia.")
                : new HardeningConfig();
            settings.Validate();
            if (!options.DryRun && !elevation.IsElevated)
            {
                log("Endurecimiento requiere token ya elevado; no solicita UAC ni realiza cambios.");
                return ElevationRequired;
            }
            return (hardening ?? throw new InvalidOperationException("hardening_runner_missing"))
                .Run(settings, options.DryRun, true, options.Unharden);
        }
        if (options.SystemMode && !elevation.IsElevated)
        {
            var mode = options.Uninstall ? "--system-uninstall" : "--system-install";
            log($"{mode} requiere ejecutarse ya elevado, p.ej. shell SYSTEM de DWService. No se solicitara UAC ni se realizaran cambios.");
            return ElevationRequired;
        }

        InstallationConfig? config = null;
        IReadOnlyList<string> files = [];
        if (!options.Uninstall)
        {
            config = JsonSerializer.Deserialize<InstallationConfig>(paths.ReadText(options.ConfigPath), InstallationConfig.Json)
                ?? throw new ArgumentException("Configuracion vacia.");
            config.Validate(options.DryRun);
            files = paths.PayloadFiles(options.PayloadDirectory);
            if (!files.Contains("Keeper.Agent.exe", StringComparer.OrdinalIgnoreCase))
                throw new ArgumentException("El payload no contiene Keeper.Agent.exe.");
            var source = Path.GetFullPath(options.PayloadDirectory).TrimEnd(Path.DirectorySeparatorChar) + Path.DirectorySeparatorChar;
            var target = Path.GetFullPath(paths.InstallDirectory).TrimEnd(Path.DirectorySeparatorChar) + Path.DirectorySeparatorChar;
            if (source.StartsWith(target, StringComparison.OrdinalIgnoreCase) || target.StartsWith(source, StringComparison.OrdinalIgnoreCase))
                throw new ArgumentException("El payload debe estar fuera del directorio de instalacion.");
        }

        // The UAC fallback skips its elevation check during dry-run.
        if (!options.SystemMode && !options.DryRun && !elevation.IsElevated)
        {
            if (options.ElevatedChild)
            {
                log("No se obtuvo un token elevado. No se ejecuto la instalacion/desinstalacion.");
                return ElevationRequired;
            }
            log("Solicitando consentimiento UAC (runas).");
            var result = elevation.Relaunch([.. originalArguments, "--elevated-child"]);
            if (result == ElevationCancelled) log("UAC cancelado por el usuario (1223). No se realizaron cambios.");
            return result;
        }

        paths.ValidateInstallTree(options.Uninstall);
        if (options.DryRun) log("DRY-RUN: sin elevacion ni cambios. Operaciones para el estado observado:");
        var exists = services.Exists(ServiceName);
        if (exists) Step($"STOP {ServiceName}; esperar Stopped (60 s)", () => services.Stop(ServiceName));
        if (options.Uninstall)
        {
            if (exists) Step($"sc.exe delete {ServiceName}; esperar eliminacion (60 s)", () => services.Delete(ServiceName));
            else log($"Servicio {ServiceName}: ausente; omitir stop/delete.");
            foreach (var browser in BrowserKeys)
            {
                DeleteTree(browser + @"\URLBlocklist");
                DeleteTree(browser + @"\URLAllowlist");
                DeleteValue(browser, "DownloadRestrictions");
            }
            DeleteTree(UsbKey);
            DeleteValue(InstallerKey, "DisableMSI");
            DeleteValue(InstallerKey, "AlwaysInstallElevated");
            Step($"DELETE DIRECTORY \"{paths.InstallDirectory}\" (recursivo, si existe)", paths.DeleteInstallDirectory);
        }
        else
        {
            var bin = Path.Combine(paths.InstallDirectory, "bin");
            var data = Path.Combine(paths.InstallDirectory, "v4");
            Step($"MKDIR + ACL \"{paths.InstallDirectory}\" {WindowsConstants.DirectorySddl} (aplicar tambien a contenido existente)",
                () => paths.CreateProtectedDirectory(paths.InstallDirectory));
            foreach (var directory in new[] { bin, data })
                Step($"MKDIR \"{directory}\" (heredar ACL del directorio protegido)", () => paths.CreateDirectory(directory));
            foreach (var relative in files.Order(StringComparer.OrdinalIgnoreCase))
            {
                var source = Path.Combine(options.PayloadDirectory, relative);
                var destination = Path.Combine(bin, relative);
                Step($"COPY \"{source}\" -> \"{destination}\" (sobrescribir)", () => paths.CopyFile(source, destination));
            }
            var definition = new ServiceDefinition(ServiceName, Path.Combine(bin, "Keeper.Agent.exe"));
            Step(definition.Describe(exists), () => services.Configure(definition, exists));
            string[] environment = [$"KEEPER_DATA_DIR={data}", $"KEEPER_API_BASE={config!.ApiBase}",
                $"KEEPER_DEVICE_ID={config.DeviceId}", $"KEEPER_ENABLE_HKLM={(config.EnableHklm ? "1" : "0")}"];
            if (config.EnrollmentTicket is not null) environment = [.. environment, $"KEEPER_ENROLLMENT_TICKET={config.EnrollmentTicket}"];
            var loggedEnvironment = environment.Select(value => value.StartsWith("KEEPER_ENROLLMENT_TICKET=", StringComparison.Ordinal)
                ? "KEEPER_ENROLLMENT_TICKET=[REDACTED]" : value);
            Step($"REG SET HKLM64\\{WindowsConstants.ServiceKey(ServiceName)} Environment REG_MULTI_SZ\n  {string.Join("\n  ", loggedEnvironment)}",
                () => registry.SetEnvironment(ServiceName, environment));
            // Una reinstalación/actualización no debe heredar el "due" de backoff de la instancia
            // anterior: si quedó lejano, el agente esperaría en vez de sincronizar. Se descarta.
            Step($"DEL \"{Path.Combine(data, "next-sync.json")}\" (descartar backoff heredado)",
                () => { var stale = Path.Combine(data, "next-sync.json"); if (File.Exists(stale)) File.Delete(stale); });
            Step($"START {ServiceName}; esperar Running (60 s)", () => services.Start(ServiceName));
            if (hardening is not null)
            {
                var result = hardening.Run(config.Hardening, options.DryRun, false);
                if (result != 0) return result;
            }
        }
        log(options.DryRun ? "DRY-RUN finalizado: 0 mutaciones." : options.Uninstall ? "Desinstalacion completada." : "Instalacion completada; servicio Running. Verificar enrollment/sync en el backend.");
        return 0;

        void Step(string description, Action execute)
        {
            log(description);
            if (!options.DryRun) execute();
        }
        void DeleteTree(string key) => Step($"REG DELETE TREE HKLM64\\{key} (si existe)", () => registry.DeleteTree(key));
        void DeleteValue(string key, string name) => Step($"REG DELETE VALUE HKLM64\\{key} {name} (si existe)", () => registry.DeleteValue(key, name));
    }
}
