using Keeper.Bootstrapper;

[assembly: CollectionBehavior(DisableTestParallelization = true, MaxParallelThreads = 1)]

namespace Keeper.Bootstrapper.Tests;

public sealed class BootstrapTests
{
    private readonly FakeMachine machine = new();
    private readonly FakeElevation elevation = new();
    private readonly List<string> output = [];
    private BootstrapApplication App => new(elevation, machine, machine, machine, output.Add);
    private static BootstrapOptions Options => new(false, false, false, Path.GetFullPath("fake-payload"), "fake-config.json");

    [Theory]
    [InlineData(false)]
    [InlineData(true)]
    public void OptionalEnrollmentTicketIsPassedOnlyWhenPresentAndNeverLogged(bool dryRun)
    {
        machine.Config = machine.Config with { EnrollmentTicket = "short-lived-secret" };
        Assert.Equal(0, App.Run(Options with { DryRun = dryRun }, []));
        Assert.DoesNotContain(output, line => line.Contains("short-lived-secret", StringComparison.Ordinal));
        Assert.Contains(output, line => line.Contains("KEEPER_ENROLLMENT_TICKET=[REDACTED]", StringComparison.Ordinal));
        if (dryRun) Assert.Empty(machine.Mutations);
        else Assert.Contains("KEEPER_ENROLLMENT_TICKET=short-lived-secret", machine.EnvironmentValues);
    }

    [Theory]
    [InlineData("")]
    [InlineData(" ")]
    [InlineData("ticket\0KEEPER_ENABLE_HKLM=1")]
    [InlineData("ticket\r\n")]
    public void InvalidEnrollmentTicketIsRejectedBeforeChanges(string ticket)
    {
        machine.Config = machine.Config with { EnrollmentTicket = ticket };
        Assert.Throws<ArgumentException>(() => App.Run(Options, []));
        Assert.Empty(machine.Mutations);
    }

    [Theory]
    [InlineData(false, false)]
    [InlineData(false, true)]
    [InlineData(true, false)]
    [InlineData(true, true)]
    public void ElevatedSystemModePerformsSameOperationsAsNormalMode(bool uninstall, bool existing)
    {
        var normalMachine = new FakeMachine();
        var normalOutput = new List<string>();
        var normal = new BootstrapApplication(new FakeElevation(), normalMachine, normalMachine, normalMachine, normalOutput.Add);
        if (existing)
        {
            normal.Run(Options, []);
            App.Run(Options, []);
            normalMachine.Mutations.Clear(); machine.Mutations.Clear();
            normalOutput.Clear(); output.Clear();
        }
        string[] arguments = [uninstall ? "--system-uninstall" : "--system-install"];
        var options = BootstrapOptions.Parse(arguments, Path.GetFullPath("package")) with
        {
            PayloadDirectory = Options.PayloadDirectory, ConfigPath = Options.ConfigPath
        };

        Assert.Equal(0, normal.Run(Options with { Uninstall = uninstall }, []));
        Assert.Equal(0, App.Run(options, arguments));

        Assert.Equal(normalOutput, output);
        Assert.Equal(normalMachine.Mutations, machine.Mutations);
        Assert.Equal(normalMachine.Service, machine.Service);
        Assert.Equal(normalMachine.Files, machine.Files);
        Assert.Equal(normalMachine.EnvironmentValues, machine.EnvironmentValues);
        Assert.Equal(normalMachine.Running, machine.Running);
        Assert.Empty(elevation.Requests);
    }

    [Theory]
    [InlineData("--system-install", false)]
    [InlineData("--system-install", true)]
    [InlineData("--system-uninstall", false)]
    [InlineData("--system-uninstall", true)]
    public void SystemModeWithMediumTokenFailsBeforeConfigOrPayloadWithoutRelaunch(string mode, bool dryRun)
    {
        elevation.Elevated = false;
        machine.Config = machine.Config with { ApiBase = "invalid" };
        machine.Payload.Clear();
        string[] arguments = dryRun ? [mode, "--dry-run"] : [mode];
        var options = BootstrapOptions.Parse(arguments, Path.GetFullPath("package"));

        Assert.Equal(BootstrapApplication.ElevationRequired, App.Run(options, arguments));

        Assert.Contains($"{mode} requiere ejecutarse ya elevado, p.ej. shell SYSTEM de DWService", Assert.Single(output));
        Assert.Empty(machine.Mutations);
        Assert.Empty(elevation.Requests);
    }

    [Theory]
    [InlineData(false, false)]
    [InlineData(false, true)]
    [InlineData(true, false)]
    [InlineData(true, true)]
    public void ElevatedSystemDryRunPrintsSameOperationsWithoutMutations(bool uninstall, bool existing)
    {
        if (existing) App.Run(Options, []);
        machine.Mutations.Clear(); output.Clear();
        string[] arguments = [uninstall ? "--system-uninstall" : "--system-install", "--dry-run"];
        var options = BootstrapOptions.Parse(arguments, Path.GetFullPath("package"));

        Assert.Equal(0, App.Run(options, arguments));

        Assert.Empty(machine.Mutations);
        Assert.Empty(elevation.Requests);
        Assert.Equal(existing, machine.Running);
        var dryOperations = output.Skip(1).SkipLast(1).ToArray();
        output.Clear();
        Assert.Equal(0, App.Run(options with { DryRun = false }, [arguments[0]]));
        Assert.Equal(dryOperations, output.SkipLast(1));
        Assert.Empty(elevation.Requests);
    }

    [Theory]
    [InlineData("--uninstall")]
    [InlineData("--system-uninstall")]
    public void ConflictingSystemInstallArgumentsAreRejected(string argument)
    {
        Assert.Throws<ArgumentException>(() => BootstrapOptions.Parse(["--system-install", argument], Path.GetFullPath("package")));
        Assert.Throws<ArgumentException>(() => BootstrapOptions.Parse([argument, "--system-install"], Path.GetFullPath("package")));
    }

    [Fact]
    public void InstallCreatesProtectedDirectoriesEnvironmentAndAutomaticLocalSystemService()
    {
        Assert.Equal(0, App.Run(Options, []));
        Assert.Equal("KeeperAgent", machine.Service!.Name);
        Assert.Equal("LocalSystem", machine.Service.Account);
        Assert.Equal("auto", machine.Service.StartType);
        Assert.Equal("own", machine.Service.ServiceType);
        Assert.Equal(Path.Combine(machine.InstallDirectory, "bin", "Keeper.Agent.exe"), machine.Service.Executable);
        Assert.Contains(machine.InstallDirectory, machine.Directories);
        Assert.Contains(Path.Combine(machine.InstallDirectory, "v4"), machine.Directories);
        Assert.DoesNotContain(Path.Combine(machine.InstallDirectory, "config.json"), machine.Files.Keys);
        Assert.DoesNotContain(output, line => line.Contains("config.json", StringComparison.Ordinal));
        Assert.Contains($"KEEPER_DATA_DIR={Path.Combine(machine.InstallDirectory, "v4")}", machine.EnvironmentValues);
        Assert.Contains("KEEPER_API_BASE=https://keeper.test/v1/", machine.EnvironmentValues);
        Assert.Contains("KEEPER_ENABLE_HKLM=1", machine.EnvironmentValues);
        Assert.Contains($"KEEPER_DEVICE_ID={machine.Config.DeviceId}", machine.EnvironmentValues);
        Assert.DoesNotContain(machine.EnvironmentValues, value => value.StartsWith("KEEPER_ENROLLMENT_TICKET=", StringComparison.Ordinal));
        Assert.True(machine.Running);
        Assert.Empty(elevation.Requests);
        Assert.Equal(new[] { "mkdir-acl", "mkdir", "mkdir", "copy", "copy", "copy", "bin-acl", "create", "environment", "recovery", "start" }, machine.Mutations);
    }

    [Fact]
    public void ReinstallStopsBeforeCopyAndUpdatesInPlacePreservingIdentity()
    {
        App.Run(Options, []);
        var identity = Path.Combine(machine.InstallDirectory, "v4", "device-key.dpapi");
        machine.Files[identity] = "existing identity";
        var firstEnvironment = machine.EnvironmentValues;
        machine.Mutations.Clear();
        App.Run(Options, []);
        Assert.Equal("stop", machine.Mutations[0]);
        Assert.DoesNotContain("create", machine.Mutations);
        Assert.Contains("configure", machine.Mutations);
        Assert.Equal("existing identity", machine.Files[identity]);
        Assert.Equal(firstEnvironment, machine.EnvironmentValues);
        Assert.Single(machine.Mutations, mutation => mutation == "mkdir-acl");
        Assert.DoesNotContain(Path.Combine(machine.InstallDirectory, "config.json"), machine.Files.Keys);
        Assert.True(machine.Running);
    }

    [Fact]
    public void SystemUpdateReemplazaBinariosPreservandoEntornoYDatos()
    {
        elevation.Elevated = true;
        App.Run(Options with { SystemMode = true }, []);          // instalacion inicial
        var identity = Path.Combine(machine.InstallDirectory, "v4", "device-key.dpapi");
        machine.Files[identity] = "existing identity";
        var environment = machine.EnvironmentValues;
        machine.Mutations.Clear();
        Assert.Equal(0, App.Run(Options with { SystemMode = true, SystemUpdate = true }, []));
        Assert.Equal("stop", machine.Mutations[0]);
        Assert.Equal("start", machine.Mutations[^1]);
        Assert.Contains("copy", machine.Mutations);
        Assert.Contains("recovery", machine.Mutations);
        Assert.DoesNotContain("environment", machine.Mutations);   // env preservado, no reescrito
        Assert.DoesNotContain("create", machine.Mutations);
        Assert.DoesNotContain("configure", machine.Mutations);
        Assert.Equal(environment, machine.EnvironmentValues);
        Assert.Equal("existing identity", machine.Files[identity]); // device-key preservada
        Assert.True(machine.Running);
    }

    [Fact]
    public void SystemUpdateAceptaElPayloadExtraidoEnElStagingDelAgente()
    {
        // Ruta real: UpdateManager extrae en {ProgramData}/AZCKeeper/v4/staging/<id>.zip.d/agent.
        elevation.Elevated = true;
        App.Run(Options with { SystemMode = true }, []);
        machine.Mutations.Clear();
        var staged = Path.Combine(machine.InstallDirectory, "v4", "staging", "0123456789abcdef.zip.d", "agent");
        Assert.Equal(0, App.Run(Options with { SystemMode = true, SystemUpdate = true, PayloadDirectory = staged }, []));
        Assert.Contains("copy", machine.Mutations);
        Assert.True(machine.Running);
    }

    [Theory]
    [InlineData("bin")]
    [InlineData("")]
    public void SystemUpdateRechazaPayloadQueSolapaBinarios(string relative)
    {
        elevation.Elevated = true;
        App.Run(Options with { SystemMode = true }, []);
        machine.Mutations.Clear();
        var overlapping = Path.Combine(machine.InstallDirectory, relative);
        Assert.Throws<ArgumentException>(() => App.Run(Options with { SystemMode = true, SystemUpdate = true, PayloadDirectory = overlapping }, []));
        Assert.Empty(machine.Mutations);
    }

    [Fact]
    public void InstalacionYActualizacionDanLecturaAUsuariosSoloEnBin()
    {
        // Keeper.Session corre como el usuario: sin lectura en bin, el apphost de .NET no resuelve su ruta.
        elevation.Elevated = true;
        App.Run(Options with { SystemMode = true }, []);
        var bin = Path.Combine(machine.InstallDirectory, "bin");
        Assert.Equal([bin], machine.BinaryAclPaths);
        Assert.True(machine.Mutations.LastIndexOf("bin-acl") > machine.Mutations.LastIndexOf("copy"));
        Assert.True(machine.Mutations.IndexOf("bin-acl") < machine.Mutations.IndexOf("start"));
        machine.Mutations.Clear();
        machine.BinaryAclPaths.Clear();
        Assert.Equal(0, App.Run(Options with { SystemMode = true, SystemUpdate = true }, []));
        Assert.Equal([bin], machine.BinaryAclPaths);
        Assert.True(machine.Mutations.IndexOf("bin-acl") > machine.Mutations.LastIndexOf("copy"));
        Assert.True(machine.Mutations.IndexOf("bin-acl") < machine.Mutations.IndexOf("start"));
    }

    [Fact]
    public void SddlDeBinDaSoloLecturaYEjecucionAUsuarios()
    {
        Assert.Contains("(A;OICI;0x1200a9;;;BU)", WindowsConstants.BinDirectorySddl);
        Assert.Contains("(A;;0x1200a9;;;BU)", WindowsConstants.BinFileSddl);
        Assert.DoesNotContain("BU)", WindowsConstants.DirectorySddl);   // v4 (datos) sigue cerrado
        Assert.StartsWith("O:BAG:BAD:P", WindowsConstants.BinDirectorySddl);
    }

    [Fact]
    public void FormatoCompartidoUsaSuPropiaCarpetaComoPayloadYElConfigDelPadre()
    {
        var package = Path.Combine(Path.GetTempPath(), "keeper-layout-" + Guid.NewGuid().ToString("N"));
        var agent = Path.Combine(package, "agent");
        Directory.CreateDirectory(agent);
        try
        {
            File.WriteAllText(Path.Combine(agent, "Keeper.Agent.exe"), "x");
            var shared = BootstrapOptions.Parse(["--system-update"], agent + Path.DirectorySeparatorChar);
            Assert.Equal(Path.TrimEndingDirectorySeparator(agent), Path.TrimEndingDirectorySeparator(shared.PayloadDirectory));
            Assert.Equal(Path.Combine(package, "installation.json"), shared.ConfigPath);
            var legacy = BootstrapOptions.Parse(["--system-update"], package);
            Assert.Equal(agent, legacy.PayloadDirectory);
            Assert.Equal(Path.Combine(package, "installation.json"), legacy.ConfigPath);
        }
        finally { Directory.Delete(package, recursive: true); }
    }

    [Fact]
    public void SystemUpdateSinServicioInstaladoFalla()
    {
        elevation.Elevated = true;
        Assert.Throws<ArgumentException>(() => App.Run(Options with { SystemMode = true, SystemUpdate = true }, []));
    }

    [Fact]
    public void SystemUpdateDryRunNoMuta()
    {
        elevation.Elevated = true;
        App.Run(Options with { SystemMode = true }, []);
        machine.Mutations.Clear();
        Assert.Equal(0, App.Run(Options with { SystemMode = true, SystemUpdate = true, DryRun = true }, []));
        Assert.Empty(machine.Mutations);
    }

    [Fact]
    public void UninstallRemovesServiceFilesAndExactEnforcerPoliciesAndCanRepeat()
    {
        App.Run(Options, []);
        foreach (var key in BootstrapApplication.BrowserKeys)
        {
            machine.RegistryTrees.Add(key + @"\URLBlocklist");
            machine.RegistryTrees.Add(key + @"\URLAllowlist");
            machine.RegistryValues.Add((key, "DownloadRestrictions"));
        }
        machine.RegistryTrees.Add(BootstrapApplication.UsbKey);
        machine.RegistryValues.Add((BootstrapApplication.InstallerKey, "DisableMSI"));
        machine.RegistryValues.Add((BootstrapApplication.InstallerKey, "AlwaysInstallElevated"));
        machine.RegistryTrees.Add(@"SOFTWARE\Unrelated");
        machine.RegistryValues.Add((BootstrapApplication.InstallerKey, "Unrelated"));
        machine.Mutations.Clear();
        Assert.Equal(0, App.Run(Options with { Uninstall = true }, []));
        Assert.Equal(new[] { "stop", "delete-service" }, machine.Mutations.Take(2));
        Assert.Equal("delete-directory", machine.Mutations[^1]);
        Assert.Null(machine.Service);
        Assert.Empty(machine.Files);
        Assert.Empty(machine.Directories);
        Assert.Equal(new[] { @"SOFTWARE\Unrelated" }, machine.RegistryTrees);
        Assert.Equal(new[] { (BootstrapApplication.InstallerKey, "Unrelated") }, machine.RegistryValues);
        Assert.Empty(machine.EnvironmentValues);
        Assert.Equal(0, App.Run(Options with { Uninstall = true }, []));
    }

    [Theory]
    [InlineData(false)]
    [InlineData(true)]
    public void NonElevatedRequestsRelaunchWithoutMutations(bool uninstall)
    {
        elevation.Elevated = false;
        string[] arguments = ["--config", @"C:\folder with spaces\installation.json"];
        Assert.Equal(0, App.Run(Options with { Uninstall = uninstall }, arguments));
        Assert.Empty(machine.Mutations);
        Assert.Equal(new[] { "--config", @"C:\folder with spaces\installation.json", "--elevated-child" }, Assert.Single(elevation.Requests));
    }

    [Fact]
    public void CancelledUacReturns1223AndClearMessageWithoutChanges()
    {
        elevation.Elevated = false; elevation.Result = 1223;
        Assert.Equal(1223, App.Run(Options, []));
        Assert.Contains(output, line => line.Contains("UAC cancelado", StringComparison.Ordinal));
        Assert.Empty(machine.Mutations);
        Assert.Single(elevation.Requests);
    }

    [Fact]
    public void RelaunchPropagatesChildExitCode()
    {
        elevation.Elevated = false; elevation.Result = 42;
        Assert.Equal(42, App.Run(Options, []));
        Assert.Empty(machine.Mutations);
    }

    [Fact]
    public void FailedElevationDoesNotLoop()
    {
        elevation.Elevated = false;
        Assert.Equal(740, App.Run(Options with { ElevatedChild = true }, []));
        Assert.Empty(elevation.Requests);
        Assert.Empty(machine.Mutations);
    }

    [Theory]
    [InlineData(false, false)]
    [InlineData(false, true)]
    [InlineData(true, false)]
    [InlineData(true, true)]
    public void DryRunNeverChecksElevationOrMutatesAndPrintsSameOperations(bool uninstall, bool existing)
    {
        if (existing) App.Run(Options, []);
        machine.Mutations.Clear(); output.Clear();
        elevation.ForbidCheck = true;
        App.Run(Options with { DryRun = true, Uninstall = uninstall }, []);
        Assert.Empty(machine.Mutations);
        Assert.Empty(elevation.Requests);
        var dryOperations = output.Skip(1).SkipLast(1).ToArray();
        elevation.ForbidCheck = false; output.Clear();
        App.Run(Options with { Uninstall = uninstall }, []);
        Assert.Equal(dryOperations, output.SkipLast(1));
    }

    [Theory]
    [InlineData("http://keeper.test/v1/")]
    [InlineData("https://keeper.test/")]
    [InlineData("https://user:password@keeper.test/v1/")]
    [InlineData("https://keeper.test/v1/?secret=value")]
    [InlineData("https://backend.example.invalid/v1/")]
    public void InvalidConfigurationFailsBeforeUacOrMutations(string api)
    {
        elevation.Elevated = false;
        machine.Config = machine.Config with { ApiBase = api };
        Assert.Throws<ArgumentException>(() => App.Run(Options, []));
        Assert.Empty(elevation.Requests);
        Assert.Empty(machine.Mutations);
    }

    [Fact]
    public void MissingAgentFailsBeforeUacOrMutations()
    {
        machine.Payload.Clear(); elevation.Elevated = false;
        Assert.Throws<ArgumentException>(() => App.Run(Options, []));
        Assert.Empty(elevation.Requests);
        Assert.Empty(machine.Mutations);
    }

    [Theory]
    [InlineData(false)]
    [InlineData(true)]
    public void StopFailurePreventsOverwritingOrDeletingFiles(bool uninstall)
    {
        App.Run(Options, []); machine.Mutations.Clear(); machine.FailStop = true;
        Assert.Throws<TimeoutException>(() => App.Run(Options with { Uninstall = uninstall }, []));
        Assert.Empty(machine.Mutations);
        Assert.NotNull(machine.Service);
    }

    [Fact]
    public void CopyFailureNeverStartsServiceOrClaimsSuccess()
    {
        machine.FailCopy = true;
        Assert.Throws<IOException>(() => App.Run(Options, []));
        Assert.False(machine.Running);
        Assert.Null(machine.Service);
        Assert.DoesNotContain(output, line => line.StartsWith("Instalacion completada", StringComparison.Ordinal));
    }

    [Fact]
    public void PayloadInsideInstallationIsRejected()
    {
        Assert.Throws<ArgumentException>(() => App.Run(Options with { PayloadDirectory = Path.Combine(machine.InstallDirectory, "bin") }, []));
        Assert.Empty(machine.Mutations);
    }

    [Fact]
    public void ServiceCommandQuotesExecutableAndUsesRequiredScmSettings()
    {
        var definition = new ServiceDefinition("KeeperAgent", @"C:\Program Data\AZCKeeper\bin\Keeper.Agent.exe");
        var arguments = definition.ScArguments(false);
        Assert.Equal(new[] { "create", "KeeperAgent", "binPath=", "\"C:\\Program Data\\AZCKeeper\\bin\\Keeper.Agent.exe\"", "type=", "own", "start=", "auto", "obj=", "LocalSystem", "DisplayName=", "AZCKeeper v4" }, arguments);
        Assert.Equal("config", definition.ScArguments(true)[0]);
    }

    [Theory]
    [InlineData("--unknown")]
    [InlineData("--config")]
    [InlineData("--payload")]
    public void InvalidArgumentsAreRejected(string argument) =>
        Assert.Throws<ArgumentException>(() => BootstrapOptions.Parse([argument], Path.GetFullPath("package")));

    [Theory]
    [InlineData("--config", "--dry-run")]
    [InlineData("--payload", "--dry-run")]
    [InlineData("--config", "--payload")]
    [InlineData("--payload", "--config")]
    [InlineData("--config", "-file")]
    [InlineData("--payload", "-directory")]
    [InlineData("--config", "")]
    [InlineData("--payload", "")]
    public void MissingOptionValueFailsBeforeElevationOrInstallation(string option, string value)
    {
        string[] arguments = [option, value];
        var error = Assert.Throws<ArgumentException>(() => App.Run(
            BootstrapOptions.Parse(arguments, Path.GetFullPath("package")), arguments));
        Assert.Contains($"{option} requiere una ruta", error.Message);
        Assert.Empty(machine.Mutations);
        Assert.Empty(elevation.Requests);
        Assert.Null(machine.Service);
    }

    [Fact]
    public void ExplicitPathsDoNotConsumeFollowingDryRunFlag()
    {
        var options = BootstrapOptions.Parse(["--config", "installation.json", "--payload", "agent", "--dry-run"], Path.GetFullPath("package"));
        Assert.True(options.DryRun);
        Assert.Equal(Path.GetFullPath("installation.json"), options.ConfigPath);
        Assert.Equal(Path.GetFullPath("agent"), options.PayloadDirectory);
    }
}
