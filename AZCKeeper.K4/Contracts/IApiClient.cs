namespace AZCKeeper.K4.Contracts;

/// <summary>
/// Lo que un módulo necesita del canal con el servidor, sin conocer HttpClient ni el
/// core. Un módulo recibe esta interfaz por constructor y reporta lo suyo; no sabe de
/// otros módulos ni de la sesión. Los métodos devuelven éxito/fracaso, nunca lanzan.
/// </summary>
public interface IApiClient
{
    Task<bool> SendEpisodesAsync(IReadOnlyList<EpisodeDto> episodes);
    Task<bool> SendActivityDayAsync(ActivityDayDto day);
    Task<bool> ReportModuleStateAsync(IReadOnlyList<ModuleStateDto> modules);
    Task<IReadOnlyList<CommandDto>> PollCommandsAsync();
    Task<bool> ReportCommandResultAsync(long commandId, string status, object? result);
    Task<bool> SendScreenshotMetaAsync(ScreenshotMetaDto meta);
}

public sealed record EpisodeDto(
    string StartLocalTime, string EndLocalTime, int DurationSeconds,
    string ProcessName, string? WindowTitle, bool IsCallApp);

public sealed record ActivityDayDto(
    string DayDate, int TzOffsetMinutes, bool IsWorkday,
    bool ActivityTracked, bool WindowTracked, bool CallTracked,
    int ActiveSeconds, int IdleSeconds, int CallSeconds,
    int WorkActiveSeconds, int WorkIdleSeconds,
    int LunchActiveSeconds, int LunchIdleSeconds,
    int AfterHoursActiveSeconds, int AfterHoursIdleSeconds,
    string? FirstEventAt, string? LastEventAt);

public sealed record ModuleStateDto(string Code, bool Running, object? Detail = null);

public sealed record CommandDto(long Id, string CommandType, string? ParamsJson);

public sealed record ScreenshotMetaDto(
    string CapturedAt, string ObjectKey, string Sha256, int? SizeBytes,
    string TriggerType, long? CommandId);
