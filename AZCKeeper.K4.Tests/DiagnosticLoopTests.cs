using System;
using System.Threading.Tasks;
using AZCKeeper.K4.Core;
using Xunit;

namespace AZCKeeper.K4.Tests;

/// <summary>
/// La decision del loop de diagnostico: no envia si esta apagado, si vencio o si hay backoff;
/// respeta el intervalo; y solo avanza el cursor de logs cuando el envio fue exitoso.
/// </summary>
public class DiagnosticLoopTests
{
    private static DiagnosticResult BuildFrom(long cursor)
        => new DiagnosticResult(new { }, cursor + 5);   // simula 5 logs nuevos

    [Fact]
    public async Task No_envia_si_esta_apagado()
    {
        bool sent = false;
        var loop = new DiagnosticLoop(
            () => DiagnosticsFlag.Off, BuildFrom,
            _ => { sent = true; return Task.FromResult(true); },
            () => false, () => DateTime.UtcNow);

        Assert.False(await loop.TickAsync());
        Assert.False(sent);
    }

    [Fact]
    public async Task No_envia_en_backoff()
    {
        bool sent = false;
        var loop = new DiagnosticLoop(
            () => new DiagnosticsFlag(true, 4, null), BuildFrom,
            _ => { sent = true; return Task.FromResult(true); },
            () => true, () => DateTime.UtcNow);

        Assert.False(await loop.TickAsync());
        Assert.False(sent);
    }

    [Fact]
    public async Task No_envia_si_la_sesion_vencio()
    {
        var now = new DateTime(2026, 7, 31, 12, 0, 0, DateTimeKind.Utc);
        var loop = new DiagnosticLoop(
            () => new DiagnosticsFlag(true, 4, now.AddMinutes(-1)), BuildFrom,
            _ => Task.FromResult(true), () => false, () => now);

        Assert.False(await loop.TickAsync());
    }

    [Fact]
    public async Task Envia_cuando_esta_activo_y_respeta_el_intervalo()
    {
        var now = new DateTime(2026, 7, 31, 12, 0, 0, DateTimeKind.Utc);
        int sends = 0;
        var loop = new DiagnosticLoop(
            () => new DiagnosticsFlag(true, 4, now.AddHours(1)), BuildFrom,
            _ => { sends++; return Task.FromResult(true); },
            () => false, () => now);

        Assert.True(await loop.TickAsync());   // primer envio
        Assert.False(await loop.TickAsync());  // mismo instante: aun no pasa el intervalo
        Assert.Equal(1, sends);
    }
}
