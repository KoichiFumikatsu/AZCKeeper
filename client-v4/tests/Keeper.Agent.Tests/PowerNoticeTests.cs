extern alias session;
using PowerNoticeText = session::Keeper.Session.Modules.UI.PowerNoticeText;
using PowerNoticeChoices = session::Keeper.Session.Modules.UI.PowerNoticeChoices;
using PowerNoticeLayout = session::Keeper.Session.Modules.UI.PowerNoticeLayout;
using PowerNoticeGate = session::Keeper.Session.Ipc.PowerNoticeGate;
using SessionClientRules = session::Keeper.Session.Ipc.SessionClientRules;
using IInputIdleSource = session::Keeper.Session.Modules.Activity.IInputIdleSource;
using Keeper.Agent.Hosting;
using Keeper.Agent.Modules.Devices;
using Keeper.Agent.Modules.Security;
using Keeper.Shared.Contracts;
using Keeper.Shared.Protocol;

namespace Keeper.Agent.Tests;

// Reiniciar/apagar con aviso previo (agente 4.0.17): la orden espera sin bloquear el tick, avisa a las sesiones y se
// ejecuta y reporta al vencer el plazo.
public sealed class PowerNoticeTests
{
    private sealed class RecordingActions : IDeviceActions
    {
        public List<DeviceAction> Executed { get; } = [];
        public Task ExecuteAsync(DeviceAction action, CancellationToken ct) { Executed.Add(action); return Task.CompletedTask; }
    }

    private sealed class Rig : IAsyncDisposable
    {
        public TestDirectory Directory { get; } = new();
        public TestClock Clock { get; } = new();
        public RecordingActions Actions { get; } = new();
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
        // Simula un reinicio del agente: una instancia nueva sobre la misma bandeja en disco.
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

    private static Command Order(TestClock clock, CommandType type, long? notice) => CommandRegressionTests.Command(clock) with
    {
        Type = type, Parameters = notice is null ? null : new CommandParameters { NoticeSeconds = notice }
    };

    [Theory]
    [InlineData(CommandType.Restart, 60, DeviceAction.Restart, PowerNoticeAction.Restart)]
    [InlineData(CommandType.Shutdown, 300, DeviceAction.Shutdown, PowerNoticeAction.Shutdown)]
    [InlineData(CommandType.Restart, 600, DeviceAction.Restart, PowerNoticeAction.Restart)]
    public async Task EsperaElPlazoSinBloquearYSoloEntoncesEjecutaYReporta(CommandType type, long notice, DeviceAction action, PowerNoticeAction shown)
    {
        await using var rig = await new Rig().StartAsync();
        var start = rig.Clock.GetUtcNow();
        await rig.Executor.AcceptAsync([Order(rig.Clock, type, notice)], Samples.Tenant, default);
        await rig.Executor.TickAsync(default);
        Assert.Empty(rig.Actions.Executed);
        Assert.Empty(rig.Events.CommandResults);
        Assert.Equal(new PowerNotice(shown, start.AddSeconds(notice)), rig.Executor.PendingNotice);

        // Otras ordenes siguen ejecutandose durante la cuenta regresiva.
        await rig.Executor.AcceptAsync([Order(rig.Clock, CommandType.Lock, null)], Samples.Tenant, default);
        rig.Clock.Advance(TimeSpan.FromSeconds(notice - 1));
        await rig.Executor.TickAsync(default);
        Assert.True(rig.Lock.Locked);
        Assert.Empty(rig.Actions.Executed);
        Assert.Single(rig.Events.CommandResults);

        rig.Clock.Advance(TimeSpan.FromSeconds(1));
        await rig.Executor.TickAsync(default);
        Assert.Equal([action], rig.Actions.Executed);
        Assert.Equal(2, rig.Events.CommandResults.Count);
        Assert.Equal(CommandResultStatus.Succeeded, rig.Events.CommandResults[^1].Result.Status);
        Assert.Null(rig.Executor.PendingNotice);
    }

    [Theory]
    [InlineData(null)]
    [InlineData(0L)]
    public async Task SinAvisoEsInmediatoComoAntes(long? notice)
    {
        await using var rig = await new Rig().StartAsync();
        await rig.Executor.AcceptAsync([Order(rig.Clock, CommandType.Shutdown, notice)], Samples.Tenant, default);
        await rig.Executor.TickAsync(default);
        Assert.Equal([DeviceAction.Shutdown], rig.Actions.Executed);
        Assert.Equal(CommandResultStatus.Succeeded, Assert.Single(rig.Events.CommandResults).Result.Status);
        Assert.Null(rig.Executor.PendingNotice);
    }

    [Fact]
    public async Task SiElAgenteSeReiniciaRetomaLaCuentaConElMismoPlazo()
    {
        await using var rig = await new Rig().StartAsync();
        var deadline = rig.Clock.GetUtcNow().AddSeconds(300);
        await rig.Executor.AcceptAsync([Order(rig.Clock, CommandType.Restart, 300)], Samples.Tenant, default);
        await rig.Executor.TickAsync(default);
        rig.Clock.Advance(TimeSpan.FromSeconds(120));
        await rig.RestartAgentAsync();
        Assert.Equal(new PowerNotice(PowerNoticeAction.Restart, deadline), rig.Executor.PendingNotice);
        await rig.Executor.TickAsync(default);
        Assert.Empty(rig.Actions.Executed);

        rig.Clock.Advance(TimeSpan.FromSeconds(180));
        await rig.Executor.TickAsync(default);
        Assert.Equal([DeviceAction.Restart], rig.Actions.Executed);
        Assert.Equal(CommandResultStatus.Succeeded, Assert.Single(rig.Events.CommandResults).Result.Status);
    }

    [Fact]
    public async Task SiVuelveConElPlazoRecienVencidoEjecutaYa()
    {
        await using var rig = await new Rig().StartAsync();
        await rig.Executor.AcceptAsync([Order(rig.Clock, CommandType.Shutdown, 60)], Samples.Tenant, default);
        await rig.Executor.TickAsync(default);
        rig.Clock.Advance(TimeSpan.FromSeconds(60) + CommandExecutor.NoticeGrace);
        await rig.RestartAgentAsync();
        await rig.Executor.TickAsync(default);
        Assert.Equal([DeviceAction.Shutdown], rig.Actions.Executed);
    }

    [Fact]
    public async Task SiElEquipoEstuvoApagadoAlVencerNoLoApagaPorSorpresa()
    {
        await using var rig = await new Rig().StartAsync();
        await rig.Executor.AcceptAsync([Order(rig.Clock, CommandType.Shutdown, 600)], Samples.Tenant, default);
        await rig.Executor.TickAsync(default);
        rig.Clock.Advance(TimeSpan.FromHours(10));
        await rig.RestartAgentAsync();
        await rig.Executor.TickAsync(default);
        Assert.Empty(rig.Actions.Executed);
        var result = Assert.Single(rig.Events.CommandResults).Result;
        Assert.Equal(CommandResultStatus.Failed, result.Status);
        Assert.Equal("notice_deadline_missed", result.Code);
        Assert.Null(rig.Executor.PendingNotice);
    }

    [Fact]
    public async Task UnaOrdenQueVenceDuranteElAvisoIgualSeEjecuta()
    {
        await using var rig = await new Rig().StartAsync();
        var order = Order(rig.Clock, CommandType.Restart, 300) with { ExpiresAt = rig.Clock.GetUtcNow().AddSeconds(30) };
        await rig.Executor.AcceptAsync([order], Samples.Tenant, default);
        await rig.Executor.TickAsync(default);
        rig.Clock.Advance(TimeSpan.FromSeconds(300));
        await rig.Executor.TickAsync(default);
        Assert.Equal([DeviceAction.Restart], rig.Actions.Executed);
    }

    [Theory]
    [InlineData(120L)]
    [InlineData(-60L)]
    [InlineData(86400L)]
    public async Task AvisoFueraDeLasOpcionesSeRechaza(long notice)
    {
        await using var rig = await new Rig().StartAsync();
        await rig.Executor.AcceptAsync([Order(rig.Clock, CommandType.Restart, notice)], Samples.Tenant, default);
        await rig.Executor.TickAsync(default);
        Assert.Empty(rig.Actions.Executed);
        var result = Assert.Single(rig.Events.CommandResults).Result;
        Assert.Equal(CommandResultStatus.Failed, result.Status);
        Assert.Equal("invalid_notice", result.Code);
    }

    [Fact]
    public async Task ElSupervisorEnviaElAvisoATodasLasSesiones()
    {
        var clock = new TestClock();
        using var directory = new TestDirectory();
        var context = Samples.Context(clock);
        var deviceLock = new DeviceLock(directory.File("lock"));
        await deviceLock.InitAsync(context);
        var platform = new FakePlatform();
        PowerNotice? current = null;
        var supervisor = new SessionSupervisor(platform, deviceLock, () => null,
            new SessionTaskManagerPolicies(() => [], _ => new ScreenLockTests.FakeRegistry()), () => current);
        await using var host = new ModuleHost([supervisor], context);
        await host.InitAsync();
        await host.TickAsync(default);
        Assert.All(platform.Connections.Values, c => Assert.Null(c.Requests[^1].PowerNotice));

        current = new PowerNotice(PowerNoticeAction.Shutdown, clock.GetUtcNow().AddMinutes(5));
        clock.Advance(TimeSpan.FromSeconds(1));
        await host.TickAsync(default);
        Assert.Equal(2, platform.Connections.Count);
        Assert.All(platform.Connections.Values, c => Assert.Equal(current, c.Requests[^1].PowerNotice));

        current = null;
        clock.Advance(TimeSpan.FromSeconds(1));
        await host.TickAsync(default);
        Assert.All(platform.Connections.Values, c => Assert.Null(c.Requests[^1].PowerNotice));
        await deviceLock.ShutdownAsync();
    }

    [Fact]
    public void ElAvisoViajaPorElCanalDeSesion()
    {
        var notice = new PowerNotice(PowerNoticeAction.Restart, DateTimeOffset.FromUnixTimeSeconds(1700000300));
        var json = System.Text.Json.JsonSerializer.Serialize(new SessionRequest(1, Guid.NewGuid(), null, null, false, false, [], PowerNotice: notice), ProtocolJson.Options);
        Assert.Contains("\"power_notice\":{\"action\":\"restart\"", json);
        Assert.Equal(notice, System.Text.Json.JsonSerializer.Deserialize<SessionRequest>(json, ProtocolJson.Options)!.PowerNotice);
        Assert.DoesNotContain("power_notice", System.Text.Json.JsonSerializer.Serialize(new SessionRequest(1, Guid.NewGuid(), null, null, false, false, []), ProtocolJson.Options));
    }

    [Theory]
    [InlineData(PowerNoticeAction.Restart, 600, "El equipo se reiniciará en 10 minutos por orden de IT. Guarda tu trabajo.", "10:00")]
    [InlineData(PowerNoticeAction.Restart, 299.2, "El equipo se reiniciará en 5 minutos por orden de IT. Guarda tu trabajo.", "05:00")]
    [InlineData(PowerNoticeAction.Shutdown, 60, "El equipo se apagará en 1 minuto por orden de IT. Guarda tu trabajo.", "01:00")]
    [InlineData(PowerNoticeAction.Shutdown, 7, "El equipo se apagará en 1 minuto por orden de IT. Guarda tu trabajo.", "00:07")]
    [InlineData(PowerNoticeAction.Restart, -3, "El equipo se reiniciará ahora por orden de IT.", "00:00")]
    public void TextosDelAviso(PowerNoticeAction action, double seconds, string message, string countdown)
    {
        Assert.Equal(message, PowerNoticeText.Message(action, TimeSpan.FromSeconds(seconds)));
        Assert.Equal(countdown, PowerNoticeText.Countdown(TimeSpan.FromSeconds(seconds)));
    }

    private static async Task<(SessionSupervisor Supervisor, FakePlatform Platform, ModuleHost Host, TestClock Clock, DeviceLock Lock)> StartSupervisorAsync(Action<PowerChoice>? onChoice,
        int failVerify = 0, int pending = 0, bool tick = true, Func<PowerNotice?>? notice = null)
    {
        var clock = new TestClock();
        var directory = new TestDirectory();
        var context = Samples.Context(clock);
        var deviceLock = new DeviceLock(directory.File("lock"));
        await deviceLock.InitAsync(context);
        var platform = new FakePlatform { FailVerify = failVerify, Pending = pending };
        var supervisor = new SessionSupervisor(platform, deviceLock, () => null,
            new SessionTaskManagerPolicies(() => [], _ => new ScreenLockTests.FakeRegistry()), notice) { OnPowerChoice = onChoice };
        var host = new ModuleHost([supervisor], context);
        await host.InitAsync();
        if (tick) await host.TickAsync(default);
        return (supervisor, platform, host, clock, deviceLock);
    }

    [Fact]
    public async Task ElSupervisorGuardaLaInactividadDeCadaSesion()
    {
        var (supervisor, platform, host, clock, deviceLock) = await StartSupervisorAsync(null);
        await using var _ = host;
        Assert.All(platform.Connections.Values, c => c.IdleSeconds = 30);
        for (var i = 0; i < 3; i++) { clock.Advance(TimeSpan.FromSeconds(1)); await host.TickAsync(default); }
        Assert.Equal(new long?[] { 30, 30 }, supervisor.IdleSeconds());
        await deviceLock.ShutdownAsync();
    }

    // Una sesion de usuario cuyo Keeper.Session sigue conectado pero sin verificar no es «sin sesiones»: cuenta como en uso.
    [Fact]
    public async Task UnaSesionConectadaSinVerificarCuentaComoEnUso()
    {
        var (supervisor, platform, host, clock, deviceLock) = await StartSupervisorAsync(null, pending: 2);
        await using var _ = host;
        Assert.All(platform.Connections.Values, c => c.IdleSeconds = 30);
        for (var i = 0; i < 3; i++) { clock.Advance(TimeSpan.FromSeconds(1)); await host.TickAsync(default); }
        Assert.Equal(2, platform.Connections.Count); // el par de la sesion 2 sigue ahi, sin verificar
        Assert.Empty(platform.Connections[2].Requests);
        Assert.Equal(new long?[] { 30, null }, supervisor.IdleSeconds());
        await deviceLock.ShutdownAsync();
    }

    [Fact]
    public async Task UnaSesionSinParCuentaComoEnUso()
    {
        var (supervisor, platform, host, clock, deviceLock) = await StartSupervisorAsync(null, failVerify: 2);
        await using var _ = host;
        Assert.All(platform.Connections.Values, c => c.IdleSeconds = 30);
        for (var i = 0; i < 3; i++) { clock.Advance(TimeSpan.FromSeconds(1)); await host.TickAsync(default); }
        Assert.Equal(new long?[] { 30, null }, supervisor.IdleSeconds());
        await deviceLock.ShutdownAsync();
    }

    [Fact]
    public async Task AntesDeEnumerarLasSesionesLaInactividadEsDesconocidaYSinUsuariosEsVacia()
    {
        var (supervisor, platform, host, clock, deviceLock) = await StartSupervisorAsync(null, tick: false);
        await using var _ = host;
        Assert.Equal(new long?[] { null }, supervisor.IdleSeconds());
        platform.Sessions = [];
        await host.TickAsync(default);
        Assert.Empty(supervisor.IdleSeconds());
        await deviceLock.ShutdownAsync();
    }

    // I5: el planificador solo apaga tras el aviso si alguna sesion verificada lo recibio (intercambio completado).
    [Fact]
    public async Task ElSupervisorRegistraElAvisoEntregadoAUnaSesionVerificada()
    {
        var run = Guid.NewGuid();
        PowerNotice? current = null;
        var (supervisor, platform, host, clock, deviceLock) = await StartSupervisorAsync(null, notice: () => current);
        await using var _ = host;
        Assert.False(supervisor.NoticeDelivered(run));
        current = new PowerNotice(PowerNoticeAction.Shutdown, clock.GetUtcNow().AddMinutes(5), false, null, 15, false, run);
        clock.Advance(TimeSpan.FromSeconds(1));
        await host.TickAsync(default);
        Assert.True(supervisor.NoticeDelivered(run));
        Assert.False(supervisor.NoticeDelivered(Guid.NewGuid()));
        await deviceLock.ShutdownAsync();
    }

    [Fact]
    public async Task UnAvisoSinSesionVerificadaNoCuentaComoEntregado()
    {
        var run = Guid.NewGuid();
        var notice = new PowerNotice(PowerNoticeAction.Shutdown, DateTimeOffset.UtcNow.AddMinutes(5), false, null, 15, false, run);
        var (supervisor, platform, host, clock, deviceLock) = await StartSupervisorAsync(null, pending: 1, failVerify: 2, notice: () => notice);
        await using var _ = host;
        for (var i = 0; i < 3; i++) { clock.Advance(TimeSpan.FromSeconds(1)); await host.TickAsync(default); }
        Assert.False(supervisor.NoticeDelivered(run));
        await deviceLock.ShutdownAsync();
    }

    [Fact]
    public async Task UnAvisoCanceladoNoCuentaComoEntregado()
    {
        var run = Guid.NewGuid();
        var notice = new PowerNotice(PowerNoticeAction.Shutdown, DateTimeOffset.UtcNow.AddMinutes(5), true, null, null, false, run);
        var (supervisor, platform, host, clock, deviceLock) = await StartSupervisorAsync(null, notice: () => notice);
        await using var _ = host;
        for (var i = 0; i < 2; i++) { clock.Advance(TimeSpan.FromSeconds(1)); await host.TickAsync(default); }
        Assert.False(supervisor.NoticeDelivered(run));
        await deviceLock.ShutdownAsync();
    }

    [Fact]
    public async Task UnCallbackDeEleccionQueFallaNoRompeElIntercambio()
    {
        var calls = 0;
        var (supervisor, platform, host, clock, deviceLock) = await StartSupervisorAsync(_ => { calls++; throw new InvalidOperationException("boom"); });
        await using var _ = host;
        Assert.All(platform.Connections.Values, c => c.IdleSeconds = 12);
        platform.Connections[1].NextChoice = new PowerChoice(Guid.NewGuid(), PowerChoiceKind.Now);
        for (var i = 0; i < 3; i++) { clock.Advance(TimeSpan.FromSeconds(1)); await host.TickAsync(default); }
        Assert.Equal(1, calls);
        Assert.Equal(new long?[] { 12, 12 }, supervisor.IdleSeconds());
        await deviceLock.ShutdownAsync();
    }

    [Fact]
    public async Task LaEleccionDeLaPersonaLlegaAlAgenteUnaVez()
    {
        var choices = new List<PowerChoice>();
        var (_, platform, host, clock, deviceLock) = await StartSupervisorAsync(choices.Add);
        await using var _ = host;
        var choice = new PowerChoice(Guid.NewGuid(), PowerChoiceKind.Postpone);
        platform.Connections[1].NextChoice = choice;
        for (var i = 0; i < 3; i++) { clock.Advance(TimeSpan.FromSeconds(1)); await host.TickAsync(default); }
        for (var i = 0; i < 3; i++) { clock.Advance(TimeSpan.FromSeconds(1)); await host.TickAsync(default); }
        Assert.Equal([choice], choices);
        await deviceLock.ShutdownAsync();
    }

    [Fact]
    public void TextosConMensajeDeTiYPosponer()
    {
        var text = PowerNoticeText.Message(PowerNoticeAction.Restart, TimeSpan.FromMinutes(10), "Hasta el lunes");
        Assert.StartsWith("Hasta el lunes", text);
        Assert.EndsWith("El equipo se reiniciará en 10 minutos por orden de IT. Guarda tu trabajo.", text);
        Assert.Equal("El equipo se apagará ahora por orden de IT.", PowerNoticeText.Message(PowerNoticeAction.Shutdown, TimeSpan.Zero, null));
        Assert.Equal("El equipo se apagará ahora por orden de IT.", PowerNoticeText.Message(PowerNoticeAction.Shutdown, TimeSpan.Zero, "  "));
        Assert.Equal("Posponer 15 min", PowerNoticeText.PostponeLabel(15));
        Assert.Equal("Posponer 1 h", PowerNoticeText.PostponeLabel(60));
        Assert.Equal("Posponer 2 h", PowerNoticeText.PostponeLabel(120));
        Assert.Equal("Posponer 90 min", PowerNoticeText.PostponeLabel(90));
        Assert.Equal("Apagar ahora", PowerNoticeText.NowLabel(PowerNoticeAction.Shutdown));
        Assert.Equal("Reiniciar ahora", PowerNoticeText.NowLabel(PowerNoticeAction.Restart));
    }

    private static PowerNotice Offer(Guid? run, bool compact = false, bool cancelled = false, long? postpone = 15) =>
        new(PowerNoticeAction.Restart, DateTimeOffset.UnixEpoch.AddHours(1), cancelled, null, postpone, compact, run);

    [Fact]
    public void ElBotonPosponerSoloSeOfreceUnaVezPorOrdenYSeTomaUnaVez()
    {
        var choices = new PowerNoticeChoices();
        var run = Guid.NewGuid();
        Assert.False(choices.ButtonsVisible);
        choices.Update(Offer(run));
        Assert.True(choices.ButtonsVisible);
        choices.Pick(PowerChoiceKind.Postpone);
        Assert.False(choices.ButtonsVisible);
        choices.Pick(PowerChoiceKind.Now); // ignorado: ya eligio
        var expected = new PowerChoice(run, PowerChoiceKind.Postpone);
        Assert.Equal(expected, choices.Peek());
        Assert.Equal(expected, choices.Peek()); // Peek no consume
        choices.Commit(expected);
        Assert.Null(choices.Peek());
        choices.Update(Offer(run)); // el mismo aviso repetido no vuelve a ofrecerse
        Assert.False(choices.ButtonsVisible);
        choices.Update(Offer(Guid.NewGuid())); // otra ejecucion si
        Assert.True(choices.ButtonsVisible);
    }

    [Fact]
    public void LaEleccionPendienteSeDescartaSiElAvisoCambiaOSeCancela()
    {
        var choices = new PowerNoticeChoices();
        var run = Guid.NewGuid();
        choices.Update(Offer(run)); choices.Pick(PowerChoiceKind.Now);
        choices.Update(null);
        Assert.Null(choices.Peek());
        choices.Update(Offer(run)); choices.Pick(PowerChoiceKind.Now);
        choices.Update(Offer(Guid.NewGuid()));
        Assert.Null(choices.Peek());
        var other = Guid.NewGuid();
        choices.Update(Offer(other, cancelled: true));
        Assert.False(choices.ButtonsVisible);
        choices.Update(Offer(other, compact: true));
        Assert.False(choices.ButtonsVisible);
        choices.Update(Offer(null));
        Assert.False(choices.ButtonsVisible);
        choices.Update(Offer(Guid.NewGuid(), postpone: null));
        Assert.False(choices.ButtonsVisible);
    }

    [Fact]
    public void SiLaEscrituraFallaLaEleccionSigueSiendoPendienteYNoSeReofrece()
    {
        var choices = new PowerNoticeChoices();
        var run = Guid.NewGuid();
        choices.Update(Offer(run));
        choices.Pick(PowerChoiceKind.Postpone);
        var first = choices.Peek();
        Assert.NotNull(first); // escritura fallida: no hay Commit
        choices.Update(Offer(run)); // el agente repite el aviso
        Assert.False(choices.ButtonsVisible);
        Assert.Equal(first, choices.Peek()); // reintento en el siguiente intercambio
        choices.Commit(first!);
        Assert.Null(choices.Peek());
        Assert.False(choices.ButtonsVisible);
    }

    [Fact]
    public void ConfirmarUnaEleccionDistintaNoBorraLaPendiente()
    {
        var choices = new PowerNoticeChoices();
        var run = Guid.NewGuid();
        choices.Update(Offer(run));
        choices.Pick(PowerChoiceKind.Now);
        choices.Commit(new PowerChoice(run, PowerChoiceKind.Postpone));
        Assert.Equal(new PowerChoice(run, PowerChoiceKind.Now), choices.Peek());
    }

    private sealed class FakeIdle(Func<TimeSpan> get) : IInputIdleSource
    {
        public TimeSpan GetIdleTime() => get();
    }

    [Fact]
    public void LaInactividadDeLaSesionBloqueadaPorTiEsUnAnoYWindowsSinRespuestaEsNull()
    {
        var broken = new FakeIdle(() => throw new System.ComponentModel.Win32Exception(5));
        Assert.Equal(31_536_000L, SessionClientRules.IdleSeconds(windowsLocked: false, itLocked: true, inCall: false, broken));
        Assert.Null(SessionClientRules.IdleSeconds(false, false, false, broken));
        Assert.Equal(90L, SessionClientRules.IdleSeconds(false, false, false, new FakeIdle(() => TimeSpan.FromSeconds(90.9))));
        Assert.Equal(31_536_000L, SessionClientRules.IdleSeconds(false, false, false, new FakeIdle(() => TimeSpan.FromDays(4000))));
        Assert.Equal(0L, SessionClientRules.IdleSeconds(false, false, false, new FakeIdle(() => TimeSpan.FromSeconds(-5))));
    }

    // Win+L no es «sin uso»: la persona bloquea para ir a almorzar o a una reunion. Viaja la inactividad real de Windows
    // (bloqueada mas de idle_minutes cuenta sola como sin uso); solo el bloqueo de TI (DeviceLock) es «sin uso» inmediato.
    [Fact]
    public void ElBloqueoDeWindowsEnviaLaInactividadRealYElDeTiUnAno()
    {
        var idle = new FakeIdle(() => TimeSpan.FromSeconds(120));
        Assert.Equal(120L, SessionClientRules.IdleSeconds(windowsLocked: true, itLocked: false, inCall: false, idle));
        Assert.Equal(31_536_000L, SessionClientRules.IdleSeconds(windowsLocked: false, itLocked: true, inCall: false, idle));
        Assert.Equal(31_536_000L, SessionClientRules.IdleSeconds(windowsLocked: true, itLocked: true, inCall: false, idle));
        Assert.Equal(7200L, SessionClientRules.IdleSeconds(windowsLocked: true, itLocked: false, inCall: false, new FakeIdle(() => TimeSpan.FromHours(2))));
    }

    // Una llamada (Teams/Zoom/telefono) es uso aunque no se toque teclado ni raton.
    [Fact]
    public void EnLlamadaLaInactividadEsCero()
    {
        var idle = new FakeIdle(() => TimeSpan.FromHours(1));
        Assert.Equal(0L, SessionClientRules.IdleSeconds(windowsLocked: false, itLocked: false, inCall: true, idle));
        Assert.Equal(0L, SessionClientRules.IdleSeconds(windowsLocked: true, itLocked: false, inCall: true, idle));
        Assert.Equal(0L, SessionClientRules.IdleSeconds(false, false, true, new FakeIdle(() => throw new System.ComponentModel.Win32Exception(5))));
        Assert.Equal(31_536_000L, SessionClientRules.IdleSeconds(windowsLocked: false, itLocked: true, inCall: true, idle));
    }

    [Fact]
    public void UnAvisoInvalidoSeIgnoraYDejaUnWarnPorAvisoDistinto()
    {
        var gate = new PowerNoticeGate();
        var good = new PowerNotice(PowerNoticeAction.Restart, DateTimeOffset.UnixEpoch.AddHours(1), false, "Hasta el lunes", 15, false, Guid.NewGuid());
        Assert.Same(good, gate.Filter(good, false, out var none));
        Assert.Null(none);
        var bad = good with { Message = "a\nb" };
        Assert.Null(gate.Filter(bad, false, out var warn));
        Assert.NotNull(warn);
        Assert.Equal(LogEntryLevel.Warn, warn!.Level);
        Assert.True(SessionProtocol.IsAccepted(warn));
        Assert.Null(gate.Filter(bad, false, out var again)); // mismo aviso: sin otro warn
        Assert.Null(again);
        Assert.Null(gate.Filter(bad with { Message = "x\ty" }, false, out var other));
        Assert.NotNull(other);
        Assert.Null(gate.Filter(good with { PostponeMinutes = 0 }, false, out _));
        Assert.Null(gate.Filter(good, true, out _)); // cierre: sin aviso
        Assert.Same(good, gate.Filter(good, false, out _)); // uno valido vuelve a mostrarse
    }

    [Theory]
    [InlineData(14f, 584)]
    [InlineData(10f, 352)]
    public void LaAlturaDelMensajeCreceConElTextoYCabeHastaElMaximoDeTi(float points, int width)
    {
        using var font = new System.Drawing.Font("Segoe UI", points);
        var sentence = PowerNoticeText.Message(PowerNoticeAction.Restart, TimeSpan.FromMinutes(10));
        var full = PowerNoticeText.Message(PowerNoticeAction.Restart, TimeSpan.FromMinutes(10), string.Concat(Enumerable.Repeat("Mensaje de TI ", 20))[..279] + "m");
        var small = PowerNoticeLayout.MessageHeight(sentence, font, width);
        var big = PowerNoticeLayout.MessageHeight(full, font, width);
        Assert.True(big > small);
        // Tope de cada modo: grande 520 - 210 fijos = 310; compacta 300 - 104 fijos = 196.
        Assert.True(big <= (points > 12 ? 310 : 196), $"{big}");
    }

    [Fact]
    public void ElAvisoAmpliadoViajaPorElCanal()
    {
        var run = Guid.NewGuid();
        var notice = new PowerNotice(PowerNoticeAction.Shutdown, DateTimeOffset.FromUnixTimeSeconds(1700000300), false, "Hasta el lunes", 15, true, run);
        var json = System.Text.Json.JsonSerializer.Serialize(new SessionRequest(1, Guid.NewGuid(), null, null, false, false, [], PowerNotice: notice), ProtocolJson.Options);
        Assert.Contains("\"postpone_minutes\":15", json);
        Assert.Equal(notice, System.Text.Json.JsonSerializer.Deserialize<SessionRequest>(json, ProtocolJson.Options)!.PowerNotice);
        SessionProtocol.Validate(notice);
        Assert.Throws<InvalidDataException>(() => SessionProtocol.Validate(notice with { Message = new string('a', 281) }));
        Assert.Throws<InvalidDataException>(() => SessionProtocol.Validate(notice with { Message = "a\nb" }));
        Assert.Throws<InvalidDataException>(() => SessionProtocol.Validate(notice with { PostponeMinutes = 0 }));
    }

    private sealed class FakePlatform : ISessionSupervisorPlatform
    {
        public Dictionary<int, FakeConnection> Connections { get; } = [];
        public int FailVerify { get; init; }
        public int Pending { get; init; }
        public IReadOnlyList<InteractiveSession> Sessions { get; set; } = [new(1, "a"), new(2, "b")];
        public IReadOnlyList<InteractiveSession> Enumerate() => Sessions;
        public ISupervisedSession Connect(InteractiveSession session, CancellationToken ct) => Connections[session.Id] = new FakeConnection
        {
            VerifyFails = session.Id == FailVerify, Connecting = session.Id == Pending ? new TaskCompletionSource().Task : Task.CompletedTask
        };
        public void CloseExistingTaskManagers(IReadOnlyList<InteractiveSession> sessions) { }
    }

    private sealed class FakeConnection : ISupervisedSession
    {
        public bool HasExited => false;
        public Task Connecting { get; init; } = Task.CompletedTask;
        public List<SessionRequest> Requests { get; } = [];
        public long? IdleSeconds { get; set; }
        public PowerChoice? NextChoice { get; set; }
        private PowerChoice? Take() { var c = NextChoice; NextChoice = null; return c; }
        public bool VerifyFails { get; init; }
        public void Verify() { if (VerifyFails) throw new UnauthorizedAccessException("unverified"); }
        public Task<SessionResponse> ExchangeAsync(SessionRequest request, CancellationToken ct)
        {
            Requests.Add(request);
            return Task.FromResult(new SessionResponse(1, request.CorrelationId, [], [], IdleSeconds: IdleSeconds, PowerChoice: Take()));
        }
        public Task SendAsync(SessionRequest request, CancellationToken ct) => Task.CompletedTask;
        public void Dispose() { }
    }
}
