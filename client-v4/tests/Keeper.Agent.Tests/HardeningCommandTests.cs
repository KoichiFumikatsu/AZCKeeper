using Keeper.Agent.Modules.Devices;
using Keeper.Agent.Modules.Security;
using Keeper.Shared.Contracts;
using Keeper.Shared.Protocol;

namespace Keeper.Agent.Tests;

public sealed class HardeningCommandTests
{
    private const string Worker = "S-1-5-21-1-2-3-1000";

    private sealed class FakeLauncher(HardeningOutcome outcome) : IHardeningLauncher
    {
        public List<(bool Undo, string Admin)> Calls { get; } = [];
        public Task<HardeningOutcome> RunAsync(bool undo, string adminName, CancellationToken ct) { Calls.Add((undo, adminName)); return Task.FromResult(outcome); }
    }

    private sealed class FakeLogoff(int sessions) : ISessionLogoff
    {
        public List<(string[] Sids, TimeSpan Delay)> Scheduled { get; } = [];
        public int Schedule(IReadOnlyCollection<string> sids, TimeSpan delay) { Scheduled.Add((sids.ToArray(), delay)); return sessions; }
    }

    private sealed class NoActions : IDeviceActions { public Task ExecuteAsync(DeviceAction action, CancellationToken ct) => Task.CompletedTask; }

    private static Command Harden(bool undo, string? admin = "azcadmin") => CommandRegressionTests.Command(new TestClock()) with
    {
        Type = undo ? CommandType.Unharden : CommandType.Harden,
        Parameters = admin is null ? null : new CommandParameters { AdminName = admin }
    };

    private static async Task<CommandResult> RunAsync(Command command, IHardeningLauncher? launcher, ISessionLogoff? logoff)
    {
        using var directory = new TestDirectory();
        var context = Samples.Context(new TestClock());
        var deviceLock = new DeviceLock(directory.File("lock"), PinVerifier.Create("625184"));
        await deviceLock.InitAsync(context);
        var executor = new CommandExecutor(directory.File("inbox"), Samples.Device, deviceLock, new NoActions(), null, launcher, logoff);
        await executor.InitAsync(context);
        await executor.AcceptAsync([command], Samples.Tenant, default);
        await executor.TickAsync(default);
        var result = Assert.Single(((MemoryEvents)context.Outbox).CommandResults).Result;
        await executor.ShutdownAsync();
        await deviceLock.ShutdownAsync();
        return result;
    }

    private static HardeningState Hardened(bool logoff) => new()
    {
        Status = "hardened", RestoreAdminSids = logoff ? [Worker] : [], LogoffRequired = logoff
    };

    [Fact]
    public async Task EndureceYProgramaCierreDeLaSesionDegradada()
    {
        var launcher = new FakeLauncher(new(0, Hardened(true)));
        var logoff = new FakeLogoff(1);
        var result = await RunAsync(Harden(false), launcher, logoff);
        Assert.Equal(CommandResultStatus.Succeeded, result.Status);
        Assert.Equal("hardened_logoff_1", result.Code);
        Assert.Equal([(false, "azcadmin")], launcher.Calls);
        var scheduled = Assert.Single(logoff.Scheduled);
        Assert.Equal([Worker], scheduled.Sids);
        Assert.Equal(HardeningCommand.LogoffDelay, scheduled.Delay);
    }

    [Fact]
    public async Task SinCuentasDegradadasNoCierraSesiones()
    {
        var logoff = new FakeLogoff(0);
        var result = await RunAsync(Harden(false), new FakeLauncher(new(0, Hardened(false))), logoff);
        Assert.Equal("hardened", result.Code);
        Assert.Empty(logoff.Scheduled);
    }

    [Fact]
    public async Task FalloReportaElPasoDelJournalYNoCierraSesiones()
    {
        var logoff = new FakeLogoff(1);
        var state = new HardeningState { Status = "failed", ErrorCode = "failed_step_2", LogoffRequired = false };
        var result = await RunAsync(Harden(false), new FakeLauncher(new(1, state)), logoff);
        Assert.Equal(CommandResultStatus.Failed, result.Status);
        Assert.Equal("failed_step_2", result.Code);
        Assert.Empty(logoff.Scheduled);
    }

    [Fact]
    public async Task RevertirNoCierraSesiones()
    {
        var logoff = new FakeLogoff(1);
        var launcher = new FakeLauncher(new(0, new HardeningState { Status = "unhardened" }));
        var result = await RunAsync(Harden(true), launcher, logoff);
        Assert.Equal(CommandResultStatus.Succeeded, result.Status);
        Assert.Equal("unhardened", result.Code);
        Assert.Equal([(true, "azcadmin")], launcher.Calls);
        Assert.Empty(logoff.Scheduled);
    }

    [Theory]
    [InlineData("azc/admin")]
    [InlineData("nombre-de-cuenta-demasiado-largo")]
    [InlineData("admin.")]
    public async Task NombreDeCuentaInvalidoSeRechazaSinLanzarNada(string admin)
    {
        var launcher = new FakeLauncher(new(0, Hardened(false)));
        var result = await RunAsync(Harden(false, admin), launcher, null);
        Assert.Equal(CommandResultStatus.Failed, result.Status);
        Assert.Equal("invalid_admin_name", result.Code);
        Assert.Empty(launcher.Calls);
    }

    [Fact]
    public async Task SinLanzadorEnDryRunSeRechaza()
    {
        var result = await RunAsync(Harden(false), null, null);
        Assert.Equal(CommandResultStatus.Failed, result.Status);
        Assert.Equal("device_actions_dry_run", result.Code);
    }

    [Fact]
    public void SalidaSinJournalReportaElCodigoDeSalida()
    {
        Assert.Equal((false, "exit_2"), HardeningCommand.Result(new(2, null), false, 0));
        Assert.Equal((false, "elevation_required"), HardeningCommand.Result(new(3, null), false, 0));
    }
}
