using System;
using System.IO;
using AZCKeeper.K4.Shell;
using Microsoft.Win32;
using Xunit;

namespace AZCKeeper.K4.Tests;

/// <summary>
/// La limpieza de instalaciones previas: quita entradas de arranque y binarios viejos de
/// CUALQUIER Keeper, sin tocar la data. Se prueban las partes de registro (subclave de
/// prueba) y de archivos (dirs temporales); matar procesos es best-effort del SO.
/// </summary>
public class LegacyCleanerTests : IDisposable
{
    private readonly string _root;
    private readonly string _testRunKey = $@"SOFTWARE\AZCKeeper_CleanTest\{Guid.NewGuid():N}";

    public LegacyCleanerTests()
    {
        _root = Path.Combine(Path.GetTempPath(), "k4clean_" + Guid.NewGuid().ToString("N"));
        Directory.CreateDirectory(_root);
    }

    public void Dispose()
    {
        try { Directory.Delete(_root, true); } catch { }
        try { Registry.CurrentUser.DeleteSubKeyTree(@"SOFTWARE\AZCKeeper_CleanTest", false); } catch { }
    }

    [Fact]
    public void RemoveRunEntries_QuitaSoloLasDeKeeper()
    {
        using (var key = Registry.CurrentUser.CreateSubKey(_testRunKey, writable: true)!)
        {
            key.SetValue("AZCKeeper_Cliente", "\"C:\\...\\AZCKeeper_Client.exe\""); // K3, por nombre
            key.SetValue("OtraApp", "\"C:\\algo\\AZCKeeper4.exe\"");                 // por data (menciona AZCKeeper)
            key.SetValue("Spotify", "\"C:\\spotify.exe\"");                          // ajeno: se conserva
        }

        int removed;
        using (var key = Registry.CurrentUser.OpenSubKey(_testRunKey, writable: true)!)
            removed = new LegacyCleaner().RemoveRunEntries(key);

        Assert.Equal(2, removed);
        using var check = Registry.CurrentUser.OpenSubKey(_testRunKey, writable: false)!;
        Assert.Null(check.GetValue("AZCKeeper_Cliente"));
        Assert.Null(check.GetValue("OtraApp"));
        Assert.NotNull(check.GetValue("Spotify")); // el ajeno sobrevive
    }

    [Fact]
    public void RemoveOldBinaries_BorraElExeDeK3_NoElDeK4()
    {
        var appDir = Path.Combine(_root, "app");
        Directory.CreateDirectory(appDir);
        File.WriteAllText(Path.Combine(appDir, "AZCKeeper_Client.exe"), "k3");   // viejo
        File.WriteAllText(Path.Combine(appDir, "AZCKeeper4.exe"), "k4");         // actual: NO se toca
        File.WriteAllText(Path.Combine(appDir, "otro.exe"), "x");               // ajeno

        var removed = new LegacyCleaner().RemoveOldBinaries(new[] { appDir });

        Assert.Equal(1, removed);
        Assert.False(File.Exists(Path.Combine(appDir, "AZCKeeper_Client.exe")));
        Assert.True(File.Exists(Path.Combine(appDir, "AZCKeeper4.exe")));   // el de K4 se conserva
        Assert.True(File.Exists(Path.Combine(appDir, "otro.exe")));
    }

    [Fact]
    public void RemoveOldBinaries_DirInexistente_NoRevienta()
    {
        var removed = new LegacyCleaner().RemoveOldBinaries(new[] { Path.Combine(_root, "no_existe") });
        Assert.Equal(0, removed);
    }

    [Fact]
    public void KillKeeperProcesses_ExcluyeElActual_NoSeMata()
    {
        // El proceso de test se llama "testhost"/"dotnet", no "AZCKeeper*", así que no mata
        // nada; lo importante es que excluir el PID actual no lanza ni cuelga.
        var killed = new LegacyCleaner().KillKeeperProcesses(Environment.ProcessId);
        Assert.True(killed >= 0);
    }
}
