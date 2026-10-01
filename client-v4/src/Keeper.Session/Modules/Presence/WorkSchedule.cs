using System.Globalization;

namespace Keeper.Session.Modules.Presence;

public enum TimeCategory { WorkHours, LunchTime, AfterHours }

public sealed class WorkSchedule(TimeZoneInfo zone, IReadOnlyList<Schedule>? schedules = null,
    TimeOnly? lunchStart = null, TimeOnly? lunchEnd = null)
{
    public TimeZoneInfo Zone { get; } = zone;
    public DateOnly Day(DateTimeOffset utc) => DateOnly.FromDateTime(TimeZoneInfo.ConvertTime(utc, Zone).DateTime);
    public TimeCategory GetTimeCategory(DateTimeOffset utc)
    {
        var local = TimeZoneInfo.ConvertTime(utc, Zone);
        var time = TimeOnly.FromDateTime(local.DateTime);
        if (schedules is null || schedules.Count == 0) return TimeCategory.AfterHours;
        foreach (var schedule in schedules)
        {
            var at = TimeZoneInfo.ConvertTime(utc, TimeZoneInfo.FindSystemTimeZoneById(schedule.Timezone));
            var t = TimeOnly.FromDateTime(at.DateTime);
            var today = Weekday(at.DayOfWeek);
            var yesterday = today == 1 ? 7 : today - 1;
            // Turno que empezo hoy, o turno nocturno que empezo ayer y aun no termina. Cada dia usa su
            // propia franja (sabado medio dia) si el horario la define.
            Hours? shift = null;
            if (schedule.Days.Contains(today) && HoursOf(schedule, today) is { } h &&
                (h.End <= h.Start ? t >= h.Start : t >= h.Start && t < h.End)) shift = h;
            else if (schedule.Days.Contains(yesterday) && HoursOf(schedule, yesterday) is { } y && y.End <= y.Start && t < y.End) shift = y;
            if (shift is not { } found) continue;
            // El almuerzo del propio turno manda sobre el pasado al constructor: cada horario de
            // K3 define el suyo (van de 11:30 a 16:00 segun la persona), asi que uno global
            // clasificaria mal a casi todos. Un dia con franja propia trae su almuerzo (o ninguno).
            var (from, to) = found.OwnDay ? (found.LunchStart, found.LunchEnd) : LunchOf(schedule) ?? (lunchStart, lunchEnd);
            return from is not null && to is not null && t >= from && t < to
                ? TimeCategory.LunchTime : TimeCategory.WorkHours;
        }
        return TimeCategory.AfterHours;
    }

    private readonly record struct Hours(TimeOnly Start, TimeOnly End, TimeOnly? LunchStart, TimeOnly? LunchEnd, bool OwnDay);

    private static int Weekday(DayOfWeek day) => day == DayOfWeek.Sunday ? 7 : (int)day;

    private static TimeOnly Parse(string value) => TimeOnly.ParseExact(value, "HH:mm", CultureInfo.InvariantCulture);

    /// <summary>
    /// Franja de un dia: la propia del dia (day_hours, agentes 4.0.13+) o la general del horario.
    /// </summary>
    private static Hours HoursOf(Schedule schedule, int weekday)
    {
        var own = schedule.DayHours?.FirstOrDefault(d => d.Weekday == weekday);
        if (own is not null)
        {
            TimeOnly? from = own.LunchStartLocal is null ? null : Parse(own.LunchStartLocal);
            TimeOnly? to = own.LunchEndLocal is null ? null : Parse(own.LunchEndLocal);
            return new Hours(Parse(own.StartLocal), Parse(own.EndLocal), from, to, true);
        }
        return new Hours(Parse(schedule.StartLocal), Parse(schedule.EndLocal), null, null, false);
    }

    public DateTimeOffset? ShiftStart(DateTimeOffset utc)
    {
        if (schedules is null) return null;
        foreach (var schedule in schedules)
        {
            var tz = TimeZoneInfo.FindSystemTimeZoneById(schedule.Timezone);
            var local = TimeZoneInfo.ConvertTime(utc, tz);
            var day = Weekday(local.DayOfWeek);
            if (!schedule.Days.Contains(day)) continue;
            var start = local.Date + HoursOf(schedule, day).Start.ToTimeSpan();
            if (!tz.IsInvalidTime(start)) return new DateTimeOffset(start, tz.GetUtcOffset(start));
        }
        return null;
    }

    /// <summary>
    /// Almuerzo declarado por el turno. Ambos nulos significa que ese horario no define
    /// almuerzo; un par a medias se ignora en vez de inventar el extremo que falta.
    /// </summary>
    private static (TimeOnly?, TimeOnly?)? LunchOf(Schedule schedule)
    {
        if (schedule.LunchStartLocal is null || schedule.LunchEndLocal is null) return null;
        if (!TimeOnly.TryParseExact(schedule.LunchStartLocal, "HH:mm", CultureInfo.InvariantCulture, DateTimeStyles.None, out var from)
            || !TimeOnly.TryParseExact(schedule.LunchEndLocal, "HH:mm", CultureInfo.InvariantCulture, DateTimeStyles.None, out var to)
            || from >= to)
            return null;
        return (from, to);
    }

    public static WorkSchedule FromPolicy(EffectivePolicy policy) => new(
        policy.Schedules.Count == 0 ? TimeZoneInfo.Local : TimeZoneInfo.FindSystemTimeZoneById(policy.Schedules[0].Timezone), policy.Schedules);
}
