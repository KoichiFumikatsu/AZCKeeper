using Keeper.Agent.Modules.Maintenance;
using Keeper.Shared.Contracts;
using Keeper.Shared.Protocol;
using Keeper.Shared.Time;

namespace Keeper.Agent.Tests;

// Modulo TimeSync con un sistema simulado: se verifican los comandos que se ejecutarian (nunca w32tm de verdad).
public sealed class TimeSyncTests
{
    private static readonly TimeSettings Good = new(2, true, "NTP", "time.windows.com,0x9", false, 3, "SA Pacific Standard Time");
    private static readonly TimeSettings Factory = new(3, false, "NoSync", null, false, 4, "SA Pacific Standard Time");

    private sealed class FakeTime(TestClock clock) : ITimeSystem
    {
        public TimeSettings Settings { get; set; } = Good;
        public List<string> Commands { get; } = [];
        public int Reads { get; private set; }
        // Lo que corrige la resincronizacion en el reloj de Windows.
        public TimeSpan ResyncCorrection { get; set; }
        public int ResyncExit { get; set; }
        public Task<TimeSettings> ReadAsync(CancellationToken ct) { Reads++; return Task.FromResult(Settings); }
        public Task<TimeCommandResult> RunAsync(TimeCommand command, CancellationToken ct)
        {
            lock (Commands) Commands.Add(command.ToString());
            if (command == TimeSyncPlan.Resync)
            {
                clock.ShiftWallClock(ResyncCorrection);
                ResyncCorrection = TimeSpan.Zero;
                return Task.FromResult(new TimeCommandResult(ResyncExit, ""));
            }
            if (command.Item != "zona_horaria") Settings = Good with { TimeZoneId = Settings.TimeZoneId };
            else Settings = Settings with { TimeZoneId = command.Arguments[1] };
            return Task.FromResult(new TimeCommandResult(0, ""));
        }
    }

    private sealed class Harness
    {
        public TestClock Clock { get; } = new();
        public FakeTime Time { get; }
        public MemoryEvents Events { get; } = new();
        public List<TimeSpan> Jumps { get; } = [];
        public TimeSpan? ServerOffset { get; set; }
        public bool Defer { get; set; }
        public TimeSync Module { get; }
        public Harness(bool enabled = true)
        {
            Time = new FakeTime(Clock);
            Module = new TimeSync(Time, enabled, () => ServerOffset, () => Defer) { OnClockJump = Jumps.Add };
        }
        public Task InitAsync() => Module.InitAsync(new ModuleContext(Events, Clock, _ => { }, CancellationToken.None));
        // Un tick y, si arranco comandos, espera que terminen y los recoge con el tick siguiente.
        public async Task TickAsync()
        {
            await Module.TickAsync(default);
            if (Module.Running is { } running)
            {
                await running.WaitAsync(TimeSpan.FromSeconds(10));
                await Module.TickAsync(default);
            }
        }
        public void Clear() { lock (Time.Commands) Time.Commands.Clear(); Events.Logs.Clear(); }
        public IEnumerable<(string Code, string? Detail, LogEntryLevel Level)> Logs =>
            Events.Logs.Select(l => (l.Code, l.Fields?.ErrorCode, l.Level));
    }

    [Fact]
    public async Task StartupTurnsOnAutomaticTimeAndZoneThenResyncsAndReportsTheCorrection()
    {
        var h = new Harness();
        h.Time.Settings = Factory;
        h.Time.ResyncCorrection = TimeSpan.FromMinutes(-5);
        await h.InitAsync();
        await h.TickAsync();
        Assert.Equal([
            "sc.exe config w32time start= auto", "sc.exe start w32time",
            "w32tm.exe /config /manualpeerlist:time.windows.com,0x9 /syncfromflags:manual /update",
            "sc.exe config tzautoupdate start= demand", "w32tm.exe /resync"], h.Time.Commands);
        Assert.Equal([
            ("time_settings_applied", "servicio_hora.servicio_detenido.hora_automatica.zona_automatica", LogEntryLevel.Info),
            ("time_resynced", "desfase_-300s", LogEntryLevel.Info)], h.Logs);
        Assert.Equal([TimeSpan.FromMinutes(-5)], h.Jumps);
        Assert.Equal("applied", h.Module.Snapshot().State);
    }

    [Fact]
    public async Task GoodSettingsResyncAtStartAndEverySixHoursWithoutNoise()
    {
        var h = new Harness();
        await h.InitAsync();
        await h.TickAsync();
        Assert.Equal(["w32tm.exe /resync"], h.Time.Commands);
        Assert.Empty(h.Logs);
        h.Clear();
        h.Clock.Advance(TimeSync.CheckInterval);
        await h.TickAsync();
        Assert.Empty(h.Time.Commands);
        Assert.Equal(2, h.Time.Reads);
        h.Clock.Advance(TimeSync.ResyncInterval);
        await h.TickAsync();
        Assert.Equal(["w32tm.exe /resync"], h.Time.Commands);
        Assert.Empty(h.Logs);
    }

    [Fact]
    public async Task UserDisablingAutomaticTimeIsRestoredAndReported()
    {
        var h = new Harness();
        await h.InitAsync();
        await h.TickAsync();
        h.Clear();
        h.Time.Settings = Good with { NtpType = "NoSync", TzAutoUpdateStart = 4 };
        h.Clock.Advance(TimeSync.CheckInterval);
        await h.TickAsync();
        Assert.Equal([
            "w32tm.exe /config /manualpeerlist:time.windows.com,0x9 /syncfromflags:manual /update",
            "sc.exe config tzautoupdate start= demand", "w32tm.exe /resync"], h.Time.Commands);
        Assert.Equal([("time_settings_restored", "hora_automatica.zona_automatica", LogEntryLevel.Warn)], h.Logs);
    }

    [Fact]
    public async Task ManualClockChangeIsDetectedAgainstTheMonotonicClockAndResynced()
    {
        var h = new Harness();
        await h.InitAsync();
        await h.TickAsync();
        h.Clear();
        // Alguien adelanta la hora 1 h; NTP la devuelve.
        h.Clock.ShiftWallClock(TimeSpan.FromHours(1));
        h.Time.ResyncCorrection = TimeSpan.FromHours(-1);
        h.Clock.Advance(TimeSpan.FromSeconds(1));
        await h.TickAsync();
        Assert.Equal(["w32tm.exe /resync"], h.Time.Commands);
        Assert.Equal([("time_resynced", "desfase_-3600s.hora_cambiada", LogEntryLevel.Info)], h.Logs);
        // El desfase de las firmas recibe los dos saltos: queda como estaba.
        Assert.Equal([TimeSpan.FromHours(1), TimeSpan.FromHours(-1)], h.Jumps);
        // Otro cambio enseguida no dispara otra resincronizacion antes de 10 min (evita bucles).
        h.Clear();
        h.Clock.ShiftWallClock(TimeSpan.FromHours(1));
        h.Clock.Advance(TimeSpan.FromSeconds(1));
        await h.TickAsync();
        Assert.Empty(h.Time.Commands);
    }

    [Fact]
    public async Task ServerSkewTriggersResyncButSmallSkewDoesNot()
    {
        var h = new Harness();
        await h.InitAsync();
        await h.TickAsync();
        h.Clear();
        h.ServerOffset = TimeSpan.FromSeconds(20);
        h.Clock.Advance(TimeSpan.FromSeconds(1));
        await h.TickAsync();
        Assert.Empty(h.Time.Commands);
        h.ServerOffset = TimeSpan.FromMinutes(5);
        h.Time.ResyncCorrection = TimeSpan.FromMinutes(5);
        h.Clock.Advance(TimeSpan.FromSeconds(1));
        await h.TickAsync();
        Assert.Equal(["w32tm.exe /resync"], h.Time.Commands);
        Assert.Equal([("time_resynced", "desfase_300s.desfase_servidor", LogEntryLevel.Info)], h.Logs);
    }

    [Fact]
    public async Task ResyncFailureIsReportedOnceAndRetriedIn15Minutes()
    {
        var h = new Harness();
        h.Time.ResyncExit = 1;
        await h.InitAsync();
        await h.TickAsync();
        Assert.Equal([("time_resync_failed", "w32tm_salida_1", LogEntryLevel.Warn)], h.Logs);
        h.Clear();
        h.Clock.Advance(TimeSync.CheckInterval);
        await h.TickAsync();
        Assert.Empty(h.Time.Commands);
        h.Clock.Advance(TimeSync.RetryAfterFailure);
        await h.TickAsync();
        Assert.Equal(["w32tm.exe /resync"], h.Time.Commands);
        Assert.Empty(h.Logs);
        Assert.Equal("resync_failed", h.Module.Snapshot().State);
    }

    [Fact]
    public async Task NothingRunsDuringAPowerCountdownOrInDryRun()
    {
        var h = new Harness { Defer = true };
        await h.InitAsync();
        await h.TickAsync();
        Assert.Empty(h.Time.Commands);
        Assert.Equal(0, h.Time.Reads);
        h.Defer = false;
        await h.TickAsync();
        Assert.Equal(["w32tm.exe /resync"], h.Time.Commands);

        var dry = new Harness(enabled: false);
        dry.Time.Settings = Factory;
        await dry.InitAsync();
        await dry.TickAsync();
        Assert.Empty(dry.Time.Commands);
        Assert.Equal("dry_run", dry.Module.Snapshot().State);
    }

    [Fact]
    public async Task ManualZoneChangeIsReturnedToTheLastKnownZone()
    {
        var h = new Harness();
        await h.InitAsync();
        await h.TickAsync();
        h.Clear();
        h.Time.Settings = Good with { TzAutoUpdateStart = 4, TimeZoneId = "Pacific Standard Time" };
        h.Clock.Advance(TimeSync.CheckInterval);
        await h.TickAsync();
        Assert.Equal(["sc.exe config tzautoupdate start= demand", "tzutil.exe /s SA Pacific Standard Time", "w32tm.exe /resync"], h.Time.Commands);
        Assert.Equal([("time_settings_restored", "zona_automatica.zona_horaria", LogEntryLevel.Warn)], h.Logs);
        // Con la zona automatica encendida, un cambio de zona lo hizo Windows: se acepta.
        h.Clear();
        h.Time.Settings = Good with { TimeZoneId = "Eastern Standard Time" };
        h.Clock.Advance(TimeSync.CheckInterval);
        await h.TickAsync();
        Assert.Empty(h.Time.Commands);
    }

    [Theory]
    [InlineData("NT5DS", false)]
    [InlineData("NTP", false)]
    [InlineData("NoSync", true)]
    public void DomainTimeSourceIsNeverOverwritten(string type, bool reenabled)
    {
        var commands = TimeSyncPlan.Restore(new(2, true, type, "dc.corp.local,0x8", true, 3, "x"));
        string[] expected = reenabled ? ["w32tm.exe /config /syncfromflags:domhier /update"] : [];
        Assert.Equal(expected, commands.Select(c => c.ToString()));
    }

    [Fact]
    public void PlanHelpers()
    {
        Assert.True(TimeSyncPlan.UsesWindowsNtp("time.windows.com,0x9"));
        Assert.True(TimeSyncPlan.UsesWindowsNtp("pool.ntp.org TIME.windows.com"));
        Assert.False(TimeSyncPlan.UsesWindowsNtp("evil.example,0x9"));
        Assert.False(TimeSyncPlan.UsesWindowsNtp(null));
        Assert.True(TimeSyncPlan.IsRunning("SERVICE_NAME: w32time\r\n        ESTADO              : 4  RUNNING\r\n"));
        Assert.False(TimeSyncPlan.IsRunning("SERVICE_NAME: w32time\r\n        STATE              : 1  STOPPED\r\n"));
        Assert.Empty(TimeSyncPlan.Restore(Good));
    }
}
