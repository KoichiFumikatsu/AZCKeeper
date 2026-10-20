using Keeper.Bootstrapper;
using Keeper.Shared.Contracts;
using Keeper.Shared.Time;

namespace Keeper.Bootstrapper.Tests;

// Desinstalar borra el directorio de datos del agente, donde vive el registro del bloqueo del cambio de hora (4.0.28):
// antes se le devuelve SeSystemtimePrivilege a Administradores. LSA simulada.
public sealed class TimeChangeLockUninstallTests : IDisposable
{
    private readonly FakeMachine machine = new();
    private readonly List<string> output = [];
    private static BootstrapOptions Options => new(false, false, false, Path.GetFullPath("fake-payload"), "fake-config.json");
    private string StatePath => Path.Combine(machine.InstallDirectory, "v4", AgentDataFiles.TimeChangeLock);

    private sealed class FakeRights : IAccountRights
    {
        public HashSet<string> Holders { get; } = [TimeChangeLock.Administrators, TimeChangeLock.LocalService];
        public bool Fail { get; set; }
        public bool Has(string sid, string right) => right == TimeChangeLock.Right && Holders.Contains(sid);
        public void Add(string sid, string right) { if (Fail) throw new UnauthorizedAccessException(); Holders.Add(sid); }
        public void Remove(string sid, string right) { if (Fail) throw new UnauthorizedAccessException(); Holders.Remove(sid); }
    }

    public void Dispose()
    {
        if (File.Exists(StatePath)) File.Delete(StatePath);
    }

    [Fact]
    public void UninstallGivesTheRightBackBeforeDeletingTheDataDirectory()
    {
        var rights = new FakeRights();
        new TimeChangeLock(rights, StatePath).Lock(DateTimeOffset.UtcNow);
        Assert.DoesNotContain(TimeChangeLock.Administrators, rights.Holders);
        var app = new BootstrapApplication(new FakeElevation(), machine, machine, machine, output.Add, accountRights: rights);
        Assert.Equal(0, app.Run(Options with { Uninstall = true }, []));
        Assert.Equal(new HashSet<string> { TimeChangeLock.Administrators, TimeChangeLock.LocalService }, rights.Holders);
        Assert.False(File.Exists(StatePath));
        Assert.Contains(output, l => l.StartsWith("HORA: SeSystemtimePrivilege devuelto a Administradores", StringComparison.Ordinal));
        Assert.True(output.FindIndex(l => l.StartsWith("HORA: devolver", StringComparison.Ordinal)) < output.FindIndex(l => l.StartsWith("DELETE DIRECTORY", StringComparison.Ordinal)));
    }

    [Fact]
    public void UninstallWithoutLockOrWithAFailureNeverFails()
    {
        var rights = new FakeRights();
        var app = new BootstrapApplication(new FakeElevation(), machine, machine, machine, output.Add, accountRights: rights);
        Assert.Equal(0, app.Run(Options with { Uninstall = true }, []));
        Assert.Contains(output, l => l.Contains("nada que devolver", StringComparison.Ordinal));

        new TimeChangeLock(rights, StatePath).Lock(DateTimeOffset.UtcNow);
        rights.Fail = true;
        output.Clear();
        Assert.Equal(0, new BootstrapApplication(new FakeElevation(), machine, machine, machine, output.Add, accountRights: rights).Run(Options with { Uninstall = true }, []));
        Assert.Contains(output, l => l.Contains("la desinstalacion continua", StringComparison.Ordinal));
    }

    [Fact]
    public void DryRunOnlyDescribesTheStep()
    {
        var rights = new FakeRights();
        new TimeChangeLock(rights, StatePath).Lock(DateTimeOffset.UtcNow);
        new BootstrapApplication(new FakeElevation(), machine, machine, machine, output.Add, accountRights: rights).Run(Options with { DryRun = true, Uninstall = true }, []);
        Assert.DoesNotContain(TimeChangeLock.Administrators, rights.Holders);
        Assert.True(File.Exists(StatePath));
    }
}
