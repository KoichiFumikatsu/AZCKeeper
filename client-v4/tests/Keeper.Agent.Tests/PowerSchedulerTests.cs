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
            Scheduler = new PowerScheduler(Directory.File("power-schedules.json"), Actions, () => Idle, () => Manual, () => Boot)
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

    [Fact]
    public async Task ProgramacionRecibidaDespuesDeLaHoraNoSeEjecuta()
    {
        using var rig = new Rig();
        rig.At(Occurrence.AddMinutes(5));
        await rig.RestartAgentAsync();
        await rig.Scheduler.OfferAsync([Friday()], default);
        await rig.TickAsync();
        Assert.Empty(rig.Actions.Executed);
        Assert.Empty(rig.Runs);
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
