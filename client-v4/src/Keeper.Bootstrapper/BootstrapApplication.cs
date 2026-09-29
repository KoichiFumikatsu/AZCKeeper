using System.Text.Json;
using Keeper.Bootstrapper.Hardening;

namespace Keeper.Bootstrapper;

public sealed class BootstrapApplication(IElevation elevation, IServiceControl services, ISystemPaths paths,
    IRegistryStore registry, Action<string> log, IHardeningRunner? hardening = null, IUpdateGuard? guard = null,
    IRescueInstaller? rescue = null)
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
            var mode = options.SystemUpdate ? "--system-update" : options.Uninstall ? "--system-uninstall" : "--system-install";
            log($"{mode} requiere ejecutarse ya elevado, p.ej. shell SYSTEM de DWService. No se solicitara UAC ni se realizaran cambios.");
            return ElevationRequired;
        }
        if (options.SystemUpdate) return RunUpdate(options);

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
            if (rescue is not null) Step("RESCATE: borrar tarea programada 'AZCKeeper Recovery'", rescue.Remove);
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
            Step($"ACL \"{bin}\" {WindowsConstants.BinDirectorySddl} (Usuarios: lectura+ejecucion para Keeper.Session)", () => paths.ApplyBinaryAcl(bin));
            if (rescue is not null) Step("RESCATE: instalar recovery\\Keeper-Recovery.ps1 + tarea horaria SYSTEM", () => rescue.Install(options.PayloadDirectory));
            var definition = new ServiceDefinition(ServiceName, Path.Combine(bin, "Keeper.Agent.exe"));
            Step(definition.Describe(exists), () => services.Configure(definition, exists));
            string[] environment = [$"KEEPER_DATA_DIR={data}", $"KEEPER_API_BASE={config!.ApiBase}", $"KEEPER_ENABLE_HKLM={(config.EnableHklm ? "1" : "0")}"];
            if (config.DeviceId != Guid.Empty) environment = [.. environment, $"KEEPER_DEVICE_ID={config.DeviceId}"];
            if (config.EnrollmentTicket is not null) environment = [.. environment, $"KEEPER_ENROLLMENT_TICKET={config.EnrollmentTicket}"];
            if (config.EnrollmentKey is not null) environment = [.. environment, $"KEEPER_ENROLLMENT_KEY={config.EnrollmentKey}"];
            var loggedEnvironment = environment.Select(value =>
                value.StartsWith("KEEPER_ENROLLMENT_TICKET=", StringComparison.Ordinal) ? "KEEPER_ENROLLMENT_TICKET=[REDACTED]"
                : value.StartsWith("KEEPER_ENROLLMENT_KEY=", StringComparison.Ordinal) ? "KEEPER_ENROLLMENT_KEY=[REDACTED]" : value);
            Step($"REG SET HKLM64\\{WindowsConstants.ServiceKey(ServiceName)} Environment REG_MULTI_SZ\n  {string.Join("\n  ", loggedEnvironment)}",
                () => registry.SetEnvironment(ServiceName, environment));
            // Una reinstalación/actualización no debe heredar el "due" de backoff de la instancia
            // anterior: si quedó lejano, el agente esperaría en vez de sincronizar. Se descarta.
            Step($"DEL \"{Path.Combine(data, "next-sync.json")}\" (descartar backoff heredado)",
                () => { var stale = Path.Combine(data, "next-sync.json"); if (File.Exists(stale)) File.Delete(stale); });
            Step($"SC FAILURE {ServiceName} (recovery: reinicio automatico ante caida)", () => services.ConfigureRecovery(ServiceName));
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

    // Actualización en sitio: reemplaza los binarios y reinicia, PRESERVANDO el entorno del servicio
    // (device_id, api_base, enable_hklm) y los datos (device-key, outbox). No escribe env ni config, así
    // que reusa lo ya instalado sin riesgo de duplicar o perder ajustes. La usa el auto-update del agente.
    private int RunUpdate(BootstrapOptions options)
    {
        void Step(string description, Action execute) { log(description); if (!options.DryRun) execute(); }
        if (!services.Exists(ServiceName))
            throw new ArgumentException("Servicio ausente; use --system-install para la instalacion inicial.");
        var files = paths.PayloadFiles(options.PayloadDirectory);
        if (!files.Contains("Keeper.Agent.exe", StringComparer.OrdinalIgnoreCase))
            throw new ArgumentException("El payload no contiene Keeper.Agent.exe.");
        // El agente extrae el paquete verificado en v4\staging (dentro de la instalacion, con su ACL), asi que
        // lo prohibido es solapar los BINARIOS que se van a sobrescribir, no toda la instalacion.
        var bin = Path.Combine(paths.InstallDirectory, "bin");
        var source = Path.GetFullPath(options.PayloadDirectory).TrimEnd(Path.DirectorySeparatorChar) + Path.DirectorySeparatorChar;
        var target = Path.GetFullPath(bin).TrimEnd(Path.DirectorySeparatorChar) + Path.DirectorySeparatorChar;
        if (source.StartsWith(target, StringComparison.OrdinalIgnoreCase) || target.StartsWith(source, StringComparison.OrdinalIgnoreCase))
            throw new ArgumentException("El payload no puede solapar el directorio de binarios.");
        paths.ValidateInstallTree(false);
        var data = Path.Combine(paths.InstallDirectory, "v4");
        if (options.DryRun) log("DRY-RUN: actualizacion en sitio; preserva entorno y datos. Operaciones:");
        var sequence = guard?.PayloadSequence(options.PayloadDirectory);
        Step($"STOP {ServiceName}; esperar Stopped (60 s)", () => services.Stop(ServiceName));
        if (guard is not null) Step($"BACKUP \"{bin}\" -> \"{bin}.previous\" (vuelta atras si la version nueva no sincroniza)", () => guard.Backup(bin));
        foreach (var relative in files.Order(StringComparer.OrdinalIgnoreCase))
        {
            var src = Path.Combine(options.PayloadDirectory, relative);
            var dst = Path.Combine(bin, relative);
            Step($"COPY \"{src}\" -> \"{dst}\" (sobrescribir)", () => paths.CopyFile(src, dst));
        }
        Step($"ACL \"{bin}\" {WindowsConstants.BinDirectorySddl} (Usuarios: lectura+ejecucion para Keeper.Session)", () => paths.ApplyBinaryAcl(bin));
        // El update nunca reemplaza un rescate existente: solo lo instala si falta.
        if (rescue is not null && !rescue.IsInstalled) Step("RESCATE: instalar (faltaba) recovery\\Keeper-Recovery.ps1 + tarea horaria SYSTEM", () => rescue.Install(options.PayloadDirectory));
        Step($"SC FAILURE {ServiceName} (recovery: reinicio automatico ante caida)", () => services.ConfigureRecovery(ServiceName));
        Step($"DEL \"{Path.Combine(data, "next-sync.json")}\" (descartar backoff heredado)",
            () => { var stale = Path.Combine(data, "next-sync.json"); if (File.Exists(stale)) File.Delete(stale); });
        var startedAt = DateTimeOffset.UtcNow;
        Step($"START {ServiceName}; esperar Running (60 s)", () => services.Start(ServiceName));
        if (guard is not null && !options.DryRun)
        {
            log("Esperando un sync exitoso de la version nueva...");
            var health = guard.WaitHealthy(startedAt);
            if (health == UpdateHealth.Unhealthy)
            {
                log("ROLLBACK: la version nueva no sincronizo y el servidor si responde. Se restaura la anterior.");
                services.Stop(ServiceName);
                guard.Restore(bin);
                paths.ApplyBinaryAcl(bin);
                services.Start(ServiceName);
                if (sequence is { } blocked) { guard.BlockRelease(blocked); log($"Release sequence {blocked} bloqueada: el agente no la reintentara."); }
                log("Vuelta atras completada. Servicio Running con la version anterior.");
                return 3;
            }
            log(health == UpdateHealth.Healthy ? "Version nueva sana: sync exitoso."
                : "Sin sync pero tampoco hay red hacia el servidor: se conserva la version nueva (no es fallo de la version).");
        }
        log(options.DryRun ? "DRY-RUN finalizado: 0 mutaciones."
            : "Actualizacion completada; entorno y datos preservados. Servicio Running.");
        return 0;
    }
}
