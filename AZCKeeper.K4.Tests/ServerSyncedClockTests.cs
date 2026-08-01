using System;
using AZCKeeper.K4.Platform;
using Xunit;

namespace AZCKeeper.K4.Tests;

/// <summary>El reloj sincronizado corrige con el offset del servidor, ignora ajustes triviales.</summary>
public class ServerSyncedClockTests
{
    [Fact]
    public void Aplica_offset_significativo()
    {
        var c = new ServerSyncedClock();
        var future = DateTime.UtcNow.AddMinutes(10);  // el server va 10 min adelante
        c.SyncTo(future);
        // Ahora UtcNow debe estar ~10 min adelante del reloj real.
        Assert.True((c.UtcNow - DateTime.UtcNow).TotalMinutes is > 9 and < 11);
    }

    [Fact]
    public void Ignora_desfase_trivial()
    {
        var c = new ServerSyncedClock();
        c.SyncTo(DateTime.UtcNow.AddSeconds(1));   // < 2s: no ajusta
        Assert.True(Math.Abs((c.UtcNow - DateTime.UtcNow).TotalSeconds) < 2);
    }
}
