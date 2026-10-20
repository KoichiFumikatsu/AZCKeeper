using Keeper.Agent.Transport;
using Keeper.Shared.Contracts;
using Keeper.Shared.Protocol;
using Keeper.Shared.Time;

namespace Keeper.Agent.Modules.Maintenance;

// Mantiene la hora de Windows correcta (la necesita la firma de cada peticion: el servidor admite +-60 s).
// Al arrancar y cada 6 h: W32Time automatico y en marcha, NTP time.windows.com (o el del dominio, sin tocarlo) y
// w32tm /resync. Cada 2 min revisa la configuracion; si alguien la cambio la repone (time_settings_restored). Si el
// reloj de Windows salta respecto del reloj monotonico (alguien cambio la hora) o el servidor dice que la hora esta
// corrida, resincroniza en el acto (time_resynced con los segundos corregidos).
// Los comandos corren en segundo plano y se sondean en los ticks (como RestorePoint): ModuleHost ejecuta los ticks en
// serie. No resincroniza mientras haya una cuenta regresiva de reinicio/apagado en pantalla (deferResync): un salto
// de hora la adelantaria o la daria por vencida. El PIN local usa el reloj monotonico y no se ve afectado.
//
// Zona horaria libre (decision 2026-10-20, agente 4.0.28): los abogados ponen la zona de la firma extranjera para la que
// trabajan. TimeSync ya no exige la zona automatica (tzautoupdate) ni devuelve la zona a la anterior; si la zona
// automatica esta encendida tampoco la apaga (es eleccion del usuario). Keeper no depende de la zona del PC: las firmas
// usan UTC y el horario laboral usa la zona del horario de la politica. Cambiar la zona no mueve la hora UTC, asi que
// no dispara el detector de saltos; cambiar la hora si (y con time_change_lock solo SYSTEM y W32Time pueden hacerlo).
//
// Hora del servidor de Keeper como ultimo recurso (decision 2026-10-16): si W32Time ya intento (fallo o no corrigio:
// sin salida a NTP, desfase mayor que MaxPhaseCorrection) y dos mediciones autenticadas y consistentes del servidor
// (ServerClock.ConsistentOffset, tomadas despues de ese intento) dicen que la hora esta corrida mas de 2 min, se pone
// la hora del sistema con SetSystemTime (SeSystemtimePrivilege), el desfase interno vuelve a 0 y se resincroniza
// W32Time (time_set_from_server). Nunca en dominio: alli manda el DC.
public sealed class TimeSync(ITimeSystem system, bool enabled, ServerClock? server = null, Func<bool>? deferResync = null)
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
    // Desfase con el servidor que justifica poner la hora del sistema con la suya (si W32Time no lo resolvio).
    public static readonly TimeSpan SetFromServerThreshold = TimeSpan.FromMinutes(2);

    private sealed record Job(IReadOnlyList<string> Restored, string? Failed, int? ResyncExit, bool? Domain);

    private Task<Job>? _running;
    // Comandos en curso (segundo plano); el siguiente tick recoge el resultado.
    public Task? Running => _running;
    private string? _reason;
    private bool _firstCheck = true;
    private long _nextCheck, _nextResync, _nextTriggered;
    private long _lastTimestamp;
    private DateTimeOffset _lastWall;
    private string? _lastFailure;
    // Poner la hora con la del servidor: solo tras un intento de W32Time, con muestras posteriores a ese intento y
    // fuera de dominio (se sabe al leer la configuracion).
    private bool _resyncAttempted;
    private long _samplesAfter;
    private bool? _domain;
    private long _nextSet;
    private bool _setting;

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
            if (_setting)
            {
                _setting = false;
                await CompleteSetAsync(finished, moved, ct);
                return;
            }
            if (moved.Duration() >= ServerClockJump) server?.ShiftLocal(moved);
            await CompleteAsync(finished, moved, ct);
            return;
        }
        // Salto de la hora de Windows respecto del reloj monotonico: alguien cambio la hora (o el equipo desperto).
        var jump = Jump();
        Rebase();
        if (jump.Duration() > JumpThreshold)
        {
            server?.ShiftLocal(jump);
            if (now >= _nextTriggered) Trigger("hora_cambiada");
        }
        if (server?.Confirmed is { } offset && offset.Duration() > ServerSkewThreshold && now >= _nextTriggered)
            Trigger("desfase_servidor");
        if (server is not null && _resyncAttempted && _domain == false && now >= _nextSet &&
            server.ConsistentOffset(_samplesAfter) is { } measured && measured.Duration() > SetFromServerThreshold &&
            deferResync?.Invoke() != true)
        {
            _nextSet = now + Ticks(MinTriggeredGap);
            _setting = true;
            Rebase();
            State = "setting_from_server";
            _running = Task.Run(() => SetFromServerAsync(measured, Context.StoppingToken), Context.StoppingToken);
            return;
        }
        if (now < _nextCheck && now < _nextResync && _reason is null) return;
        if (deferResync?.Invoke() == true) return;
        var resync = _reason is not null || now >= _nextResync;
        _nextCheck = now + Ticks(CheckInterval);
        Rebase();
        State = resync ? "syncing" : State;
        _running = Task.Run(() => RunAsync(resync, Context.StoppingToken), Context.StoppingToken);
    }

    private void Trigger(string reason)
    {
        _reason ??= reason;
        _nextTriggered = Context.Clock.GetTimestamp() + Ticks(MinTriggeredGap);
    }

    private async Task<Job> RunAsync(bool resync, CancellationToken ct)
    {
        var settings = await system.ReadAsync(ct);
        var commands = TimeSyncPlan.Restore(settings);
        var restored = new List<string>();
        string? failed = null;
        foreach (var command in commands)
        {
            var result = await system.RunAsync(command, ct);
            // sc start de un servicio ya en marcha devuelve 1056: no es un fallo.
            if (result.ExitCode == 0 || (command.Item == "servicio_detenido" && result.ExitCode == 1056)) restored.Add(command.Item);
            else failed ??= $"{command.Item}_salida_{result.ExitCode}";
        }
        if (!resync && restored.Count == 0) return new([], failed, null, settings.DomainJoined);
        return new(restored, failed, (await system.RunAsync(TimeSyncPlan.Resync, ct)).ExitCode, settings.DomainJoined);
    }

    private async Task<Job> SetFromServerAsync(TimeSpan offset, CancellationToken ct)
    {
        system.SetSystemTime(Context.Clock.GetUtcNow() + offset);
        // W32Time toma la hora nueva como base (si NTP responde, la afina).
        var exit = (await system.RunAsync(TimeSyncPlan.Resync, ct)).ExitCode;
        return new([], null, exit, false);
    }

    private async Task CompleteSetAsync(Task<Job> finished, TimeSpan moved, CancellationToken ct)
    {
        if (!finished.IsCompletedSuccessfully)
        {
            if (finished.IsCanceled) return;
            State = "set_failed";
            await ReportOnceAsync("time_set_from_server_failed", $"error_{finished.Exception?.InnerException?.GetType().Name ?? "desconocido"}", ct);
            return;
        }
        // La hora de Windows ya es la del servidor: el desfase de las firmas y de los eventos vuelve a 0.
        server!.ResetAfterSystemSet();
        _samplesAfter = server.SampleCount;
        _lastFailure = null;
        State = "applied";
        Context.Log($"{Name}: w32tm /resync tras poner la hora del servidor -> {finished.Result.ResyncExit}");
        await ReportAsync("time_set_from_server", ct, LogEntryLevel.Warn, $"desfase_{(long)Math.Round(moved.TotalSeconds)}s");
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
        _domain = job.Domain ?? _domain;
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
        // Desde aqui cuentan las mediciones del servidor para decidir si W32Time no corrigio.
        _resyncAttempted = true;
        _samplesAfter = server?.SampleCount ?? 0;
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
