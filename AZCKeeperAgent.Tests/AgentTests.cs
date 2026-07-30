using System;
using System.Collections.Generic;
using AZCKeeperAgent.Core;
using Xunit;

namespace AZCKeeperAgent.Tests;

/// <summary>
/// La garantía anti-fallo-silencioso, hecha prueba. Un agente que corre pero no puede
/// escribir HKLM DEBE reportarlo, no fingir que aplicó. Estos tests fijan esa invariante
/// sin necesidad de correr elevado.
/// </summary>
public class AgentTests
{
    /// <summary>Registro que niega la escritura, como cuando el proceso no está elevado.</summary>
    private sealed class DeniedRegistry : IPrivilegedRegistry
    {
        public void WriteValue(string s, string n, object v) => throw new UnauthorizedAccessException();
        public object? ReadValue(string s, string n) => null;
        public void DeleteValue(string s, string n) { }
        public void WriteEnumeratedSubkey(string s, IReadOnlyList<string> v) => throw new UnauthorizedAccessException();
    }

    /// <summary>Registro en memoria que sí escribe, como cuando el proceso está elevado.</summary>
    private sealed class MemoryRegistry : IPrivilegedRegistry
    {
        public readonly Dictionary<string, object> Store = new();
        public HashSet<string>? FailCodes; // subkeys cuya escritura falla, para probar fallo parcial
        public void WriteValue(string s, string n, object v)
        {
            if (FailCodes?.Contains(s) == true) throw new InvalidOperationException("simulated");
            Store[s + "\\" + n] = v;
        }
        public object? ReadValue(string s, string n) => Store.TryGetValue(s + "\\" + n, out var v) ? v : null;
        public void DeleteValue(string s, string n) => Store.Remove(s + "\\" + n);
        public void WriteEnumeratedSubkey(string s, IReadOnlyList<string> v)
        {
            if (FailCodes?.Contains(s) == true) throw new InvalidOperationException("simulated");
            Store[s] = string.Join("|", v);
        }
    }

    private static DateTime FixedNow() => new(2026, 7, 30, 12, 0, 0, DateTimeKind.Utc);

    [Fact]
    public void SinPrivilegio_ReportaNoElevadoYNoAplica()
    {
        var report = new AgentCycle(new DeniedRegistry(), "4.0.0.0", FixedNow)
            .Run(new List<DesiredControl> { new("x", "Sub", "V", 1) });

        Assert.True(report.AgentPresent);       // el agente SÍ está (corriendo)
        Assert.False(report.Elevated);          // pero NO elevado
        Assert.False(report.CanEnforce);        // no puede hacer cumplir
        Assert.NotNull(report.SelfTestError);   // dice por qué
        Assert.Empty(report.AppliedControls);   // NO fingió aplicar nada
    }

    [Fact]
    public void ConPrivilegio_AplicaYReportaVerde()
    {
        var report = new AgentCycle(new MemoryRegistry(), "4.0.0.0", FixedNow)
            .Run(new List<DesiredControl> {
                new("chrome.DownloadRestrictions", "Google\\Chrome", "DownloadRestrictions", 3) });

        Assert.True(report.Elevated);
        Assert.True(report.CanEnforce);
        Assert.Contains("chrome.DownloadRestrictions", report.AppliedControls);
        Assert.Empty(report.FailedControls);
    }

    [Fact]
    public void UnControlQueFalla_NoAbortaLosDemas()
    {
        var reg = new MemoryRegistry { FailCodes = new HashSet<string> { "Bad\\Path" } };
        var report = new AgentCycle(reg, "4.0.0.0", FixedNow)
            .Run(new List<DesiredControl> {
                new("bueno", "Good\\Path", "V", 1),
                new("malo",  "Bad\\Path",  "V", 1) });

        Assert.Contains("bueno", report.AppliedControls);                 // el bueno se aplicó
        Assert.Contains(report.FailedControls, f => f.Code == "malo");    // el malo quedó marcado como fallo
        Assert.True(report.CanEnforce);                                    // el agente sí puede (el self-test pasó)
    }

    [Fact]
    public void SelfTest_LimpiaElCanario()
    {
        var reg = new MemoryRegistry();
        SelfTest.Run(reg, FixedNow);
        Assert.Null(reg.ReadValue(SelfTest.CanarySubkey, "probe")); // no deja rastro del canario
    }
}
