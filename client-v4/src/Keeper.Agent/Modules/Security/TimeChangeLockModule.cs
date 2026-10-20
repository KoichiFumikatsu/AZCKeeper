using System.ComponentModel;
using Keeper.Shared.Contracts;
using Keeper.Shared.Protocol;
using Keeper.Shared.Time;

namespace Keeper.Agent.Modules.Security;

// Bloqueo real del cambio manual de la hora (agente 4.0.28; opcion time_change_lock de la politica, apagada por
// defecto). Con true quita «Cambiar la hora del sistema» a Administradores (TimeChangeLock: SYSTEM y LOCAL SERVICE lo
// conservan, la zona horaria sigue libre); sin el campo o con false devuelve exactamente lo que se cambio. Nunca en
// dominio: si el equipo entra a un dominio con el bloqueo puesto, se devuelve. Se revisa al recibir la politica y cada
// 10 min (si alguien le devolvio el derecho a Administradores con secpol.msc, se le vuelve a quitar). Los usuarios ya
// conectados conservan el privilegio en su token hasta cerrar sesion; el bloqueo aplica desde el siguiente inicio.
// rights null = dry-run (no toca la LSA). Eventos: time_change_locked, time_change_unlocked, time_change_lock_failed.
public sealed class TimeChangeLockModule(IAccountRights? rights, Func<bool> domainJoined, string statePath) : ModuleBase
{
    public override string Name => "TimeChangeLock";
    public static readonly TimeSpan CheckInterval = TimeSpan.FromMinutes(10);
    private readonly TimeChangeLock? _lock = rights is null ? null : new TimeChangeLock(rights, statePath);
    private bool? _desired;
    private long _nextCheck;
    private string? _reportedFailure;

    public override ModuleSnapshot Snapshot() => base.Snapshot() with { ErrorCode = State == "failed" ? _reportedFailure : null };

    public override async Task InitAsync(ModuleContext ctx)
    {
        await base.InitAsync(ctx);
        State = _lock is null ? "dry_run" : File.Exists(statePath) ? "applied" : "ready";
    }

    public override async Task ApplyPolicyAsync(EffectivePolicy policy)
    {
        await base.ApplyPolicyAsync(policy);
        _desired = policy.TimeChangeLock == true;
        await ReconcileAsync(Context.StoppingToken);
    }

    public override async Task TickAsync(CancellationToken ct)
    {
        // Hasta tener politica (la de cache o la del servidor) no se decide nada: ni bloquear ni desbloquear.
        if (_desired is null || Context.Clock.GetTimestamp() < _nextCheck) return;
        await ReconcileAsync(ct);
    }

    private async Task ReconcileAsync(CancellationToken ct)
    {
        _nextCheck = Context.Clock.GetTimestamp() + (long)(CheckInterval.TotalSeconds * Context.Clock.TimestampFrequency);
        if (_lock is null || _desired is not { } desired) return;
        try
        {
            var domain = domainJoined();
            if (desired && !domain)
            {
                var result = _lock.Lock(Context.Clock.GetUtcNow());
                State = "applied";
                _reportedFailure = null;
                if (result.Changed) await ReportAsync("time_change_locked", ct, LogEntryLevel.Info, result.Reapplied ? "reaplicado" : null);
                return;
            }
            var unlocked = _lock.Unlock();
            State = desired ? "domain" : "ready";
            _reportedFailure = null;
            if (unlocked) await ReportAsync("time_change_unlocked", ct, LogEntryLevel.Info, desired ? "dominio" : "politica");
        }
        catch (Exception ex) when (ex is not OperationCanceledException)
        {
            State = "failed";
            var code = ex switch
            {
                Win32Exception win32 => $"Win32Exception_{win32.NativeErrorCode}",
                InvalidOperationException or InvalidDataException when ex.Message.Contains("time_change", StringComparison.Ordinal) => ex.Message,
                _ => ex.GetType().Name
            };
            // Un fallo que se repite en cada revision se reporta una vez.
            if (_reportedFailure == code) return;
            _reportedFailure = code;
            await ReportAsync("time_change_lock_failed", ct, LogEntryLevel.Warn, code);
        }
    }
}
