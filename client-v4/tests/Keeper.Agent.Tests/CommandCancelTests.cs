extern alias session;
using PowerNoticeText = session::Keeper.Session.Modules.UI.PowerNoticeText;
using Keeper.Agent.Modules.Devices;
using Keeper.Agent.Modules.Security;
using Keeper.Shared.Contracts;
using Keeper.Shared.Protocol;

namespace Keeper.Agent.Tests;

// Cancelar una orden de reinicio/apagado con aviso (agente 4.0.27): el servidor reenvia la orden con cancel_requested_at;
// si la cuenta regresiva no llego a shutdown.exe se detiene, la persona ve «IT cancelo...» y se responde cancelled_by_admin.
public sealed class CommandCancelTests
{
    private sealed class GatedActions : IDeviceActions
    {
        public List<DeviceAction> Executed { get; } = [];
        public TaskCompletionSource Entered { get; } = new(TaskCreationOptions.RunContinuationsAsynchronously);
        public TaskCompletionSource? Release { get; set; }
        public async Task ExecuteAsync(DeviceAction action, CancellationToken ct)
        {
            Executed.Add(action);
            Entered.TrySetResult();
            if (Release is not null) await Release.Task;
        }
    }

    private sealed class Rig : IAsyncDisposable
    {
        public TestDirectory Directory { get; } = new();
        public TestClock Clock { get; } = new();
        public GatedActions Actions { get; } = new();
        public ModuleContext Context { get; }
        public DeviceLock Lock { get; private set; } = null!;
        public CommandExecutor Executor { get; private set; } = null!;
        public MemoryEvents Events => (MemoryEvents)Context.Outbox;
        public Rig() => Context = Samples.Context(Clock);
        public async Task<Rig> StartAsync()
        {
            Lock = new DeviceLock(Directory.File("lock"));
            await Lock.InitAsync(Context);
            return await RestartAgentAsync();
        }
        public async Task<Rig> RestartAgentAsync()
        {
            if (Executor is not null) await Executor.ShutdownAsync();
            Executor = new CommandExecutor(Directory.File("inbox"), Samples.Device, Lock, Actions);
            await Executor.InitAsync(Context);
            return this;
        }
        public async ValueTask DisposeAsync()
        {
            await Executor.ShutdownAsync();
            await Lock.ShutdownAsync();
            Directory.Dispose();
        }
    }

    private static Command Order(TestClock clock, CommandType type, long notice) => CommandRegressionTests.Command(clock) with
    {
        Type = type, Parameters = new CommandParameters { NoticeSeconds = notice }
    };
    private static Command CancelOf(Command order, TestClock clock) => order with { Status = CommandStatus.Delivered, CancelRequestedAt = clock.GetUtcNow() };

    [Theory]
    [InlineData(CommandType.Restart, PowerNoticeAction.Restart)]
    [InlineData(CommandType.Shutdown, PowerNoticeAction.Shutdown)]
    public async Task LaCancelacionDetieneLaCuentaYNoLlamaAShutdown(CommandType type, PowerNoticeAction action)
    {
        await using var rig = await new Rig().StartAsync();
        var order = Order(rig.Clock, type, 300);
        await rig.Executor.AcceptAsync([order], Samples.Tenant, default);
        await rig.Executor.TickAsync(default);
        var deadline = rig.Executor.PendingNotice!.Deadline;
        rig.Clock.Advance(TimeSpan.FromSeconds(120));

        await rig.Executor.AcceptAsync([CancelOf(order, rig.Clock)], Samples.Tenant, default);
        // La persona ve la cancelacion (sin cuenta regresiva) en lugar del aviso.
        Assert.Equal(new PowerNotice(action, deadline, Cancelled: true), rig.Executor.PendingNotice);
        await rig.Executor.TickAsync(default);
        var result = Assert.Single(rig.Events.CommandResults);
        Assert.Equal(order.Id, result.CommandId);
        Assert.Equal(CommandResultStatus.Failed, result.Result.Status);
        Assert.Equal(CommandExecutor.CancelledByAdmin, result.Result.Code);
        var log = Assert.Single(rig.Events.Logs);
        Assert.Equal(("command_cancelled", LogEntryLevel.Info, order.Id, "countdown_stopped"), (log.Code, log.Level, log.Fields!.CommandId, log.Fields.ErrorCode));

        // Pasa el plazo original: no se reinicia ni se apaga.
        rig.Clock.Advance(TimeSpan.FromSeconds(400));
        await rig.Executor.TickAsync(default);
        Assert.Empty(rig.Actions.Executed);
        Assert.Single(rig.Events.CommandResults);
        // El aviso de cancelacion dura CancelledNoticeFor y luego la ventana se cierra.
        Assert.Null(rig.Executor.PendingNotice);
        // Reenviada otra vez antes de que el servidor procese el resultado: no repite nada.
        await rig.Executor.AcceptAsync([CancelOf(order, rig.Clock)], Samples.Tenant, default);
        await rig.Executor.TickAsync(default);
        Assert.Single(rig.Events.CommandResults);
        Assert.Single(rig.Events.Logs);
    }

    [Fact]
    public async Task ElAvisoDeCancelacionSeQuitaAlTerminarSuTiempo()
    {
        await using var rig = await new Rig().StartAsync();
        var order = Order(rig.Clock, CommandType.Restart, 600);
        await rig.Executor.AcceptAsync([order], Samples.Tenant, default);
        await rig.Executor.TickAsync(default);
        await rig.Executor.AcceptAsync([CancelOf(order, rig.Clock)], Samples.Tenant, default);
        rig.Clock.Advance(CommandExecutor.CancelledNoticeFor - TimeSpan.FromSeconds(1));
        await rig.Executor.TickAsync(default);
        Assert.True(rig.Executor.PendingNotice?.Cancelled);
        rig.Clock.Advance(TimeSpan.FromSeconds(1));
        await rig.Executor.TickAsync(default);
        Assert.Null(rig.Executor.PendingNotice);
    }

    [Fact]
    public async Task LaCancelacionSobreviveAUnReinicioDelServicio()
    {
        await using var rig = await new Rig().StartAsync();
        var order = Order(rig.Clock, CommandType.Shutdown, 300);
        await rig.Executor.AcceptAsync([order], Samples.Tenant, default);
        await rig.Executor.TickAsync(default);
        await rig.Executor.AcceptAsync([CancelOf(order, rig.Clock)], Samples.Tenant, default);
        // El servicio se reinicia antes de publicar el resultado: la cuenta no se retoma y el resultado sale igual.
        await rig.RestartAgentAsync();
        Assert.Null(rig.Executor.PendingNotice);
        rig.Clock.Advance(TimeSpan.FromSeconds(300));
        await rig.Executor.TickAsync(default);
        Assert.Empty(rig.Actions.Executed);
        Assert.Equal(CommandExecutor.CancelledByAdmin, Assert.Single(rig.Events.CommandResults).Result.Code);
        await rig.RestartAgentAsync();
        await rig.Executor.TickAsync(default);
        Assert.Single(rig.Events.CommandResults);
    }

    [Fact]
    public async Task LaCancelacionTardiaSeInformaYNoCambiaElResultado()
    {
        await using var rig = await new Rig().StartAsync();
        var order = Order(rig.Clock, CommandType.Restart, 60);
        await rig.Executor.AcceptAsync([order], Samples.Tenant, default);
        await rig.Executor.TickAsync(default);
        rig.Clock.Advance(TimeSpan.FromSeconds(60));
        await rig.Executor.TickAsync(default);
        Assert.Equal([DeviceAction.Restart], rig.Actions.Executed);

        await rig.Executor.AcceptAsync([CancelOf(order, rig.Clock)], Samples.Tenant, default);
        await rig.Executor.AcceptAsync([CancelOf(order, rig.Clock)], Samples.Tenant, default);
        await rig.Executor.TickAsync(default);
        var result = Assert.Single(rig.Events.CommandResults).Result;
        Assert.Equal((CommandResultStatus.Succeeded, "completed"), (result.Status, result.Code));
        var log = Assert.Single(rig.Events.Logs);
        Assert.Equal(("command_cancel_too_late", LogEntryLevel.Warn, order.Id, "completed"), (log.Code, log.Level, log.Fields!.CommandId, log.Fields.ErrorCode));
        Assert.Null(rig.Executor.PendingNotice);
    }

    [Fact]
    public async Task SiShutdownYaSeEstaLlamandoLaCancelacionEsperaYLlegaTarde()
    {
        await using var rig = await new Rig().StartAsync();
        var order = Order(rig.Clock, CommandType.Shutdown, 60);
        await rig.Executor.AcceptAsync([order], Samples.Tenant, default);
        await rig.Executor.TickAsync(default);
        rig.Clock.Advance(TimeSpan.FromSeconds(60));
        rig.Actions.Release = new(TaskCreationOptions.RunContinuationsAsynchronously);
        var tick = rig.Executor.TickAsync(default);
        await rig.Actions.Entered.Task;
        // shutdown.exe en curso: la cancelacion espera a que termine (no corre en paralelo) y queda como tardia.
        var cancel = rig.Executor.AcceptAsync([CancelOf(order, rig.Clock)], Samples.Tenant, default);
        await Task.Delay(100);
        Assert.False(cancel.IsCompleted);
        rig.Actions.Release.SetResult();
        await tick; await cancel;
        Assert.Equal([DeviceAction.Shutdown], rig.Actions.Executed);
        Assert.Equal("command_cancel_too_late", Assert.Single(rig.Events.Logs).Code);
    }

    [Fact]
    public async Task UnaOrdenNuncaRecibidaSeCancelaYNoSeEjecutaSiLlegaDespues()
    {
        await using var rig = await new Rig().StartAsync();
        var order = Order(rig.Clock, CommandType.Restart, 300);
        await rig.Executor.AcceptAsync([CancelOf(order, rig.Clock) with { ExpiresAt = rig.Clock.GetUtcNow().AddMinutes(-1) }], Samples.Tenant, default);
        await rig.Executor.AcceptAsync([order], Samples.Tenant, default);
        await rig.Executor.TickAsync(default);
        rig.Clock.Advance(TimeSpan.FromSeconds(600));
        await rig.Executor.TickAsync(default);
        Assert.Empty(rig.Actions.Executed);
        Assert.Null(rig.Executor.PendingNotice);
        Assert.Equal(CommandExecutor.CancelledByAdmin, Assert.Single(rig.Events.CommandResults).Result.Code);
        Assert.Equal("not_started", Assert.Single(rig.Events.Logs).Fields!.ErrorCode);
    }

    [Fact]
    public void ElCampoViajaEnElProtocoloYElAvisoCanceladoPorLaSesion()
    {
        var json = """{"id":"33333333-3333-4333-8333-333333333333","tenant_id":"22222222-2222-4222-8222-222222222222","device_id":"11111111-1111-4111-8111-111111111111","type":"restart","status":"delivered","created_at":"2026-10-19T15:00:00Z","expires_at":"2026-10-19T16:00:00Z","parameters":{"notice_seconds":300},"cancel_requested_at":"2026-10-19T15:02:00Z","result":null}""";
        var command = System.Text.Json.JsonSerializer.Deserialize<Command>(json, ProtocolJson.Options)!;
        Assert.Equal(DateTimeOffset.Parse("2026-10-19T15:02:00Z"), command.CancelRequestedAt);
        var notice = new PowerNotice(PowerNoticeAction.Shutdown, DateTimeOffset.FromUnixTimeSeconds(1700000300), Cancelled: true);
        var wire = System.Text.Json.JsonSerializer.Serialize(new SessionRequest(1, Guid.NewGuid(), null, null, false, false, [], PowerNotice: notice), ProtocolJson.Options);
        Assert.Contains("\"cancelled\":true", wire);
        Assert.Equal(notice, System.Text.Json.JsonSerializer.Deserialize<SessionRequest>(wire, ProtocolJson.Options)!.PowerNotice);
    }

    [Theory]
    [InlineData(PowerNoticeAction.Restart, "Reinicio cancelado", "IT canceló el reinicio. Puedes seguir trabajando con normalidad.")]
    [InlineData(PowerNoticeAction.Shutdown, "Apagado cancelado", "IT canceló el apagado. Puedes seguir trabajando con normalidad.")]
    public void TextosDeLaCancelacion(PowerNoticeAction action, string title, string message)
    {
        Assert.Equal(title, PowerNoticeText.CancelledTitle(action));
        Assert.Equal(message, PowerNoticeText.CancelledMessage(action));
    }
}
