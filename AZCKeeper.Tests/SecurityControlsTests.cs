using System.Collections.Generic;
using AZCKeeper_Cliente.Security;
using Xunit;

public class SecurityControlsTests
{
    [Fact]
    public void Evaluate_MarcaAusenteLoQueNoEstaEnElRegistro()
    {
        var raw = new Dictionary<string, object>();

        var result = SecurityControls.Evaluate(raw);

        Assert.False(result["chrome.DownloadRestrictions"].Present);
        Assert.Null(result["chrome.DownloadRestrictions"].Value);
    }

    [Fact]
    public void Evaluate_MarcaPresenteYConservaElValor()
    {
        var raw = new Dictionary<string, object>
        {
            ["chrome.DownloadRestrictions"] = 3
        };

        var result = SecurityControls.Evaluate(raw);

        Assert.True(result["chrome.DownloadRestrictions"].Present);
        Assert.Equal(3, result["chrome.DownloadRestrictions"].Value);
    }

    [Fact]
    public void Evaluate_DevuelveTodosLosControlesDelCatalogo()
    {
        var result = SecurityControls.Evaluate(new Dictionary<string, object>());

        Assert.Equal(SecurityControls.All.Count, result.Count);
    }

    [Fact]
    public void All_NoTieneClavesDuplicadas()
    {
        var seen = new HashSet<string>();
        foreach (var c in SecurityControls.All)
        {
            Assert.True(seen.Add(c.Key), $"clave duplicada: {c.Key}");
        }
    }

    [Fact]
    public void All_IncluyeLosControlesDeListaQueMotivanLaFase1()
    {
        var claves = new HashSet<string>();
        foreach (var c in SecurityControls.All) claves.Add(c.Key);

        Assert.Contains("chrome.URLBlocklist", claves);
        Assert.Contains("chrome.ExtensionInstallBlocklist", claves);
        Assert.Contains("edge.URLBlocklist", claves);
        Assert.Contains("brave.URLBlocklist", claves);
    }

    [Fact]
    public void All_LosControlesDeListaSonSubclavesEnumeradas()
    {
        foreach (var c in SecurityControls.All)
        {
            bool esLista = c.Key.EndsWith("URLBlocklist") || c.Key.EndsWith("URLAllowlist")
                        || c.Key.EndsWith("ExtensionInstallBlocklist") || c.Key.EndsWith("ExtensionInstallAllowlist");
            Assert.Equal(esLista, c.IsEnumeratedSubkey);
            if (c.IsEnumeratedSubkey) Assert.Null(c.ValueName);
            else Assert.NotNull(c.ValueName);
        }
    }

    [Fact]
    public void All_CubreLosTresNavegadoresPorIgual()
    {
        var porNavegador = new Dictionary<string, int> { ["chrome."] = 0, ["edge."] = 0, ["brave."] = 0 };
        foreach (var c in SecurityControls.All)
            foreach (var p in new[] { "chrome.", "edge.", "brave." })
                if (c.Key.StartsWith(p)) porNavegador[p]++;

        Assert.Equal(porNavegador["chrome."], porNavegador["edge."]);
        Assert.Equal(porNavegador["chrome."], porNavegador["brave."]);
    }

    [Fact]
    public void Evaluate_ConservaUnValorDeLista()
    {
        var raw = new Dictionary<string, object>
        {
            ["chrome.URLBlocklist"] = new[] { "facebook.com", "x.com" }
        };

        var result = SecurityControls.Evaluate(raw);

        Assert.True(result["chrome.URLBlocklist"].Present);
        Assert.Equal(new[] { "facebook.com", "x.com" }, (string[])result["chrome.URLBlocklist"].Value);
    }
}
