using Keeper.Agent.Storage;
using Keeper.Agent.Policy;
using Keeper.Shared.Contracts;
using Keeper.Shared.Protocol;

[assembly: CollectionBehavior(DisableTestParallelization = true, MaxParallelThreads = 1)]

namespace Keeper.Agent.Tests;

internal sealed class TestDirectory : IDisposable
{
    public string Root { get; } = Path.Combine(Path.GetTempPath(), "Keeper.Agent.Tests", Guid.NewGuid().ToString("N"));
    public TestDirectory() => Directory.CreateDirectory(Root);
    public string File(string name) => Path.Combine(Root, name);
    public void Dispose()
    {
        Directory.Delete(Root, recursive: true);
        var parent = Path.GetDirectoryName(Root)!;
        if (!Directory.EnumerateFileSystemEntries(parent).Any()) Directory.Delete(parent);
    }
}

internal sealed class TestClock : TimeProvider
{
    private DateTimeOffset _now = DateTimeOffset.FromUnixTimeSeconds(1700000000);
    private long _timestamp;
    public override DateTimeOffset GetUtcNow() => _now;
    public override long GetTimestamp() => _timestamp;
    public override long TimestampFrequency => TimeSpan.TicksPerSecond;
    public void Advance(TimeSpan duration) { _now += duration; _timestamp += duration.Ticks; }
}

internal sealed class MemoryPolicyStore : IPolicyStore
{
    public CachedPolicy? Value { get; set; }
    public int Saves { get; private set; }
    public Task<CachedPolicy?> LoadAsync(CancellationToken ct) => Task.FromResult(Value);
    public Task SaveAsync(CachedPolicy policy, CancellationToken ct) { Value = policy; Saves++; return Task.CompletedTask; }
}

internal sealed class TestModule : IModule
{
    public string Name { get; init; } = "test";
    public int Applications { get; private set; }
    public int Ticks { get; private set; }
    public int Shutdowns { get; private set; }
    public bool Fail { get; init; }
    public Task InitAsync(ModuleContext ctx) => Task.CompletedTask;
    public Task ApplyPolicyAsync(EffectivePolicy p) { Applications++; return Task.CompletedTask; }
    public Task TickAsync(CancellationToken ct) { Ticks++; return Fail ? Task.FromException(new IOException("test")) : Task.CompletedTask; }
    public ModuleSnapshot Snapshot() => new(Name, null, null, "unknown");
    public Task ShutdownAsync() { Shutdowns++; return Task.CompletedTask; }
}

internal sealed class MemoryEvents : IEventSink
{
    public List<LogEntry> Logs { get; } = [];
    public List<SyncRequestCommandResultsItem> CommandResults { get; } = [];
    public List<SecurityReport> SecurityReports { get; } = [];
    public List<ActivitySnapshot> Activity { get; } = [];
    public Task EnqueueAsync(Episode episode, CancellationToken ct) => Task.CompletedTask;
    public Task EnqueueAsync(LogEntry log, CancellationToken ct) { Logs.Add(log); return Task.CompletedTask; }
    public Task EnqueueAsync(SyncRequestCommandResultsItem result, CancellationToken ct) { CommandResults.Add(result); return Task.CompletedTask; }
    public Task EnqueueAsync(SecurityReport report, CancellationToken ct) { SecurityReports.Add(report); return Task.CompletedTask; }
    public Task EnqueueAsync(ActivitySnapshot activity, CancellationToken ct) { Activity.Add(activity); return Task.CompletedTask; }
}

internal static class Samples
{
    public static readonly Guid Device = Guid.Parse("11111111-1111-4111-8111-111111111111");
    public static readonly Guid Tenant = Guid.Parse("22222222-2222-4222-8222-222222222222");
    public static ModuleContext Context(TimeProvider? clock = null) => new(new MemoryEvents(), clock ?? TimeProvider.System, _ => { }, CancellationToken.None);
    public static EffectivePolicy Policy(params Rule[] rules) => new()
    {
        DeviceId = Device, TenantId = Tenant, Version = "opaque-v1", Etag = "\"1\"",
        Rules = rules, Schedules = [], Composition = [], ManagementHosts = ["keep.azclegal.com"]
    };
    public static Rule Rule(RuleKind kind, RuleEffect effect, params string[] targets) => new()
    {
        Id = Guid.NewGuid(), Kind = kind, Effect = effect, Targets = targets, Priority = 0, ScheduleId = null
    };
    public static Episode Episode(string? title = null) => new()
    {
        EventId = Guid.NewGuid(), StartedAt = DateTimeOffset.UnixEpoch, EndedAt = DateTimeOffset.UnixEpoch.AddSeconds(1),
        ProcessName = "test", WindowTitle = title, ActiveSeconds = 1, IdleSeconds = 0
    };
    public static LogEntry Log() => new()
    {
        EventId = Guid.NewGuid(), At = DateTimeOffset.UnixEpoch, Level = LogEntryLevel.Info, Code = "test", Component = "test"
    };
}
