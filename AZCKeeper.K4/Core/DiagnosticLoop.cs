namespace AZCKeeper.K4.Core;

/// <summary>
/// Loop del modo diagnostico. Independiente del loop de handshake (no lo acelera). Cada
/// segundo mira el flag que el ultimo handshake dejo en CoreService; mientras este activo y
/// no haya vencido, sube un snapshot cada IntervalSeconds. En backoff de red, salta el tick
/// (no abre socket). No arranca/para timers: solo obedece el flag, asi no hay carreras de
/// ciclo de vida cuando IT enciende/apaga desde el panel.
///
/// El cursor de logs avanza SOLO cuando el envio fue exitoso: si un snapshot no entra, el
/// siguiente reincluye esos logs en vez de perderlos.
/// </summary>
public sealed class DiagnosticLoop
{
    private readonly Func<DiagnosticsFlag> _getFlag;
    private readonly Func<long, DiagnosticResult> _build;
    private readonly Func<object, Task<bool>> _send;
    private readonly Func<bool> _isBackingOff;
    private readonly Func<DateTime> _nowUtc;
    private readonly Action<string>? _log;

    private long _cursor;
    private DateTime _lastSendUtc = DateTime.MinValue;

    public DiagnosticLoop(
        Func<DiagnosticsFlag> getFlag,
        Func<long, DiagnosticResult> build,
        Func<object, Task<bool>> send,
        Func<bool> isBackingOff,
        Func<DateTime>? nowUtc = null,
        Action<string>? log = null)
    {
        _getFlag = getFlag; _build = build; _send = send;
        _isBackingOff = isBackingOff; _nowUtc = nowUtc ?? (() => DateTime.UtcNow); _log = log;
    }

    /// <summary>
    /// Evalua una vez si toca enviar y, de ser asi, arma y envia el snapshot. Devuelve true si
    /// envio. Extraido para poder testear la decision sin el timer.
    /// </summary>
    public async Task<bool> TickAsync()
    {
        var flag = _getFlag();
        var now = _nowUtc();

        if (!flag.Enabled) return false;
        if (flag.UntilUtc is { } until && now >= until) return false;   // sesion vencida
        if (_isBackingOff()) return false;                              // no abrir socket
        if ((now - _lastSendUtc).TotalSeconds < flag.IntervalSeconds) return false;

        try
        {
            var res = _build(_cursor);
            if (await _send(res.Payload))
            {
                _cursor = res.MaxSeq;       // solo avanzar si el server confirmo
                _lastSendUtc = now;
                return true;
            }
        }
        catch (Exception ex) { _log?.Invoke($"diag tick: {ex.Message}"); }
        return false;
    }

    public async Task RunAsync(CancellationToken ct)
    {
        try
        {
            using var timer = new PeriodicTimer(TimeSpan.FromSeconds(1));
            do { await TickAsync(); }
            while (await timer.WaitForNextTickAsync(ct));
        }
        catch (OperationCanceledException) { /* cierre normal */ }
    }
}
