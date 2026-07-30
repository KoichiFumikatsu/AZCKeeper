using System;
using System.Threading.Tasks;
using AZCKeeper.K4.Shell;
using Xunit;

namespace AZCKeeper.K4.Tests;

/// <summary>
/// La lógica de cadencia/backoff/flush del cliente residente, sin red. Se prueba con
/// TickAsync (decisión pura) y relojes/flags controlados.
/// </summary>
public class ResidentHostTests
{
    [Fact]
    public async Task NoCorreMientrasHayBackoff()
    {
        int runs = 0;
        var now = new DateTime(2026, 7, 30, 0, 0, 0, DateTimeKind.Utc);
        var host = new ResidentHost(
            runCycle: () => { runs++; return Task.FromResult(true); },
            isBackingOff: () => true, // siempre en backoff
            flushAndStop: () => Task.CompletedTask,
            interval: TimeSpan.FromSeconds(300),
            nowUtc: () => now);

        var ran = await host.TickAsync();
        Assert.False(ran);
        Assert.Equal(0, runs); // no abrió ciclo durante el backoff
    }

    [Fact]
    public async Task CorreYReprogramaAlIntervalo_TrasExito()
    {
        int runs = 0;
        var t = new DateTime(2026, 7, 30, 0, 0, 0, DateTimeKind.Utc);
        DateTime now() => t;
        var host = new ResidentHost(
            runCycle: () => { runs++; return Task.FromResult(true); },
            isBackingOff: () => false,
            flushAndStop: () => Task.CompletedTask,
            interval: TimeSpan.FromSeconds(300),
            retryInterval: TimeSpan.FromSeconds(30),
            nowUtc: now);

        Assert.True(await host.TickAsync());  // primer tick corre (nextRun = MinValue)
        Assert.Equal(1, runs);
        Assert.False(await host.TickAsync()); // mismo instante: aún no toca (reprogramó a +300s)
        Assert.Equal(1, runs);
    }

    [Fact]
    public async Task TrasFallo_ReprogramaAlRetryCorto()
    {
        int runs = 0;
        var t = new DateTime(2026, 7, 30, 0, 0, 0, DateTimeKind.Utc);
        DateTime now() => t;
        var host = new ResidentHost(
            runCycle: () => { runs++; return Task.FromResult(false); }, // falla
            isBackingOff: () => false,
            flushAndStop: () => Task.CompletedTask,
            interval: TimeSpan.FromSeconds(300),
            retryInterval: TimeSpan.FromSeconds(30),
            nowUtc: now);

        Assert.True(await host.TickAsync());
        Assert.Equal(1, runs);
        // a los 30s ya toca de nuevo (retry corto), no a los 300
        t = t.AddSeconds(31);
        Assert.True(await host.TickAsync());
        Assert.Equal(2, runs);
    }

    [Fact]
    public async Task Flush_OcurreUnaSolaVez()
    {
        int flushes = 0;
        var host = new ResidentHost(
            runCycle: () => Task.FromResult(true),
            isBackingOff: () => false,
            flushAndStop: () => { flushes++; return Task.CompletedTask; },
            interval: TimeSpan.FromSeconds(300));

        await host.FlushAndStopAsync();
        await host.FlushAndStopAsync(); // segunda vía de cierre
        await host.FlushAndStopAsync();
        Assert.Equal(1, flushes); // el guard de una-sola-vez sostiene
    }

    [Fact]
    public async Task UnCicloQueLanza_NoTumbaElHost()
    {
        var host = new ResidentHost(
            runCycle: () => throw new InvalidOperationException("boom"),
            isBackingOff: () => false,
            flushAndStop: () => Task.CompletedTask,
            interval: TimeSpan.FromSeconds(300));

        var ran = await host.TickAsync(); // no debe propagar la excepción
        Assert.True(ran); // corrió (y falló internamente), pero TickAsync no lanzó
    }
}
