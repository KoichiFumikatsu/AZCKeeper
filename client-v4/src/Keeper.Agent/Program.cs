using Keeper.Agent.Hosting;
using Keeper.Agent.Modules.Enforcement;
using Keeper.Agent.Modules.Devices;
using Keeper.Agent.Modules.Security;
using Keeper.Agent.Modules.Update;
using Keeper.Agent.Modules.Diagnostics;
using Keeper.Agent.Modules.Maintenance;
using Keeper.Agent.Policy;
using Keeper.Agent.Storage;
using Keeper.Agent.Transport;
using Keeper.Shared.Contracts;
using Keeper.Shared.Diagnostics;
using Keeper.Shared.Protocol;
using Keeper.Shared.Time;
using System.Text.Json;
using Microsoft.Extensions.DependencyInjection;
using Microsoft.Extensions.Hosting;
using Microsoft.Extensions.Logging;

if (args.Contains("--print-enrollment", StringComparer.Ordinal))
{
    try { await EnrollmentIdentityCommand.PrintAsync(Console.Out, CancellationToken.None); }
    catch (Exception ex) when (ex is IOException or System.Security.Cryptography.CryptographicException or InvalidOperationException or UnauthorizedAccessException)
    {
        Console.Error.WriteLine($"Enrollment identity: {ex.Message} Run as SYSTEM with an existing device-key.dpapi; no key was created.");
        Environment.ExitCode = 1;
    }
    return;
}

var builder = Host.CreateApplicationBuilder(args);
builder.Services.AddWindowsService(options => options.ServiceName = "AZCKeeper v4");
// Log de archivo en {data}/logs/agent-yyyyMMdd.log (Information+). El EventLog sigue en Warning+:
// los sync_failed y fallos de modulo se emiten como Warning para que aparezcan en el Visor de eventos.
builder.Logging.AddProvider(new FileLoggerProvider(new RollingFileLog(Path.Combine(AgentWorker.DataDirectory(), "logs"), "agent")));
builder.Services.AddHostedService<AgentWorker>();
await builder.Build().RunAsync();

internal sealed class AgentWorker(ILogger<AgentWorker> logger) : BackgroundService
{
    protected override async Task ExecuteAsync(CancellationToken stoppingToken)
    {
        var dataDirectory = DataDirectory();
        Directory.CreateDirectory(dataDirectory);
        await using var lease = new FileStream(Path.Combine(dataDirectory, "agent.lock"), FileMode.OpenOrCreate, FileAccess.ReadWrite, FileShare.None);
        var tokenPath = Path.Combine(dataDirectory, "device-token.dpapi");
        if (AgentVersionMarker.Changed(Path.Combine(dataDirectory, "agent-version.txt"), AgentVersion) && File.Exists(tokenPath))
        {
            // Login nuevo para que el backend registre la version (solo la guarda en /client/login).
            File.Delete(tokenPath);
            Log($"version del agente cambio a {AgentVersion}: se descarta el token para forzar login");
        }
        using var key = await DeviceKeyStore.LoadOrCreateAsync(Path.Combine(dataDirectory, "device-key.dpapi"), stoppingToken);
        // Firmas con la hora del servidor (Date / server_time): un equipo con la hora de Windows mal igual se da de alta y
        // sincroniza; el modulo TimeSync corrige la hora de Windows. Ver ServerClock.
        var serverClock = new ServerClock(TimeProvider.System, Log);
        using var signer = new HttpMessageSigner(key) { Clock = serverClock };
        // outbox.json + outbox.quarantine.json (lo apartado tras un 422) + outbox.isolation.json (busqueda en curso).
        // Con la hora de Windows corrida mas de 5 min, los eventos se encolan con la hora del servidor (no van a cuarentena).
        using var outbox = new DurableOutbox(Path.Combine(dataDirectory, "outbox.json")) { TimeCorrection = () => serverClock.EventCorrection };
        var context = new ModuleContext(outbox, TimeProvider.System, Log, stoppingToken);
        var registry = new WindowsSystemPolicyStore(Environment.GetEnvironmentVariable("KEEPER_ENABLE_HKLM") == "1", Log);
        using var trust = InstalledTrust.Load(AppContext.BaseDirectory);
        // device_id: el del paquete (KEEPER_DEVICE_ID) o, en el alta por clave de la empresa, el que asigno el servidor.
        var deviceIdPath = Path.Combine(dataDirectory, "device-id.txt");
        var deviceId = Guid.TryParse(Environment.GetEnvironmentVariable("KEEPER_DEVICE_ID"), out var configuredId) ? configuredId : IntakeEnrollment.ReadDeviceId(deviceIdPath);
        // Base de API: {data}\api-base.json (mudanza firmada aplicada, ServerRelocation) antes que KEEPER_API_BASE.
        var installedApi = Environment.GetEnvironmentVariable("KEEPER_API_BASE");
        var (apiRoot, relocationSequence) = ServerRelocation.StartupBase(dataDirectory, installedApi);
        if (apiRoot is not null && relocationSequence > 0) Log($"api base desde {ApiBaseOverride.FileName}: {apiRoot} (instalada {installedApi}, mudanza {relocationSequence})");
        var enrollmentKey = Environment.GetEnvironmentVariable("KEEPER_ENROLLMENT_KEY");
        var claimedDocument = Environment.GetEnvironmentVariable(InstallerClaimedDocument.Variable);
        if (deviceId == Guid.Empty && apiRoot is not null && !string.IsNullOrWhiteSpace(enrollmentKey))
        {
            // Hasta que IT apruebe (o cargue el equipo como esperado) el agente solo pide alta: sin device_id no hay
            // politica que aplicar ni a quien reportar.
            using var intakeHandler = new ClockSkewHandler(signer) { InnerHandler = new HttpClientHandler { AllowAutoRedirect = false, UseCookies = false } };
            using var intakeHttp = new HttpClient(intakeHandler) { Timeout = TimeSpan.FromSeconds(45), BaseAddress = apiRoot };
            Log("sin device_id: alta por solicitud con la clave de la empresa");
            deviceId = await new IntakeEnrollment(intakeHttp, signer, enrollmentKey, TimeProvider.System, Log,
                    () => OperatingSystem.IsWindows() ? Smbios.ReadSystemSerial() : null)
                {
                    ClaimedDocument = claimedDocument,
                    AskDocument = OperatingSystem.IsWindows()
                        ? new WindowsDocumentPrompt(new WindowsSessionLauncher(trust.BinaryHashes), Path.Combine(AppContext.BaseDirectory, "Keeper.Session.exe"), Log).AskAsync
                        : null
                }
                .RunAsync(new DeviceTokenStore(tokenPath, apiRoot.AbsoluteUri + signer.KeyId), deviceIdPath, stoppingToken);
        }
        // Inscrito (ahora o en un arranque anterior): la cedula declarada por el instalador ya no hace falta y no se guarda.
        if (deviceId != Guid.Empty && claimedDocument is not null && OperatingSystem.IsWindows()) InstallerClaimedDocument.Scrub(Log);
        var pinPath = Path.Combine(dataDirectory, "pin-verifier.dpapi");
        var deviceLock = new DeviceLock(Path.Combine(dataDirectory, "device-lock.json"), PinVerifier.LoadProtected(pinPath), pinPath);
        var commands = new CommandExecutor(Path.Combine(dataDirectory, "commands.json"), deviceId, deviceLock,
            new WindowsDeviceActions(!registry.IsDryRun),
            OperatingSystem.IsWindows() ? new WindowsComputerNamer(!registry.IsDryRun) : null,
            OperatingSystem.IsWindows() && !registry.IsDryRun ? new WindowsHardeningLauncher(AppContext.BaseDirectory, dataDirectory, trust.BinaryHashes) : null,
            OperatingSystem.IsWindows() && !registry.IsDryRun ? new WindowsSessionLogoff(new WindowsSessionLauncher(trust.BinaryHashes), Log) : null)
            { ServerOffset = () => serverClock.Confirmed };
        using var updateHttp = new HttpClient { Timeout = TimeSpan.FromMinutes(10) };
        var updater = new UpdateManager(Path.Combine(dataDirectory, "staging"), trust.ReleaseKeys, trust.InstalledSequence, trust.Channel,
            registry.IsDryRun ? null : new WindowsReleaseDownloader(updateHttp),
            !registry.IsDryRun && OperatingSystem.IsWindows() ? new WindowsReleaseInstaller() : null, AgentVersion,
            Path.Combine(dataDirectory, "update-blocked.json"));
        // Smart App Control: se lee siempre (inventario); solo se escribe con HKLM habilitado (no en dry-run).
        var sacRegistry = OperatingSystem.IsWindows() ? new WindowsSmartAppControlRegistry() : null;
        var smartAppControl = new SmartAppControl(registry.IsDryRun ? null : sacRegistry);
        // K3 residual: solo lectura (tambien en dry-run); va antes de Inventory para que el primer inventario ya lo lleve.
        var inventory = new Inventory(outbox.EnqueueInventoryAsync, sacRegistry);
        var legacyK3 = new LegacyK3(OperatingSystem.IsWindows() ? new WindowsLegacyK3Probe() : null, inventory.UpdateLegacyK3Async);
        PolicyCoordinator? policy = null;
        ModuleHost? hostReference = null;
        var restorePointHours = int.TryParse(Environment.GetEnvironmentVariable("KEEPER_RESTORE_POINT_HOURS"), out var hours)
            ? Math.Clamp(hours, 6, 720) : 24;
        var moduleList = new List<IModule> { new WebEnforcer(registry), new UsbEnforcer(registry),
            new InstallEnforcer(registry, loadOptions: () => AppLockerOptions.FromEnvironment(Environment.GetEnvironmentVariable)),
            new DownloadEnforcer(registry), deviceLock, commands, legacyK3, inventory, updater, smartAppControl,
            HardeningStatusModule.FromFile(Path.Combine(dataDirectory, "hardening", "state.json")),
            new RestorePointModule(new WindowsRestorePointService(!registry.IsDryRun), Path.Combine(dataDirectory, "restore-point.json"),
                TimeSpan.FromHours(restorePointHours), TimeSpan.FromHours(6)),
            new TamperGuard(trust.BinaryHashes), new AgentDiagnostics(() => hostReference?.Snapshot() ?? []) };
        // Hora de Windows automatica y resincronizada (en dry-run no toca nada). No resincroniza durante una cuenta
        // regresiva de reinicio/apagado; cada salto de hora que ve corrige tambien el desfase de las firmas. Si W32Time
        // no la corrige, la pone con la hora del servidor (fuera de dominio, con dos mediciones consistentes).
        if (OperatingSystem.IsWindows()) moduleList.Add(new TimeSync(new WindowsTimeSystem(), !registry.IsDryRun,
            serverClock, () => commands.PendingNotice is { Cancelled: false }));
        // Bloqueo del cambio manual de la hora (4.0.28, time_change_lock de la politica; apagado por defecto). En dry-run
        // no toca la LSA; nunca en dominio.
        if (OperatingSystem.IsWindows()) moduleList.Add(new TimeChangeLockModule(registry.IsDryRun ? null : new WindowsAccountRights(),
            WindowsTimeSystem.DomainJoined, Path.Combine(dataDirectory, AgentDataFiles.TimeChangeLock)));
        Keeper.Agent.Modules.Location.LocationModule? locationModule = null;
        if (OperatingSystem.IsWindows())
        {
            locationModule = new Keeper.Agent.Modules.Location.LocationModule(registry.IsDryRun ? null :
                new Keeper.Shared.Location.LocationSettingsStore(new Keeper.Shared.Location.WindowsLocationSettings(), Path.Combine(dataDirectory, "location-settings.json")), WindowsTimeSystem.DomainJoined);
            moduleList.Add(locationModule);
        }
        if (OperatingSystem.IsWindows()) moduleList.Add(new SessionSupervisor(new WindowsSessionLauncher(trust.BinaryHashes),
            Path.Combine(AppContext.BaseDirectory, "Keeper.Session.exe"), deviceLock, () => policy, () => commands.PendingNotice) { Location = locationModule });
        await using var modules = new ModuleHost(moduleList, context);
        hostReference = modules;
        await modules.InitAsync();
        policy = new PolicyCoordinator(new SignedFilePolicyStore(Path.Combine(dataDirectory, Keeper.Shared.Contracts.AgentDataFiles.PolicyCache), key, Log), modules, deviceId);
        await policy.RestoreAsync(stoppingToken);
        var localPolicy = Environment.GetEnvironmentVariable("KEEPER_POLICY_FILE");
        if (localPolicy is not null)
        {
            var document = JsonSerializer.Deserialize<CachedPolicy>(await File.ReadAllBytesAsync(localPolicy, stoppingToken), ProtocolJson.Options)
                ?? throw new InvalidDataException("invalid_local_policy");
            await policy.ApplyAsync(document.PolicyVersion, document.Policy, stoppingToken);
        }
        ServerRelocation? relocation = null;
        if (apiRoot is { } root)
        {
            if (deviceId == Guid.Empty) throw new InvalidOperationException("KEEPER_DEVICE_ID or KEEPER_ENROLLMENT_KEY is required");
            var ticket = Environment.GetEnvironmentVariable("KEEPER_ENROLLMENT_TICKET");
            var hardeningPassword = OperatingSystem.IsWindows()
                ? new HardeningPasswordModule(dataDirectory, new WindowsHardeningPasswordFileSystem(), new DpapiHardeningPasswordProtector())
                : null;
            // Una conexion por servidor: HttpClient con su BaseAddress (no se puede cambiar tras la primera peticion) y
            // token guardado con clave base+keyId. El firmante es el mismo: firma la URI absoluta de cada peticion.
            IRelocatableSync Connect(Uri api)
            {
                var http = new HttpClient(new ClockSkewHandler(signer) { InnerHandler = new HttpClientHandler { AllowAutoRedirect = false, UseCookies = false } }) { Timeout = TimeSpan.FromSeconds(45), BaseAddress = api };
                return new SyncConnection(http, new SyncClient(http, signer, deviceId, outbox, policy, TimeProvider.System,
                    api == root ? ticket : null, new DeviceTokenStore(tokenPath, api.AbsoluteUri + signer.KeyId))
                {
                    HardeningPassword = hardeningPassword,
                    Log = Log,
                    OnResponse = async (response, tenant, ct) =>
                    {
                        if (response.UnlockPin is not null) await deviceLock.TryApplyVerifierAsync(response.UnlockPin, tenant, ct);
                        await commands.AcceptAsync(response.Commands, tenant, ct);
                        updater.Offer(response.Release);
                        await smartAppControl.TryApplyAsync(response.WindowsSecurity, ct);
                        await relocation!.OfferAsync(response.Relocation, tenant, ct);
                    }
                });
            }
            relocation = new ServerRelocation(root, relocationSequence, installedApi!, Connect, trust.ReleaseKeys, deviceId,
                () => policy?.Current?.TenantId, dataDirectory, outbox, TimeProvider.System, Log);
        }
        using var transportOwner = relocation;
        ISyncCycle? transport = relocation;
        var interval = int.TryParse(Environment.GetEnvironmentVariable("KEEPER_SYNC_SECONDS"), out var value) ? value : 120;
        using var scheduler = new Scheduler(modules, transport, new SyncSchedule(deviceId, interval), TimeProvider.System,
            Path.Combine(dataDirectory, "next-sync.json"), Log, new AgentHealthFile(Path.Combine(dataDirectory, "health.json"), AgentVersion.ToString(3)));
        Log($"Agent {AgentVersion} started: trust {(trust.ReleaseKeys.Count == 0 ? "sin claves de release (auto-update deshabilitado)" : $"{trust.ReleaseKeys.Count} clave(s), sequence {trust.InstalledSequence}, canal {trust.Channel}")}; {(registry.IsDryRun ? "dry-run" : "HKLM enabled")}; {(transport is null ? "offline" : "sync configured")}");
        await scheduler.RunAsync(stoppingToken);
    }

    internal static string DataDirectory() => Environment.GetEnvironmentVariable("KEEPER_DATA_DIR") ??
        Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.CommonApplicationData), "AZCKeeper", "v4");

    private static Version AgentVersion => AgentIdentity.Version;

    private void Log(string message)
    {
        if (AgentLogLevel.IsWarning(message)) logger.LogWarning("{AgentEvent}", message);
        else logger.LogInformation("{AgentEvent}", message);
    }
}
