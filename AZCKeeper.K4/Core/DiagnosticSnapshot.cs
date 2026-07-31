using AZCKeeper.K4.Contracts;
using AZCKeeper.K4.Shell;

namespace AZCKeeper.K4.Core;

/// <summary>Salud de red del cliente, para el bloque 'net' del snapshot. La arma el shell.</summary>
public sealed record DiagNet(
    bool BackingOff, string? BackoffUntil, int LastHandshakeStatus, int QueueDepth, string Version);

/// <summary>
/// Arma el snapshot de diagnostico que el cliente sube en modo diagnostico. Cuatro bloques:
///
///   1. modules  — ESPERADO vs REAL por modulo: expected (de la ultima config del handshake)
///                 vs running (estado real del ModuleHost), + ultimo error del modulo.
///   2. logs     — lo NUEVO del LocalLogger desde el cursor (no reenvia lo mismo cada 4s).
///   3. activity — lo que el cliente ve AHORA: proceso/titulo en foco + inactividad.
///   4. net      — backoff, ultimo handshake, cola offline, version.
///
/// Es pura (sin I/O, sin red): recibe todo por parametro para poder testearla sin Windows.
/// Devuelve el objeto payload y el mayor Seq incluido (para que el loop avance su cursor).
/// </summary>
public static class DiagnosticSnapshot
{
    public static DiagnosticResult Build(
        ModuleHost host,
        IReadOnlyDictionary<string, bool> expected,
        LocalLogger logger,
        long afterSeq,
        IForegroundWindow fg,
        IIdleMonitor idle,
        DiagNet net)
    {
        var modules = host.Modules.Select(m => new
        {
            code     = m.Code,
            expected = expected.TryGetValue(m.Code, out var e) && e,
            running  = host.IsModuleRunning(m.Code),
            lastError = logger.LastErrorFor(m.Code),
        }).ToList();

        var newLogs = logger.RecentSince(afterSeq);
        long maxSeq = afterSeq;
        var logs = newLogs.Select(l =>
        {
            if (l.Seq > maxSeq) maxSeq = l.Seq;
            return new
            {
                seq = l.Seq,
                ts = l.Ts.ToString("yyyy-MM-ddTHH:mm:ssZ"),
                level = l.Level,
                source = l.Source,
                message = l.Message,
            };
        }).ToList();

        var activity = new
        {
            process = SafeGet(() => fg.ProcessName),
            title = SafeGet(() => fg.Title),
            idleSeconds = SafeGet(() => (int?)idle.IdleSeconds) ?? -1,
        };

        var payload = new { modules, logs, activity, net };
        return new DiagnosticResult(payload, maxSeq);
    }

    private static T? SafeGet<T>(Func<T> f) { try { return f(); } catch { return default; } }
}

/// <summary>El payload del snapshot + el mayor Seq incluido (cursor para el proximo envio).</summary>
public sealed record DiagnosticResult(object Payload, long MaxSeq);
