using Keeper.Bootstrapper;
using Keeper.Shared.Time;

namespace Keeper.Bootstrapper.Tests;

// La instalacion deja la hora de Windows automatica y resincroniza ANTES de arrancar el servicio (que pide el alta
// firmada al instante). Comandos simulados: nunca se ejecuta w32tm de verdad.
public sealed class TimeSyncBootstrapTests
{
    private readonly FakeMachine machine = new();
    private readonly List<string> output = [];
    private static BootstrapOptions Options => new(false, false, false, Path.GetFullPath("fake-payload"), "fake-config.json");

    private sealed class FakeTime(FakeMachine machine, TimeSettings settings, Exception? fail = null) : ITimeSystem
    {
        public List<string> Commands { get; } = [];
        public Task<TimeSettings> ReadAsync(CancellationToken ct) => fail is null ? Task.FromResult(settings) : throw fail;
        public Task<TimeCommandResult> RunAsync(TimeCommand command, CancellationToken ct)
        {
            Assert.False(machine.Running);
            Commands.Add(command.ToString());
            machine.Mutations.Add("time:" + command.Item);
            return Task.FromResult(new TimeCommandResult(0, ""));
        }
    }

    private static readonly TimeSettings Manual = new(3, false, "NoSync", null, false, 4, "SA Pacific Standard Time");

    [Fact]
    public void InstallSetsAutomaticTimeAndResyncsBeforeStartingTheService()
    {
        var time = new FakeTime(machine, Manual);
        Assert.Equal(0, new BootstrapApplication(new FakeElevation(), machine, machine, machine, output.Add, time: time).Run(Options, []));
        Assert.Equal([
            "sc.exe config w32time start= auto", "sc.exe start w32time",
            "w32tm.exe /config /manualpeerlist:time.windows.com,0x9 /syncfromflags:manual /update",
            "sc.exe config tzautoupdate start= demand", "w32tm.exe /resync"], time.Commands);
        var resync = machine.Mutations.IndexOf("time:resincronizar");
        Assert.True(resync >= 0 && resync < machine.Mutations.IndexOf("start"));
        Assert.Contains(output, line => line.StartsWith("HORA: w32tm.exe /resync -> 0", StringComparison.Ordinal));
    }

    [Fact]
    public void DryRunOnlyDescribesTheTimeStep()
    {
        var time = new FakeTime(machine, Manual);
        Assert.Equal(0, new BootstrapApplication(new FakeElevation(), machine, machine, machine, output.Add, time: time)
            .Run(Options with { DryRun = true }, []));
        Assert.Empty(time.Commands);
        Assert.Contains(output, line => line.StartsWith("HORA: W32Time automatico", StringComparison.Ordinal));
    }

    [Fact]
    public void TimeFailureNeverBlocksTheInstallation()
    {
        var time = new FakeTime(machine, Manual, new UnauthorizedAccessException());
        Assert.Equal(0, new BootstrapApplication(new FakeElevation(), machine, machine, machine, output.Add, time: time).Run(Options, []));
        Assert.Contains("start", machine.Mutations);
        Assert.Contains(output, line => line.Contains("no se pudo ajustar (UnauthorizedAccessException)", StringComparison.Ordinal));
    }

    [Fact]
    public void DomainMachineKeepsTheDomainTimeSource()
    {
        var time = new FakeTime(machine, new(2, true, "NT5DS", "", true, 3, "SA Pacific Standard Time"));
        Assert.Equal(0, new BootstrapApplication(new FakeElevation(), machine, machine, machine, output.Add, time: time).Run(Options, []));
        Assert.Equal(["w32tm.exe /resync"], time.Commands);
    }
}
