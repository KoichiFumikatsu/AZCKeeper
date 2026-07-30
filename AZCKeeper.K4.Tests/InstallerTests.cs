using System;
using System.IO;
using AZCKeeper.K4.Shell;
using Xunit;

namespace AZCKeeper.K4.Tests;

/// <summary>
/// La auto-instalación per-user: detectar si ya estamos instalados y copiar el exe (+ el
/// updater) al dir de instalación. Con directorios temporales, sin tocar %APPDATA% real.
/// </summary>
public class InstallerTests : IDisposable
{
    private readonly string _root;
    private readonly string _srcDir;
    private readonly string _installDir;

    public InstallerTests()
    {
        _root = Path.Combine(Path.GetTempPath(), "k4inst_" + Guid.NewGuid().ToString("N"));
        _srcDir = Path.Combine(_root, "download");
        _installDir = Path.Combine(_root, "app");
        Directory.CreateDirectory(_srcDir);
    }

    public void Dispose() { try { Directory.Delete(_root, true); } catch { } }

    private string MakeFakeExe(string dir, string name, string content = "exe")
    {
        Directory.CreateDirectory(dir);
        var p = Path.Combine(dir, name);
        File.WriteAllText(p, content);
        return p;
    }

    [Fact]
    public void IsInInstallDir_DistingueOrigen()
    {
        var fromDownload = Path.Combine(_srcDir, Installer.ExeName);
        var fromInstall = Path.Combine(_installDir, Installer.ExeName);

        Assert.False(Installer.IsInInstallDir(fromDownload, _installDir));
        Assert.True(Installer.IsInInstallDir(fromInstall, _installDir));
    }

    [Fact]
    public void IsInInstallDir_EsCaseInsensitiveYNormalizado()
    {
        var exe = Path.Combine(_installDir.ToUpperInvariant(), Installer.ExeName);
        Assert.True(Installer.IsInInstallDir(exe, _installDir));
    }

    [Fact]
    public void InstallFrom_CopiaExeYUpdater_YDevuelveLaRuta()
    {
        var srcExe = MakeFakeExe(_srcDir, Installer.ExeName, "cliente");
        MakeFakeExe(_srcDir, Installer.UpdaterName, "helper");

        var installer = new Installer(_installDir);
        var installedExe = installer.InstallFrom(srcExe);

        Assert.Equal(Path.Combine(_installDir, Installer.ExeName), installedExe);
        Assert.True(File.Exists(installedExe));
        Assert.Equal("cliente", File.ReadAllText(installedExe));
        Assert.True(File.Exists(Path.Combine(_installDir, Installer.UpdaterName))); // el updater viajó
    }

    [Fact]
    public void InstallFrom_SinUpdaterAlLado_IgualInstalaElExe()
    {
        var srcExe = MakeFakeExe(_srcDir, Installer.ExeName, "cliente");
        // no se crea el updater

        var installer = new Installer(_installDir);
        installer.InstallFrom(srcExe);

        Assert.True(File.Exists(Path.Combine(_installDir, Installer.ExeName)));
        Assert.False(File.Exists(Path.Combine(_installDir, Installer.UpdaterName)));
    }

    [Fact]
    public void InstallFrom_SinUpdaterEnDisco_LoExtraeDelStreamEmbebido()
    {
        var srcExe = MakeFakeExe(_srcDir, Installer.ExeName, "cliente");
        // no hay updater al lado, pero sí embebido (Setup de un solo archivo)
        using var embedded = new MemoryStream(System.Text.Encoding.UTF8.GetBytes("helper-embebido"));

        new Installer(_installDir).InstallFrom(srcExe, embedded);

        var updater = Path.Combine(_installDir, Installer.UpdaterName);
        Assert.True(File.Exists(updater));
        Assert.Equal("helper-embebido", File.ReadAllText(updater));
    }

    [Fact]
    public void InstallFrom_ConUpdaterEnDisco_LePrefiereSobreElEmbebido()
    {
        var srcExe = MakeFakeExe(_srcDir, Installer.ExeName, "cliente");
        MakeFakeExe(_srcDir, Installer.UpdaterName, "helper-disco");
        using var embedded = new MemoryStream(System.Text.Encoding.UTF8.GetBytes("helper-embebido"));

        new Installer(_installDir).InstallFrom(srcExe, embedded);
        Assert.Equal("helper-disco", File.ReadAllText(Path.Combine(_installDir, Installer.UpdaterName)));
    }

    [Fact]
    public void InstallFrom_SobrescribeUnaInstalacionPrevia()
    {
        MakeFakeExe(_installDir, Installer.ExeName, "viejo");     // instalación previa
        var srcExe = MakeFakeExe(_srcDir, Installer.ExeName, "nuevo");

        new Installer(_installDir).InstallFrom(srcExe);
        Assert.Equal("nuevo", File.ReadAllText(Path.Combine(_installDir, Installer.ExeName)));
    }
}
