using Keeper.Agent.Modules.Devices;
using Keeper.Agent.Modules.Security;
using Keeper.Shared.Protocol;

namespace Keeper.Agent.Tests;

public sealed class RenameComputerTests
{
    private sealed class RecordingNamer : IComputerNamer
    {
        public List<string> Names { get; } = [];
        public void Rename(string name) => Names.Add(name);
    }

    private sealed class CountingActions : IDeviceActions
    {
        public List<DeviceAction> Executed { get; } = [];
        public Task ExecuteAsync(DeviceAction action, CancellationToken ct) { Executed.Add(action); return Task.CompletedTask; }
    }

    private static Command Rename(TestClock clock, string? name, bool? restart) =>
        CommandRegressionTests.Command(clock) with
        {
            Type = CommandType.RenameComputer,
            Parameters = new CommandParameters { ComputerName = name, RestartNow = restart }
        };

    private static async Task<(CommandResult Result, RecordingNamer Namer, CountingActions Actions)> RunAsync(Command command, bool withNamer = true)
    {
        using var directory = new TestDirectory();
        var clock = new TestClock();
        var context = Samples.Context(clock);
        var deviceLock = new DeviceLock(directory.File("lock"), PinVerifier.Create("625184"));
        await deviceLock.InitAsync(context);
        var namer = new RecordingNamer();
        var actions = new CountingActions();
        var executor = new CommandExecutor(directory.File("inbox"), Samples.Device, deviceLock, actions, withNamer ? namer : null);
        await executor.InitAsync(context);
        await executor.AcceptAsync([command], Samples.Tenant, default);
        await executor.TickAsync(default);
        var result = Assert.Single(((MemoryEvents)context.Outbox).CommandResults).Result;
        await executor.ShutdownAsync();
        await deviceLock.ShutdownAsync();
        return (result, namer, actions);
    }

    [Fact]
    public async Task RenombraYQuedaPendienteDeReinicio()
    {
        var (result, namer, actions) = await RunAsync(Rename(new TestClock(), "ACT-0015", false));
        Assert.Equal(CommandResultStatus.Succeeded, result.Status);
        Assert.Equal("pending_restart", result.Code);
        Assert.Equal(["ACT-0015"], namer.Names);
        Assert.Empty(actions.Executed);
    }

    [Fact]
    public async Task RenombraYReiniciaSiSePide()
    {
        var (result, namer, actions) = await RunAsync(Rename(new TestClock(), "ACT-0015", true));
        Assert.Equal(CommandResultStatus.Succeeded, result.Status);
        Assert.Equal(["ACT-0015"], namer.Names);
        Assert.Equal([DeviceAction.Restart], actions.Executed);
    }

    [Theory]
    [InlineData("ACT_0015")]
    [InlineData("12345")]
    [InlineData("NOMBRE-DEMASIADO-LARGO")]
    [InlineData("")]
    [InlineData(null)]
    public async Task NombreInvalidoSeRechazaSinTocarWindows(string? name)
    {
        var (result, namer, _) = await RunAsync(Rename(new TestClock(), name, false));
        Assert.Equal(CommandResultStatus.Failed, result.Status);
        Assert.Equal("invalid_computer_name", result.Code);
        Assert.Empty(namer.Names);
    }

    [Fact]
    public async Task SinPermisoDeEscrituraFallaLimpio()
    {
        var (result, _, _) = await RunAsync(Rename(new TestClock(), "ACT-0015", false), withNamer: false);
        Assert.Equal(CommandResultStatus.Failed, result.Status);
        Assert.Equal("device_actions_dry_run", result.Code);
    }

    [Theory]
    [InlineData("ACT-0015", true)]
    [InlineData("DESKTOP-949SGVE", true)]
    [InlineData("A", true)]
    [InlineData("ACT_0015", false)]
    [InlineData("12345", false)]
    [InlineData("ACT 0015", false)]
    [InlineData("ABCDEFGHIJKLMNOP", false)]
    public void ReglaDeNombresDeEquipo(string name, bool valid) => Assert.Equal(valid, ComputerName.IsValid(name));
}
