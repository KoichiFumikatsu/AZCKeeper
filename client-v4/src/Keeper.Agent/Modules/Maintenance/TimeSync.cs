using Keeper.Shared.Contracts;
using Keeper.Shared.Protocol;
using Keeper.Shared.Time;

namespace Keeper.Agent.Modules.Maintenance;

// Mantiene la hora de Windows correcta (la necesita la firma de cada peticion: el servidor admite +-60 s).
// Al arrancar y cada 6 h: W32Time automatico y en marcha, NTP time.windows.com (o el del dominio, sin tocarlo), zona
// automatica (tzautoupdate) y w32tm /resync. Cada 2 min revisa la configuracion; si alguien la cambio la repone
// (time_settings_restored). Si el reloj de Windows salta respecto del reloj monotonico (alguien cambio la hora) o el
// servidor dice que la hora esta corrida, resincroniza en el acto (time_resynced con los segundos corregidos).
// Los comandos corren en segundo plano y se sondean en los ticks (como RestorePoint): ModuleHost ejecuta los ticks en
// serie. No resincroniza mientras haya una cuenta regresiva de reinicio/apagado en pantalla (deferResync): un salto
// de hora la adelantaria o la daria por vencida. El PIN local usa el reloj monotonico y no se ve afectado.
public sealed class TimeSync(ITimeSystem system, bool enabled, Func<TimeSpan?>? serverOffset = null, Func<bool>? deferResync = null)
    : ModuleBase
{
    public override string Name => "TimeSync";
    public static readonly TimeSpan ResyncInterval = TimeSpan.FromHours(6);
    public static readonly TimeSpan CheckInterval = TimeSpan.FromMinutes(2);
    public static readonly TimeSpan RetryAfterFailure = TimeSpan.FromMinutes(15);
    // Salto del reloj de Windows (contra el monotonico) entre dos ticks que se toma como un cambio manual de hora.
    public static readonly TimeSpan JumpThreshold = TimeSpan.FromSeconds(60);
    // Desfase con el servidor que justifica resincronizar (la ventana de la firma es 60 s).
    public static readonly TimeSpan ServerSkewThreshold = TimeSpan.FromSeconds(30);
    // Correccion minima que se reporta al panel; las menores quedan solo en el log local.
    public static readonly TimeSpan ReportThreshold = TimeSpan.FromSeconds(5);
    // Entre dos resincronizaciones disparadas por un salto o por el servidor (evita un bucle si NTP no corrige).
    public static readonly TimeSpan MinTriggeredGap = TimeSpan.FromMinutes(10);
    // Salto durante los comandos que se pasa a ServerClock (por debajo es ruido de la medicion).
    public static readonly TimeSpan ServerClockJump = TimeSpan.FromSeconds(2);

    private sealed record Job(IReadOnlyList<string> Restored, string? Failed, int? ResyncExit, string? Zone);

    // Cada salto del reloj de Windows que ve el modulo (cambio manual o resincronizacion): el desfase de las firmas
    // con el servidor (ServerClock) se mueve lo mismo en sentido contrario y no queda viejo.
    public Action<TimeSpan>? OnClockJump { get; init; }

    private Task<Job>? _running;
    // Comandos en curso (segundo plano); el siguiente tick recoge el resultado.
    public Task? Running => _running;
    private string? _reason;
    private bool _firstCheck = true;
    private string? _zone;
    private long _nextCheck, _nextResync, _nextTriggered;
    private long _lastTimestamp;
    private DateTimeOffset _lastWall;
    private string? _lastFailure;

    public override async Task InitAsync(ModuleContext ctx)
    {
        await base.InitAsync(ctx);
        _nextCheck = _nextResync = _nextTriggered = ctx.Clock.GetTimestamp();
        Rebase();
        State = enabled ? "pending" : "dry_run";
    }

    public override async Task TickAsync(CancellationToken ct)
    {
        if (!enabled) return;
        var now = Context.Clock.GetTimestamp();
        if (_running is not null)
        {
            if (!_running.IsCompleted) return;
            var finished = _running;
            _running = null;
            // Lo que se movio el reloj de Windows mientras corrian los comandos (/config /update tambien puede ajustarlo).
            var moved = Jump();
            Rebase();
            if (moved.Duration() >= ServerClockJump) OnClockJump?.Invoke(moved);
            await CompleteAsync(finished, moved, ct);
            return;
        }
        // Salto de la hora de Windows respecto del reloj monotonico: alguien cambio la hora (o el equipo desperto).
        var jump = Jump();
        Rebase();
        if (jump.Duration() > JumpThreshold)
        {
            OnClockJump?.Invoke(jump);
            if (now >= _nextTriggered) Trigger("hora_cambiada");
        }
        if (serverOffset?.Invoke() is { } offset && offset.Duration() > ServerSkewThreshold && now >= _nextTriggered)
            Trigger("desfase_servidor");
        if (now < _nextCheck && now < _nextResync && _reason is null) return;
        if (deferResync?.Invoke() == true) return;
        var resync = _reason is not null || now >= _nextResync;
        _nextCheck = now + Ticks(CheckInterval);
        Rebase();
        State = resync ? "syncing" : State;
        var zone = _zone;
        var first = _firstCheck;
        _running = Task.Run(() => RunAsync(resync, zone, first, Context.StoppingToken), Context.StoppingToken);
    }

    private void Trigger(string reason)
    {
        _reason ??= reason;
        _nextTriggered = Context.Clock.GetTimestamp() + Ticks(MinTriggeredGap);
    }

    private async Task<Job> RunAsync(bool resync, string? zone, bool first, CancellationToken ct)
    {
        var settings = await system.ReadAsync(ct);
        var commands = TimeSyncPlan.Restore(settings).ToList();
        // Zona: con la automatica encendida Windows la elige (no se puede cambiar a mano). Si la apagaron y la zona
        // cambio, se vuelve a la ultima conocida. En el primer chequeo se toma la actual como conocida.
        var zoneManual = settings.TzAutoUpdateStart is not (TimeSyncPlan.ServiceAuto or TimeSyncPlan.ServiceDemand);
        if (!first && zoneManual && zone is not null && settings.TimeZoneId is { } current && current != zone)
            commands.Add(TimeSyncPlan.RestoreZone(zone));
        var restored = new List<string>();
        string? failed = null;
        foreach (var command in commands)
        {
            var result = await system.RunAsync(command, ct);
            // sc start de un servicio ya en marcha devuelve 1056: no es un fallo.
            if (result.ExitCode == 0 || (command.Item == "servicio_detenido" && result.ExitCode == 1056)) restored.Add(command.Item);
            else failed ??= $"{command.Item}_salida_{result.ExitCode}";
        }
        var nextZone = commands.Any(c => c.Item == "zona_horaria") ? zone : settings.TimeZoneId ?? zone;
        if (!resync && restored.Count == 0) return new([], failed, null, nextZone);
        return new(restored, failed, (await system.RunAsync(TimeSyncPlan.Resync, ct)).ExitCode, nextZone);
    }

    // moved: correccion aplicada = cuanto se movio el reloj de Windows menos lo que paso de verdad (monotonico).
    private async Task CompleteAsync(Task<Job> finished, TimeSpan moved, CancellationToken ct)
    {
        var now = Context.Clock.GetTimestamp();
        var reason = _reason;
        _reason = null;
        var first = _firstCheck;
        _firstCheck = false;
        if (!finished.IsCompletedSuccessfully)
        {
            if (finished.IsCanceled) return;
            var code = $"error_{finished.Exception?.InnerException?.GetType().Name ?? "desconocido"}";
            State = "failed";
            _nextResync = now + Ticks(RetryAfterFailure);
            await ReportOnceAsync("time_settings_failed", code, ct);
            return;
        }
        var job = finished.Result;
        _zone = job.Zone;
        if (job.Restored.Count > 0)
            await ReportAsync(first ? "time_settings_applied" : "time_settings_restored", ct,
                first ? LogEntryLevel.Info : LogEntryLevel.Warn, Detail(job.Restored));
        if (job.Failed is not null)
        {
            State = "degraded";
            await ReportOnceAsync("time_settings_failed", job.Failed, ct);
        }
        // Solo se reviso la configuracion (estaba bien): el estado de la ultima resincronizacion se mantiene.
        if (job.ResyncExit is not { } exit) return;
        if (exit != 0)
        {
            State = "resync_failed";
            _nextResync = now + Ticks(RetryAfterFailure);
            await ReportOnceAsync("time_resync_failed", $"w32tm_salida_{exit}{(reason is null ? "" : "." + reason)}", ct);
            return;
        }
        _nextResync = now + Ticks(ResyncInterval);
        _lastFailure = null;
        State = job.Failed is null ? "applied" : "degraded";
        var seconds = (long)Math.Round(moved.TotalSeconds);
        if (moved.Duration() >= ReportThreshold)
            await ReportAsync("time_resynced", ct, LogEntryLevel.Info, $"desfase_{seconds}s{(reason is null ? "" : "." + reason)}");
        else Context.Log($"{Name}: hora resincronizada (correccion {seconds} s{(reason is null ? "" : ", " + reason)})");
    }

    // Un fallo que se repite en cada intento (p.ej. sin salida a NTP) se reporta una vez, no cada 15 min.
    private async Task ReportOnceAsync(string code, string detail, CancellationToken ct)
    {
        var key = code + ":" + detail;
        if (_lastFailure == key) { Context.Log($"{Name}: {code} ({detail}) sigue"); return; }
        _lastFailure = key;
        await ReportAsync(code, ct, LogEntryLevel.Warn, detail);
    }

    private static string Detail(IEnumerable<string> items)
    {
        var text = string.Join(".", items.Distinct());
        return text.Length <= 80 ? text : text[..80];
    }

    private TimeSpan Jump() => Context.Clock.GetUtcNow() - _lastWall - Context.Clock.GetElapsedTime(_lastTimestamp);

    private void Rebase()
    {
        _lastWall = Context.Clock.GetUtcNow();
        _lastTimestamp = Context.Clock.GetTimestamp();
    }

    private long Ticks(TimeSpan span) => (long)(span.TotalSeconds * Context.Clock.TimestampFrequency);
}
