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
            var start = TimeOnly.ParseExact(schedule.StartLocal, "HH:mm", CultureInfo.InvariantCulture);
            var end = TimeOnly.ParseExact(schedule.EndLocal, "HH:mm", CultureInfo.InvariantCulture);
            var t = TimeOnly.FromDateTime(at.DateTime);
            var overnight = end <= start;
            var anchor = overnight && t < end ? at.AddDays(-1) : at;
            var day = anchor.DayOfWeek == DayOfWeek.Sunday ? 7 : (int)anchor.DayOfWeek;
            if (!schedule.Days.Contains(day) || !(overnight ? t >= start || t < end : t >= start && t < end)) continue;
            // El almuerzo del propio turno manda sobre el pasado al constructor: cada horario de
            // K3 define el suyo (van de 11:30 a 16:00 segun la persona), asi que uno global
            // clasificaria mal a casi todos.
            var (from, to) = LunchOf(schedule) ?? (lunchStart, lunchEnd);
            return from is not null && to is not null && time >= from && time < to
                ? TimeCategory.LunchTime : TimeCategory.WorkHours;
        }
        return TimeCategory.AfterHours;
    }

    public DateTimeOffset? ShiftStart(DateTimeOffset utc)
    {
        if (schedules is null) return null;
        foreach (var schedule in schedules)
        {
            var tz = TimeZoneInfo.FindSystemTimeZoneById(schedule.Timezone);
            var local = TimeZoneInfo.ConvertTime(utc, tz);
            var day = local.DayOfWeek == DayOfWeek.Sunday ? 7 : (int)local.DayOfWeek;
            if (!schedule.Days.Contains(day)) continue;
            var start = local.Date + TimeOnly.ParseExact(schedule.StartLocal, "HH:mm", CultureInfo.InvariantCulture).ToTimeSpan();
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
