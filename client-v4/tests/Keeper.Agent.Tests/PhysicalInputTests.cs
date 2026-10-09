extern alias session;
using ActivityTracker = session::Keeper.Session.Modules.Activity.ActivityTracker;
using IInputIdleSource = session::Keeper.Session.Modules.Activity.IInputIdleSource;
using IPhysicalInputSource = session::Keeper.Session.Modules.Activity.IPhysicalInputSource;
using PhysicalInputReading = session::Keeper.Session.Modules.Activity.PhysicalInputReading;
using PhysicalInputStatus = session::Keeper.Session.Modules.Activity.PhysicalInputStatus;
using RawInputClassifier = session::Keeper.Session.Modules.Activity.RawInputClassifier;
using RawInputKind = session::Keeper.Session.Modules.Activity.RawInputKind;
using WorkSchedule = session::Keeper.Session.Modules.Presence.WorkSchedule;
using Keeper.Shared.Contracts;
using Keeper.Shared.Protocol;

namespace Keeper.Agent.Tests;

// 4.0.22. Produccion 2026-10-09: ACT-0000 con 15-22 h seguidas "en uso" de noche y la pantalla de bloqueo delante, y
// ACT-0180 con 128 min de Outlook "en uso" mientras se instalaba por DWService. GetLastInputInfo cuenta tambien la entrada
// inyectada; desde ahora solo el teclado/raton fisico (Raw Input con dispositivo) es uso.
public sealed class PhysicalInputTests
{
    private static readonly DateOnly Day = new(2026, 9, 14);

    [Fact]
    public async Task EntradaFisicaRecienteEsActiva()
    {
        var (tracker, clock, _) = await Start(new Physical { Idle = TimeSpan.FromSeconds(10) });
        clock.Advance(20); await tracker.TickAsync(default);
        var snapshot = tracker.Snapshot(Day, 1)!;
        Assert.True(tracker.IsActive);
        Assert.Equal(20, snapshot.ActiveSeconds);
        Assert.Equal(0, snapshot.SimulatedSeconds);
        Assert.Equal(0, snapshot.RemoteSeconds);
    }

    [Theory]
    [InlineData(9999.0)]
    [InlineData(null)]          // ninguna entrada fisica desde que arranco la sesion
    public async Task SoloEntradaInyectadaEsInactivaYSumaSimulado(double? physicalIdle)
    {
        var (tracker, clock, _) = await Start(new Physical { Idle = physicalIdle is null ? null : TimeSpan.FromSeconds(physicalIdle.Value) });
        clock.Advance(20); await tracker.TickAsync(default);
        var snapshot = tracker.Snapshot(Day, 1)!;
        Assert.False(tracker.IsActive);
        Assert.Equal(0, snapshot.ActiveSeconds);
        Assert.Equal(20, snapshot.IdleSeconds);
        Assert.Equal(20, snapshot.SimulatedSeconds);
        Assert.Null(snapshot.FirstActivityAt);
    }

    [Fact]
    public async Task SinNingunaEntradaEsInactivoPeroNoSimulado()
    {
        var (tracker, clock, _) = await Start(new Physical { Idle = TimeSpan.FromSeconds(9999) }, lastInput: 9999);
        clock.Advance(20); await tracker.TickAsync(default);
        Assert.Equal(0, tracker.Snapshot(Day, 1)!.SimulatedSeconds);
    }

    [Fact]
    public async Task EnRdpUsaGetLastInputInfoYSumaRemoto()
    {
        var (tracker, clock, _) = await Start(new Physical { Status = PhysicalInputStatus.Remote });
        clock.Advance(20); await tracker.TickAsync(default);
        var snapshot = tracker.Snapshot(Day, 1)!;
        Assert.True(tracker.IsActive);
        Assert.Equal(20, snapshot.ActiveSeconds);
        Assert.Equal(20, snapshot.RemoteSeconds);
        Assert.Equal(0, snapshot.SimulatedSeconds);
    }

    [Fact]
    public async Task SinRawInputMideComoAntesYLoAvisaUnaVez()
    {
        var (tracker, clock, events) = await Start(new Physical { Status = PhysicalInputStatus.Unavailable, Error = "win32_5" });
        for (var i = 0; i < 3; i++) { clock.Advance(10); await tracker.TickAsync(default); }
        var snapshot = tracker.Snapshot(Day, 1)!;
        Assert.Equal(30, snapshot.ActiveSeconds);
        Assert.Null(snapshot.SimulatedSeconds);
        Assert.Null(snapshot.RemoteSeconds);
        var log = Assert.Single(events.Logs);
        Assert.Equal("physical_input_unavailable", log.Code);
        Assert.Equal(LogEntryLevel.Warn, log.Level);
        Assert.Equal("win32_5", log.Fields?.ErrorCode);
    }

    [Fact]
    public async Task SinFuenteFisicaNoReportaSimulado()
    {
        var clock = new Clock("2026-09-14T10:00:00Z");
        var tracker = new ActivityTracker(new LastInput(), idleThresholdSeconds: 600) { Schedule = new WorkSchedule(TimeZoneInfo.Utc) };
        await tracker.InitAsync(new ModuleContext(new Events(), clock, _ => { }, default));
        clock.Advance(10); await tracker.TickAsync(default);
        var snapshot = tracker.Snapshot(Day, 1)!;
        Assert.Equal(10, snapshot.ActiveSeconds);
        Assert.Null(snapshot.SimulatedSeconds);
    }

    [Fact]
    public async Task LaReglaDeLlamadasSeAplicaSobreLaEntradaFisica()
    {
        var physical = new Physical { Idle = TimeSpan.FromSeconds(100) };
        var clock = new Clock("2026-09-14T10:00:00Z");
        var tracker = new ActivityTracker(new LastInput(), () => true, 60, 300, physical: physical) { Schedule = new WorkSchedule(TimeZoneInfo.Utc) };
        await tracker.InitAsync(new ModuleContext(new Events(), clock, _ => { }, default));
        clock.Advance(5); await tracker.TickAsync(default);
        Assert.True(tracker.IsActive);
        physical.Idle = TimeSpan.FromSeconds(301);
        clock.Advance(5); await tracker.TickAsync(default);
        Assert.False(tracker.IsActive);
        Assert.Equal(5, tracker.Snapshot(Day, 1)!.SimulatedSeconds);
    }

    [Fact]
    public async Task RachaSimuladaDeCincoMinutosSeAvisaUnaVezYSuFinConDuracion()
    {
        var physical = new Physical { Idle = TimeSpan.FromHours(1) };
        var (tracker, clock, events) = await Start(physical);
        for (var i = 0; i < 9; i++) { clock.Advance(30); await tracker.TickAsync(default); }   // 270 s: aun no
        Assert.Empty(events.Logs);
        for (var i = 0; i < 4; i++) { clock.Advance(30); await tracker.TickAsync(default); }   // 390 s
        var warn = Assert.Single(events.Logs);
        Assert.Equal(("simulated_input", LogEntryLevel.Warn), (warn.Code, warn.Level));
        physical.Idle = TimeSpan.Zero;
        clock.Advance(30); await tracker.TickAsync(default);
        var end = events.Logs[^1];
        Assert.Equal(("simulated_input_end", LogEntryLevel.Info, "390s"), (end.Code, end.Level, end.Fields?.ErrorCode));
        Assert.Equal(2, events.Logs.Count);
        Assert.Equal(390, tracker.Snapshot(Day, 1)!.SimulatedSeconds);
    }

    [Fact]
    public async Task EstadoViejoSinCamposSeRestauraYEmpiezaAMedirSimulado()
    {
        using var directory = new TestDirectory();
        var path = directory.File("activity-state.json");
        // Formato escrito por 4.0.21: sin Simulated ni Remote.
        await File.WriteAllTextAsync(path, """{"Sequence":42,"Days":[{"Day":"2026-09-14","Active":100,"Idle":50,"Call":0,"First":"2026-09-14T08:00:00+00:00","Last":"2026-09-14T09:00:00+00:00","Samples":7}],"Totals":[]}""");
        var clock = new Clock("2026-09-14T10:00:00Z");
        var physical = new Physical { Status = PhysicalInputStatus.Unavailable };
        var tracker = new ActivityTracker(new LastInput(), statePath: path, physical: physical) { Schedule = new WorkSchedule(TimeZoneInfo.Utc) };
        await tracker.InitAsync(new ModuleContext(new Events(), clock, _ => { }, default));
        var restored = tracker.Days[Day];
        Assert.Equal((100, 50, 7L), (restored.Active, restored.Idle, restored.Samples));
        Assert.Null(restored.Simulated);
        Assert.Null(tracker.Snapshot(Day, 43)!.SimulatedSeconds);

        physical.Status = PhysicalInputStatus.Physical; physical.Idle = null;
        clock.Advance(10); await tracker.TickAsync(default);
        await tracker.ShutdownAsync();
        var reloaded = new ActivityTracker(new LastInput(), statePath: path) { Schedule = new WorkSchedule(TimeZoneInfo.Utc) };
        await reloaded.InitAsync(new ModuleContext(new Events(), clock, _ => { }, default));
        Assert.Equal(10, reloaded.Days[Day].Simulated);
        Assert.Equal(0, reloaded.Days[Day].Remote);
        Assert.Equal(60, reloaded.Days[Day].Idle);
    }

    [Theory]
    [InlineData(RawInputClassifier.Keyboard, 0L, 0, 0, 0, 0, RawInputKind.Injected)]
    [InlineData(RawInputClassifier.Keyboard, 7L, 0, 0, 0, 0, RawInputKind.Physical)]
    [InlineData(RawInputClassifier.Mouse, 7L, 0, 0, 3, 0, RawInputKind.Physical)]
    [InlineData(RawInputClassifier.Mouse, 0L, 0, 0, 3, -1, RawInputKind.Injected)]
    [InlineData(RawInputClassifier.Mouse, 7L, 0, 1, 0, 0, RawInputKind.Physical)]      // boton sin movimiento
    [InlineData(RawInputClassifier.Mouse, 7L, 0, 0, 0, 0, RawInputKind.Ignored)]       // paquete vacio
    [InlineData(RawInputClassifier.Mouse, 0L, 1, 0, 0, 0, RawInputKind.Injected)]      // absoluto (SendInput con MOUSEEVENTF_ABSOLUTE)
    [InlineData(2u, 7L, 0, 0, 0, 0, RawInputKind.Ignored)]                               // HID
    public void ClasificaRawInputPorDispositivo(uint type, long device, int flags, int buttons, int x, int y, RawInputKind expected) =>
        Assert.Equal(expected, RawInputClassifier.Classify(type, (nint)device, (ushort)flags, (ushort)buttons, x, y));

    private static async Task<(ActivityTracker, Clock, Events)> Start(Physical physical, double lastInput = 0)
    {
        var clock = new Clock("2026-09-14T10:00:00Z");
        var events = new Events();
        var tracker = new ActivityTracker(new LastInput { Seconds = lastInput }, idleThresholdSeconds: 600, physical: physical) { Schedule = new WorkSchedule(TimeZoneInfo.Utc) };
        await tracker.InitAsync(new ModuleContext(events, clock, _ => { }, default));
        return (tracker, clock, events);
    }

    private sealed class LastInput : IInputIdleSource { public double Seconds { get; set; } public TimeSpan GetIdleTime() => TimeSpan.FromSeconds(Seconds); }
    private sealed class Physical : IPhysicalInputSource
    {
        public PhysicalInputStatus Status { get; set; } = PhysicalInputStatus.Physical;
        public TimeSpan? Idle { get; set; }
        public string? Error { get; set; }
        public PhysicalInputReading Read() => new(Status, Idle, Error);
    }
    private sealed class Events : IEventSink
    {
        public List<LogEntry> Logs { get; } = [];
        public Task EnqueueAsync(Episode episode, CancellationToken ct) => Task.CompletedTask;
        public Task EnqueueAsync(LogEntry log, CancellationToken ct) { Logs.Add(log); return Task.CompletedTask; }
        public Task EnqueueAsync(SyncRequestCommandResultsItem result, CancellationToken ct) => Task.CompletedTask;
        public Task EnqueueAsync(SecurityReport report, CancellationToken ct) => Task.CompletedTask;
        public Task EnqueueAsync(ActivitySnapshot activity, CancellationToken ct) => Task.CompletedTask;
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
