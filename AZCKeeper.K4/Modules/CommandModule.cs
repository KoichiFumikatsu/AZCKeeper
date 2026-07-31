using System.Net;
using System.Net.NetworkInformation;
using AZCKeeper.K4.Contracts;

namespace AZCKeeper.K4.Modules;

/// <summary>
/// Módulo de comandos remotos: apagado remoto y diagnóstico de red. Hace polling
/// periódico de comandos pendientes vía IApiClient y ejecuta cada uno según su
/// CommandType, reportando el resultado. No conoce a ningún otro módulo, solo el
/// contrato y lo recibido por constructor (api, clock, log).
/// </summary>
public sealed class CommandModule : IKeeperModule
{
    public string Code => "remoteShutdown";

    private readonly IApiClient _api;
    private readonly IClock _clock;
    private readonly Action<string>? _log;

    // Ejecutor de acciones del sistema (apagar/reiniciar/logoff/bloquear), inyectable para
    // testear sin ejecutar de verdad. Default = implementacion real de Windows.
    private readonly ISystemActions _sys;

    private readonly object _gate = new();
    private System.Threading.Timer? _timer;
    private int _pollIntervalSeconds = 30;
    private volatile bool _running;
    private int _polling; // 0/1, guarda contra solapamiento de ciclos

    public CommandModule(IApiClient api, IClock clock, Action<string>? log = null, ISystemActions? sys = null)
    {
        _api = api;
        _clock = clock;
        _log = log;
        _sys = sys ?? new WindowsSystemActions();
    }

    public bool IsRunning => _running;

    public void Configure(ModuleSettings settings)
    {
        _pollIntervalSeconds = settings.GetInt("pollIntervalSeconds", 30);
    }

    public void Start()
    {
        lock (_gate)
        {
            if (_running) return; // idempotente

            var period = TimeSpan.FromSeconds(Math.Max(1, _pollIntervalSeconds));
            _timer = new System.Threading.Timer(OnTick, null, period, period);
            _running = true;
        }
    }

    public void Stop()
    {
        lock (_gate)
        {
            if (!_running) return; // idempotente
            _timer?.Dispose();
            _timer = null;
            _running = false;
        }
    }

    /// <summary>Callback del Timer: void por contrato, así que el ciclo async se aísla
    /// completo en try/catch para que ninguna excepción se escape sin capturar y mate
    /// el timer o el proceso.</summary>
    private void OnTick(object? state)
    {
        // Evita solapar ciclos si un poll tarda más que el intervalo.
        if (Interlocked.CompareExchange(ref _polling, 1, 0) != 0) return;

        _ = Task.Run(async () =>
        {
            try
            {
                await PollAndExecuteAsync();
            }
            catch (Exception ex)
            {
                _log?.Invoke($"commandModule: error en ciclo de polling: {ex.Message}");
            }
            finally
            {
                Interlocked.Exchange(ref _polling, 0);
            }
        });
    }

    /// <summary>Ejecuta un ciclo de poll+ejecucion. Publico para pruebas (el flujo normal es por timer).</summary>
    public Task PollOnceForTestAsync() => PollAndExecuteAsync();

    private async Task PollAndExecuteAsync()
    {
        var commands = await _api.PollCommandsAsync();
        if (commands is null || commands.Count == 0) return;

        foreach (var cmd in commands)
        {
            try
            {
                await ExecuteAsync(cmd);
            }
            catch (Exception ex)
            {
                _log?.Invoke($"commandModule: error ejecutando comando {cmd.Id} ({cmd.CommandType}): {ex.Message}");
                try
                {
                    await _api.ReportCommandResultAsync(cmd.Id, "failed", new { error = ex.Message });
                }
                catch (Exception reportEx)
                {
                    _log?.Invoke($"commandModule: error reportando resultado del comando {cmd.Id}: {reportEx.Message}");
                }
            }
        }
    }

    private async Task ExecuteAsync(CommandDto cmd)
    {
        switch (cmd.CommandType)
        {
            case "network_diag":
                await ExecuteNetworkDiagAsync(cmd.Id);
                break;

            case "shutdown":
                await ExecuteShutdownAsync(cmd.Id, cmd.ParamsJson, "/s");
                break;

            case "restart":
                await ExecuteShutdownAsync(cmd.Id, cmd.ParamsJson, "/r");
                break;

            case "logoff":
                _sys.Logoff();
                _log?.Invoke("commandModule: logoff solicitado");
                await _api.ReportCommandResultAsync(cmd.Id, "done", new { scheduled = true, kind = "logoff" });
                break;

            case "lock":
                _sys.LockWorkStation();
                _log?.Invoke("commandModule: bloqueo de pantalla solicitado");
                await _api.ReportCommandResultAsync(cmd.Id, "done", new { locked = true });
                break;

            default:
                _log?.Invoke($"commandModule: tipo de comando desconocido '{cmd.CommandType}' (id={cmd.Id})");
                await _api.ReportCommandResultAsync(cmd.Id, "failed", new { error = "unknown command type" });
                break;
        }
    }

    /// <summary>
    /// Diagnóstico de red real y compacto: ping a 8.8.8.8 y resolución DNS de google.com.
    /// El diagnóstico "encuentra" fallas de red, no falla como comando: si el ping o el
    /// DNS revientan, el resultado igual se reporta como "done" con el error adentro.
    /// </summary>
    private async Task ExecuteNetworkDiagAsync(long commandId)
    {
        var checkedAt = _clock.UtcNow;
        object pingResult;
        try
        {
            using var ping = new Ping();
            var reply = await ping.SendPingAsync("8.8.8.8", 4000);
            pingResult = new
            {
                status = reply.Status.ToString(),
                roundtripMs = reply.Status == IPStatus.Success ? (long?)reply.RoundtripTime : null,
            };
        }
        catch (Exception ex)
        {
            pingResult = new { status = "error", error = ex.Message };
        }

        object dnsResult;
        try
        {
            var sw = System.Diagnostics.Stopwatch.StartNew();
            var addresses = await Dns.GetHostAddressesAsync("google.com");
            sw.Stop();
            dnsResult = new
            {
                status = "ok",
                elapsedMs = sw.ElapsedMilliseconds,
                addresses = addresses.Select(a => a.ToString()).ToArray(),
            };
        }
        catch (Exception ex)
        {
            dnsResult = new { status = "error", error = ex.Message };
        }

        var result = new
        {
            checkedAt = checkedAt.ToString("o"),
            ping = pingResult,
            dns = dnsResult,
        };

        await _api.ReportCommandResultAsync(commandId, "done", result);
    }

    /// <summary>
    /// Apagado (/s) o reinicio (/r) REAL con ventana de gracia: el usuario ve el aviso nativo
    /// de Windows y puede guardar. La gracia viene en params.graceSeconds (10..600, 60 por
    /// defecto). Reporta 'done' con lo programado. El ejecutor es inyectable para tests.
    /// </summary>
    private async Task ExecuteShutdownAsync(long commandId, string? paramsJson, string flag)
    {
        int grace = ReadGraceSeconds(paramsJson);
        _sys.Shutdown(flag, grace);
        var kind = flag == "/r" ? "restart" : "shutdown";
        _log?.Invoke($"commandModule: {kind} programado en {grace}s");
        await _api.ReportCommandResultAsync(commandId, "done", new { scheduled = true, kind, graceSeconds = grace });
    }

    private static int ReadGraceSeconds(string? paramsJson)
    {
        if (string.IsNullOrWhiteSpace(paramsJson)) return 60;
        try
        {
            using var doc = System.Text.Json.JsonDocument.Parse(paramsJson);
            if (doc.RootElement.TryGetProperty("graceSeconds", out var g) && g.TryGetInt32(out var s))
                return Math.Max(10, Math.Min(600, s));
        }
        catch { /* params invalido -> default */ }
        return 60;
    }
}

/// <summary>Acciones del sistema operativo, abstraidas para poder testear el modulo sin ejecutarlas.</summary>
public interface ISystemActions
{
    void Shutdown(string flag, int graceSeconds);   // flag = "/s" (apagar) | "/r" (reiniciar)
    void Logoff();
    void LockWorkStation();
}

/// <summary>Implementacion real en Windows: 'shutdown.exe' + user32 LockWorkStation.</summary>
public sealed class WindowsSystemActions : ISystemActions
{
    [System.Runtime.InteropServices.DllImport("user32.dll")]
    private static extern bool LockWorkStation();

    public void Shutdown(string flag, int graceSeconds)
        => Run("shutdown", $"{flag} /t {graceSeconds}");

    public void Logoff() => Run("shutdown", "/l");

    void ISystemActions.LockWorkStation() => LockWorkStation();

    private static void Run(string file, string args)
        => System.Diagnostics.Process.Start(new System.Diagnostics.ProcessStartInfo(file, args) { UseShellExecute = false, CreateNoWindow = true });
}
