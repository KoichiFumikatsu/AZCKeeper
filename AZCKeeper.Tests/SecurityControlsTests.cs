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
}
