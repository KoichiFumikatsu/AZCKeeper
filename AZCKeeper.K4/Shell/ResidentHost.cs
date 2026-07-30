namespace AZCKeeper.K4.Shell;

/// <summary>
/// El motor del cliente residente: corre el ciclo del core en una cadencia, se salta los
/// ciclos mientras hay backoff de red, reintenta pronto tras un fallo, y garantiza un
/// flush ÚNICO al cerrar. No conoce el core ni el ApiClient: recibe delegates, así se
/// prueba la lógica de cadencia/backoff/flush sin red.
///
/// La decisión de un tick está en TickAsync (pura, testeable); RunLoopAsync solo la
/// dispara en un PeriodicTimer corto para responder rápido cuando el backoff se libera.
/// </summary>
public sealed class ResidentHost
{
    private readonly Func<Task<bool>> _runCycle;
    private readonly Func<bool> _isBackingOff;
    private readonly Func<Task> _flushAndStop;
    private readonly TimeSpan _interval;
    private readonly TimeSpan _retryInterval;
    private readonly Func<DateTime> _nowUtc;
    private readonly Action<string>? _log;

    private DateTime _nextRunUtc = DateTime.MinValue; // MinValue => corre en el primer tick
    private int _flushed;

    /// <summary>Cada cuánto se sondea si toca correr un ciclo. Corto para responder al backoff.</summary>
    public static readonly TimeSpan PollTick = TimeSpan.FromSeconds(5);

    public ResidentHost(
        Func<Task<bool>> runCycle,
        Func<bool> isBackingOff,
        Func<Task> flushAndStop,
        TimeSpan interval,
        TimeSpan? retryInterval = null,
        Func<DateTime>? nowUtc = null,
        Action<string>? log = null)
    {
        _runCycle = runCycle;
        _isBackingOff = isBackingOff;
        _flushAndStop = flushAndStop;
        _interval = interval;
        _retryInterval = retryInterval ?? TimeSpan.FromSeconds(30);
        _nowUtc = nowUtc ?? (() => DateTime.UtcNow);
        _log = log;
    }

    /// <summary>
    /// Una decisión de tick. Devuelve true si corrió un ciclo. No corre si hay backoff o si
    /// aún no toca. Tras un ciclo, reprograma: intervalo completo si fue bien, retry corto
    /// si falló (el backoff, si aplica, gatea los envíos de todas formas).
    /// </summary>
    public async Task<bool> TickAsync()
    {
        if (_isBackingOff()) return false;
        var now = _nowUtc();
        if (now < _nextRunUtc) return false;

        bool ok;
        try { ok = await _runCycle(); }
        catch (Exception ex) { _log?.Invoke($"ciclo error: {ex.Message}"); ok = false; }

        _nextRunUtc = now + (ok ? _interval : _retryInterval);
        return true;
    }

    /// <summary>Corre el loop hasta cancelar. Primer ciclo inmediato, luego cada PollTick.</summary>
    public async Task RunLoopAsync(CancellationToken ct)
    {
        try
        {
            await TickAsync(); // arranque inmediato: no esperar el primer tick
            using var timer = new PeriodicTimer(PollTick);
            while (await timer.WaitForNextTickAsync(ct))
                await TickAsync();
        }
        catch (OperationCanceledException) { /* cierre normal */ }
    }

    /// <summary>
    /// Flush + parada, garantizado UNA sola vez aunque lo disparen ProcessExit,
    /// SessionEnding y el fin de Application.Run a la vez. Nunca lanza.
    /// </summary>
    public async Task FlushAndStopAsync()
    {
        if (Interlocked.Exchange(ref _flushed, 1) == 1) return;
        try { await _flushAndStop(); }
        catch (Exception ex) { _log?.Invoke($"flush error: {ex.Message}"); }
    }
}
