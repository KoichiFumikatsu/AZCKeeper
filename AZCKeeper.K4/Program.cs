using System.Windows.Forms;
using AZCKeeper.K4.Contracts;
using AZCKeeper.K4.Core;
using AZCKeeper.K4.Modules;
using AZCKeeper.K4.Platform;
using AZCKeeper.K4.Shell;

namespace AZCKeeper.K4;

/// <summary>
/// Cliente residente Keeper 4: app WinForms SIN ventana ni bandeja (invisible, apropiado
/// para BPO — el usuario monitoreado no ve ni puede cerrar un ícono). Mantiene sesión sin
/// re-loguear (token DPAPI), corre el ciclo de handshake en una cadencia con backoff, y
/// cierra con flush síncrono por cualquiera de las vías (ProcessExit / SessionEnding / fin
/// de Application.Run). Todo per-user, sin admin: la parte SYSTEM es el agente elevado,
/// proyecto aparte.
///
/// Diagnóstico: 'AZCKeeper4 --once [baseUrl] [cc]' corre UN ciclo y sale.
/// </summary>
internal static class Program
{
    private static ResidentHost? _resident;
    private static CancellationTokenSource? _cts;

    // Bajo WinExe no hay consola; para el modo --once diagnostico nos enganchamos a la del
    // proceso padre (la terminal desde la que se lanzó) para que se vean los mensajes.
    [System.Runtime.InteropServices.DllImport("kernel32.dll")]
    private static extern bool AttachConsole(int dwProcessId);
    private const int AttachParentProcess = -1;

    [STAThread]
    private static int Main(string[] args)
    {
        bool once = args.Contains("--once");
        bool noInstall = once || args.Contains("--no-install");
        if (once) AttachConsole(AttachParentProcess);

        // Auto-instalación per-user (ANTES del mutex, para que la copia instalada sea la que
        // obtenga el mutex, no esta). Si el exe corre desde fuera del dir de instalación
        // (descarga/USB), se copia a %APPDATA%\AZCKeeper4\app, registra el arranque y relanza
        // la copia instalada. En dev se salta con --no-install/--once.
        if (!noInstall && TrySelfInstall()) return 0;

        // Instancia única: que no corran dos clientes K4 a la vez. (K3 no corre: el
        // instalador lo limpió antes de poner esta versión.)
        using var mutex = new Mutex(initiallyOwned: true, @"Local\AZCKeeper_K4_SingleInstance", out bool isNew);
        if (!isNew) return 0; // ya hay una instancia corriendo

        var positional = args.Where(a => !a.StartsWith("--", StringComparison.Ordinal)).ToArray();

        // Config + identidad estable del equipo.
        var cfg = K4Config.LoadOrCreate();

        // La version que CORRE sale del assembly (build-release lo sella con -p:Version), no del
        // config. Sin esto el cliente se reportaria siempre como el default del config y el
        // auto-update no podria distinguir un build de otro. En dev (sin -p:Version) cae al config.
        string version = System.Reflection.Assembly.GetExecutingAssembly().GetName().Version?.ToString() ?? cfg.Version;

        // Logging real (anillo en memoria + archivo diario). Reemplaza el Console.WriteLine
        // que bajo WinExe se perdia. En --once ademas eco a la consola del padre (diagnostico).
        var logger = new LocalLogger();
        Action<string> Log = once
            ? (m => { logger.Log(m); Console.WriteLine($"  {m}"); })
            : logger.Log;

        var creds = new K4CredentialStore();
        var queue = new OfflineQueue();

        // --- Resolver identidad: entorno + cedula + contrasena ---
        // Precedencia: argumentos (dev/--once) > credenciales guardadas (DPAPI) > dialogo de
        // primer arranque. El token DPAPI persiste, asi que el login solo se pide una vez.
        string baseUrl = positional.Length > 0 ? positional[0] : cfg.BaseUrl;
        string cc, password;
        var savedCreds = creds.LoadCredentials();

        if (positional.Length > 1)
        {
            cc = positional[1];
            password = positional.Length > 2 ? positional[2] : $"z{cc}Z@!$"; // patron por defecto
        }
        else if (savedCreds is { } sc)
        {
            cc = sc.Cc; password = sc.Password;   // identidad ya establecida: sin dialogo
        }
        else if (once || noInstall)
        {
            cc = "K4TEST"; password = "zK4TESTZ@!$";   // fallback de desarrollo sin dialogo
        }
        else
        {
            // Primer arranque real: unica UI del cliente. Entorno + cedula + contrasena.
            var deviceId = cfg.DeviceId;
            using var dlg = new FirstRunLogin(K4Config.Environments, async (bu, c, p) =>
            {
                try
                {
                    var tmp = new K4ApiClient(bu, deviceId);   // efimero: solo valida credenciales
                    var r = await tmp.LoginAsync(c, p, Environment.MachineName, version);
                    return (r.Ok, r.Note == "pending" ? "pending" : (r.Ok ? "ok" : "bad"));
                }
                catch { return (false, "error"); }
            });
            if (dlg.ShowDialog() != DialogResult.OK) return 0;   // el usuario cancelo
            baseUrl = dlg.ResultBaseUrl; cc = dlg.ResultCc; password = dlg.ResultPassword;
            creds.SaveCredentials(cc, password);   // DPAPI: no se vuelve a pedir
        }

        cfg.BaseUrl = baseUrl; cfg.Cc = cc; cfg.Save();

        // Token persistente (DPAPI). Se restaura al arrancar y se guarda cuando cambia.
        var api = new K4ApiClient(baseUrl, cfg.DeviceId, queue: queue);
        var savedToken = creds.LoadToken();
        if (savedToken is not null) api.RestoreToken(savedToken);
        api.TokenChanged += t => { if (t is not null) creds.SaveToken(t); };

        // Core único + módulos independientes (ninguno conoce a otro).
        var clock = new SystemClock();
        // onError -> logger.Error(code,...) para que el snapshot de diagnostico pueda mostrar
        // "modulo X: real=OFF, ultimo error=...". LastErrorFor(code) se llena aqui.
        var host = new ModuleHost(onError: (code, ex) => logger.Error(code, ex.Message));
        var fg = new WinForegroundWindow();
        var idle = new WinIdleMonitor();
        host.Register(new ActivityModule(api, idle, clock, host.IsModuleRunning, Log));
        host.Register(new WindowModule(api, fg, clock, Log));
        host.Register(new CommandModule(api, clock, Log));
        host.Register(new ScreenshotModule(api, new WinScreenCapturer(), new StubBlobStore(), clock, Log));

        var core = new CoreService(api, host, cc, password, Environment.MachineName, version,
            log: Log, agentReader: new AgentReportReader(), idleSeconds: () => idle.IdleSeconds);

        if (once)
        {
            var ok = core.RunOnceAsync().GetAwaiter().GetResult();
            core.StopAndFlushAsync().GetAwaiter().GetResult();
            Log(ok ? "CICLO OK" : "CICLO FALLIDO");
            return ok ? 0 : 1;
        }

        // Excepciones no manejadas: registrar, no morir en silencio.
        AppDomain.CurrentDomain.UnhandledException += (_, e) =>
            Log($"unhandled: {(e.ExceptionObject as Exception)?.Message}");
        Application.ThreadException += (_, e) => Log($"thread ex: {e.Exception.Message}");

        _cts = new CancellationTokenSource();
        _resident = new ResidentHost(
            runCycle: () => core.RunOnceAsync(),
            isBackingOff: () => api.IsBackingOff,
            flushAndStop: () => core.StopAndFlushAsync(),
            interval: TimeSpan.FromSeconds(cfg.HandshakeIntervalSeconds),
            retryInterval: TimeSpan.FromSeconds(cfg.OfflineRetrySeconds),
            log: Log,
            drain: () => api.DrainAsync());

        // Flush garantizado UNA sola vez (ResidentHost lo asegura) por cualquier vía.
        void Shutdown()
        {
            _cts?.Cancel();
            _resident?.FlushAndStopAsync().GetAwaiter().GetResult();
        }
        AppDomain.CurrentDomain.ProcessExit += (_, _) => Shutdown();
        Microsoft.Win32.SystemEvents.SessionEnding += (_, _) => Shutdown();

        // Arranque automático con Windows (HKCU Run, sin admin). Best-effort.
        try { new StartupManager().EnableStartup(); } catch (Exception ex) { Log($"startup: {ex.Message}"); }

        // Loop de diagnostico: mientras IT marque a esta persona en el panel (el handshake lo
        // anuncia), sube un snapshot cada pocos segundos. Independiente del loop de handshake.
        var diagLoop = new DiagnosticLoop(
            getFlag: () => core.LastDiagnostics,
            build: cursor => DiagnosticSnapshot.Build(
                host, core.LastExpected, logger, cursor, fg, idle,
                new DiagNet(
                    api.IsBackingOff,
                    api.IsBackingOff ? api.BackoffUntilUtc.ToString("yyyy-MM-ddTHH:mm:ssZ") : null,
                    api.LastHandshakeStatus, api.PendingQueueCount, version)),
            send: payload => api.SendDiagnosticsAsync(payload),
            isBackingOff: () => api.IsBackingOff,
            log: Log);

        // El loop corre en background; la UI invisible mantiene vivo el proceso.
        _ = _resident.RunLoopAsync(_cts.Token);
        _ = RunUpdateLoopAsync(cfg, version, _cts.Token, Log);
        _ = diagLoop.RunAsync(_cts.Token);

        Application.Run(new ApplicationContext());

        Shutdown(); // por si Application.Run retornó sin pasar por ProcessExit
        GC.KeepAlive(mutex);
        return 0;
    }

    /// <summary>
    /// Si el exe corre desde fuera del dir de instalación, se instala per-user y relanza la
    /// copia instalada. Devuelve true si instaló (el caller debe salir). Si algo falla,
    /// devuelve false para seguir corriendo en el sitio actual — mejor eso que no arrancar.
    /// </summary>
    private static bool TrySelfInstall()
    {
        try
        {
            var current = Environment.ProcessPath;
            if (string.IsNullOrEmpty(current)) return false;
            if (Installer.IsInInstallDir(current, K4Paths.InstallDir)) return false; // ya instalado

            // Limpiar cualquier Keeper previo (K3 u otra K4) para que no corra doble.
            try { new LegacyCleaner().CleanExisting(Environment.ProcessId); } catch { /* best-effort */ }

            var installer = new Installer();
            // El updater puede venir embebido (Setup de un solo archivo) o al lado del exe.
            using var embedded = System.Reflection.Assembly.GetExecutingAssembly()
                .GetManifestResourceStream(Installer.UpdaterName);
            var installedExe = installer.InstallFrom(current, embedded);
            try { new StartupManager().EnableStartup(); } catch { /* best-effort */ }

            System.Diagnostics.Process.Start(new System.Diagnostics.ProcessStartInfo(installedExe)
            {
                UseShellExecute = true,
                WorkingDirectory = K4Paths.InstallDir,
            });
            return true;
        }
        catch
        {
            return false; // no se pudo instalar: seguir en el sitio actual
        }
    }

    /// <summary>
    /// Chequeo periódico de actualización. Por defecto (AutoDownload=false) solo AUTO-APLICA
    /// lo crítico o forzado por el servidor; lo demás se registra y se deja a decisión manual.
    /// Al aplicar, lanza el helper y cierra el cliente para que el swap ocurra.
    /// </summary>
    private static async Task RunUpdateLoopAsync(K4Config cfg, string version, CancellationToken ct, Action<string> log)
    {
        if (!cfg.Updates.Enable) return;
        var mgr = new K4UpdateManager(cfg.BaseUrl, version, cfg.Updates.AutoDownload, log: log);
        try
        {
            using var timer = new PeriodicTimer(TimeSpan.FromMinutes(Math.Max(15, cfg.Updates.IntervalMinutes)));
            do
            {
                try
                {
                    var d = await mgr.CheckAsync(cfg.Updates.AllowBeta);
                    if (d.ShouldDownload && await mgr.ApplyAsync(d))
                    {
                        log("update: aplicado, cerrando para el swap");
                        Application.Exit(); // dispara el flush y deja al helper hacer el swap
                        return;
                    }
                }
                catch (Exception ex) { log($"update loop: {ex.Message}"); }
            }
            while (await timer.WaitForNextTickAsync(ct));
        }
        catch (OperationCanceledException) { /* cierre normal */ }
    }
}
