using Keeper.Shared.Contracts;
using Keeper.Shared.Protocol;

namespace Keeper.Session.Ipc;

public sealed class SessionBuffer : IEventSink
{
    private readonly List<Episode> _episodes = [];
    private readonly List<LogEntry> _logs = [];
    private readonly Dictionary<DateOnly, ActivitySnapshot> _activity = [];
    public int LostEvents { get; private set; }
    public Task EnqueueAsync(Episode episode, CancellationToken ct)
    {
        ct.ThrowIfCancellationRequested();
        if (_episodes.Count >= 500) { LostEvents++; return Task.CompletedTask; }
        _episodes.Add(episode); return Task.CompletedTask;
    }
    public Task EnqueueAsync(LogEntry log, CancellationToken ct)
    {
        ct.ThrowIfCancellationRequested();
        if (_logs.Count >= 100) { LostEvents++; return Task.CompletedTask; }
        _logs.Add(log); return Task.CompletedTask;
    }
    public Task EnqueueAsync(SyncRequestCommandResultsItem result, CancellationToken ct) => throw new NotSupportedException("session_cannot_execute_commands");
    public Task EnqueueAsync(SecurityReport report, CancellationToken ct) => throw new NotSupportedException("session_cannot_report_security");

    /// <summary>
    /// Los contadores del dia son absolutos, no incrementales: solo interesa el ultimo de
    /// cada dia. Por eso se reemplaza en vez de encolar, y este buffer no se llena aunque la
    /// sesion dure semanas sin que el agente conecte.
    /// </summary>
    public Task EnqueueAsync(ActivitySnapshot activity, CancellationToken ct)
    {
        ct.ThrowIfCancellationRequested();
        SessionProtocol.Validate(activity);
        _activity[activity.Day] = activity;
        foreach (var day in _activity.Keys.Where(d => d < activity.Day.AddDays(-7)).ToArray()) _activity.Remove(day);
        return Task.CompletedTask;
    }

    public void Acknowledge(IReadOnlyList<Guid> ids)
    {
        _episodes.RemoveAll(e => ids.Contains(e.EventId)); _logs.RemoveAll(e => ids.Contains(e.EventId));
        foreach (var day in _activity.Where(p => ids.Contains(p.Value.SnapshotId)).Select(p => p.Key).ToArray()) _activity.Remove(day);
    }
    public SessionResponse Response(Guid correlation, string? pin)
    {
        if (LostEvents > 0 && _logs.Count < 100)
        {
            _logs.Add(new LogEntry { EventId = Guid.NewGuid(), At = DateTimeOffset.UtcNow, Level = LogEntryLevel.Warn,
                Component = "SessionBuffer", Code = "buffer_full" });
            LostEvents = 0;
        }
        return new(1, correlation, _episodes.Take(20).ToArray(), _logs.Take(20).ToArray(), pin,
            _activity.OrderBy(p => p.Key).Select(p => p.Value).Take(7).ToArray());
    }
}
