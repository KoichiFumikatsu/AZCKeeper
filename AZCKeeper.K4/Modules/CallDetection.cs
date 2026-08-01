namespace AZCKeeper.K4.Modules;

/// <summary>
/// Detección de "está en llamada" por proceso/título. Compartida por WindowModule (marca el
/// episodio) y CallTrackingModule (acumula segundos), para no duplicar los keywords.
/// </summary>
public static class CallDetection
{
    private static readonly string[] DefaultKeywords = { "teams", "zoom", "meet", "webex", "skype", "discord" };

    public static bool IsCallApp(string? proc, string? title, IReadOnlyCollection<string>? extraKeywords = null)
    {
        var p = (proc ?? string.Empty).ToLowerInvariant();
        foreach (var k in DefaultKeywords) if (p.Contains(k)) return true;
        if (extraKeywords is not null) foreach (var k in extraKeywords) if (k.Length > 0 && p.Contains(k)) return true;
        var t = title?.ToLowerInvariant();
        if (t is not null && (t.Contains("llamada") || t.Contains("in call") || t.Contains("meeting"))) return true;
        return false;
    }
}
