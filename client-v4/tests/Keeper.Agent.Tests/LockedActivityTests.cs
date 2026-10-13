extern alias session;
using ActivityTracker = session::Keeper.Session.Modules.Activity.ActivityTracker;
using IInputIdleSource = session::Keeper.Session.Modules.Activity.IInputIdleSource;
using IPhysicalInputSource = session::Keeper.Session.Modules.Activity.IPhysicalInputSource;
using PhysicalInputReading = session::Keeper.Session.Modules.Activity.PhysicalInputReading;
using PhysicalInputStatus = session::Keeper.Session.Modules.Activity.PhysicalInputStatus;
using WindowTracker = session::Keeper.Session.Modules.Windows.WindowTracker;
using IForegroundSource = session::Keeper.Session.Modules.Windows.IForegroundSource;
using ForegroundWindow = session::Keeper.Session.Modules.Windows.ForegroundWindow;
using WorkSchedule = session::Keeper.Session.Modules.Presence.WorkSchedule;
using Keeper.Shared.Contracts;
using Keeper.Shared.Protocol;

namespace Keeper.Agent.Tests;

// 4.0.24 (decision del dueño 2026-10-09): con el equipo bloqueado (pantalla de Windows o bloqueo de Keeper) el tiempo no es
// en uso, ni inactivo, ni simulado: es bloqueado. En produccion la pantalla de bloqueo de Keeper estuvo 3,5 h contando como uso.
public sealed class LockedActivityTests
{
    private static readonly DateOnly Day = new(2026, 9, 14);

    [Fact]
    public async Task BloqueoDeWindowsEsBloqueadoNiActivoNiInactivoNiSimulado()
    {
        // Entrada inyectada sin fisica: sin bloqueo seria inactivo + simulado (jiggler con la pantalla de bloqueo delante).
        var t = await Start(new Physical { Idle = null });
        t.Windows = true;
        t.Clock.Advance(20); await t.Tracker.TickAsync(default);
        var s = t.Tracker.Snapshot(Day, 1)!;
        Assert.False(t.Tracker.IsActive);
        Assert.Equal((0L, 0L, 20L), (s.ActiveSeconds, s.IdleSeconds, s.LockedSeconds));
        Assert.Equal(0, s.SimulatedSeconds ?? 0);    // bloqueado no lee entrada: el dia no suma simulado
        Assert.Equal((20L, 0L, 0L), (s.WorkHoursLockedSeconds, s.LunchLockedSeconds, s.AfterHoursLockedSeconds));
        Assert.Null(s.FirstActivityAt);
        Assert.All(t.Tracker.Intervals, i => Assert.True(i.Locked && !i.Active));
        SessionProtocol.Validate(s);
    }

    [Fact]
    public async Task BloqueoDeKeeperEsBloqueado()
    {
        var t = await Start(new Physical { Idle = TimeSpan.Zero });
        t.Keeper = true;
        t.Clock.Advance(15); await t.Tracker.TickAsync(default);
        var s = t.Tracker.Snapshot(Day, 1)!;
        Assert.Equal((0L, 0L, 15L), (s.ActiveSeconds, s.IdleSeconds, s.LockedSeconds));
    }

    [Fact]
    public async Task AlDesbloquearVuelveAMedir()
    {
        var t = await Start(new Physical { Idle = TimeSpan.Zero });
        t.Clock.Advance(10); await t.Tracker.TickAsync(default);
        t.Windows = true;
        t.Clock.Advance(20); await t.Tracker.TickAsync(default);
        t.Windows = false;
        t.Clock.Advance(5); await t.Tracker.TickAsync(default);
        Assert.True(t.Tracker.IsActive);
        var s = t.Tracker.Snapshot(Day, 1)!;
        Assert.Equal((15L, 0L, 20L, 0L), (s.ActiveSeconds, s.IdleSeconds, s.LockedSeconds, s.SimulatedSeconds));
        Assert.Equal(DateTimeOffset.Parse("2026-09-14T10:00:35Z"), s.LastActivityAt);
    }

    [Fact]
    public async Task BloqueadoAlCruzarMedianocheSeRepartePorDia()
    {
        var t = await Start(new Physical { Idle = TimeSpan.Zero }, "2026-09-14T23:59:50Z");
        t.Windows = true;
        t.Clock.Advance(20); await t.Tracker.TickAsync(default);
        var before = t.Tracker.Snapshot(Day, 1)!;
        var after = t.Tracker.Snapshot(Day.AddDays(1), 2)!;
        Assert.Equal((10L, 10L), (before.LockedSeconds, before.AfterHoursLockedSeconds));
        Assert.Equal((10L, 0L, 0L), (after.LockedSeconds, after.ActiveSeconds, after.IdleSeconds));
        // Se publican los dos dias: el ultimo tramo del dia viejo tambien sale.
        Assert.Equal([Day, Day.AddDays(1)], t.Events.Activity.Select(a => a.Day).ToArray());
    }

    [Fact]
    public async Task BloquearCortaLaRachaSimulada()
    {
        var t = await Start(new Physical { Idle = TimeSpan.FromHours(1) });
        for (var i = 0; i < 12; i++) { t.Clock.Advance(30); await t.Tracker.TickAsync(default); }   // 360 s simulados
        Assert.Equal("simulated_input", Assert.Single(t.Events.Logs).Code);
        t.Windows = true;
        t.Clock.Advance(30); await t.Tracker.TickAsync(default);
        var end = t.Events.Logs[^1];
        Assert.Equal(("simulated_input_end", "360s"), (end.Code, end.Fields?.ErrorCode));
        // Tras desbloquear sigue la entrada inyectada: es otra racha, que vuelve a esperar 5 min para avisar.
        t.Windows = false;
        t.Clock.Advance(30); await t.Tracker.TickAsync(default);
        Assert.Equal(2, t.Events.Logs.Count);
        Assert.Equal((390L, 30L), (t.Tracker.Snapshot(Day, 1)!.SimulatedSeconds!.Value, t.Tracker.Snapshot(Day, 1)!.LockedSeconds!.Value));
    }

    [Fact]
    public async Task VentanaNoCuentaElTiempoBloqueado()
    {
        var t = await Start(new Physical { Idle = TimeSpan.Zero });
        var foreground = new Foreground { Window = new("WINWORD", "Informe") };
        var windows = new WindowTracker(foreground, t.Tracker);
        await windows.InitAsync(new ModuleContext(t.Events, t.Clock, _ => { }, default));
        t.Clock.Advance(10); await t.Tracker.TickAsync(default); await windows.TickAsync(default);
        t.Keeper = true; foreground.Window = new("Keeper.Session", "AZCKeeper");
        t.Clock.Advance(20); await t.Tracker.TickAsync(default); await windows.TickAsync(default);
        t.Clock.Advance(20); await t.Tracker.TickAsync(default); await windows.TickAsync(default);
        t.Keeper = false; foreground.Window = new("WINWORD", "Informe");
        t.Clock.Advance(5); await t.Tracker.TickAsync(default); await windows.TickAsync(default);
        await windows.ShutdownAsync();
        // Los 40 s bloqueados no estan en ningun episodio. El tramo del tick que desbloquea se atribuye, como siempre, a la
        // ventana leida en el tick anterior (Keeper.Session): el servidor la clasifica como sistema (0056) y no cuenta.
        Assert.Equal(15, t.Events.Episodes.Sum(e => e.ActiveSeconds + e.IdleSeconds));
        Assert.Equal(("WINWORD", 10L), (t.Events.Episodes[0].ProcessName, t.Events.Episodes[0].ActiveSeconds));
        Assert.Equal(5, t.Events.Episodes.Where(e => e.ProcessName == "Keeper.Session").Sum(e => e.ActiveSeconds));
        Assert.All(t.Events.Episodes, e => Assert.True((e.EndedAt - e.StartedAt).TotalSeconds <= 10));
    }

    [Fact]
    public async Task EstadoDe4023SeRestauraYEmpiezaAMedirBloqueado()
    {
        using var directory = new TestDirectory();
        var path = directory.File("activity-state.json");
        // Formato escrito por 4.0.23: con Simulated/Remote, sin Locked.
        await File.WriteAllTextAsync(path, """{"Sequence":42,"Days":[{"Day":"2026-09-14","Active":100,"Idle":50,"Call":0,"First":"2026-09-14T08:00:00+00:00","Last":"2026-09-14T09:00:00+00:00","Samples":7,"Simulated":5,"Remote":0}],"Totals":[{"Day":"2026-09-14","Category":0,"Active":100,"Idle":50}]}""");
        var clock = new Clock("2026-09-14T10:00:00Z");
        var tracker = new ActivityTracker(new LastInput(), statePath: path, physical: new Physical { Idle = TimeSpan.Zero }, locked: () => true) { Schedule = new WorkSchedule(TimeZoneInfo.Utc, [TrackingTests.Shift("08:00", "18:00")]) };
        await tracker.InitAsync(new ModuleContext(new Events(), clock, _ => { }, default));
        var restored = tracker.Days[Day];
        Assert.Equal((100.0, 50.0, 5.0), (restored.Active, restored.Idle, restored.Simulated!.Value));
        Assert.Null(restored.Locked);
        var old = tracker.Snapshot(Day, 43)!;
        Assert.Null(old.LockedSeconds);
        Assert.Null(old.WorkHoursLockedSeconds);

        clock.Advance(10); await tracker.TickAsync(default);
        await tracker.ShutdownAsync();
        var reloaded = new ActivityTracker(new LastInput(), statePath: path) { Schedule = new WorkSchedule(TimeZoneInfo.Utc, [TrackingTests.Shift("08:00", "18:00")]) };
        await reloaded.InitAsync(new ModuleContext(new Events(), clock, _ => { }, default));
        var day = reloaded.Days[Day];
        Assert.Equal((100.0, 50.0, 10.0), (day.Active, day.Idle, day.Locked!.Value));
        var s = reloaded.Snapshot(Day, 44)!;
        Assert.Equal((10L, 10L), (s.LockedSeconds, s.WorkHoursLockedSeconds));
    }

    [Fact]
    public async Task DiaSinBloqueoReportaCeroNoDesconocido()
    {
        var t = await Start(new Physical { Idle = TimeSpan.Zero });
        t.Clock.Advance(10); await t.Tracker.TickAsync(default);
        var s = t.Tracker.Snapshot(Day, 1)!;
        Assert.Equal((0L, 0L, 0L, 0L), (s.LockedSeconds, s.WorkHoursLockedSeconds, s.LunchLockedSeconds, s.AfterHoursLockedSeconds));
    }

    [Fact]
    public void ValidacionRechazaBloqueadoImposible()
    {
        var ok = new ActivitySnapshot { SnapshotId = Guid.NewGuid(), Sequence = 1, Day = Day, ActiveSeconds = 30000, IdleSeconds = 20000, LockedSeconds = 36400,
            WorkHoursLockedSeconds = 20000, LunchLockedSeconds = 0, AfterHoursLockedSeconds = 16400 };
        SessionProtocol.Validate(ok);
        Assert.Throws<InvalidDataException>(() => SessionProtocol.Validate(ok with { LockedSeconds = 86400, WorkHoursLockedSeconds = 0, AfterHoursLockedSeconds = 0 }));
        Assert.Throws<InvalidDataException>(() => SessionProtocol.Validate(ok with { WorkHoursLockedSeconds = 30000 }));
        Assert.Throws<InvalidDataException>(() => SessionProtocol.Validate(ok with { LockedSeconds = 90000 }));
        SessionProtocol.Validate(ok with { LockedSeconds = null, WorkHoursLockedSeconds = null, LunchLockedSeconds = null, AfterHoursLockedSeconds = null });
    }

    private sealed class Tracked(ActivityTracker tracker, Clock clock, Events events)
    {
        public ActivityTracker Tracker { get; } = tracker;
        public Clock Clock { get; } = clock;
        public Events Events { get; } = events;
        public bool Windows { get; set; }
        public bool Keeper { get; set; }
    }

    private static async Task<Tracked> Start(Physical physical, string at = "2026-09-14T10:00:00Z")
    {
        var clock = new Clock(at);
        var events = new Events();
        Tracked? t = null;
        var schedule = new WorkSchedule(TimeZoneInfo.Utc, [TrackingTests.Shift("08:00", "18:00")]);
        var tracker = new ActivityTracker(new LastInput(), idleThresholdSeconds: 600, physical: physical, locked: () => t!.Windows || t.Keeper) { Schedule = schedule };
        t = new Tracked(tracker, clock, events);
        await tracker.InitAsync(new ModuleContext(events, clock, _ => { }, default));
        return t;
    }

    private sealed class LastInput : IInputIdleSource { public double Seconds { get; set; } public TimeSpan GetIdleTime() => TimeSpan.FromSeconds(Seconds); }
    private sealed class Foreground : IForegroundSource { public ForegroundWindow? Window { get; set; } public ForegroundWindow? Read() => Window; }
    private sealed class Physical : IPhysicalInputSource
    {
        public PhysicalInputStatus Status { get; set; } = PhysicalInputStatus.Physical;
        public TimeSpan? Idle { get; set; }
        public PhysicalInputReading Read() => new(Status, Idle, null);
    }
    private sealed class Events : IEventSink
    {
        public List<LogEntry> Logs { get; } = [];
        public List<Episode> Episodes { get; } = [];
        public List<ActivitySnapshot> Activity { get; } = [];
        public Task EnqueueAsync(Episode episode, CancellationToken ct) { Episodes.Add(episode); return Task.CompletedTask; }
        public Task EnqueueAsync(LogEntry log, CancellationToken ct) { Logs.Add(log); return Task.CompletedTask; }
        public Task EnqueueAsync(SyncRequestCommandResultsItem result, CancellationToken ct) => Task.CompletedTask;
        public Task EnqueueAsync(SecurityReport report, CancellationToken ct) => Task.CompletedTask;
        public Task EnqueueAsync(ActivitySnapshot activity, CancellationToken ct) { Activity.Add(activity); return Task.CompletedTask; }
    }
    private sealed class Clock(string time) : TimeProvider
    {
        private DateTimeOffset _now = DateTimeOffset.Parse(time);
        private long _timestamp;
        public override DateTimeOffset GetUtcNow() => _now;
        public override long GetTimestamp() => _timestamp;
        public override long TimestampFrequency => TimeSpan.TicksPerSecond;
        public void Advance(double seconds) { _now = _now.AddSeconds(seconds); _timestamp += (long)(seconds * TimeSpan.TicksPerSecond); }
    }
}
