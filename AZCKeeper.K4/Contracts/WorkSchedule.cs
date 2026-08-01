namespace AZCKeeper.K4.Contracts;

/// <summary>
/// Horario laboral que el servidor envía en el handshake. Divide el día en trabajo / almuerzo /
/// fuera-de-horario para que la productividad no cuente el almuerzo ni lo de después como trabajo
/// (equivalente al WorkSchedule de K3). Workdays: índices 0=Domingo..6=Sábado.
/// </summary>
public sealed record WorkSchedule(
    TimeSpan WorkStart, TimeSpan WorkEnd, TimeSpan LunchStart, TimeSpan LunchEnd, bool[] Workdays)
{
    public enum Band { Work, Lunch, After }

    public static WorkSchedule Default { get; } = new(
        new TimeSpan(8, 0, 0), new TimeSpan(18, 0, 0),
        new TimeSpan(12, 0, 0), new TimeSpan(13, 0, 0),
        new[] { false, true, true, true, true, true, false }); // Lun-Vie

    public bool IsWorkday(DateTime d)
    {
        int i = (int)d.DayOfWeek;
        return i >= 0 && i < Workdays.Length && Workdays[i];
    }

    /// <summary>Clasifica un instante en trabajo/almuerzo/fuera. Fuera de día laboral = After.</summary>
    public Band Classify(DateTime now)
    {
        if (!IsWorkday(now)) return Band.After;
        var t = now.TimeOfDay;
        if (LunchEnd > LunchStart && t >= LunchStart && t < LunchEnd) return Band.Lunch;
        if (t >= WorkStart && t < WorkEnd) return Band.Work;
        return Band.After;
    }

    private static TimeSpan ParseHm(string? s, TimeSpan fallback)
        => TimeSpan.TryParseExact(s ?? "", "hh\\:mm", null, out var ts) ? ts : fallback;

    /// <summary>Construye desde el JSON del handshake ({workStart,workEnd,lunchStart,lunchEnd,workdays}).</summary>
    public static WorkSchedule FromJson(System.Text.Json.JsonElement e)
    {
        if (e.ValueKind != System.Text.Json.JsonValueKind.Object) return Default;
        string? Str(string n) => e.TryGetProperty(n, out var v) && v.ValueKind == System.Text.Json.JsonValueKind.String ? v.GetString() : null;
        var days = Default.Workdays;
        if (e.TryGetProperty("workdays", out var wd) && wd.ValueKind == System.Text.Json.JsonValueKind.Array)
        {
            var arr = new bool[7];
            foreach (var d in wd.EnumerateArray()) if (d.TryGetInt32(out var i) && i >= 0 && i < 7) arr[i] = true;
            days = arr;
        }
        return new WorkSchedule(
            ParseHm(Str("workStart"), Default.WorkStart),
            ParseHm(Str("workEnd"), Default.WorkEnd),
            ParseHm(Str("lunchStart"), Default.LunchStart),
            ParseHm(Str("lunchEnd"), Default.LunchEnd),
            days);
    }
}
