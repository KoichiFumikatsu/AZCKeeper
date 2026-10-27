using System.Buffers.Binary;
using System.Text.Json;
using Keeper.Shared.Protocol;

namespace Keeper.Shared.Contracts;

public sealed record SessionRequest(int ProtocolVersion, Guid CorrelationId, long? PolicyVersion,
    EffectivePolicy? Policy, bool Locked, bool PinAllowed, IReadOnlyList<Guid> Acknowledged, bool Shutdown = false,
    PowerNotice? PowerNotice = null, PinStatus? PinStatus = null, LocationCapture? Location = null);

public sealed record PinStatus(int Attempts, DateTimeOffset? RetryAt);

/// <summary>
/// Aviso previo a un reinicio o apagado ordenado por IT (agentes 4.0.17+). El agente lo repite en cada intercambio
/// mientras dure la cuenta regresiva; la sesion muestra la ventana mientras lo reciba y la cierra cuando deja de llegar.
/// Cancelled (4.0.27): IT cancelo la orden; la ventana dice «IT cancelo el reinicio/apagado» sin cuenta regresiva y se
/// puede cerrar. El agente lo envia un rato (CommandExecutor.CancelledNoticeFor) y luego deja de enviarlo.
/// </summary>
/// <remarks>
/// Message: texto de TI (&lt;= 280, sin control). PostponeMinutes: si viene, la ventana ofrece «Posponer N» (una vez por RunId).
/// Compact: tarjeta chica abajo a la derecha, sin boton de posponer (aviso tras posponer). RunId identifica la ejecucion.
/// </remarks>
public sealed record PowerNotice(PowerNoticeAction Action, DateTimeOffset Deadline, bool Cancelled = false,
    string? Message = null, long? PostponeMinutes = null, bool Compact = false, Guid? RunId = null);
public enum PowerNoticeAction { Restart, Shutdown }
public enum PowerChoiceKind { Postpone, Now }
public sealed record PowerChoice(Guid RunId, PowerChoiceKind Kind);
public sealed record SessionResponse(int ProtocolVersion, Guid CorrelationId, IReadOnlyList<Episode> Episodes,
    IReadOnlyList<LogEntry> Logs, string? Pin = null, IReadOnlyList<ActivitySnapshot>? Activity = null, IReadOnlyList<LocationPoint>? Locations = null, Guid? LocationNetworkId = null,
    long? IdleSeconds = null, PowerChoice? PowerChoice = null)
{
    public override string ToString() => $"SessionResponse Version={ProtocolVersion} Correlation={CorrelationId}";
}

public static class SessionProtocol
{
    public const int MaxFrameBytes = 128 * 1024;
    /// <summary>Un año: tope de IdleSeconds y valor que la sesion manda con el equipo bloqueado (no cuenta como uso).</summary>
    public const long MaxIdleSeconds = 31_536_000;
    public static void Validate(PowerNotice notice)
    {
        if (notice.Message is { } m && (m.Length > 280 || m.Any(char.IsControl)) || notice.PostponeMinutes is <= 0 or > 1440)
            throw new InvalidDataException("invalid_power_notice");
    }
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
            // Textos largos (proceso, titulo) no invalidan el lote: el outbox los recorta al contrato (ProtocolText). Antes
            // un nombre de proceso de 161-255 pasaba aqui y el servidor (maximo 160) rechazaba el sync entero.
            if (episode.EventId == Guid.Empty || seconds <= 0 || seconds > 300 || episode.ProcessName is not { Length: > 0 } ||
                episode.ActiveSeconds < 0 || episode.IdleSeconds < 0 ||
                episode.ActiveSeconds + (double)episode.IdleSeconds > seconds + 1)
                throw new InvalidDataException("invalid_session_episode");
        }
        foreach (var log in response.Logs)
            if (log is null || log.EventId == Guid.Empty) throw new InvalidDataException("invalid_session_log");
        if (response.IdleSeconds is < 0 or > MaxIdleSeconds) throw new InvalidDataException("invalid_session_idle");
        if (response.PowerChoice is { } choice && (choice.RunId == Guid.Empty || !Enum.IsDefined(choice.Kind)))
            throw new InvalidDataException("invalid_session_power_choice");
        if (response.Locations?.Count > 2) throw new InvalidDataException("invalid_session_locations");
        foreach (var point in response.Locations ?? []) Validate(point);
        foreach (var activity in response.Activity ?? [])
            Validate(activity);
    }

    /// <summary>
    /// Avisos que la sesion (proceso del usuario, menos confiable que el agente) puede mandar al servidor. Uno fuera de la
    /// lista se DESCARTA y el agente avisa session_log_rejected; antes cortaba la conexion con la sesion, y como el aviso
    /// seguia en su buffer volvia a cortarla en cada reconexion (4.0.22: simulated_input no estaba en la lista y la
    /// sesion se perdia cada 5,5 min con DWService conectado). Solo error_code viaja en los campos, corto y sin espacios.
    /// </summary>
    public static bool IsAccepted(LogEntry log) =>
        (log.Component, log.Code) switch
        {
            ("Presence", "first_login" or "late_login") => log.Fields is null,
            ("SessionBuffer", "buffer_full") => log.Fields is null,
            ("ActivityTracker", "capture_gap" or "simulated_input") => log.Fields is null,
            ("ActivityTracker", "simulated_input_end" or "physical_input_unavailable") => log.Fields is null ||
                log.Fields is { ControlId: null, CommandId: null, Attempt: null, ErrorCode: { Length: > 0 and <= 80 } code } &&
                code.All(c => char.IsAsciiLetterOrDigit(c) || c == '_'),
            _ => false,
        };

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
            activity.SampleCount, activity.LockedSeconds, activity.WorkHoursLockedSeconds, activity.LunchLockedSeconds,
            activity.AfterHoursLockedSeconds];
        if (counters.Any(c => c is < 0 or > day)) throw new InvalidDataException("invalid_session_activity");
        // Bloqueado (4.0.24) va aparte de activo e inactivo: los tres juntos no pasan del dia. Margen de un dia de 25 h (cambio
        // de hora) mas el redondeo por separado, el mismo que acepta el servidor (largo del dia + 120 s).
        if (activity.ActiveSeconds + activity.IdleSeconds + (activity.LockedSeconds ?? 0) > 25 * 3600 + 120)
            throw new InvalidDataException("invalid_session_activity");
        if (activity.CallSeconds > activity.ActiveSeconds) throw new InvalidDataException("call_exceeds_activity");
        // Ausente significa DESCONOCIDO, no cero: un desglose a medias no se compara, para no
        // rechazar por "suma menor" lo que simplemente no vino.
        if (Sum(activity.WorkHoursActiveSeconds, activity.LunchActiveSeconds, activity.AfterHoursActiveSeconds) > activity.ActiveSeconds
            || Sum(activity.WorkHoursIdleSeconds, activity.LunchIdleSeconds, activity.AfterHoursIdleSeconds) > activity.IdleSeconds
            // Cada franja se redondea por separado: hasta 3 s de margen sobre el total bloqueado.
            || Sum(activity.WorkHoursLockedSeconds, activity.LunchLockedSeconds, activity.AfterHoursLockedSeconds) > (activity.LockedSeconds ?? 0) + 3)
            throw new InvalidDataException("breakdown_exceeds_total");
        if (activity.FirstActivityAt > activity.LastActivityAt) throw new InvalidDataException("invalid_session_activity");
    }

    public static void Validate(LocationPoint p)
    {
        if (p.EventId == Guid.Empty || p.NetworkId == Guid.Empty || !Enum.IsDefined(p.NetworkType) ||
            p.WifiSsid?.Length > 128 || p.WifiBssid is { } b && !System.Text.RegularExpressions.Regex.IsMatch(b, "^[0-9a-fA-F]{2}(:[0-9a-fA-F]{2}){5}$") ||
            p.NetworkType != LocationPointNetworkType.Wifi && (p.WifiSsid is not null || p.WifiBssid is not null))
            throw new InvalidDataException("invalid_location_point");
        var coords = new[] { p.Latitude, p.Longitude, p.AccuracyM };
        if (coords.Any(x => x is not null) && (coords.Any(x => x is null || !double.IsFinite(x.Value)) || p.Latitude is < -90 or > 90 || p.Longitude is < -180 or > 180 || p.AccuracyM < 0))
            throw new InvalidDataException("invalid_location_coordinates");
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
