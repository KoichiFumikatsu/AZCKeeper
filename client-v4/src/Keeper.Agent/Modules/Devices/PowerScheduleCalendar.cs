using System.Globalization;
using Keeper.Shared.Protocol;

namespace Keeper.Agent.Modules.Devices;

/// <summary>Ocurrencias de una programación en la zona horaria de la empresa (IANA, igual que PolicyCoordinator).</summary>
public static class PowerScheduleCalendar
{
    public static DateTimeOffset? Latest(PowerScheduleDirective s, DateTimeOffset nowUtc)
    {
        TimeZoneInfo zone;
        try { zone = TimeZoneInfo.FindSystemTimeZoneById(s.Timezone); }
        catch (Exception ex) when (ex is TimeZoneNotFoundException or InvalidTimeZoneException) { return null; }
        if (!TimeOnly.TryParseExact(s.TimeLocal, "HH:mm", CultureInfo.InvariantCulture, DateTimeStyles.None, out var time)) { return null; }
        var today = DateOnly.FromDateTime(TimeZoneInfo.ConvertTime(nowUtc, zone).DateTime);
        // Se mira hoy y los 7 días anteriores: basta para semanal y para "una vez" reciente.
        for (var back = 0; back <= 7; back++)
        {
            var day = today.AddDays(-back);
            if (s.Recurrence == PowerScheduleDirectiveRecurrence.Once ? day != s.RunOn : !MatchesWeekly(s, day)) continue;
            var local = day.ToDateTime(time, DateTimeKind.Unspecified);
            if (zone.IsInvalidTime(local)) local = local.AddHours(1); // salto de horario de verano: la hora no existe
            var utc = new DateTimeOffset(TimeZoneInfo.ConvertTimeToUtc(local, zone), TimeSpan.Zero);
            if (utc <= nowUtc) return utc;
        }
        return null;
    }

    public static bool InWindow(PowerScheduleDirective s, DateTimeOffset occurrence, DateTimeOffset nowUtc) =>
        nowUtc >= occurrence && nowUtc - occurrence <= TimeSpan.FromMinutes(s.WindowMinutes);

    private static bool MatchesWeekly(PowerScheduleDirective s, DateOnly day)
    {
        if (day < s.ValidFrom || (s.ValidUntil is { } until && day > until)) return false;
        var iso = day.DayOfWeek == DayOfWeek.Sunday ? 7 : (long)day.DayOfWeek;
        return s.Days?.Contains(iso) == true;
    }
}
