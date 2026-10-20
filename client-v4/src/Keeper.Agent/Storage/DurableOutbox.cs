using System.Text.Json;
using Keeper.Shared.Contracts;
using Keeper.Shared.Protocol;

namespace Keeper.Agent.Storage;

public sealed record OutboxEvent(Guid Id, Episode? Episode = null, LogEntry? Log = null,
    SyncRequestCommandResultsItem? Command = null, SecurityReport? Security = null,
    ActivitySnapshot? Activity = null, DeviceInventory? Inventory = null, LocationPoint? Location = null);
public sealed record PendingBatch(Guid IdempotencyKey, byte[] Body, IReadOnlyList<Guid> EventIds);
public sealed record OutboxState(long Sequence, List<OutboxEvent> Events, List<OutboxEvent> Quarantine,
    PendingBatch? Pending);

/// <summary>Elemento apartado tras un 422 del sync: se guarda tal cual (no se borra) con el campo y la regla, sin el valor.</summary>
public sealed record QuarantinedEvent(DateTimeOffset At, string Detail, OutboxEvent Event);
/// <summary>
/// Busqueda del elemento malo partiendo el paquete (422 sin pointer util). Suspects: ids que aun pueden ser el malo;
/// Confirmed: algun envio paso durante la busqueda (el rechazo no es general); Control: falta probar un sync vacio para
/// distinguir un elemento malo de un rechazo general antes de apartar al ultimo sospechoso.
/// </summary>
public sealed record OutboxIsolation(List<Guid> Suspects, int Rounds, bool Confirmed, bool Control);
public enum RejectionKind { Quarantined, Isolating, Systemic }
public sealed record RejectionOutcome(RejectionKind Kind, string Detail)
{
    public string Describe() => Kind switch
    {
        RejectionKind.Quarantined => $"apartado a cuarentena: {Detail}",
        RejectionKind.Isolating => $"partiendo el paquete: {Detail}",
        _ => $"rechazo general, no se aparta nada: {Detail}"
    };
}

public sealed partial class DurableOutbox(string path, int maxBytes = 16 * 1024 * 1024, TimeProvider? clock = null) : IEventSink, IDisposable
{
    private readonly SemaphoreSlim _gate = new(1, 1);
    private const int MaxRequestBytes = 128 * 1024;
    public const string Component = "Outbox";
    public const string RejectedCode = "outbox_item_rejected";
    // Tope de la cuarentena (cada evento <= 32 KB) y de rondas de busqueda antes de darlo por rechazo general.
    public const int MaxQuarantine = 200;
    public const int MaxIsolationRounds = 24;
    // Archivos aparte de outbox.json (no cambia su formato: un agente anterior lo sigue leyendo).
    private readonly string _quarantinePath = Sibling(path, "quarantine");
    private readonly string _isolationPath = Sibling(path, "isolation");
    private readonly TimeProvider _clock = clock ?? TimeProvider.System;
    // Hora del servidor menos hora de Windows cuando el desfase confirmado supera 5 min (ServerClock.EventCorrection);
    // cero en otro caso. Los eventos que llegan a la cola se estampan con la hora del servidor para que no los rechace
    // (invalid_range si van >5 min a futuro) mientras TimeSync corrige la hora de Windows. Se aplica una sola vez, al
    // encolar: el reintento de un lote lleva exactamente el mismo cuerpo.
    public Func<TimeSpan>? TimeCorrection { get; init; }
    private static string Sibling(string path, string name) =>
        Path.Combine(Path.GetDirectoryName(path) ?? "", $"{Path.GetFileNameWithoutExtension(path)}.{name}.json");

    public Task EnqueueAsync(LocationPoint point, CancellationToken ct) { SessionProtocol.Validate(point); return EnqueueAsync(new OutboxEvent(point.EventId, Location: point), ct); }
    public Task EnqueueAsync(Episode episode, CancellationToken ct) =>
        EnqueueAsync(new OutboxEvent(episode.EventId, Episode: episode), ct);
    public Task EnqueueAsync(LogEntry log, CancellationToken ct) =>
        EnqueueAsync(new OutboxEvent(log.EventId, Log: log), ct);
    public Task EnqueueAsync(SyncRequestCommandResultsItem result, CancellationToken ct) =>
        EnqueueAsync(new OutboxEvent(result.Result.EventId, Command: result), ct);
    public Task EnqueueAsync(SecurityReport report, CancellationToken ct) =>
        EnqueueAsync(new OutboxEvent(report.EventId, Security: report), ct);
    // La foto de actividad es acumulada del dia (el servidor hace upsert monotonico): solo importa la ultima de cada
    // dia. Guardarlas todas llenaba la cola (una por ciclo de sesion, se envia una por sync) hasta bloquear el agente.
    public async Task EnqueueAsync(ActivitySnapshot activity, CancellationToken ct)
    {
        await _gate.WaitAsync(ct);
        try
        {
            var state = await ReadAsync(ct);
            if (state.Events.Any(e => e.Id == activity.SnapshotId)) return;
            var pending = state.Pending?.EventIds ?? [];
            state.Events.RemoveAll(e => e.Activity is { } a && a.Day == activity.Day && a.Sequence < activity.Sequence && !pending.Contains(e.Id));
            state.Events.Add(Clean(new OutboxEvent(activity.SnapshotId, Activity: activity)));
            await SaveAsync(state, ct, activity.SnapshotId);
        }
        finally { _gate.Release(); }
    }

    private async Task EnqueueAsync(OutboxEvent item, CancellationToken ct)
    {
        item = Clean(Corrected(item, TimeCorrection?.Invoke() ?? TimeSpan.Zero));
        if (JsonSerializer.SerializeToUtf8Bytes(item, ProtocolJson.Options).Length > 32 * 1024)
            throw new InvalidDataException("outbox_event_too_large");
        await _gate.WaitAsync(ct);
        try
        {
            var state = await ReadAsync(ct);
            if (state.Events.Any(e => e.Id == item.Id)) return;
            state.Events.Add(item);
            await SaveAsync(state, ct, item.Id);
        }
        finally { _gate.Release(); }
    }

    // Marcas corregidas: episodio (started_at, ended_at), log (at), resultado de orden (at) y reporte de seguridad
    // (observed_at y el de cada control, con su report_hash recalculado igual que AgentDiagnostics). La foto de
    // actividad (dia local y primera/ultima actividad) y el inventario no se tocan: el servidor no les aplica el rango.
    public static OutboxEvent Corrected(OutboxEvent item, TimeSpan shift)
    {
        if (shift == TimeSpan.Zero) return item;
        if (item.Location is { } location) item = item with { Location = location with { At = location.At + shift } };
        if (item.Episode is { } e) item = item with { Episode = e with { StartedAt = e.StartedAt + shift, EndedAt = e.EndedAt + shift } };
        if (item.Log is { } l) item = item with { Log = l with { At = l.At + shift } };
        if (item.Command is { } c) item = item with { Command = c with { Result = c.Result with { At = c.Result.At + shift } } };
        if (item.Security is { } r)
        {
            var controls = r.Controls.Select(x => x with { ObservedAt = x.ObservedAt + shift }).ToArray();
            item = item with { Security = r with { ObservedAt = r.ObservedAt + shift, Controls = controls,
                ReportHash = Convert.ToHexString(System.Security.Cryptography.SHA256.HashData(
                    JsonSerializer.SerializeToUtf8Bytes(controls, ProtocolJson.Options))).ToLowerInvariant() } };
        }
        return item;
    }

    // Inventario: solo cuenta el ultimo. Sustituye al anterior que siga sin enviar; uno ya incluido en el lote
    // pendiente se respeta para que el reintento lleve exactamente el mismo cuerpo (idempotencia).
    public async Task EnqueueInventoryAsync(DeviceInventory inventory, CancellationToken ct)
    {
        await _gate.WaitAsync(ct);
        try
        {
            var state = await ReadAsync(ct);
            var pending = state.Pending?.EventIds ?? [];
            state.Events.RemoveAll(e => e.Inventory is not null && !pending.Contains(e.Id));
            state.Events.Add(Clean(new OutboxEvent(Guid.NewGuid(), Inventory: inventory)));
            await SaveAsync(state, ct);
        }
        finally { _gate.Release(); }
    }

    public async Task<PendingBatch> PrepareAsync(long? policyVersion, CancellationToken ct)
    {
        await _gate.WaitAsync(ct);
        try
        {
            var state = await ReadAsync(ct);
            // Un lote pendiente se reenvia identico (idempotencia), salvo que lleve textos fuera del contrato (cola de
            // una version anterior): ese lote nunca pasaria, se rehace recortado (el servidor deduplica por event_id).
            if (state.Pending is { } previous)
            {
                if (!state.Events.Any(e => previous.EventIds.Contains(e.Id) && !ReferenceEquals(Clean(e), e))) return previous;
                state = state with { Pending = null };
            }
            for (var i = 0; i < state.Events.Count; i++) state.Events[i] = Clean(state.Events[i]);
            // Busqueda del elemento malo en curso: solo se prueba la primera mitad de los sospechosos (o un sync vacio
            // de control). El resto de la cola espera a que termine; son pocas rondas.
            var isolation = await ReadIsolationAsync(ct);
            Func<OutboxEvent, bool> include = _ => true;
            if (isolation is not null)
            {
                var present = state.Events.Select(e => e.Id).ToHashSet();
                isolation.Suspects.RemoveAll(id => !present.Contains(id));
                if (isolation.Suspects.Count == 0) await SaveIsolationAsync(isolation = null, ct);
                else if (isolation.Control) include = _ => false;
                else
                {
                    var half = isolation.Suspects.Take((isolation.Suspects.Count + 1) / 2).ToHashSet();
                    include = e => half.Contains(e.Id);
                }
            }
            // Fotos de actividad superadas (mismo dia, secuencia menor): el servidor las rechazaria y, al enviarse una
            // por sync empezando por la mas vieja, retrasarian la del dia en curso. Colas de versiones anteriores.
            var latestPerDay = state.Events.Where(e => e.Activity is not null).GroupBy(e => e.Activity!.Day)
                .Select(g => g.MaxBy(e => e.Activity!.Sequence)!.Id).ToHashSet();
            state.Events.RemoveAll(e => e.Activity is not null && !latestPerDay.Contains(e.Id));
            var selected = new List<OutboxEvent>();
            var episodes = new List<Episode>();
            var locations = new List<LocationPoint>();
            var logs = new List<LogEntry>();
            var commands = new List<SyncRequestCommandResultsItem>();
            SecurityReport? security = null;
            ActivitySnapshot? activity = null;
            DeviceInventory? inventory = null;
            byte[] Serialize() => JsonSerializer.SerializeToUtf8Bytes(new SyncRequest
            {
                ProtocolVersion = 1, Sequence = checked(state.Sequence + 1), PolicyVersion = policyVersion,
                ReleaseId = null, Episodes = episodes, Logs = logs, CommandResults = commands, Security = security,
                Activity = activity, Inventory = inventory, Locations = locations.Count == 0 ? null : locations
            }, ProtocolJson.Options);
            var body = Serialize();
            // El contrato lleva UN snapshot por sincronizacion, asi que si hay varios dias
            // pendientes se envia el mas antiguo primero y el resto en las siguientes vueltas:
            // de lo contrario un dia viejo nunca saldria de la cola.
            var oldestActivity = state.Events.Where(e => e.Activity is not null && include(e))
                .OrderBy(e => e.Activity!.Day).ThenBy(e => e.Activity!.Sequence).FirstOrDefault();
            foreach (var item in state.Events.Where(include).OrderByDescending(e => e.Command is not null || e.Security is not null))
            {
                if (selected.Count == 200) break;
                if (item.Location is not null && locations.Count == 100) continue;
                if (item.Episode is not null && episodes.Count == 200 || item.Log is not null && logs.Count == 50 ||
                    item.Command is not null && commands.Count == 20 || item.Security is not null && security is not null
                    || item.Activity is not null && !ReferenceEquals(item, oldestActivity) || item.Inventory is not null && inventory is not null) continue;
                if (item.Location is not null) locations.Add(item.Location);
                if (item.Episode is not null) episodes.Add(item.Episode);
                if (item.Log is not null) logs.Add(item.Log);
                if (item.Command is not null) commands.Add(item.Command);
                if (item.Security is not null) security = item.Security;
                if (item.Activity is not null) activity = item.Activity;
                if (item.Inventory is not null) inventory = item.Inventory;
                var candidate = Serialize();
                if (candidate.Length > MaxRequestBytes) break;
                selected.Add(item);
                body = candidate;
            }
            var batch = new PendingBatch(Guid.NewGuid(), body, selected.Select(e => e.Id).ToArray());
            await SaveAsync(state with { Sequence = checked(state.Sequence + 1), Pending = batch }, ct);
            return batch;
        }
        finally { _gate.Release(); }
    }

    public async Task CompleteAsync(IEnumerable<Ack> acknowledgments, CancellationToken ct)
    {
        await _gate.WaitAsync(ct);
        try
        {
            var state = await ReadAsync(ct);
            if (state.Pending is null) return;
            foreach (var ack in acknowledgments.Where(a => state.Pending.EventIds.Contains(a.EventId)))
            {
                if (ack.Status == AckStatus.Rejected && ack.Retryable) continue;
                var item = state.Events.Find(e => e.Id == ack.EventId);
                if (item is null) continue;
                if (ack.Status == AckStatus.Rejected)
                {
                    state.Quarantine.Add(item);
                    if (state.Quarantine.Count > 100) state.Quarantine.RemoveAt(0);
                }
                state.Events.Remove(item);
            }
            // El servidor no confirma el inventario por separado: si el sync que lo llevaba respondio, ya se aplico.
            var sent = state.Pending.EventIds;
            state.Events.RemoveAll(e => e.Inventory is not null && sent.Contains(e.Id));
            if (await ReadIsolationAsync(ct) is { } isolation)
            {
                // Paso el sync vacio de control: el rechazo no es general, el ultimo sospechoso (que solo dio 422) es el malo.
                if (isolation.Control && sent.Count == 0)
                {
                    await QuarantineAsync(state, state.Events.Where(e => isolation.Suspects.Contains(e.Id)).Select(e => (e, Detail(null, null, e))).ToList(), ct);
                    isolation = null;
                }
                else
                {
                    isolation.Suspects.RemoveAll(sent.Contains);
                    isolation = isolation.Suspects.Count == 0 ? null : isolation with { Confirmed = true };
                }
                await SaveIsolationAsync(isolation, ct);
            }
            await SaveAsync(state with { Pending = null }, ct);
        }
        finally { _gate.Release(); }
    }

    /// <summary>
    /// El servidor rechazo el lote pendiente con 422. Con un pointer a un elemento (/episodes/N, /logs/N,
    /// /command_results/N, /security, /activity, /inventory) se aparta SOLO ese elemento a la cuarentena; sin pointer util
    /// se parte el paquete (mitades) hasta aislarlo; un campo del sobre (/sequence...) o un sync vacio rechazado es un
    /// rechazo general y no se aparta nada. Siempre descarta el lote pendiente: el mismo paquete no se reenvia.
    /// </summary>
    public async Task<RejectionOutcome> RejectPendingAsync(string? pointer, string? rule, CancellationToken ct)
    {
        await _gate.WaitAsync(ct);
        try
        {
            var state = await ReadAsync(ct);
            if (state.Pending is not { } pending) return new(RejectionKind.Systemic, "sin lote pendiente");
            var isolation = await ReadIsolationAsync(ct);
            var target = Locate(pointer, pending, state, out var envelope);
            RejectionOutcome outcome;
            if (target is not null)
            {
                var detail = Detail(pointer, rule, target);
                await QuarantineAsync(state, [(target, detail)], ct);
                isolation?.Suspects.Remove(target.Id);
                if (isolation is { Suspects.Count: 0 }) isolation = null;
                outcome = new(RejectionKind.Quarantined, detail);
            }
            else if (envelope || pending.EventIds.Count == 0)
            {
                isolation = null;
                outcome = new(RejectionKind.Systemic, pending.EventIds.Count == 0 ? "sync vacio rechazado" : $"campo del sobre {pointer}");
            }
            else
            {
                var sent = pending.EventIds.ToHashSet();
                var suspects = isolation?.Suspects.Where(sent.Contains).ToList() ?? [];
                isolation = new OutboxIsolation(suspects.Count > 0 ? suspects : [.. pending.EventIds], (isolation?.Rounds ?? 0) + 1,
                    isolation?.Confirmed ?? false, false);
                if (isolation.Rounds > MaxIsolationRounds)
                {
                    isolation = null;
                    outcome = new(RejectionKind.Systemic, $"sin aislar tras {MaxIsolationRounds} rondas");
                }
                else if (isolation.Suspects.Count == 1 && pending.EventIds.Count == 1)
                {
                    if (isolation.Confirmed)
                    {
                        var bad = state.Events.Find(e => e.Id == isolation.Suspects[0])!;
                        var detail = Detail(null, rule, bad);
                        await QuarantineAsync(state, [(bad, detail)], ct);
                        isolation = null;
                        outcome = new(RejectionKind.Quarantined, detail);
                    }
                    else
                    {
                        isolation = isolation with { Control = true };
                        outcome = new(RejectionKind.Isolating, "un sospechoso; se prueba un sync vacio antes de apartarlo");
                    }
                }
                else outcome = new(RejectionKind.Isolating, $"{isolation.Suspects.Count} sospechoso(s), ronda {isolation.Rounds}");
            }
            await SaveIsolationAsync(isolation, ct);
            await SaveAsync(state with { Pending = null }, ct);
            return outcome;
        }
        finally { _gate.Release(); }
    }

    public async Task<OutboxState> InspectAsync(CancellationToken ct = default)
    {
        await _gate.WaitAsync(ct);
        try { return await ReadAsync(ct); }
        finally { _gate.Release(); }
    }

    public async Task<IReadOnlyList<QuarantinedEvent>> InspectQuarantineAsync(CancellationToken ct = default)
    {
        await _gate.WaitAsync(ct);
        try { return await ReadQuarantineAsync(ct); }
        finally { _gate.Release(); }
    }

    public async Task<OutboxIsolation?> InspectIsolationAsync(CancellationToken ct = default)
    {
        await _gate.WaitAsync(ct);
        try { return await ReadIsolationAsync(ct); }
        finally { _gate.Release(); }
    }

    // Recorta los textos a los maxLength del contrato (ProtocolText); la misma instancia si no hay nada que recortar.
    private static OutboxEvent Clean(OutboxEvent item)
    {
        var clean = item with
        {
            Episode = ProtocolText.Sanitize(item.Episode), Log = ProtocolText.Sanitize(item.Log), Command = ProtocolText.Sanitize(item.Command),
            Security = ProtocolText.Sanitize(item.Security), Activity = ProtocolText.Sanitize(item.Activity), Inventory = ProtocolText.Sanitize(item.Inventory)
        };
        return clean.Equals(item) ? item : clean;
    }

    // Elemento del lote al que apunta el pointer del 422. envelope = campo del sobre (protocol_version, sequence...):
    // no hay elemento que apartar.
    private static OutboxEvent? Locate(string? pointer, PendingBatch pending, OutboxState state, out bool envelope)
    {
        envelope = false;
        if (pointer is null || !pointer.StartsWith('/')) return null;
        var parts = pointer[1..].Split('/');
        SyncRequest request;
        try { request = JsonSerializer.Deserialize<SyncRequest>(pending.Body, ProtocolJson.Options)!; }
        catch (JsonException) { return null; }
        static int? Index(string[] parts, int count) =>
            parts.Length > 1 && int.TryParse(parts[1], System.Globalization.NumberStyles.None, System.Globalization.CultureInfo.InvariantCulture, out var i) && i < count ? i : null;
        Guid? id = parts[0] switch
        {
            "episodes" when request.Episodes is { } list && Index(parts, list.Count) is { } i => list[i].EventId,
            "locations" when request.Locations is { } points && Index(parts, points.Count) is { } n => points[n].EventId,
            "logs" when request.Logs is { } list && Index(parts, list.Count) is { } i => list[i].EventId,
            "command_results" when request.CommandResults is { } list && Index(parts, list.Count) is { } i => list[i].Result.EventId,
            "security" => request.Security?.EventId,
            "activity" => request.Activity?.SnapshotId,
            "inventory" => state.Events.Find(e => e.Inventory is not null && pending.EventIds.Contains(e.Id))?.Id,
            _ => null
        };
        envelope = id is null && parts[0] is "protocol_version" or "sequence" or "policy_version" or "release_id";
        return id is { } found && pending.EventIds.Contains(found) ? state.Events.Find(e => e.Id == found) : null;
    }

    // Detalle del log outbox_item_rejected (va en fields.error_code): campo y regla, nunca el valor. El servidor solo
    // acepta [A-Za-z][A-Za-z0-9_.:-]{0,99} sin palabras como token o password; si no cabe queda solo el tipo.
    private static string Detail(string? pointer, string? rule, OutboxEvent item)
    {
        var kind = item.Episode is not null ? "episodes" : item.Log is not null ? "logs" : item.Command is not null ? "command_results"
            : item.Location is not null ? "locations" : item.Security is not null ? "security" : item.Activity is not null ? "activity" : "inventory";
        var where = pointer is null ? kind : pointer.Trim('/').Replace('/', '.');
        var text = new string($"{where}:{rule ?? "isolated"}".Select(c => char.IsAsciiLetterOrDigit(c) || c is '_' or '.' or ':' or '-' ? c : '_').ToArray());
        if (text.Length > 80) text = text[..80];
        return char.IsAsciiLetter(text[0]) && !ForbiddenCode().IsMatch(text) ? text : kind;
    }

    [System.Text.RegularExpressions.GeneratedRegex(@"password|secret|token|bearer|authorization|(?:^|[_.:-])pin(?:$|[_.:-])", System.Text.RegularExpressions.RegexOptions.IgnoreCase)]
    private static partial System.Text.RegularExpressions.Regex ForbiddenCode();

    // Aparta los elementos a outbox.quarantine.json (antes de quitarlos de la cola: un corte deja a lo sumo un duplicado,
    // nunca una perdida) y deja un log outbox_item_rejected por cada uno para el panel.
    private async Task QuarantineAsync(OutboxState state, IReadOnlyList<(OutboxEvent Event, string Detail)> items, CancellationToken ct)
    {
        if (items.Count == 0) return;
        var quarantine = await ReadQuarantineAsync(ct);
        var now = _clock.GetUtcNow();
        foreach (var (item, detail) in items)
        {
            if (!quarantine.Any(q => q.Event.Id == item.Id)) quarantine.Add(new QuarantinedEvent(now, detail, item));
            state.Events.RemoveAll(e => e.Id == item.Id);
            // Un aviso de rechazo rechazado no genera otro (no hay bucle).
            if (item.Log is { Component: Component, Code: RejectedCode }) continue;
            var log = new LogEntry
            {
                EventId = Guid.NewGuid(), At = now, Level = LogEntryLevel.Warn, Component = Component, Code = RejectedCode,
                Fields = new LogEntryFields { ErrorCode = detail }
            };
            state.Events.Add(new OutboxEvent(log.EventId, Log: log));
        }
        if (quarantine.Count > MaxQuarantine) quarantine.RemoveRange(0, quarantine.Count - MaxQuarantine);
        await AtomicFile.WriteAsync(_quarantinePath, JsonSerializer.SerializeToUtf8Bytes(quarantine, ProtocolJson.Options), ct);
    }

    // Un archivo ilegible no frena el sync: la cuarentena se aparta como .corrupt (no se borra) y la busqueda se reinicia.
    private async Task<List<QuarantinedEvent>> ReadQuarantineAsync(CancellationToken ct)
    {
        if (!File.Exists(_quarantinePath)) return [];
        try { return JsonSerializer.Deserialize<List<QuarantinedEvent>>(await File.ReadAllBytesAsync(_quarantinePath, ct), ProtocolJson.Options) ?? []; }
        catch (JsonException)
        {
            File.Move(_quarantinePath, $"{_quarantinePath}.{_clock.GetUtcNow():yyyyMMddHHmmss}.corrupt", overwrite: true);
            return [];
        }
    }

    private async Task<OutboxIsolation?> ReadIsolationAsync(CancellationToken ct)
    {
        if (!File.Exists(_isolationPath)) return null;
        try { return JsonSerializer.Deserialize<OutboxIsolation>(await File.ReadAllBytesAsync(_isolationPath, ct), ProtocolJson.Options); }
        catch (JsonException) { return null; }
    }

    private async Task SaveIsolationAsync(OutboxIsolation? isolation, CancellationToken ct)
    {
        if (isolation is not null) await AtomicFile.WriteAsync(_isolationPath, JsonSerializer.SerializeToUtf8Bytes(isolation, ProtocolJson.Options), ct);
        else if (File.Exists(_isolationPath)) File.Delete(_isolationPath);
    }

    private async Task<OutboxState> ReadAsync(CancellationToken ct) => File.Exists(path)
        ? JsonSerializer.Deserialize<OutboxState>(await File.ReadAllBytesAsync(path, ct), ProtocolJson.Options)
            ?? throw new InvalidDataException("invalid_outbox")
        : new OutboxState(0, [], [], null);

    // Cola llena: se descarta lo menos valioso en vez de bloquear el sync (una cola que no se puede guardar impide
    // enviar y por tanto vaciarla). Orden: logs, fotos de actividad ya superadas, episodios; siempre lo mas viejo y
    // nunca lo que va en el lote pendiente. Resultados de comandos, reportes de seguridad e inventario no se tocan.
    private Task SaveAsync(OutboxState state, CancellationToken ct, Guid? keep = null)
    {
        var bytes = JsonSerializer.SerializeToUtf8Bytes(state, ProtocolJson.Options);
        if (bytes.Length > maxBytes)
        {
            var pending = state.Pending?.EventIds ?? [];
            var latestDay = state.Events.Where(e => e.Activity is not null).GroupBy(e => e.Activity!.Day)
                .Select(g => g.MaxBy(e => e.Activity!.Sequence)!.Id).ToHashSet();
            var droppable = state.Events.Where(e => !pending.Contains(e.Id) && e.Id != keep
                && e.Log is not { Component: "DeviceLock", Code: "local_unlock" }).Select((e, i) => (Event: e, Index: i,
                Rank: e.Log is not null ? 0 : e.Activity is not null && !latestDay.Contains(e.Id) ? 1 : e.Episode is not null ? 2 : -1))
                .Where(x => x.Rank >= 0).OrderBy(x => x.Rank).ThenBy(x => x.Index).Select(x => x.Event).ToList();
            state.Quarantine.Clear();
            var removed = 0;
            foreach (var victim in droppable)
            {
                if (bytes.Length <= maxBytes * 3L / 4) break;
                state.Events.Remove(victim);
                if (++removed % 50 == 0) bytes = JsonSerializer.SerializeToUtf8Bytes(state, ProtocolJson.Options);
            }
            bytes = JsonSerializer.SerializeToUtf8Bytes(state, ProtocolJson.Options);
            if (bytes.Length > maxBytes) throw new IOException("outbox_quota_exceeded");
        }
        return AtomicFile.WriteAsync(path, bytes, ct);
    }

    public void Dispose() => _gate.Dispose();
}
