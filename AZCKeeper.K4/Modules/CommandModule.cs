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

    private readonly object _gate = new();
    private System.Threading.Timer? _timer;
    private int _pollIntervalSeconds = 30;
    private volatile bool _running;
    private int _polling; // 0/1, guarda contra solapamiento de ciclos

    public CommandModule(IApiClient api, IClock clock, Action<string>? log = null)
    {
        _api = api;
        _clock = clock;
        _log = log;
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
                await ExecuteShutdownAsync(cmd.Id);
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
    /// Apagado remoto. En dev NO ejecuta un apagado real: solo loguea y reporta el
    /// comando como simulado. El mecanismo real queda cableado pero comentado.
    /// </summary>
    private async Task ExecuteShutdownAsync(long commandId)
    {
        ExecuteShutdown();
        await _api.ReportCommandResultAsync(commandId, "done", new { simulated = true });
    }

    private void ExecuteShutdown()
    {
        _log?.Invoke("commandModule: shutdown solicitado (no ejecutado en dev)");
        // TODO produccion: Process.Start("shutdown", "/s /t 60") tras confirmacion/guardas
    }
}
