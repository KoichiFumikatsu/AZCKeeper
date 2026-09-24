extern alias session;
using ActivityTracker = session::Keeper.Session.Modules.Activity.ActivityTracker;
using IInputIdleSource = session::Keeper.Session.Modules.Activity.IInputIdleSource;
using WindowTracker = session::Keeper.Session.Modules.Windows.WindowTracker;
using IForegroundSource = session::Keeper.Session.Modules.Windows.IForegroundSource;
using ForegroundWindow = session::Keeper.Session.Modules.Windows.ForegroundWindow;
using CallDetector = session::Keeper.Session.Modules.Calls.CallDetector;
using WorkSchedule = session::Keeper.Session.Modules.Presence.WorkSchedule;
using TimeCategory = session::Keeper.Session.Modules.Presence.TimeCategory;
using PresenceTracker = session::Keeper.Session.Modules.Presence.PresenceTracker;
using SessionBuffer = session::Keeper.Session.Ipc.SessionBuffer;
using Keeper.Shared.Contracts;
using Keeper.Shared.Protocol;

namespace Keeper.Agent.Tests;

public sealed class TrackingTests
{
    internal static Schedule Shift(string start = "07:00", string end = "19:00",
        string? lunchStart = null, string? lunchEnd = null) => new()
    {
        Id = Guid.NewGuid(), TenantId = Samples.Tenant, Name = "Work", Timezone = "UTC", Days = [1, 2, 3, 4, 5],
        StartLocal = start, EndLocal = end, Version = 1,
        LunchStartLocal = lunchStart, LunchEndLocal = lunchEnd
    };

    // Regresion: la unica via real de configuracion es FromPolicy, y no pasaba almuerzo alguno,
    // asi que TimeCategory.LunchTime era inalcanzable en produccion y el almuerzo entero se
    // contabilizaba como jornada. Las pruebas no lo detectaban porque construian el horario a
    // mano con el almuerzo por constructor.
    [Theory]
    [InlineData("2026-09-14T11:59:59Z", TimeCategory.WorkHours)]
    [InlineData("2026-09-14T12:00:00Z", TimeCategory.LunchTime)]
    [InlineData("2026-09-14T12:59:59Z", TimeCategory.LunchTime)]
    [InlineData("2026-09-14T13:00:00Z", TimeCategory.WorkHours)]
    public void LunchComesFromThePolicySchedule(string instant, TimeCategory expected)
    {
        var policy = Samples.Policy() with { Schedules = [Shift(lunchStart: "12:00", lunchEnd: "13:00")] };
        Assert.Equal(expected, WorkSchedule.FromPolicy(policy).GetTimeCategory(DateTimeOffset.Parse(instant)));
    }

    // Cada horario de K3 define su propio almuerzo (van de 11:30 a 16:00 segun la persona),
    // asi que el del turno debe mandar sobre cualquier valor global.
    [Fact]
    public void ScheduleLunchWinsOverTheGlobalOne()
    {
        var schedule = new WorkSchedule(TimeZoneInfo.Utc, [Shift(lunchStart: "15:00", lunchEnd: "16:00")],
            new TimeOnly(12, 0), new TimeOnly(13, 0));
        Assert.Equal(TimeCategory.WorkHours, schedule.GetTimeCategory(DateTimeOffset.Parse("2026-09-14T12:30:00Z")));
        Assert.Equal(TimeCategory.LunchTime, schedule.GetTimeCategory(DateTimeOffset.Parse("2026-09-14T15:30:00Z")));
    }

    [Theory]
    [InlineData("12:00", null)]
    [InlineData(null, "13:00")]
    [InlineData("13:00", "12:00")]
    public void AnUnusableLunchPairIsIgnoredInsteadOfGuessed(string? start, string? end)
    {
        var policy = Samples.Policy() with { Schedules = [Shift(lunchStart: start, lunchEnd: end)] };
        Assert.Equal(TimeCategory.WorkHours,
            WorkSchedule.FromPolicy(policy).GetTimeCategory(DateTimeOffset.Parse("2026-09-14T12:30:00Z")));
    }

    [Theory]
    [InlineData("2026-09-14T06:59:59Z", TimeCategory.AfterHours)]
    [InlineData("2026-09-14T07:00:00Z", TimeCategory.WorkHours)]
    [InlineData("2026-09-14T12:00:00Z", TimeCategory.LunchTime)]
    [InlineData("2026-09-14T13:00:00Z", TimeCategory.WorkHours)]
    [InlineData("2026-09-14T19:00:00Z", TimeCategory.AfterHours)]
    [InlineData("2026-09-19T12:00:00Z", TimeCategory.AfterHours)]
    public void SchedulePreservesWorkLunchAndWeekendBoundaries(string timestamp, TimeCategory expected)
    {
        var schedule = new WorkSchedule(TimeZoneInfo.Utc, [Shift()], new TimeOnly(12, 0), new TimeOnly(13, 0));
        Assert.Equal(expected, schedule.GetTimeCategory(DateTimeOffset.Parse(timestamp)));
    }

    [Fact]
    public void OvernightShiftUsesPreviousWorkingDayAndTimezone()
    {
        var schedule = new WorkSchedule(TimeZoneInfo.Utc, [Shift("22:00", "06:00")]);
        Assert.Equal(TimeCategory.WorkHours, schedule.GetTimeCategory(DateTimeOffset.Parse("2026-09-19T03:00:00Z")));
        Assert.Equal(TimeCategory.AfterHours, schedule.GetTimeCategory(DateTimeOffset.Parse("2026-09-21T03:00:00Z")));
        var colombia = Shift() with { Timezone = "America/Bogota" };
        Assert.Equal(TimeCategory.WorkHours, new WorkSchedule(TimeZoneInfo.Utc, [colombia]).GetTimeCategory(DateTimeOffset.Parse("2026-09-14T12:00:00Z")));
    }

    [Fact]
    public async Task ActivityUsesElapsedTimeAndThresholdNotTickCount()
    {
        var clock = new TrackingClock("2026-09-14T10:00:00Z");
        var input = new IdleInput();
        var tracker = new ActivityTracker(input, idleThresholdSeconds: 60);
        await tracker.InitAsync(Context(clock));
        clock.Advance(3); await tracker.TickAsync(default);
        input.Seconds = 60;
        clock.Advance(2); await tracker.TickAsync(default);
        Assert.Equal(3, tracker.Totals.Values.Sum(v => v.Active));
        Assert.Equal(2, tracker.Totals.Values.Sum(v => v.Idle));
    }

    // El snapshot es lo que alimenta la Productividad, el Desglose de Tiempo y las alertas
    // fuera de horario del panel. Hasta ahora el cliente calculaba las franjas y las tiraba.
    [Fact]
    public async Task SnapshotCarriesTheHourlyBreakdown()
    {
        var clock = new TrackingClock("2026-09-14T11:59:58Z");
        var tracker = new ActivityTracker(new IdleInput())
        {
            Schedule = new WorkSchedule(TimeZoneInfo.Utc, [Shift(lunchStart: "12:00", lunchEnd: "13:00")])
        };
        await tracker.InitAsync(Context(clock));
        clock.Advance(4); await tracker.TickAsync(default);

        var snapshot = tracker.Snapshot(new DateOnly(2026, 9, 14), 1);
        Assert.NotNull(snapshot);
        Assert.Equal(2, snapshot!.WorkHoursActiveSeconds);   // 11:59:58 -> 12:00:00
        Assert.Equal(2, snapshot.LunchActiveSeconds);        // 12:00:00 -> 12:00:02
        Assert.Equal(0, snapshot.AfterHoursActiveSeconds);
        // El desglose no puede sumar mas que el total del que forma parte.
        Assert.Equal(snapshot.ActiveSeconds,
            snapshot.WorkHoursActiveSeconds + snapshot.LunchActiveSeconds + snapshot.AfterHoursActiveSeconds);
    }

    // Lo que faltaba no era calcular el desglose, sino ENVIARLO: el tracker lo computaba y lo
    // descartaba porque nadie leia Totals. Esta prueba cubre el tramo que quedaba suelto.
    [Fact]
    public async Task EachTickPublishesTheSnapshotToTheOutbox()
    {
        var clock = new TrackingClock("2026-09-14T10:00:00Z");
        var sink = new CapturedEvents();
        var tracker = new ActivityTracker(new IdleInput())
        {
            Schedule = new WorkSchedule(TimeZoneInfo.Utc, [Shift(lunchStart: "12:00", lunchEnd: "13:00")])
        };
        await tracker.InitAsync(new ModuleContext(sink, clock, _ => { }, CancellationToken.None));
        clock.Advance(2); await tracker.TickAsync(default);

        var published = Assert.Single(sink.Activity);
        Assert.Equal(new DateOnly(2026, 9, 14), published.Day);
        Assert.Equal(2, published.WorkHoursActiveSeconds);
        Assert.Equal(1, published.Sequence);

        clock.Advance(2); await tracker.TickAsync(default);
        // La secuencia avanza para que el servidor sepa cual es el ultimo estado del dia.
        Assert.Equal(2, sink.Activity[^1].Sequence);
        Assert.Equal(4, sink.Activity[^1].WorkHoursActiveSeconds);
    }

    // En K3 el contador de llamadas solo se ponia a cero al arrancar el programa, de modo que
    // un equipo encendido varios dias acumulaba: el 17,4% de los dias reales registran mas
    // tiempo en llamada que tiempo activo, con un maximo de 184 horas en un dia de 24.
    [Fact]
    public async Task CallSecondsResetWithTheDayAndNeverExceedActivity()
    {
        var clock = new TrackingClock("2026-09-14T23:59:58Z");
        var tracker = new ActivityTracker(new IdleInput(), () => true) { Schedule = new WorkSchedule(TimeZoneInfo.Utc) };
        await tracker.InitAsync(Context(clock));
        clock.Advance(4); await tracker.TickAsync(default);

        var before = tracker.Snapshot(new DateOnly(2026, 9, 14), 1)!;
        var after = tracker.Snapshot(new DateOnly(2026, 9, 15), 2)!;
        Assert.Equal(2, before.CallSeconds);
        Assert.Equal(2, after.CallSeconds);
        Assert.True(before.CallSeconds <= before.ActiveSeconds);
        Assert.True(after.CallSeconds <= after.ActiveSeconds);
    }

    // En K3 first_event_at marcaba el primer envio HTTP, no la primera actividad: un equipo
    // encendido a las 07:00 con trabajo desde las 09:00 registraba las 07:00.
    [Fact]
    public async Task FirstActivityIsTheRealOneNotTheFirstReport()
    {
        var clock = new TrackingClock("2026-09-14T07:00:00Z");
        var input = new IdleInput { Seconds = 9999 };
        var tracker = new ActivityTracker(input, idleThresholdSeconds: 60) { Schedule = new WorkSchedule(TimeZoneInfo.Utc) };
        await tracker.InitAsync(Context(clock));
        clock.Advance(10); await tracker.TickAsync(default);       // encendido pero inactivo
        Assert.Null(tracker.Snapshot(new DateOnly(2026, 9, 14), 1)!.FirstActivityAt);

        input.Seconds = 0;
        clock.Advance(5); await tracker.TickAsync(default);        // aqui empieza de verdad
        var snapshot = tracker.Snapshot(new DateOnly(2026, 9, 14), 2)!;
        Assert.Equal(DateTimeOffset.Parse("2026-09-14T07:00:10Z"), snapshot.FirstActivityAt);
        Assert.True(snapshot.SampleCount >= 2);
    }

    [Theory]
    [InlineData(100, true)]
    [InlineData(300, false)]
    public async Task CallsOverrideIdleOnlyWithinSafetyLimit(double idle, bool active)
    {
        var clock = new TrackingClock("2026-09-14T10:00:00Z");
        var tracker = new ActivityTracker(new IdleInput { Seconds = idle }, () => true, 60, 300);
        await tracker.InitAsync(Context(clock));
        clock.Advance(1); await tracker.TickAsync(default);
        Assert.Equal(active, tracker.IsActive);
    }

    [Fact]
    public async Task MidnightAndLunchAreSplitWithoutDoubleCounting()
    {
        var clock = new TrackingClock("2026-09-14T23:59:59Z");
        var tracker = new ActivityTracker(new IdleInput()) { Schedule = new WorkSchedule(TimeZoneInfo.Utc) };
        await tracker.InitAsync(Context(clock));
        clock.Advance(2); await tracker.TickAsync(default);
        Assert.Equal(2, tracker.Totals.Count);
        Assert.All(tracker.Totals.Values, t => Assert.Equal(1, t.Active));
        var lunchClock = new TrackingClock("2026-09-14T11:59:59Z");
        var lunch = new ActivityTracker(new IdleInput()) { Schedule = new WorkSchedule(TimeZoneInfo.Utc, [Shift()], new(12, 0), new(13, 0)) };
        await lunch.InitAsync(Context(lunchClock));
        lunchClock.Advance(2); await lunch.TickAsync(default);
        Assert.Equal(1, lunch.Totals.Single(k => k.Key.Category == TimeCategory.WorkHours).Value.Active);
        Assert.Equal(1, lunch.Totals.Single(k => k.Key.Category == TimeCategory.LunchTime).Value.Active);
    }

    [Fact]
    public async Task SuspensionAndClockRollbackReportGapWithoutChargingHours()
    {
        var clock = new TrackingClock("2026-09-14T10:00:00Z");
        var events = new CapturedEvents();
        var tracker = new ActivityTracker(new IdleInput());
        await tracker.InitAsync(Context(clock, events));
        clock.Advance(3600); await tracker.TickAsync(default);
        clock.JumpWall(-3600); clock.Advance(1); await tracker.TickAsync(default);
        Assert.Empty(tracker.Totals);
        Assert.Equal(2, events.Logs.Count);
        Assert.All(events.Logs, log => Assert.Equal("capture_gap", log.Code));
    }

    [Fact]
    public async Task WindowChangeAndShutdownProduceEpisodesWithExactDurations()
    {
        var clock = new TrackingClock("2026-09-14T10:00:00Z");
        var events = new CapturedEvents();
        var input = new IdleInput();
        var activity = new ActivityTracker(input, idleThresholdSeconds: 60);
        var foreground = new Foreground { Window = new("editor", "A") };
        var windows = new WindowTracker(foreground, activity);
        await activity.InitAsync(Context(clock, events)); await windows.InitAsync(Context(clock, events));
        clock.Advance(3); await activity.TickAsync(default); await windows.TickAsync(default);
        foreground.Window = new("browser", "B");
        clock.Advance(2); await activity.TickAsync(default); await windows.TickAsync(default);
        input.Seconds = 60;
        clock.Advance(4); await activity.TickAsync(default); await windows.TickAsync(default);
        await windows.ShutdownAsync();
        Assert.Equal(2, events.Episodes.Count);
        Assert.Equal("editor", events.Episodes[0].ProcessName);
        Assert.Equal(5, events.Episodes[0].ActiveSeconds);
        Assert.Equal(5, (events.Episodes[0].EndedAt - events.Episodes[0].StartedAt).TotalSeconds);
        Assert.Equal(4, events.Episodes[1].IdleSeconds);
        Assert.Equal(2, events.Episodes.Select(e => e.EventId).Distinct().Count());
    }

    [Fact]
    public async Task UnchangedWindowFlushesBoundedEpisodesAndDoesNotChargeSuspend()
    {
        var clock = new TrackingClock("2026-09-14T10:00:00Z");
        var events = new CapturedEvents();
        var activity = new ActivityTracker(new IdleInput());
        var windows = new WindowTracker(new Foreground { Window = new("editor", "A") }, activity);
        await activity.InitAsync(Context(clock, events)); await windows.InitAsync(Context(clock, events));
        for (var i = 0; i < 121; i++) { clock.Advance(1); await activity.TickAsync(default); await windows.TickAsync(default); }
        clock.Advance(3600); await activity.TickAsync(default); await windows.TickAsync(default);
        await windows.ShutdownAsync();
        Assert.Equal(121, events.Episodes.Sum(e => e.ActiveSeconds));
        Assert.All(events.Episodes, e => Assert.InRange((e.EndedAt - e.StartedAt).TotalSeconds, 1, 120));
    }

    [Theory]
    [InlineData("ZOOM.exe", "", true)]
    [InlineData("chrome", "Google Meet - daily", true)]
    [InlineData("editor", "code", false)]
    public void CallsUseCaseInsensitiveProcessOrTitleKeywords(string process, string title, bool expected) =>
        Assert.Equal(expected, CallDetector.Matches(new(process, title), ["zoom", ""], ["Google Meet"]));

    [Fact]
    public async Task PresenceReportsOncePerDayAcrossRestart()
    {
        using var directory = new TestDirectory();
        var clock = new TrackingClock("2026-09-14T07:05:00Z");
        var events = new CapturedEvents();
        var policy = Samples.Policy() with { Schedules = [Shift()] };
        var presence = new PresenceTracker(directory.File("presence"));
        await presence.InitAsync(Context(clock, events)); await presence.ApplyPolicyAsync(policy); await presence.TickAsync(default);
        Assert.Equal(TimeSpan.FromMinutes(5), presence.Lateness);
        var restarted = new PresenceTracker(directory.File("presence"));
        await restarted.InitAsync(Context(clock, events)); await restarted.ApplyPolicyAsync(policy); await restarted.TickAsync(default);
        Assert.Single(events.Logs);
    }

    [Fact]
    public async Task SessionKeepsStableIdsUntilDurableAckAndBoundsMemory()
    {
        var buffer = new SessionBuffer();
        var episode = Samples.Episode();
        await buffer.EnqueueAsync(episode, default);
        Assert.Equal(episode.EventId, buffer.Response(Guid.NewGuid(), null).Episodes.Single().EventId);
        Assert.Equal(episode.EventId, buffer.Response(Guid.NewGuid(), null).Episodes.Single().EventId);
        buffer.Acknowledge([episode.EventId]);
        Assert.Empty(buffer.Response(Guid.NewGuid(), null).Episodes);
        for (var i = 0; i < 501; i++) await buffer.EnqueueAsync(Samples.Episode(), default);
        Assert.Equal(1, buffer.LostEvents);
        Assert.Contains(buffer.Response(Guid.NewGuid(), null).Logs, l => l.Code == "buffer_full");
    }

    private static ModuleContext Context(TimeProvider clock, CapturedEvents? events = null) => new(events ?? new(), clock, _ => { }, default);
    private sealed class IdleInput : IInputIdleSource { public double Seconds { get; set; } public TimeSpan GetIdleTime() => TimeSpan.FromSeconds(Seconds); }
    private sealed class Foreground : IForegroundSource { public ForegroundWindow? Window { get; set; } public ForegroundWindow? Read() => Window; }
    private sealed class CapturedEvents : IEventSink
    {
        public List<Episode> Episodes { get; } = [];
        public List<LogEntry> Logs { get; } = [];
        public List<ActivitySnapshot> Activity { get; } = [];
        public Task EnqueueAsync(Episode episode, CancellationToken ct) { Episodes.Add(episode); return Task.CompletedTask; }
        public Task EnqueueAsync(LogEntry log, CancellationToken ct) { Logs.Add(log); return Task.CompletedTask; }
        public Task EnqueueAsync(SyncRequestCommandResultsItem result, CancellationToken ct) => Task.CompletedTask;
        public Task EnqueueAsync(SecurityReport report, CancellationToken ct) => Task.CompletedTask;
        public Task EnqueueAsync(ActivitySnapshot activity, CancellationToken ct) { Activity.Add(activity); return Task.CompletedTask; }
    }
    private sealed class TrackingClock(string time) : TimeProvider
    {
        private DateTimeOffset _now = DateTimeOffset.Parse(time);
        private long _timestamp;
        public override DateTimeOffset GetUtcNow() => _now;
        public override long GetTimestamp() => _timestamp;
        public override long TimestampFrequency => TimeSpan.TicksPerSecond;
        public void Advance(double seconds) { _now = _now.AddSeconds(seconds); _timestamp += (long)(seconds * TimeSpan.TicksPerSecond); }
        public void JumpWall(double seconds) => _now = _now.AddSeconds(seconds);
    }
}
