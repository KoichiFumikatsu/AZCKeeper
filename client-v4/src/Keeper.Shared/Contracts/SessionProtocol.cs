using System.Buffers.Binary;
using System.Text.Json;
using Keeper.Shared.Protocol;

namespace Keeper.Shared.Contracts;

public sealed record SessionRequest(int ProtocolVersion, Guid CorrelationId, long? PolicyVersion,
    EffectivePolicy? Policy, bool Locked, bool PinAllowed, IReadOnlyList<Guid> Acknowledged, bool Shutdown = false);
public sealed record SessionResponse(int ProtocolVersion, Guid CorrelationId, IReadOnlyList<Episode> Episodes,
    IReadOnlyList<LogEntry> Logs, string? Pin = null, IReadOnlyList<ActivitySnapshot>? Activity = null)
{
    public override string ToString() => $"SessionResponse Version={ProtocolVersion} Correlation={CorrelationId}";
}

public static class SessionProtocol
{
    public const int MaxFrameBytes = 128 * 1024;
    public static void Validate(SessionResponse response, Guid correlation)
    {
        if (response.ProtocolVersion != 1 || response.CorrelationId != correlation || response.Episodes is null ||
            response.Logs is null || response.Episodes.Count > 100 || response.Logs.Count > 20 ||
            response.Pin is { Length: > 32 } || response.Pin?.Any(c => !char.IsAsciiDigit(c)) == true)
            throw new InvalidDataException("invalid_session_response");
        foreach (var episode in response.Episodes)
        {
            if (episode is null) throw new InvalidDataException("null_session_episode");
            var seconds = (episode.EndedAt - episode.StartedAt).TotalSeconds;
            if (episode.EventId == Guid.Empty || seconds <= 0 || seconds > 300 || episode.ProcessName is not { Length: > 0 and <= 255 } ||
                episode.WindowTitle?.Length > 1024 || episode.ActiveSeconds < 0 || episode.IdleSeconds < 0 ||
                episode.ActiveSeconds + (double)episode.IdleSeconds > seconds + 1)
                throw new InvalidDataException("invalid_session_episode");
        }
        foreach (var log in response.Logs)
            if (log is null || log.EventId == Guid.Empty || log.Component is not ("Presence" or "SessionBuffer" or "ActivityTracker") ||
                log.Code is not ("first_login" or "late_login" or "capture_gap" or "buffer_full") || log.Fields is not null)
                throw new InvalidDataException("invalid_session_log");
        foreach (var activity in response.Activity ?? [])
            Validate(activity);
    }

    /// <summary>
    /// El desglose por franja no puede sumar mas que el total del que forma parte, y las
    /// llamadas son un subconjunto del tiempo activo. En K3 ninguna de las dos cosas se
    /// comprobaba: el 17,4% de los dias reales registran mas llamada que actividad, con un
    /// maximo de 184 horas en un dia de 24. Un dato imposible se rechaza en el borde y no
    /// llega a contaminar el panel.
    /// </summary>
    public static void Validate(ActivitySnapshot activity)
    {
        const long day = 86400;
        if (activity is null || activity.SnapshotId == Guid.Empty || activity.Sequence < 1) throw new InvalidDataException("invalid_session_activity");
        long?[] counters = [activity.ActiveSeconds, activity.IdleSeconds, activity.CallSeconds,
            activity.WorkHoursActiveSeconds, activity.WorkHoursIdleSeconds, activity.LunchActiveSeconds,
            activity.LunchIdleSeconds, activity.AfterHoursActiveSeconds, activity.AfterHoursIdleSeconds,
            activity.SampleCount];
        if (counters.Any(c => c is < 0 or > day)) throw new InvalidDataException("invalid_session_activity");
        if (activity.CallSeconds > activity.ActiveSeconds) throw new InvalidDataException("call_exceeds_activity");
        // Ausente significa DESCONOCIDO, no cero: un desglose a medias no se compara, para no
        // rechazar por "suma menor" lo que simplemente no vino.
        if (Sum(activity.WorkHoursActiveSeconds, activity.LunchActiveSeconds, activity.AfterHoursActiveSeconds) > activity.ActiveSeconds
            || Sum(activity.WorkHoursIdleSeconds, activity.LunchIdleSeconds, activity.AfterHoursIdleSeconds) > activity.IdleSeconds)
            throw new InvalidDataException("breakdown_exceeds_total");
        if (activity.FirstActivityAt > activity.LastActivityAt) throw new InvalidDataException("invalid_session_activity");
    }

    private static long? Sum(long? work, long? lunch, long? after)
    {
        if (work is null || lunch is null || after is null) return null;
        return work + lunch + after;
    }

    public static async Task WriteAsync<T>(Stream stream, T message, CancellationToken ct)
    {
        var bytes = JsonSerializer.SerializeToUtf8Bytes(message, ProtocolJson.Options);
        if (bytes.Length > MaxFrameBytes) throw new InvalidDataException("ipc_frame_too_large");
        var prefix = new byte[4];
        BinaryPrimitives.WriteInt32LittleEndian(prefix, bytes.Length);
        await stream.WriteAsync(prefix, ct);
        await stream.WriteAsync(bytes, ct);
        await stream.FlushAsync(ct);
    }

    public static async Task<T> ReadAsync<T>(Stream stream, CancellationToken ct)
    {
        var prefix = new byte[4];
        await stream.ReadExactlyAsync(prefix, ct);
        var length = BinaryPrimitives.ReadInt32LittleEndian(prefix);
        if (length is <= 0 or > MaxFrameBytes) throw new InvalidDataException("invalid_ipc_frame_length");
        var bytes = new byte[length];
        await stream.ReadExactlyAsync(bytes, ct);
        return JsonSerializer.Deserialize<T>(bytes, ProtocolJson.Options) ?? throw new InvalidDataException("empty_ipc_frame");
    }
}
