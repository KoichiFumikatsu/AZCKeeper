using System;
using System.Threading.Tasks;
using AZCKeeper.K4.Shell;
using Microsoft.Win32;
using Xunit;

namespace AZCKeeper.K4.Tests;

/// <summary>
/// StartupManager sobre una subclave HKCU de PRUEBA (no la Run real, para no ensuciar el
/// arranque de la máquina de test) — verifica el round-trip enable/disable/isEnabled.
/// </summary>
public class StartupManagerTests : IDisposable
{
    private readonly string _testKey = $@"SOFTWARE\AZCKeeper4_Test\{Guid.NewGuid():N}\Run";

    public void Dispose()
    {
        try { Registry.CurrentUser.DeleteSubKeyTree(_testKey.Split(new[] { '\\' })[0] + @"\AZCKeeper4_Test", false); } catch { }
        try { Registry.CurrentUser.DeleteSubKeyTree(@"SOFTWARE\AZCKeeper4_Test", false); } catch { }
    }

    [Fact]
    public void Enable_Disable_IsEnabled_RoundTrip()
    {
        var mgr = new StartupManager(_testKey, exePath: () => @"C:\ruta\AZCKeeper4.exe");

        Assert.False(mgr.IsEnabled());
        mgr.EnableStartup();
        Assert.True(mgr.IsEnabled());
        Assert.Equal("\"C:\\ruta\\AZCKeeper4.exe\"", mgr.RegisteredValue()); // con comillas
        mgr.DisableStartup();
        Assert.False(mgr.IsEnabled());
    }
}

/// <summary>
/// La DECISIÓN de actualizar es pura y cubre los casos que importan: al día, disponible,
/// crítico (bajo el mínimo), forzado. Nunca se baja de versión.
/// </summary>
public class UpdateDecisionTests
{
    private static ServerVersion Sv(string latest, string? min = null, bool force = false)
        => new(latest, "http://x/pkg.zip", min, force);

    [Fact]
    public void AlDia_NoActualiza()
    {
        var d = K4UpdateManager.Decide("4.0.0.0", Sv("4.0.0.0"), autoDownload: true);
        Assert.False(d.ShouldDownload);
        Assert.Equal("al dia", d.Reason);
    }

    [Fact]
    public void VersionMasNueva_SinFlags_EsDisponibleManual()
    {
        var d = K4UpdateManager.Decide("4.0.0.0", Sv("4.1.0.0"), autoDownload: false);
        Assert.False(d.ShouldDownload);          // hay nueva, pero sin auto/force no se baja sola
        Assert.False(d.Critical);
    }

    [Fact]
    public void AutoDownload_DescargaLaNueva()
    {
        var d = K4UpdateManager.Decide("4.0.0.0", Sv("4.1.0.0"), autoDownload: true);
        Assert.True(d.ShouldDownload);
        Assert.Equal("4.1.0.0", d.Version);
    }

    [Fact]
    public void BajoElMinimo_EsCriticoYDescargaAunqueSinAuto()
    {
        var d = K4UpdateManager.Decide("4.0.0.0", Sv("4.2.0.0", min: "4.1.0.0"), autoDownload: false);
        Assert.True(d.ShouldDownload);
        Assert.True(d.Critical);
    }

    [Fact]
    public void Forzado_DescargaAunqueSinAuto()
    {
        var d = K4UpdateManager.Decide("4.0.0.0", Sv("4.1.0.0", force: true), autoDownload: false);
        Assert.True(d.ShouldDownload);
        Assert.Contains("forzado", d.Reason);
    }

    [Fact]
    public void SinRespuesta_NoActualiza()
    {
        var d = K4UpdateManager.Decide("4.0.0.0", null, autoDownload: true);
        Assert.False(d.ShouldDownload);
    }

    [Fact]
    public async Task CheckAsync_ParseaLaRespuestaYDecide()
    {
        Task<string?> Get(string url) => Task.FromResult<string?>(
            "{\"ok\":true,\"latestVersion\":\"4.1.0.0\",\"downloadUrl\":\"http://x/p.zip\",\"forceUpdate\":true}");
        var mgr = new K4UpdateManager("http://x/api", "4.0.0.0", autoDownload: false, httpGet: Get);

        var d = await mgr.CheckAsync();
        Assert.True(d.ShouldDownload);        // forceUpdate=true
        Assert.Equal("4.1.0.0", d.Version);
    }
}
