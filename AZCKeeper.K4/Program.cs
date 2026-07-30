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

    [STAThread]
    private static int Main(string[] args)
    {
        // Instancia única. Nombre DISTINTO al de K3 ("AZCKeeper_Cliente_SingleInstance")
        // para que producción 3.0.3.2 y K4 puedan coexistir durante la migración.
        using var mutex = new Mutex(initiallyOwned: true, @"Local\AZCKeeper_K4_SingleInstance", out bool isNew);
        if (!isNew) return 0; // ya hay una instancia corriendo

        bool once = args.Contains("--once");
        var positional = args.Where(a => !a.StartsWith("--", StringComparison.Ordinal)).ToArray();

        // Config + identidad estable del equipo.
        var cfg = K4Config.LoadOrCreate();
        if (positional.Length > 0) cfg.BaseUrl = positional[0];
        if (positional.Length > 1) cfg.Cc = positional[1];
        if (string.IsNullOrWhiteSpace(cfg.Cc)) cfg.Cc = "K4TEST"; // fallback de desarrollo
        cfg.Save();

        void Log(string m) => Console.WriteLine($"  {m}"); // Etapa 2: LocalLogger a archivo

        // Token persistente (DPAPI). Se restaura al arrancar y se guarda cuando cambia.
        var creds = new K4CredentialStore();
        var queue = new OfflineQueue();
        var api = new K4ApiClient(cfg.BaseUrl, cfg.DeviceId, queue: queue);
        var savedToken = creds.LoadToken();
        if (savedToken is not null) api.RestoreToken(savedToken);
        api.TokenChanged += t => { if (t is not null) creds.SaveToken(t); };

        // Core único + módulos independientes (ninguno conoce a otro).
        var clock = new SystemClock();
        var host = new ModuleHost(onError: (code, ex) => Log($"[modulo {code}] error: {ex.Message}"));
        var fg = new WinForegroundWindow();
        var idle = new WinIdleMonitor();
        host.Register(new ActivityModule(api, idle, clock, host.IsModuleRunning, Log));
        host.Register(new WindowModule(api, fg, clock, Log));
        host.Register(new CommandModule(api, clock, Log));
        host.Register(new ScreenshotModule(api, new WinScreenCapturer(), new StubBlobStore(), clock, Log));

        var core = new CoreService(api, host, cfg.Cc, Environment.MachineName, cfg.Version, log: Log);

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

        // El loop corre en background; la UI invisible mantiene vivo el proceso.
        _ = _resident.RunLoopAsync(_cts.Token);

        Application.Run(new ApplicationContext());

        Shutdown(); // por si Application.Run retornó sin pasar por ProcessExit
        GC.KeepAlive(mutex);
        return 0;
    }
}
