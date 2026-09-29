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
        using var signer = new HttpMessageSigner(key);
        using var outbox = new DurableOutbox(Path.Combine(dataDirectory, "outbox.json"));
        var context = new ModuleContext(outbox, TimeProvider.System, Log, stoppingToken);
        var registry = new WindowsSystemPolicyStore(Environment.GetEnvironmentVariable("KEEPER_ENABLE_HKLM") == "1", Log);
        var deviceId = Guid.TryParse(Environment.GetEnvironmentVariable("KEEPER_DEVICE_ID"), out var configuredId) ? configuredId : Guid.Empty;
        var deviceLock = new DeviceLock(Path.Combine(dataDirectory, "device-lock.json"), PinVerifier.LoadProtected(Path.Combine(dataDirectory, "pin-verifier.dpapi")));
        var commands = new CommandExecutor(Path.Combine(dataDirectory, "commands.json"), deviceId, deviceLock,
            new WindowsDeviceActions(!registry.IsDryRun),
            OperatingSystem.IsWindows() ? new WindowsComputerNamer(!registry.IsDryRun) : null);
        using var trust = InstalledTrust.Load(AppContext.BaseDirectory);
        using var updateHttp = new HttpClient { Timeout = TimeSpan.FromMinutes(10) };
        var updater = new UpdateManager(Path.Combine(dataDirectory, "staging"), trust.ReleaseKeys, trust.InstalledSequence, trust.Channel,
            registry.IsDryRun ? null : new WindowsReleaseDownloader(updateHttp),
            !registry.IsDryRun && OperatingSystem.IsWindows() ? new WindowsReleaseInstaller() : null, AgentVersion,
            Path.Combine(dataDirectory, "update-blocked.json"));
        PolicyCoordinator? policy = null;
        ModuleHost? hostReference = null;
        var restorePointHours = int.TryParse(Environment.GetEnvironmentVariable("KEEPER_RESTORE_POINT_HOURS"), out var hours)
            ? Math.Clamp(hours, 6, 720) : 24;
        var moduleList = new List<IModule> { new WebEnforcer(registry), new UsbEnforcer(registry),
            new InstallEnforcer(registry, loadOptions: () => AppLockerOptions.FromEnvironment(Environment.GetEnvironmentVariable)),
            new DownloadEnforcer(registry), deviceLock, commands, new Inventory(outbox.EnqueueInventoryAsync), updater,
            HardeningStatusModule.FromFile(Path.Combine(dataDirectory, "hardening", "state.json")),
            new RestorePointModule(new WindowsRestorePointService(!registry.IsDryRun), Path.Combine(dataDirectory, "restore-point.json"),
                TimeSpan.FromHours(restorePointHours), TimeSpan.FromHours(6)),
            new TamperGuard(trust.BinaryHashes), new AgentDiagnostics(() => hostReference?.Snapshot() ?? []) };
        if (OperatingSystem.IsWindows()) moduleList.Add(new SessionSupervisor(new WindowsSessionLauncher(trust.BinaryHashes),
            Path.Combine(AppContext.BaseDirectory, "Keeper.Session.exe"), deviceLock, () => policy));
        await using var modules = new ModuleHost(moduleList, context);
        hostReference = modules;
        await modules.InitAsync();
        policy = new PolicyCoordinator(new SignedFilePolicyStore(Path.Combine(dataDirectory, "policy-cache.json"), key), modules, deviceId);
        await policy.RestoreAsync(stoppingToken);
        var localPolicy = Environment.GetEnvironmentVariable("KEEPER_POLICY_FILE");
        if (localPolicy is not null)
        {
            var document = JsonSerializer.Deserialize<CachedPolicy>(await File.ReadAllBytesAsync(localPolicy, stoppingToken), ProtocolJson.Options)
                ?? throw new InvalidDataException("invalid_local_policy");
            await policy.ApplyAsync(document.PolicyVersion, document.Policy, stoppingToken);
        }
        using var handler = new HttpClientHandler { AllowAutoRedirect = false, UseCookies = false };
        using var http = new HttpClient(handler) { Timeout = TimeSpan.FromSeconds(45) };
        ISyncCycle? transport = null;
        var api = Environment.GetEnvironmentVariable("KEEPER_API_BASE");
        if (api is not null)
        {
            if (deviceId == Guid.Empty) throw new InvalidOperationException("KEEPER_DEVICE_ID is required");
            if (!Uri.TryCreate(api, UriKind.Absolute, out var root) || root.Scheme != "https" || !root.AbsolutePath.EndsWith("/v1/", StringComparison.Ordinal) ||
                root.Query.Length != 0 || root.Fragment.Length != 0 || root.UserInfo.Length != 0)
                throw new InvalidOperationException("KEEPER_API_BASE must be an HTTPS /v1/ URL");
            http.BaseAddress = root;
            transport = new SyncClient(http, signer, deviceId, outbox, policy, TimeProvider.System,
                Environment.GetEnvironmentVariable("KEEPER_ENROLLMENT_TICKET"),
                new DeviceTokenStore(tokenPath, root.AbsoluteUri + signer.KeyId))
            {
                HardeningPassword = OperatingSystem.IsWindows()
                    ? new HardeningPasswordModule(dataDirectory, new WindowsHardeningPasswordFileSystem(), new DpapiHardeningPasswordProtector())
                    : null,
                OnResponse = async (response, tenant, ct) =>
                {
                    await commands.AcceptAsync(response.Commands, tenant, ct);
                    updater.Offer(response.Release);
                }
            };
        }
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
