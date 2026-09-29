using System.Text.Json;
using Keeper.Shared.Contracts;
using Keeper.Shared.Protocol;

namespace Keeper.Agent.Storage;

public sealed record OutboxEvent(Guid Id, Episode? Episode = null, LogEntry? Log = null,
    SyncRequestCommandResultsItem? Command = null, SecurityReport? Security = null,
    ActivitySnapshot? Activity = null, DeviceInventory? Inventory = null);
public sealed record PendingBatch(Guid IdempotencyKey, byte[] Body, IReadOnlyList<Guid> EventIds);
public sealed record OutboxState(long Sequence, List<OutboxEvent> Events, List<OutboxEvent> Quarantine,
    PendingBatch? Pending);

public sealed class DurableOutbox(string path, int maxBytes = 16 * 1024 * 1024) : IEventSink, IDisposable
{
    private readonly SemaphoreSlim _gate = new(1, 1);
    private const int MaxRequestBytes = 128 * 1024;

    public Task EnqueueAsync(Episode episode, CancellationToken ct) =>
        EnqueueAsync(new OutboxEvent(episode.EventId, Episode: episode), ct);
    public Task EnqueueAsync(LogEntry log, CancellationToken ct) =>
        EnqueueAsync(new OutboxEvent(log.EventId, Log: log), ct);
    public Task EnqueueAsync(SyncRequestCommandResultsItem result, CancellationToken ct) =>
        EnqueueAsync(new OutboxEvent(result.Result.EventId, Command: result), ct);
    public Task EnqueueAsync(SecurityReport report, CancellationToken ct) =>
        EnqueueAsync(new OutboxEvent(report.EventId, Security: report), ct);
    public Task EnqueueAsync(ActivitySnapshot activity, CancellationToken ct) =>
        EnqueueAsync(new OutboxEvent(activity.SnapshotId, Activity: activity), ct);

    private async Task EnqueueAsync(OutboxEvent item, CancellationToken ct)
    {
        if (JsonSerializer.SerializeToUtf8Bytes(item, ProtocolJson.Options).Length > 32 * 1024)
            throw new InvalidDataException("outbox_event_too_large");
        await _gate.WaitAsync(ct);
        try
        {
            var state = await ReadAsync(ct);
            if (state.Events.Any(e => e.Id == item.Id)) return;
            state.Events.Add(item);
            await SaveAsync(state, ct);
        }
        finally { _gate.Release(); }
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
            state.Events.Add(new OutboxEvent(Guid.NewGuid(), Inventory: inventory));
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
            if (state.Pending is not null) return state.Pending;
            var selected = new List<OutboxEvent>();
            var episodes = new List<Episode>();
            var logs = new List<LogEntry>();
            var commands = new List<SyncRequestCommandResultsItem>();
            SecurityReport? security = null;
            ActivitySnapshot? activity = null;
            DeviceInventory? inventory = null;
            byte[] Serialize() => JsonSerializer.SerializeToUtf8Bytes(new SyncRequest
            {
                ProtocolVersion = 1, Sequence = checked(state.Sequence + 1), PolicyVersion = policyVersion,
                ReleaseId = null, Episodes = episodes, Logs = logs, CommandResults = commands, Security = security,
                Activity = activity, Inventory = inventory
            }, ProtocolJson.Options);
            var body = Serialize();
            // El contrato lleva UN snapshot por sincronizacion, asi que si hay varios dias
            // pendientes se envia el mas antiguo primero y el resto en las siguientes vueltas:
            // de lo contrario un dia viejo nunca saldria de la cola.
            var oldestActivity = state.Events.Where(e => e.Activity is not null)
                .OrderBy(e => e.Activity!.Day).ThenBy(e => e.Activity!.Sequence).FirstOrDefault();
            foreach (var item in state.Events.OrderByDescending(e => e.Command is not null || e.Security is not null))
            {
                if (selected.Count == 200) break;
                if (item.Episode is not null && episodes.Count == 200 || item.Log is not null && logs.Count == 50 ||
                    item.Command is not null && commands.Count == 20 || item.Security is not null && security is not null
                    || item.Activity is not null && !ReferenceEquals(item, oldestActivity) || item.Inventory is not null && inventory is not null) continue;
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
            await SaveAsync(state with { Pending = null }, ct);
        }
        finally { _gate.Release(); }
    }

    public async Task<OutboxState> InspectAsync(CancellationToken ct = default)
    {
        await _gate.WaitAsync(ct);
        try { return await ReadAsync(ct); }
        finally { _gate.Release(); }
    }

    private async Task<OutboxState> ReadAsync(CancellationToken ct) => File.Exists(path)
        ? JsonSerializer.Deserialize<OutboxState>(await File.ReadAllBytesAsync(path, ct), ProtocolJson.Options)
            ?? throw new InvalidDataException("invalid_outbox")
        : new OutboxState(0, [], [], null);

    private Task SaveAsync(OutboxState state, CancellationToken ct)
    {
        var bytes = JsonSerializer.SerializeToUtf8Bytes(state, ProtocolJson.Options);
        if (bytes.Length > maxBytes) throw new IOException("outbox_quota_exceeded");
        return AtomicFile.WriteAsync(path, bytes, ct);
    }

    public void Dispose() => _gate.Dispose();
}
