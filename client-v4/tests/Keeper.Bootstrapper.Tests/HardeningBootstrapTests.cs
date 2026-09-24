using Keeper.Bootstrapper.Hardening;
using Keeper.Shared.Contracts;

namespace Keeper.Bootstrapper.Tests;

public sealed class HardeningBootstrapTests
{
    [Theory]
    [InlineData("--harden")]
    [InlineData("--unharden")]
    public void ExplicitDryRunDoesNotCheckElevationReadInstallationOrTouchMachine(string mode)
    {
        var machine = new FakeMachine { Config = new("invalid", Guid.Empty, Guid.Empty) };
        machine.Payload.Clear();
        var elevation = new FakeElevation { ForbidCheck = true };
        var fake = new HardeningFake { ForbidAccess = true };
        var output = new List<string>();
        var coordinator = new HardeningCoordinator(fake, fake, fake, fake, output.Add);
        var app = new BootstrapApplication(elevation, machine, machine, machine, output.Add, coordinator);
        string[] args = [mode, "--dry-run"];
        Assert.Equal(0, app.Run(BootstrapOptions.Parse(args, Path.GetFullPath("unused")), args));
        Assert.Empty(machine.Mutations);
        Assert.Empty(fake.Operations);
        Assert.Empty(elevation.Requests);
    }

    [Theory]
    [InlineData("--harden")]
    [InlineData("--unharden")]
    public void ExplicitCommandWithoutElevationDoesNotRelaunchOrTouchMachine(string mode)
    {
        var machine = new FakeMachine();
        var elevation = new FakeElevation { Elevated = false };
        var fake = new HardeningFake { ForbidAccess = true };
        var coordinator = new HardeningCoordinator(fake, fake, fake, fake, _ => { });
        var app = new BootstrapApplication(elevation, machine, machine, machine, _ => { }, coordinator);
        Assert.Equal(740, app.Run(BootstrapOptions.Parse([mode], Path.GetFullPath("unused")), [mode]));
        Assert.Empty(elevation.Requests);
        Assert.Empty(fake.Operations);
    }

    [Theory]
    [InlineData(HardeningMode.Auto, true)]
    [InlineData(HardeningMode.Auto, false)]
    [InlineData(HardeningMode.Panel, true)]
    public void InstallationStartsServiceBeforeHardeningAndPersistsEffectiveMode(HardeningMode mode, bool validCredential)
    {
        var machine = new FakeMachine();
        machine.Config = machine.Config with { Hardening = new() { HardeningMode = mode } };
        var fake = new HardeningFake { CredentialValid = validCredential };
        var coordinator = new HardeningCoordinator(fake, fake, fake, fake, _ => Assert.True(machine.Running));
        var app = new BootstrapApplication(new FakeElevation(), machine, machine, machine, _ => { }, coordinator);
        var options = new BootstrapOptions(false, false, false, Path.GetFullPath("fake-payload"), "fake-config.json");
        Assert.Equal(mode == HardeningMode.Auto && !validCredential ? 1 : 0, app.Run(options, []));
        Assert.Equal(mode == HardeningMode.Auto && validCredential ? "hardened" : mode == HardeningMode.Panel ? "waiting_panel" : "failed", fake.State!.Status);
        Assert.Equal(validCredential ? mode : HardeningMode.Panel, fake.State.Mode);
    }

    [Theory]
    [InlineData("--harden", "--unharden")]
    [InlineData("--harden", "--uninstall")]
    [InlineData("--unharden", "--system-install")]
    [InlineData("--harden", "--system-uninstall")]
    [InlineData("--harden", "--password")]
    [InlineData("--unharden", "--hardening-config")]
    public void RejectsConflictsAndNeverAcceptsPasswordArgument(string first, string second) =>
        Assert.Throws<ArgumentException>(() => BootstrapOptions.Parse([first, second], Path.GetFullPath("unused")));
}
