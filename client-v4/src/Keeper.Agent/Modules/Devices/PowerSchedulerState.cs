using Keeper.Shared.Protocol;

namespace Keeper.Agent.Modules.Devices;

/// <summary>
/// power-schedules.json: última lista recibida, la ocurrencia en curso, las ocurrencias ya resueltas (14 días) y los
/// resultados aún no entregados a la cola (se reintentan tras un corte: la cola deduplica por event_id).
/// ListReceivedAt: hora de Windows del último sync que trajo power_schedules; pasadas 24 h sin otro la lista se descarta.
/// </summary>
public sealed record PowerSchedulerState(IReadOnlyList<PowerScheduleEntry> Schedules, PowerScheduleActive? Active,
    IReadOnlyList<PowerScheduleDone> Done, IReadOnlyList<PowerScheduleRunReport> Unsent, DateTimeOffset? ListReceivedAt = null)
{
    public static PowerSchedulerState Empty { get; } = new([], null, [], []);
}

/// <summary>ReceivedAt: cuándo llegó esta versión (hora del calendario). La regla de «editada después de la hora» usa
/// Directive.UpdatedAt (servidor); ReceivedAt solo evita reportar una ocurrencia cuya ventana ya había cerrado cuando la
/// versión llegó (primer sync, agente recién actualizado): el agente nunca tuvo ocasión de atenderla.</summary>
public sealed record PowerScheduleEntry(PowerScheduleDirective Directive, DateTimeOffset ReceivedAt);

/// <summary>
/// Ocurrencia en curso. Deadline en hora de Windows (la sesión cuenta con su reloj). Started = ya se llamó (o se iba a
/// llamar) a shutdown.exe: nunca se repite; tras un corte se reporta Outcome. Delivered = alguna sesión verificada recibió
/// el aviso (sin eso, al vencer no se apaga: not_needed/no_session_ui).
/// </summary>
public sealed record PowerScheduleActive(Guid RunId, Guid ScheduleId, long Version, DateTimeOffset Occurrence,
    PowerScheduleDirectiveAction Action, DateTimeOffset? Deadline, bool Postponed, bool Started,
    PowerScheduleRunReportOutcome? Outcome = null, bool Delivered = false);

public sealed record PowerScheduleDone(Guid ScheduleId, DateTimeOffset Occurrence);
