using System.Text.Json;
using Keeper.Agent.Storage;
using Keeper.Shared.Contracts;
using Keeper.Shared.Protocol;
using Outcome = Keeper.Shared.Protocol.PowerScheduleRunReportOutcome;

namespace Keeper.Agent.Modules.Devices;

/// <summary>
/// Apagado/reinicio programado (4.0.30). A la hora de cada programación (o al despertar dentro de window_minutes): sin
/// sesiones o todas inactivas ≥ idle_minutes ejecuta; si alguna está en uso (o no se sabe) avisa con cuenta regresiva,
/// «Posponer» una sola vez y «ahora». Cada ocurrencia termina en un resultado que viaja por la cola como power_schedule_runs.
/// </summary>
public sealed class PowerScheduler(string statePath, IDeviceActions actions, Func<IReadOnlyList<long?>> idleSeconds,
    Func<PowerNotice?> manualNotice, Func<DateTimeOffset> bootTimeUtc) : ModuleBase
{
    public static readonly TimeSpan EvaluateEvery = TimeSpan.FromSeconds(30);
    public static readonly TimeSpan CancelledNoticeFor = TimeSpan.FromSeconds(60);
    // Plazo vencido hace más de esto (equipo suspendido o agente detenido durante el aviso): no se apaga de golpe.
    public static readonly TimeSpan NoticeGrace = TimeSpan.FromMinutes(2);
    public static readonly TimeSpan SkewThreshold = TimeSpan.FromMinutes(5);
    public static readonly TimeSpan DoneRetention = TimeSpan.FromDays(14);
    // Recién encendido, el SessionSupervisor aún no ve a la persona que inicia sesión: «sin sesiones» cuenta como en uso.
    public static readonly TimeSpan BootGrace = TimeSpan.FromMinutes(2);
    private readonly SemaphoreSlim _gate = new(1, 1);
    private readonly object _choiceLock = new();
    private PowerChoice? _choice;
    private PowerSchedulerState _state = PowerSchedulerState.Empty;
    private (PowerNotice Notice, DateTimeOffset Until)? _cancelled;
    private DateTimeOffset? _lastEvaluation;
    private volatile PowerNotice? _notice;
    private bool _flushFailing;
    private bool _saveFailing;

    public override string Name => "PowerScheduler";
    /// <summary>Aviso para las sesiones; null mientras una ocurrencia pospuesta aún no está a notice_minutes del plazo.</summary>
    public PowerNotice? PendingNotice => _notice;
    /// <summary>Hay una cuenta regresiva en curso (visible o pospuesta): TimeSync no debe mover la hora.</summary>
    public bool CountdownActive => _state.Active is { Started: false, Deadline: not null };
    // Hora del servidor menos hora de Windows (ServerClock.Confirmed). Con más de 5 min de desfase las ocurrencias se
    // calculan con la hora del servidor; los plazos del aviso siguen en hora de Windows.
    public Func<TimeSpan>? ServerOffset { get; init; }

    public static DateTimeOffset SystemBootTimeUtc() => DateTimeOffset.UtcNow - TimeSpan.FromMilliseconds(Environment.TickCount64);

    /// <summary>Aviso para las sesiones: manual vigente > programado > manual «TI canceló» (60 s que no deben tapar una
    /// cuenta regresiva programada en curso).</summary>
    public static PowerNotice? SelectNotice(PowerNotice? manual, PowerNotice? scheduled) =>
        manual is { Cancelled: false } ? manual : scheduled ?? manual;

    public override async Task InitAsync(ModuleContext ctx)
    {
        await base.InitAsync(ctx);
        _state = await LoadAsync(ctx.StoppingToken);
        // Corte con shutdown.exe ya iniciado: no se repite, se reporta lo que se iba a reportar.
        if (_state.Active is { Started: true } started) await FinishAsync(started, started.Outcome ?? Outcome.ExecutedIdle, null, ctx.StoppingToken);
        else await FlushAsync(ctx.StoppingToken);
        RefreshNotice(ctx.Clock.GetUtcNow());
    }

    /// <summary>Lista resuelta para este equipo en cada sync (null: el servidor no la envía, no cambia nada).</summary>
    public async Task OfferAsync(IReadOnlyList<PowerScheduleDirective>? schedules, CancellationToken ct)
    {
        if (schedules is null) return;
        await _gate.WaitAsync(ct);
        var local = Context.Clock.GetUtcNow();
        try { await ApplyOfferAsync(schedules, local, ct); }
        catch (OperationCanceledException) when (ct.IsCancellationRequested) { throw; }
        // Llamado desde OnResponse del sync: un fallo local no debe tumbar el resto de la respuesta.
        catch (Exception ex) { Context.Log($"apagado programado warn: no se pudo aplicar la lista ({ex.GetType().Name}: {ex.Message})"); }
        finally { RefreshNotice(local); _gate.Release(); }
    }

    private async Task ApplyOfferAsync(IReadOnlyList<PowerScheduleDirective> schedules, DateTimeOffset local, CancellationToken ct)
    {
        var now = CalendarNow(local);
        var previous = _state.Schedules.ToDictionary(e => e.Directive.Id);
        var entries = schedules.DistinctBy(s => s.Id).Select(s => previous.TryGetValue(s.Id, out var old) && old.Directive.Version == s.Version
            ? old with { Directive = s } : new PowerScheduleEntry(s, now)).ToList();
        _state = _state with { Schedules = entries };
        // Pausada, editada o borrada con la ocurrencia en curso: se cancela (si el aviso estaba a la vista, «TI canceló»).
        if (_state.Active is { Started: false } active && !entries.Any(e => e.Directive.Id == active.ScheduleId && e.Directive.Version == active.Version))
        {
            if (previous.TryGetValue(active.ScheduleId, out var old) && Visible(active, old.Directive, local) is { } shown)
                _cancelled = (new PowerNotice(shown.Action, shown.Deadline, Cancelled: true), local + CancelledNoticeFor);
            Context.Log($"apagado programado {active.ScheduleId}: cancelado por IT durante la ocurrencia {active.Occurrence:O}");
            Record(active.ScheduleId, active.Occurrence, Outcome.CancelledByIt, null);
            _state = _state with { Active = null };
        }
        _lastEvaluation = null;
        await CommitAsync(ct);
    }

    /// <summary>Elección de una sesión; se aplica en el siguiente tick (cada segundo con aviso activo).</summary>
    public void Choose(PowerChoice choice) { lock (_choiceLock) _choice = choice; }

    public override async Task TickAsync(CancellationToken ct)
    {
        ct.ThrowIfCancellationRequested();
        var local = Context.Clock.GetUtcNow();
        var busy = _state.Active is not null || _cancelled is not null;
        var evaluate = busy || _lastEvaluation is not { } last || local < last || local - last >= EvaluateEvery;
        if (!evaluate && _state.Unsent.Count == 0) return;
        await _gate.WaitAsync(ct);
        try
        {
            // Resultados que la cola no aceptó antes: se reintentan en cada tick.
            if (_state.Unsent.Count > 0) await FlushAsync(ct);
            if (!evaluate) return;
            _lastEvaluation = local;
            PowerChoice? choice;
            lock (_choiceLock) { choice = _choice; _choice = null; }
            if (_state.Active is { } active) await ContinueAsync(active, choice, local, ct);
            else await StartDueAsync(local, ct);
        }
        finally { RefreshNotice(local); _gate.Release(); }
    }

    private async Task StartDueAsync(DateTimeOffset local, CancellationToken ct)
    {
        var now = CalendarNow(local);
        var boot = bootTimeUtc() + (now - local);
        var due = new List<(PowerScheduleDirective Schedule, DateTimeOffset Occurrence)>();
        var changed = false;
        foreach (var entry in _state.Schedules)
        {
            var s = entry.Directive;
            // Anterior a la última edición de TI (updated_at del servidor): no apaga de golpe ni se reporta. Si esta versión
            // llegó con la ventana ya cerrada (primer sync, agente recién actualizado) el agente nunca pudo atenderla.
            if (PowerScheduleCalendar.Latest(s, now) is not { } occurrence || occurrence < s.UpdatedAt
                || (occurrence < entry.ReceivedAt && !PowerScheduleCalendar.InWindow(s, occurrence, entry.ReceivedAt))
                || _state.Done.Contains(new PowerScheduleDone(s.Id, occurrence))) continue;
            if (boot > occurrence) { Record(s.Id, occurrence, Outcome.NotNeeded, null); changed = true; }
            else if (!PowerScheduleCalendar.InWindow(s, occurrence, now)) { Record(s.Id, occurrence, Outcome.SkippedSuspended, null); changed = true; }
            else due.Add((s, occurrence));
        }
        if (due.Count > 0)
        {
            // Varias en el mismo minuto: una sola ejecución, el reinicio gana; las demás quedan como reemplazadas.
            var minute = due.Min(d => d.Occurrence.UtcTicks / TimeSpan.TicksPerMinute);
            var group = due.Where(d => d.Occurrence.UtcTicks / TimeSpan.TicksPerMinute == minute)
                .OrderByDescending(d => d.Schedule.Action == PowerScheduleDirectiveAction.Restart).ToList();
            foreach (var (other, at) in group.Skip(1)) Record(other.Id, at, Outcome.Replaced, "same_minute");
            var (s, occurrence) = group[0];
            var active = new PowerScheduleActive(Guid.NewGuid(), s.Id, s.Version, occurrence, s.Action, null, false, false);
            if (manualNotice() is { Cancelled: false }) Record(s.Id, occurrence, Outcome.Replaced, "manual_command");
            else if (Idle(s, now - boot)) { await ExecuteAsync(active, Outcome.ExecutedIdle, ct); return; }
            else
            {
                _state = _state with { Active = active with { Deadline = local.AddMinutes(s.NoticeMinutes) } };
                Context.Log($"apagado programado {s.Id}: equipo en uso, aviso de {s.NoticeMinutes} min");
            }
            changed = true;
        }
        if (changed) await CommitAsync(ct);
    }

    private async Task ContinueAsync(PowerScheduleActive active, PowerChoice? choice, DateTimeOffset local, CancellationToken ct)
    {
        var s = Find(active.ScheduleId);
        if (active.Started || s is null)
        {
            await FinishAsync(active, active.Started ? active.Outcome ?? Outcome.ExecutedIdle : Outcome.CancelledByIt, null, ct);
            return;
        }
        if (manualNotice() is { Cancelled: false })
        {
            await FinishAsync(active, Outcome.Replaced, "manual_command", ct);
            return;
        }
        if (choice is { } c && c.RunId == active.RunId)
        {
            if (c.Kind == PowerChoiceKind.Now) { await ExecuteAsync(active, Outcome.ExecutedByPerson, ct); return; }
            // Una sola vez: la sesión vuelve a ofrecer el botón tras reiniciarse, aquí se ignora.
            if (!active.Postponed && s.PostponeMinutes is { } minutes && minutes > 0)
            {
                active = active with { Postponed = true, Deadline = local.AddMinutes(minutes) };
                _state = _state with { Active = active };
                Record(active.ScheduleId, active.Occurrence, Outcome.Postponed, null, done: false);
                await CommitAsync(ct);
            }
        }
        if (active.Deadline is not { } deadline || local < deadline) return;
        if (local - deadline > NoticeGrace)
        {
            var allowed = TimeSpan.FromMinutes(s.WindowMinutes + (active.Postponed ? s.PostponeMinutes ?? 0 : 0));
            if (CalendarNow(local) - active.Occurrence > allowed) await FinishAsync(active, Outcome.SkippedSuspended, null, ct);
            else
            {
                // Despertó dentro de la ventana: la persona vuelve a ver la cuenta regresiva completa.
                _state = _state with { Active = active with { Deadline = local.AddMinutes(s.NoticeMinutes) } };
                await SaveAsync(ct);
            }
            return;
        }
        await ExecuteAsync(active, Outcome.ExecutedAfterNotice, ct);
    }

    // Started se guarda antes de llamar a shutdown.exe: un corte a mitad nunca repite la ejecución.
    private async Task ExecuteAsync(PowerScheduleActive active, Outcome outcome, CancellationToken ct)
    {
        active = active with { Started = true, Outcome = outcome };
        _state = _state with { Active = active };
        await SaveAsync(ct);
        string? failure = null;
        try { await actions.ExecuteAsync(active.Action == PowerScheduleDirectiveAction.Restart ? DeviceAction.Restart : DeviceAction.Shutdown, ct); }
        catch (OperationCanceledException) when (ct.IsCancellationRequested) { throw; }
        catch (Exception ex) { failure = FailureCode(ex); }
        if (failure is null) Context.Log($"apagado programado {active.ScheduleId}: {active.Action} ejecutado ({outcome})");
        await FinishAsync(active, failure is null ? outcome : Outcome.Unsupported, failure, ct);
        if (failure is not null) await ReportAsync("power_schedule_failed", ct, LogEntryLevel.Warn, failure);
    }

    // NotSupportedException con código (device_actions_dry_run): ese código; un fallo real: action_failed:<Tipo>.
    private static string FailureCode(Exception ex) =>
        ex is NotSupportedException && ex.Message.Length is > 0 and <= 80 && ex.Message.All(ch => char.IsAsciiLetterOrDigit(ch) || ch == '_')
            && char.IsAsciiLetter(ex.Message[0]) ? ex.Message : $"action_failed:{ex.GetType().Name}";

    private Task FinishAsync(PowerScheduleActive active, Outcome outcome, string? detail, CancellationToken ct)
    {
        Record(active.ScheduleId, active.Occurrence, outcome, detail);
        _state = _state with { Active = null };
        return CommitAsync(ct);
    }

    private void Record(Guid scheduleId, DateTimeOffset occurrence, Outcome outcome, string? detail, bool done = true)
    {
        var run = new PowerScheduleRunReport
        {
            EventId = Guid.NewGuid(), ScheduleId = scheduleId, OccurrenceAt = occurrence, Outcome = outcome,
            Detail = detail is { Length: > 100 } ? detail[..100] : detail
        };
        _state = _state with
        {
            Unsent = [.. _state.Unsent, run],
            Done = done ? [.. _state.Done, new PowerScheduleDone(scheduleId, occurrence)] : _state.Done
        };
    }

    // Lista vacía = de verdad no hay sesiones de usuario (el supervisor da null por cada sesión sin par verificado),
    // salvo en los primeros 2 min tras encender.
    private bool Idle(PowerScheduleDirective s, TimeSpan sinceBoot)
    {
        var idle = idleSeconds();
        if (idle.Count == 0 && sinceBoot < BootGrace) return false;
        return idle.All(x => x is { } seconds && seconds >= s.IdleMinutes * 60);
    }

    private PowerScheduleDirective? Find(Guid id) => _state.Schedules.FirstOrDefault(e => e.Directive.Id == id)?.Directive;

    private DateTimeOffset CalendarNow(DateTimeOffset local)
    {
        var offset = ServerOffset?.Invoke() ?? TimeSpan.Zero;
        return offset.Duration() > SkewThreshold ? local + offset : local;
    }

    private static PowerNotice? Visible(PowerScheduleActive active, PowerScheduleDirective s, DateTimeOffset local) =>
        active is { Started: false, Deadline: { } deadline } && (!active.Postponed || deadline - local <= TimeSpan.FromMinutes(s.NoticeMinutes))
            ? new PowerNotice(active.Action == PowerScheduleDirectiveAction.Restart ? PowerNoticeAction.Restart : PowerNoticeAction.Shutdown,
                deadline, false, s.Message, active.Postponed ? null : s.PostponeMinutes, active.Postponed, active.RunId)
            : null;

    private void RefreshNotice(DateTimeOffset local)
    {
        var notice = _state.Active is { } active && Find(active.ScheduleId) is { } s ? Visible(active, s, local) : null;
        if (_cancelled is { } c && (notice is not null || local >= c.Until)) _cancelled = null;
        _notice = notice ?? _cancelled?.Notice;
    }

    private async Task CommitAsync(CancellationToken ct)
    {
        await SaveAsync(ct);
        await FlushAsync(ct);
    }

    // Si la cola falla, los resultados quedan en Unsent y se reintentan en cada tick (la cola deduplica por event_id).
    private async Task FlushAsync(CancellationToken ct)
    {
        if (_state.Unsent.Count == 0) return;
        try
        {
            foreach (var run in _state.Unsent) await Context.Outbox.EnqueueAsync(run, ct);
        }
        catch (OperationCanceledException) when (ct.IsCancellationRequested) { throw; }
        catch (Exception ex)
        {
            if (_flushFailing) return;
            _flushFailing = true;
            Context.Log($"apagado programado warn: resultados sin encolar ({ex.GetType().Name}: {ex.Message}); se reintentan");
            try { await ReportAsync("power_schedule_enqueue_failed", ct, LogEntryLevel.Warn, ex.GetType().Name); }
            catch (Exception report) when (report is not OperationCanceledException || !ct.IsCancellationRequested) { }
            return;
        }
        _flushFailing = false;
        _state = _state with { Unsent = [] };
        await SaveAsync(ct);
    }

    // Un fallo de escritura no corta la ejecución (el apagado debe ocurrir igual); se registra una vez por racha.
    private async Task SaveAsync(CancellationToken ct)
    {
        var horizon = CalendarNow(Context.Clock.GetUtcNow()) - DoneRetention;
        if (_state.Done.Any(d => d.Occurrence < horizon)) _state = _state with { Done = _state.Done.Where(d => d.Occurrence >= horizon).ToList() };
        try
        {
            await AtomicFile.WriteAsync(statePath, JsonSerializer.SerializeToUtf8Bytes(_state, ProtocolJson.Options), ct);
            _saveFailing = false;
        }
        catch (Exception ex) when (ex is IOException or UnauthorizedAccessException)
        {
            if (!_saveFailing) Context.Log($"apagado programado warn: power-schedules.json no se pudo guardar ({ex.GetType().Name}: {ex.Message})");
            _saveFailing = true;
        }
    }

    // Un archivo ilegible se aparta (no se borra) y se empieza vacío: la lista vuelve con el siguiente sync.
    private async Task<PowerSchedulerState> LoadAsync(CancellationToken ct)
    {
        if (!File.Exists(statePath)) return PowerSchedulerState.Empty;
        try
        {
            return JsonSerializer.Deserialize<PowerSchedulerState>(await File.ReadAllBytesAsync(statePath, ct), ProtocolJson.Options)
                ?? PowerSchedulerState.Empty;
        }
        catch (JsonException)
        {
            try { File.Move(statePath, $"{statePath}.corrupt", overwrite: true); }
            catch (Exception ex) when (ex is IOException or UnauthorizedAccessException) { }
            Context.Log("apagado programado warn: power-schedules.json ilegible, apartado como .corrupt");
            return PowerSchedulerState.Empty;
        }
        catch (Exception ex) when (ex is IOException or UnauthorizedAccessException)
        {
            Context.Log($"apagado programado warn: power-schedules.json no se pudo leer ({ex.GetType().Name}: {ex.Message}); se empieza vacío");
            return PowerSchedulerState.Empty;
        }
    }
}
