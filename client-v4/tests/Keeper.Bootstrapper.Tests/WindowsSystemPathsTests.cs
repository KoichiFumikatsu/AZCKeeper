using System.Diagnostics;
using Keeper.Bootstrapper;

namespace Keeper.Bootstrapper.Tests;

public sealed class WindowsSystemPathsTests
{
    [Theory]
    [InlineData(false, false)]
    [InlineData(false, true)]
    [InlineData(true, false)]
    [InlineData(true, true)]
    public void UninstallDeletesJunctionWithoutTraversingTarget(bool linkAtRoot, bool missingTarget)
    {
        if (!OperatingSystem.IsWindows()) return;
        var temporary = Path.GetFullPath(Path.Combine(Path.GetTempPath(), "Keeper.Bootstrapper.Tests-" + Guid.NewGuid().ToString("N")));
        var install = Path.Combine(temporary, "install");
        var target = Path.Combine(temporary, "outside");
        var link = linkAtRoot ? install : Path.Combine(install, "nested", "junction");
        try
        {
            Directory.CreateDirectory(target);
            Directory.CreateDirectory(Path.GetDirectoryName(link)!);
            var sentinel = Path.Combine(target, "keep.txt");
            File.WriteAllText(sentinel, "untouched");
            if (!linkAtRoot) File.WriteAllText(Path.Combine(install, "local.txt"), "remove");
            CreateJunction(link, target);
            if (missingTarget)
            {
                File.Delete(sentinel);
                Directory.Delete(target);
            }
            Assert.True((File.GetAttributes(link) & FileAttributes.ReparsePoint) != 0);
            ISystemPaths paths = new WindowsSystemPaths(install);
            if (!missingTarget) Assert.Throws<IOException>(() => paths.ValidateInstallTree());
            var machine = new FakeMachine
            {
                Service = new ServiceDefinition(BootstrapApplication.ServiceName, Path.Combine(install, "agent.exe")),
                Running = true
            };
            var output = new List<string>();
            var app = new BootstrapApplication(new FakeElevation(), machine, paths, machine, output.Add);
            var options = new BootstrapOptions(true, true, false, "unused", "unused");
            Assert.Equal(0, app.Run(options, []));
            Assert.Empty(machine.Mutations);
            Assert.NotNull(new DirectoryInfo(link).LinkTarget);

            Assert.Equal(0, app.Run(options with { DryRun = false }, []));
            Assert.False(Directory.Exists(install));
            Assert.Null(new DirectoryInfo(link).LinkTarget);
            Assert.Null(machine.Service);
            Assert.False(machine.Running);
            Assert.Contains("Desinstalacion completada.", output);
            if (missingTarget) Assert.False(Directory.Exists(target));
            else Assert.Equal("untouched", File.ReadAllText(sentinel));
            Assert.Equal(0, app.Run(options with { DryRun = false }, []));
        }
        finally
        {
            // Remove the junction itself before recursively cleaning the isolated fixture.
            var junction = new DirectoryInfo(link);
            if (junction.LinkTarget is not null) junction.Delete();
            var tempRoot = Path.GetFullPath(Path.GetTempPath()).TrimEnd(Path.DirectorySeparatorChar) + Path.DirectorySeparatorChar;
            if (!temporary.StartsWith(tempRoot, StringComparison.OrdinalIgnoreCase)) throw new IOException("Test cleanup escaped temporary directory.");
            if (Directory.Exists(temporary)) Directory.Delete(temporary, recursive: true);
        }
    }

    private static void CreateJunction(string link, string target)
    {
        var start = new ProcessStartInfo("powershell.exe")
        {
            UseShellExecute = false, CreateNoWindow = true, RedirectStandardOutput = true, RedirectStandardError = true
        };
        start.ArgumentList.Add("-NoProfile");
        start.ArgumentList.Add("-NonInteractive");
        start.ArgumentList.Add("-Command");
        start.ArgumentList.Add("$ErrorActionPreference = 'Stop'; New-Item -ItemType Junction -Path $env:KEEPER_TEST_LINK -Target $env:KEEPER_TEST_TARGET | Out-Null");
        start.Environment["KEEPER_TEST_LINK"] = link;
        start.Environment["KEEPER_TEST_TARGET"] = target;
        using var process = Process.Start(start)!;
        var output = process.StandardOutput.ReadToEndAsync();
        var error = process.StandardError.ReadToEndAsync();
        try
        {
            Assert.True(process.WaitForExit(15_000), "Junction creation timed out.");
        }
        finally
        {
            if (!process.HasExited) process.Kill(entireProcessTree: true);
            process.WaitForExit();
            Task.WhenAll(output, error).GetAwaiter().GetResult();
        }
        Assert.True(process.ExitCode == 0, output.Result + error.Result);
    }
}
