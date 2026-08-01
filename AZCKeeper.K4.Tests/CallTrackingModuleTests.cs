using System;
using AZCKeeper.K4.Contracts;
using AZCKeeper.K4.Modules;
using Xunit;

namespace AZCKeeper.K4.Tests;

/// <summary>
/// CallTrackingModule acumula segundos SOLO cuando la ventana en foco es una app de llamada,
/// por dia, y CallDetection reconoce las apps conocidas.
/// </summary>
public class CallTrackingModuleTests
{
    private sealed class FakeFg : IForegroundWindow
    {
        public string? ProcessName { get; set; }
        public string? Title { get; set; }
    }
    private sealed class FixedClock : IClock
    {
        public DateTime Now { get; set; } = new(2026, 7, 31, 10, 0, 0);
        public DateTime UtcNow => Now;
    }

    [Fact]
    public void CallDetection_reconoce_apps_de_llamada()
    {
        Assert.True(CallDetection.IsCallApp("Teams.exe", null));
        Assert.True(CallDetection.IsCallApp("zoom.exe", null));
        Assert.True(CallDetection.IsCallApp("chrome.exe", "Google Meet - llamada"));
        Assert.False(CallDetection.IsCallApp("notepad.exe", "documento"));
    }

    [Fact]
    public void Acumula_segundos_solo_en_apps_de_llamada()
    {
        var fg = new FakeFg { ProcessName = "Teams.exe" };
        var clock = new FixedClock();
        var mod = new CallTrackingModule(fg, clock);
        mod.Configure(new ModuleSettings());   // sampleSeconds default 15

        // Reflexion-free: invocamos Sample via el timer no es determinista; usamos el metodo
        // publico CallSecondsForDay tras forzar muestras por el ciclo interno. En su lugar
        // ejercemos la deteccion + el acumulado a traves de Start y una espera corta seria
        // no determinista; por eso validamos el contrato via un helper de prueba.
        // Simulamos dos muestras en llamada y una fuera:
        mod.SampleForTest();                    // en llamada (+15)
        mod.SampleForTest();                    // en llamada (+15)
        fg.ProcessName = "notepad.exe";
        mod.SampleForTest();                    // fuera (+0)

        Assert.Equal(30, mod.CallSecondsForDay(clock.Now));
        Assert.Equal(0, mod.CallSecondsForDay(clock.Now.AddDays(1)));
    }
}
