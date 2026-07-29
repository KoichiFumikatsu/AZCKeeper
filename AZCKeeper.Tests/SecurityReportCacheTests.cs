using System;
using System.Collections.Generic;
using AZCKeeper_Cliente.Contracts;
using AZCKeeper_Cliente.Security;
using Xunit;

public class SecurityReportCacheTests
{
    private static Dictionary<string, SecurityControlState> Estado(int valor) =>
        new Dictionary<string, SecurityControlState>
        {
            ["chrome.DownloadRestrictions"] = new SecurityControlState { Present = true, Value = valor }
        };

    [Fact]
    public void ComputeHash_EsEstableParaElMismoEstado()
    {
        var c = new SecurityReportCache(null);
        Assert.Equal(c.ComputeHash(Estado(3)), c.ComputeHash(Estado(3)));
    }

    [Fact]
    public void ComputeHash_CambiaSiCambiaUnValor()
    {
        var c = new SecurityReportCache(null);
        Assert.NotEqual(c.ComputeHash(Estado(3)), c.ComputeHash(Estado(4)));
    }

    [Fact]
    public void ShouldSend_EsTrueLaPrimeraVez()
    {
        var c = new SecurityReportCache(null);
        Assert.True(c.ShouldSend("abc", DateTime.UtcNow));
    }

    [Fact]
    public void ShouldSend_EsFalseSiElHashNoCambioYNoVencioElLatido()
    {
        var c = new SecurityReportCache(null);
        var t0 = new DateTime(2026, 7, 29, 8, 0, 0, DateTimeKind.Utc);
        c.MarkSent("abc", t0);
        Assert.False(c.ShouldSend("abc", t0.AddHours(3)));
    }

    [Fact]
    public void ShouldSend_EsTrueSiElHashCambio()
    {
        var c = new SecurityReportCache(null);
        var t0 = new DateTime(2026, 7, 29, 8, 0, 0, DateTimeKind.Utc);
        c.MarkSent("abc", t0);
        Assert.True(c.ShouldSend("xyz", t0.AddMinutes(5)));
    }

    [Fact]
    public void ShouldSend_EsTrueTrasVencerElLatidoAunqueNoCambie()
    {
        var c = new SecurityReportCache(null);
        var t0 = new DateTime(2026, 7, 29, 8, 0, 0, DateTimeKind.Utc);
        c.MarkSent("abc", t0);
        Assert.True(c.ShouldSend("abc", t0.AddHours(25)));
    }
}
