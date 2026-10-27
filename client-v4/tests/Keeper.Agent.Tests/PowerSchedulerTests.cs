using System.Text.Json;
using Keeper.Agent.Modules.Devices;
using Keeper.Agent.Storage;
using Keeper.Shared.Contracts;
using Keeper.Shared.Protocol;

namespace Keeper.Agent.Tests;

// Apagado/reinicio programado (agente 4.0.30): programación semanal viernes 19:00 Bogotá = 2026-10-31 00:00Z.
public sealed class PowerSchedulerTests
{
    private static readonly DateTimeOffset Occurrence = new(2026, 10, 31, 0, 0, 0, TimeSpan.Zero);
    private static readonly DateTimeOffset Before = Occurrence.AddHours(-1); // 18:00 Bogotá

    private sealed class RecordingActions : IDeviceActions
    {
        public List<DeviceAction> Executed { get; } = [];
        public Exception? Failure { get; set; }
        public Task ExecuteAsync(DeviceAction action, CancellationToken ct)
        {
            if (Failure is not null) throw Failure;
            Executed.Add(action);
            return Task.CompletedTask;
        }
    }

    private sealed class Rig : IDisposable
    {
        public TestDirectory Directory { get; } = new();
        public TestClock Clock { get; } = new();
        public RecordingActions Actions { get; } = new();
        public List<long?> Idle { get; set; } = [1200];
        public PowerNotice? Manual { get; set; }
        public DateTimeOffset Boot { get; set; } = Occurrence.AddHours(-10);
        public Func<DateTimeOffset>? BootClock { get; set; }
        public TimeSpan Offset { get; set; }
        public ModuleContext Context { get; }
        public PowerScheduler Scheduler { get; private set; } = null!;
        public MemoryEvents Events => (MemoryEvents)Context.Outbox;
        public List<PowerScheduleRunReport> Runs => Events.PowerRuns;
        public Rig() => Context = Samples.Context(Clock);

        public static async Task<Rig> StartAsync(params PowerScheduleDirective[] schedules)
        {
            var rig = new Rig();
            rig.At(Before);
            await rig.RestartAgentAsync();
            await rig.Scheduler.OfferAsync(schedules, default);
            await rig.TickAsync();
            Assert.Empty(rig.Runs);
            return rig;
        }

        // Simula un reinicio del agente: instancia nueva sobre el mismo power-schedules.json.
        public async Task RestartAgentAsync()
        {
            Scheduler = new PowerScheduler(Directory.File("power-schedules.json"), Actions, () => Idle, () => Manual, () => BootClock?.Invoke() ?? Boot)
            {
                ServerOffset = () => Offset
            };
            await Scheduler.InitAsync(Context);
        }

        public void At(DateTimeOffset utc) => Clock.ShiftWallClock(utc - Clock.GetUtcNow());
        public Task TickAsync() => Scheduler.TickAsync(default);
        public void Dispose() => Directory.Dispose();
    }

    private static PowerScheduleDirective Friday(PowerScheduleDirectiveAction action = PowerScheduleDirectiveAction.Shutdown) => new()
    {
        Id = Guid.NewGuid(), Version = 1, Action = action, Recurrence = PowerScheduleDirectiveRecurrence.Weekly, Days = [5],
        TimeLocal = "19:00", ValidFrom = new DateOnly(2026, 10, 1), Timezone = "America/Bogota", Message = "Fin de jornada",
        IdleMinutes = 15, NoticeMinutes = 10, PostponeMinutes = 60, WindowMinutes = 180,
        UpdatedAt = new DateTimeOffset(2026, 10, 1, 15, 0, 0, TimeSpan.Zero),
    };

    private static void AssertRun(PowerScheduleRunReport run, PowerScheduleDirective s, PowerScheduleRunReportOutcome outcome)
    {
        Assert.Equal(s.Id, run.ScheduleId);
        Assert.Equal(Occurrence, run.OccurrenceAt);
        Assert.Equal(outcome, run.Outcome);
    }

    [Fact]
    public async Task SinUsoSeEjecutaSinPreguntar()
    {
        var s = Friday();
        using var rig = await Rig.StartAsync(s);
        rig.At(Occurrence.AddSeconds(5));
        await rig.TickAsync();
        Assert.Equal([DeviceAction.Shutdown], rig.Actions.Executed);
        AssertRun(Assert.Single(rig.Runs), s, PowerScheduleRunReportOutcome.ExecutedIdle);
        Assert.Null(rig.Scheduler.PendingNotice);
    }

    [Fact]
    public async Task SinSesionesSeEjecuta()
    {
        var s = Friday();
        using var rig = await Rig.StartAsync(s);
        rig.Idle = [];
        rig.At(Occurrence);
        await rig.TickAsync();
        Assert.Equal([DeviceAction.Shutdown], rig.Actions.Executed);
        AssertRun(Assert.Single(rig.Runs), s, PowerScheduleRunReportOutcome.ExecutedIdle);
        Assert.Null(rig.Scheduler.PendingNotice);
    }

    [Fact]
    public async Task EnUsoAvisaYEjecutaAlVencer()
    {
        var s = Friday();
        using var rig = await Rig.StartAsync(s);
        rig.Idle = [30];
        rig.At(Occurrence);
        await rig.TickAsync();
        var notice = rig.Scheduler.PendingNotice!;
        Assert.Equal(PowerNoticeAction.Shutdown, notice.Action);
        Assert.Equal(Occurrence.AddMinutes(10), notice.Deadline);
        Assert.Equal("Fin de jornada", notice.Message);
        Assert.Equal(60, notice.PostponeMinutes);
        Assert.False(notice.Compact);
        Assert.False(notice.Cancelled);
        Assert.NotNull(notice.RunId);
        Assert.True(rig.Scheduler.CountdownActive);
        Assert.Empty(rig.Actions.Executed);
        Assert.Empty(rig.Runs);

        rig.Clock.Advance(TimeSpan.FromMinutes(9));
        await rig.TickAsync();
        Assert.Empty(rig.Actions.Executed);
        rig.Clock.Advance(TimeSpan.FromMinutes(1));
        await rig.TickAsync();
        Assert.Equal([DeviceAction.Shutdown], rig.Actions.Executed);
        AssertRun(Assert.Single(rig.Runs), s, PowerScheduleRunReportOutcome.ExecutedAfterNotice);
        Assert.Null(rig.Scheduler.PendingNotice);
        Assert.False(rig.Scheduler.CountdownActive);
    }

    [Fact]
    public async Task InactividadDesconocidaCuentaComoEnUso()
    {
        using var rig = await Rig.StartAsync(Friday());
        rig.Idle = [3600, null];
        rig.At(Occurrence);
        await rig.TickAsync();
        Assert.NotNull(rig.Scheduler.PendingNotice);
        Assert.Empty(rig.Actions.Executed);
    }

    [Fact]
    public async Task PosponerUnaSolaVez()
    {
        var s = Friday();
        using var rig = await Rig.StartAsync(s);
        rig.Idle = [30];
        rig.At(Occurrence);
        await rig.TickAsync();
        var run = rig.Scheduler.PendingNotice!.RunId!.Value;
        rig.Scheduler.Choose(new PowerChoice(run, PowerChoiceKind.Postpone));
        await rig.TickAsync();
        AssertRun(Assert.Single(rig.Runs), s, PowerScheduleRunReportOutcome.Postponed);
        Assert.Null(rig.Scheduler.PendingNotice);
        Assert.True(rig.Scheduler.CountdownActive);

        rig.Clock.Advance(TimeSpan.FromMinutes(50));
        await rig.TickAsync();
        var compact = rig.Scheduler.PendingNotice!;
        Assert.True(compact.Compact);
        Assert.Null(compact.PostponeMinutes);
        Assert.Equal(Occurrence.AddMinutes(60), compact.Deadline);
        Assert.Equal(run, compact.RunId);

        // La sesión vuelve a ofrecer los botones tras reiniciarse: el segundo «Posponer» se ignora.
        rig.Scheduler.Choose(new PowerChoice(run, PowerChoiceKind.Postpone));
        await rig.TickAsync();
        Assert.Single(rig.Runs);
        Assert.Equal(Occurrence.AddMinutes(60), rig.Scheduler.PendingNotice!.Deadline);

        rig.Clock.Advance(TimeSpan.FromMinutes(10));
        await rig.TickAsync();
        Assert.Equal([DeviceAction.Shutdown], rig.Actions.Executed);
        Assert.Equal([PowerScheduleRunReportOutcome.Postponed, PowerScheduleRunReportOutcome.ExecutedAfterNotice], rig.Runs.Select(r => r.Outcome));
    }

    [Fact]
    public async Task AhoraLoEjecutaLaPersona()
    {
        var s = Friday(PowerScheduleDirectiveAction.Restart);
        using var rig = await Rig.StartAsync(s);
        rig.Idle = [30];
        rig.At(Occurrence);
        await rig.TickAsync();
        var run = rig.Scheduler.PendingNotice!.RunId!.Value;
        Assert.Equal(PowerNoticeAction.Restart, rig.Scheduler.PendingNotice.Action);
        rig.Scheduler.Choose(new PowerChoice(Guid.NewGuid(), PowerChoiceKind.Now)); // otra ocurrencia: se ignora
        await rig.TickAsync();
        Assert.Empty(rig.Actions.Executed);
        rig.Scheduler.Choose(new PowerChoice(run, PowerChoiceKind.Now));
        await rig.TickAsync();
        Assert.Equal([DeviceAction.Restart], rig.Actions.Executed);
        AssertRun(Assert.Single(rig.Runs), s, PowerScheduleRunReportOutcome.ExecutedByPerson);
        Assert.Null(rig.Scheduler.PendingNotice);
    }

    [Fact]
    public async Task OrdenManualGana()
    {
        var s = Friday();
        using var rig = await Rig.StartAsync(s);
        rig.Idle = [30];
        rig.Manual = new PowerNotice(PowerNoticeAction.Restart, Occurrence.AddMinutes(5));
        rig.At(Occurrence);
        await rig.TickAsync();
        Assert.Null(rig.Scheduler.PendingNotice);
        AssertRun(Assert.Single(rig.Runs), s, PowerScheduleRunReportOutcome.Replaced);
        rig.Clock.Advance(TimeSpan.FromMinutes(11));
        await rig.TickAsync();
        Assert.Empty(rig.Actions.Executed);
        Assert.Single(rig.Runs);
    }

    [Fact]
    public async Task OrdenManualDuranteElAvisoGana()
    {
        var s = Friday();
        using var rig = await Rig.StartAsync(s);
        rig.Idle = [30];
        rig.At(Occurrence);
        await rig.TickAsync();
        Assert.NotNull(rig.Scheduler.PendingNotice);
        rig.Manual = new PowerNotice(PowerNoticeAction.Shutdown, Occurrence.AddMinutes(5));
        rig.Clock.Advance(TimeSpan.FromSeconds(1));
        await rig.TickAsync();
        Assert.Null(rig.Scheduler.PendingNotice);
        AssertRun(Assert.Single(rig.Runs), s, PowerScheduleRunReportOutcome.Replaced);
        rig.Clock.Advance(TimeSpan.FromMinutes(11));
        await rig.TickAsync();
        Assert.Empty(rig.Actions.Executed);
    }

    [Fact]
    public async Task TiPausaDuranteElAviso()
    {
        var s = Friday();
        using var rig = await Rig.StartAsync(s);
        rig.Idle = [30];
        rig.At(Occurrence);
        await rig.TickAsync();
        await rig.Scheduler.OfferAsync([], default);
        var cancelled = rig.Scheduler.PendingNotice!;
        Assert.True(cancelled.Cancelled);
        Assert.Equal(PowerNoticeAction.Shutdown, cancelled.Action);
        AssertRun(Assert.Single(rig.Runs), s, PowerScheduleRunReportOutcome.CancelledByIt);
        Assert.False(rig.Scheduler.CountdownActive);

        rig.Clock.Advance(TimeSpan.FromSeconds(59));
        await rig.TickAsync();
        Assert.True(rig.Scheduler.PendingNotice!.Cancelled);
        rig.Clock.Advance(TimeSpan.FromSeconds(2));
        await rig.TickAsync();
        Assert.Null(rig.Scheduler.PendingNotice);
        rig.Clock.Advance(TimeSpan.FromMinutes(10));
        await rig.TickAsync();
        Assert.Empty(rig.Actions.Executed);
        Assert.Single(rig.Runs);
    }

    [Fact]
    public async Task TiEditaDuranteElAviso()
    {
        var s = Friday();
        using var rig = await Rig.StartAsync(s);
        rig.Idle = [30];
        rig.At(Occurrence);
        await rig.TickAsync();
        await rig.Scheduler.OfferAsync([s with { Version = 2, TimeLocal = "20:00" }], default);
        Assert.True(rig.Scheduler.PendingNotice!.Cancelled);
        AssertRun(Assert.Single(rig.Runs), s, PowerScheduleRunReportOutcome.CancelledByIt);
        rig.Clock.Advance(TimeSpan.FromMinutes(15));
        await rig.TickAsync();
        Assert.Empty(rig.Actions.Executed);
    }

    [Fact]
    public async Task SuspendidoFueraDeVentana()
    {
        var s = Friday();
        using var rig = await Rig.StartAsync(s);
        rig.At(Occurrence.AddHours(3.5)); // 22:30 Bogotá; ventana de 180 min
        await rig.TickAsync();
        AssertRun(Assert.Single(rig.Runs), s, PowerScheduleRunReportOutcome.SkippedSuspended);
        Assert.Empty(rig.Actions.Executed);
        Assert.Null(rig.Scheduler.PendingNotice);
    }

    [Fact]
    public async Task SuspendidoDentroDeVentana()
    {
        var s = Friday();
        using var rig = await Rig.StartAsync(s);
        rig.Idle = [30];
        rig.At(Occurrence.AddHours(2)); // 21:00 Bogotá
        await rig.TickAsync();
        Assert.Equal(Occurrence.AddHours(2).AddMinutes(10), rig.Scheduler.PendingNotice!.Deadline);
        rig.Clock.Advance(TimeSpan.FromMinutes(10));
        await rig.TickAsync();
        Assert.Equal([DeviceAction.Shutdown], rig.Actions.Executed);
        AssertRun(Assert.Single(rig.Runs), s, PowerScheduleRunReportOutcome.ExecutedAfterNotice);
    }

    [Fact]
    public async Task EquipoApagadoAntes()
    {
        var s = Friday();
        using var rig = await Rig.StartAsync(s);
        rig.Boot = Occurrence.AddMinutes(30);
        rig.At(Occurrence.AddMinutes(40));
        await rig.TickAsync();
        AssertRun(Assert.Single(rig.Runs), s, PowerScheduleRunReportOutcome.NotNeeded);
        Assert.Empty(rig.Actions.Executed);
    }

    [Fact]
    public async Task ReinicioGanaEnElMismoMinuto()
    {
        var shutdown = Friday();
        var restart = Friday(PowerScheduleDirectiveAction.Restart);
        using var rig = await Rig.StartAsync(shutdown, restart);
        rig.At(Occurrence.AddSeconds(20));
        await rig.TickAsync();
        Assert.Equal([DeviceAction.Restart], rig.Actions.Executed);
        Assert.Equal(2, rig.Runs.Count);
        AssertRun(Assert.Single(rig.Runs, r => r.ScheduleId == restart.Id), restart, PowerScheduleRunReportOutcome.ExecutedIdle);
        AssertRun(Assert.Single(rig.Runs, r => r.ScheduleId == shutdown.Id), shutdown, PowerScheduleRunReportOutcome.Replaced);
        rig.Clock.Advance(TimeSpan.FromMinutes(1));
        await rig.TickAsync();
        Assert.Single(rig.Actions.Executed);
    }

    [Fact]
    public async Task SobreviveAlReinicioDelAgente()
    {
        var s = Friday();
        using var rig = await Rig.StartAsync(s);
        rig.Idle = [30];
        rig.At(Occurrence);
        await rig.TickAsync();
        var run = rig.Scheduler.PendingNotice!.RunId!.Value;
        rig.Scheduler.Choose(new PowerChoice(run, PowerChoiceKind.Postpone));
        await rig.TickAsync();

        await rig.RestartAgentAsync();
        Assert.True(rig.Scheduler.CountdownActive);
        await rig.TickAsync();
        Assert.Null(rig.Scheduler.PendingNotice);
        rig.Clock.Advance(TimeSpan.FromMinutes(50));
        await rig.TickAsync();
        var notice = rig.Scheduler.PendingNotice!;
        Assert.True(notice.Compact);
        Assert.Equal(Occurrence.AddMinutes(60), notice.Deadline);
        Assert.Equal(run, notice.RunId);
        rig.Scheduler.Choose(new PowerChoice(run, PowerChoiceKind.Postpone));
        await rig.TickAsync();
        AssertRun(Assert.Single(rig.Runs), s, PowerScheduleRunReportOutcome.Postponed);
        rig.Clock.Advance(TimeSpan.FromMinutes(10));
        await rig.TickAsync();
        Assert.Equal([DeviceAction.Shutdown], rig.Actions.Executed);
    }

    [Fact]
    public async Task NoRepiteLaMismaOcurrencia()
    {
        var s = Friday();
        using var rig = await Rig.StartAsync(s);
        rig.At(Occurrence);
        await rig.TickAsync();
        rig.Clock.Advance(TimeSpan.FromMinutes(1));
        await rig.TickAsync();
        await rig.Scheduler.OfferAsync([s], default);
        await rig.TickAsync();
        await rig.RestartAgentAsync();
        rig.Clock.Advance(TimeSpan.FromMinutes(30));
        await rig.TickAsync();
        Assert.Equal([DeviceAction.Shutdown], rig.Actions.Executed);
        Assert.Single(rig.Runs);
    }

    [Fact]
    public async Task DryRun()
    {
        var s = Friday();
        using var rig = await Rig.StartAsync(s);
        rig.Actions.Failure = new NotSupportedException("device_actions_dry_run");
        rig.At(Occurrence);
        await rig.TickAsync();
        var log = Assert.Single(rig.Events.Logs, l => l.Component == "PowerScheduler");
        Assert.Equal("device_actions_dry_run", log.Fields?.ErrorCode);
        var run = Assert.Single(rig.Runs);
        AssertRun(run, s, PowerScheduleRunReportOutcome.Unsupported);
        Assert.Equal("device_actions_dry_run", run.Detail);
        rig.Clock.Advance(TimeSpan.FromMinutes(1));
        await rig.TickAsync();
        Assert.Single(rig.Runs);
    }

    // TI la creó o editó después de la hora: esa ocurrencia no apaga el equipo de golpe ni se reporta.
    [Fact]
    public async Task ProgramacionEditadaDespuesDeLaHoraNoSeEjecuta()
    {
        var s = Friday();
        using var rig = await Rig.StartAsync(s);
        rig.Idle = [];
        rig.Boot = Occurrence.AddMinutes(-30);
        rig.At(Occurrence.AddMinutes(30)); // suspendido a las 19:00; la edición de las 19:20 llega antes del primer tick
        await rig.Scheduler.OfferAsync([s with { Version = 2, Message = "Otro", UpdatedAt = Occurrence.AddMinutes(20) }], default);
        await rig.TickAsync();
        rig.Clock.Advance(TimeSpan.FromMinutes(5));
        await rig.TickAsync();
        Assert.Empty(rig.Actions.Executed);
        Assert.Empty(rig.Runs);
        Assert.Null(rig.Scheduler.PendingNotice);
    }

    [Fact]
    public async Task ProgramacionCreadaAntesQueLlegaTardeDentroDeLaVentanaSigueLaReglaNormal()
    {
        var s = Friday();
        using var rig = new Rig { Idle = [] };
        rig.At(Occurrence.AddMinutes(5)); // el primer sync tras crearla (18:30) llega a las 19:05
        await rig.RestartAgentAsync();
        await rig.Scheduler.OfferAsync([s with { UpdatedAt = Occurrence.AddMinutes(-30) }], default);
        await rig.TickAsync();
        Assert.Equal([DeviceAction.Shutdown], rig.Actions.Executed);
        AssertRun(Assert.Single(rig.Runs), s, PowerScheduleRunReportOutcome.ExecutedIdle);
    }

    // Primera recepción con la ventana ya cerrada (p. ej. al actualizar el agente): el agente nunca pudo atenderla.
    [Fact]
    public async Task PrimeraRecepcionConLaVentanaCerradaNoReporta()
    {
        using var rig = new Rig();
        rig.At(Occurrence.AddHours(4));
        await rig.RestartAgentAsync();
        await rig.Scheduler.OfferAsync([Friday()], default);
        await rig.TickAsync();
        Assert.Empty(rig.Runs);
        Assert.Empty(rig.Actions.Executed);
    }

    // Encendido en frío o con Inicio rápido después de la hora (TickCount64 sigue en el arranque anterior): not_needed.
    [Fact]
    public async Task ArranqueHibridoTrasLaHoraNoEsSuspension()
    {
        var s = Friday();
        using var rig = await Rig.StartAsync(s);
        var tickBoot = Occurrence.AddDays(-3);
        var clock = new Keeper.Agent.Hosting.WindowsBootClock(() => tickBoot,
            () => [new(Occurrence.AddMinutes(80), 1), new(Occurrence.AddDays(-3), 0)], rig.Clock);
        rig.BootClock = clock.BootTimeUtc;
        rig.Idle = [30];
        rig.At(Occurrence.AddMinutes(90)); // dentro de la ventana de 180 min
        await rig.TickAsync();
        AssertRun(Assert.Single(rig.Runs), s, PowerScheduleRunReportOutcome.NotNeeded);
        Assert.Empty(rig.Actions.Executed);
        Assert.Null(rig.Scheduler.PendingNotice);
    }

    [Fact]
    public void ElRelojDeArranqueIgnoraLaReanudacionDeHibernacionYCachea()
    {
        var clock = new TestClock();
        var start = clock.GetUtcNow();
        var tickBoot = start.AddDays(-3);
        var reads = 0;
        IReadOnlyList<Keeper.Agent.Hosting.BootEvent> events = [new(start.AddHours(-1), 2), new(start.AddDays(-2), 1)];
        var boot = new Keeper.Agent.Hosting.WindowsBootClock(() => tickBoot, () => { reads++; return events; }, clock);
        Assert.Equal(start.AddDays(-2), boot.BootTimeUtc()); // el híbrido, no la reanudación (0x2)
        events = [new(start.AddMinutes(-1), 0)];
        clock.Advance(TimeSpan.FromSeconds(30));
        Assert.Equal(start.AddDays(-2), boot.BootTimeUtc()); // caché de 60 s
        clock.Advance(TimeSpan.FromSeconds(31));
        Assert.Equal(start.AddMinutes(-1), boot.BootTimeUtc());
        Assert.Equal(2, reads);
        var failing = new Keeper.Agent.Hosting.WindowsBootClock(() => tickBoot, () => throw new UnauthorizedAccessException(), clock);
        Assert.Equal(tickBoot, failing.BootTimeUtc());
        var older = new Keeper.Agent.Hosting.WindowsBootClock(() => tickBoot, () => [new(tickBoot.AddDays(-1), 0)], clock);
        Assert.Equal(tickBoot, older.BootTimeUtc()); // gana el más reciente
    }

    [Fact]
    public void ElRegistroDeArranqueDeWindowsSeLeeSinFallar()
    {
        if (!OperatingSystem.IsWindows()) return;
        var events = Keeper.Agent.Hosting.WindowsBootClock.ReadSystemLog();
        Assert.True(events.Count <= 5);
        // Si el equipo tiene eventos 27, el campo BootType se encuentra (valida el nombre del campo).
        Assert.All(events, e => Assert.NotNull(e.BootType));
    }

    [Fact]
    public async Task SinSesionesRecienEncendidoCuentaComoEnUso()
    {
        var s = Friday();
        using var rig = await Rig.StartAsync(s);
        rig.Idle = [];
        rig.Boot = Occurrence.AddSeconds(-60); // encendido a las 18:59: el SessionSupervisor aún no ve al usuario
        rig.At(Occurrence);
        await rig.TickAsync();
        Assert.Empty(rig.Actions.Executed);
        Assert.NotNull(rig.Scheduler.PendingNotice);
    }

    [Fact]
    public async Task AvisoVencidoDentroDeLaVentanaReiniciaLaCuenta()
    {
        var s = Friday();
        using var rig = await Rig.StartAsync(s);
        rig.Idle = [30];
        rig.At(Occurrence);
        await rig.TickAsync();
        rig.At(Occurrence.AddMinutes(60)); // suspendido durante el aviso, despierta 50 min tarde
        await rig.TickAsync();
        Assert.Empty(rig.Actions.Executed);
        Assert.Equal(Occurrence.AddMinutes(70), rig.Scheduler.PendingNotice!.Deadline);
        Assert.Empty(rig.Runs);
        rig.Clock.Advance(TimeSpan.FromMinutes(10));
        await rig.TickAsync();
        Assert.Equal([DeviceAction.Shutdown], rig.Actions.Executed);
        AssertRun(Assert.Single(rig.Runs), s, PowerScheduleRunReportOutcome.ExecutedAfterNotice);
    }

    [Fact]
    public async Task AvisoVencidoFueraDeLaVentanaEsSuspendido()
    {
        var s = Friday();
        using var rig = await Rig.StartAsync(s);
        rig.Idle = [30];
        rig.At(Occurrence);
        await rig.TickAsync();
        rig.At(Occurrence.AddMinutes(200));
        await rig.TickAsync();
        Assert.Empty(rig.Actions.Executed);
        AssertRun(Assert.Single(rig.Runs), s, PowerScheduleRunReportOutcome.SkippedSuspended);
        Assert.Null(rig.Scheduler.PendingNotice);
    }

    [Fact]
    public async Task EjecucionIniciadaAntesDelCorteNoSeRepite()
    {
        var s = Friday();
        using var rig = await Rig.StartAsync(s);
        var active = new PowerScheduleActive(Guid.NewGuid(), s.Id, s.Version, Occurrence, s.Action, Occurrence.AddMinutes(10), false, true,
            PowerScheduleRunReportOutcome.ExecutedAfterNotice);
        var state = new PowerSchedulerState([new PowerScheduleEntry(s, Before)], active, [], []);
        await File.WriteAllBytesAsync(rig.Directory.File("power-schedules.json"), JsonSerializer.SerializeToUtf8Bytes(state, ProtocolJson.Options));
        rig.At(Occurrence.AddMinutes(11));
        await rig.RestartAgentAsync();
        AssertRun(Assert.Single(rig.Runs), s, PowerScheduleRunReportOutcome.ExecutedAfterNotice);
        rig.Clock.Advance(TimeSpan.FromMinutes(1));
        await rig.TickAsync();
        Assert.Empty(rig.Actions.Executed);
        Assert.Single(rig.Runs);
    }

    [Fact]
    public async Task AccionQueFallaSeReportaComoActionFailed()
    {
        var s = Friday();
        using var rig = await Rig.StartAsync(s);
        rig.Actions.Failure = new System.ComponentModel.Win32Exception(5);
        rig.At(Occurrence);
        await rig.TickAsync();
        var run = Assert.Single(rig.Runs);
        AssertRun(run, s, PowerScheduleRunReportOutcome.Unsupported);
        Assert.Equal("action_failed:Win32Exception", run.Detail);
    }

    [Fact]
    public async Task SiLaColaFallaLosResultadosEsperanYSeReintentan()
    {
        var s = Friday();
        using var rig = await Rig.StartAsync(s);
        rig.Events.PowerRunFailure = new IOException("outbox_locked");
        rig.At(Occurrence);
        await rig.TickAsync(); // no lanza
        Assert.Equal([DeviceAction.Shutdown], rig.Actions.Executed);
        Assert.Empty(rig.Runs);
        Assert.Single(JsonSerializer.Deserialize<PowerSchedulerState>(await File.ReadAllBytesAsync(rig.Directory.File("power-schedules.json")), ProtocolJson.Options)!.Unsent);
        rig.Clock.Advance(TimeSpan.FromSeconds(1));
        await rig.TickAsync();
        Assert.Empty(rig.Runs);
        Assert.Single(rig.Events.Logs, l => l.Code == "power_schedule_enqueue_failed");
        rig.Events.PowerRunFailure = null;
        rig.Clock.Advance(TimeSpan.FromSeconds(1));
        await rig.TickAsync();
        AssertRun(Assert.Single(rig.Runs), s, PowerScheduleRunReportOutcome.ExecutedIdle);
        Assert.Empty(JsonSerializer.Deserialize<PowerSchedulerState>(await File.ReadAllBytesAsync(rig.Directory.File("power-schedules.json")), ProtocolJson.Options)!.Unsent);
        Assert.Single(rig.Actions.Executed);
    }

    [Fact]
    public async Task OfferAsyncNoLanzaConLaColaRota()
    {
        var s = Friday();
        using var rig = await Rig.StartAsync(s);
        rig.Idle = [30];
        rig.At(Occurrence);
        await rig.TickAsync();
        rig.Events.PowerRunFailure = new InvalidOperationException("broken");
        await rig.Scheduler.OfferAsync([], default); // cancelled_by_it no se puede encolar: no debe llegar a OnResponse
        Assert.True(rig.Scheduler.PendingNotice!.Cancelled);
        rig.Events.PowerRunFailure = null;
        rig.Clock.Advance(TimeSpan.FromSeconds(1));
        await rig.TickAsync();
        AssertRun(Assert.Single(rig.Runs), s, PowerScheduleRunReportOutcome.CancelledByIt);
    }

    [Fact]
    public async Task EstadoBloqueadoAlIniciarEmpiezaVacio()
    {
        using var rig = new Rig();
        rig.At(Before);
        await File.WriteAllTextAsync(rig.Directory.File("power-schedules.json"), "{}");
        using (new FileStream(rig.Directory.File("power-schedules.json"), FileMode.Open, FileAccess.Read, FileShare.None))
            await rig.RestartAgentAsync(); // IOException: no rompe el arranque del agente
        await rig.Scheduler.OfferAsync([Friday()], default);
        rig.At(Occurrence);
        await rig.TickAsync();
        Assert.Single(rig.Actions.Executed);
    }

    [Theory]
    [InlineData(false, true, "manual")]
    [InlineData(true, true, "scheduled")]
    [InlineData(true, false, "manual")]
    [InlineData(false, false, "manual")]
    public void PrioridadDeAvisos(bool manualCancelled, bool scheduledPresent, string expected)
    {
        var manual = new PowerNotice(PowerNoticeAction.Restart, Occurrence, manualCancelled);
        var scheduled = scheduledPresent ? new PowerNotice(PowerNoticeAction.Shutdown, Occurrence.AddMinutes(5), false) : null;
        Assert.Same(expected == "manual" ? manual : scheduled, PowerScheduler.SelectNotice(manual, scheduled));
        Assert.Same(scheduled, PowerScheduler.SelectNotice(null, scheduled));
        Assert.Null(PowerScheduler.SelectNotice(null, null));
    }

    [Fact]
    public async Task RelojDesfasadoUsaLaHoraDelServidor()
    {
        var s = Friday();
        using var rig = new Rig { Offset = TimeSpan.FromHours(5) };
        rig.At(Before.AddHours(-5)); // Windows atrasado 5 h: para él son las 13:00 de Bogotá
        await rig.RestartAgentAsync();
        await rig.Scheduler.OfferAsync([s], default);
        await rig.TickAsync();
        rig.Clock.Advance(TimeSpan.FromHours(1));
        await rig.TickAsync();
        Assert.Equal([DeviceAction.Shutdown], rig.Actions.Executed);
        AssertRun(Assert.Single(rig.Runs), s, PowerScheduleRunReportOutcome.ExecutedIdle);
    }

    [Fact]
    public async Task DesfasePequenoNoCambiaLaHora()
    {
        using var rig = new Rig { Offset = TimeSpan.FromMinutes(4) };
        rig.At(Before);
        await rig.RestartAgentAsync();
        await rig.Scheduler.OfferAsync([Friday()], default);
        rig.At(Occurrence.AddMinutes(-2));
        await rig.TickAsync();
        Assert.Empty(rig.Actions.Executed);
    }

    [Fact]
    public async Task ResultadosViajanEnElSyncYSeConfirman()
    {
        using var dir = new TestDirectory();
        using var outbox = new DurableOutbox(dir.File("outbox.json"));
        var run = new PowerScheduleRunReport { EventId = Guid.NewGuid(), ScheduleId = Guid.NewGuid(), OccurrenceAt = Occurrence,
            Outcome = PowerScheduleRunReportOutcome.ExecutedIdle };
        await outbox.EnqueueAsync(run, default);
        await outbox.EnqueueAsync(run, default);
        var body = JsonSerializer.Deserialize<SyncRequest>((await outbox.PrepareAsync(null, default)).Body, ProtocolJson.Options)!;
        Assert.Equal(run, Assert.Single(body.PowerScheduleRuns!));
        await outbox.CompleteAsync([new Ack { EventId = run.EventId, Status = AckStatus.Accepted, Retryable = false }], default);
        Assert.Empty((await outbox.InspectAsync()).Events);
    }

    [Fact]
    public async Task ResultadoRechazadoSeApartaPorPointer()
    {
        using var dir = new TestDirectory();
        using var outbox = new DurableOutbox(dir.File("outbox.json"));
        var run = new PowerScheduleRunReport { EventId = Guid.NewGuid(), ScheduleId = Guid.NewGuid(), OccurrenceAt = Occurrence,
            Outcome = PowerScheduleRunReportOutcome.Replaced };
        await outbox.EnqueueAsync(run, default);
        await outbox.PrepareAsync(null, default);
        var result = await outbox.RejectPendingAsync("/power_schedule_runs/0/outcome", "enum", default);
        Assert.Equal(RejectionKind.Quarantined, result.Kind);
        Assert.Equal(run, Assert.Single(await outbox.InspectQuarantineAsync()).Event.PowerRun);
    }
}
