using System;
using System.Net.Sockets;
using AZCKeeper.K4.Shell;
using Xunit;

namespace AZCKeeper.K4.Tests;

/// <summary>
/// Fija el comportamiento clave del backoff: clasificar bien por clase, escalar de forma
/// acotada, y separar DNS (segundos) de throttling (minutos) — la razón de ser del port.
/// Reloj y rng inyectados para que sea determinista.
/// </summary>
public class NetworkBackoffPolicyTests
{
    private static NetworkBackoffPolicy Fixed(DateTime[] clock, double rng = 0.0)
    {
        int i = 0;
        return new NetworkBackoffPolicy(() => clock[Math.Min(i++, clock.Length - 1)], () => rng);
    }

    [Fact]
    public void Clasifica_429_ComoThrottled_y_503_ComoTransient()
    {
        Assert.Equal(NetworkFailureKind.Throttled, NetworkBackoffPolicy.Classify(429, null));
        Assert.Equal(NetworkFailureKind.Throttled, NetworkBackoffPolicy.Classify(403, null));
        Assert.Equal(NetworkFailureKind.Transient, NetworkBackoffPolicy.Classify(503, null));
        Assert.Equal(NetworkFailureKind.Success, NetworkBackoffPolicy.Classify(200, null));
        Assert.Equal(NetworkFailureKind.Success, NetworkBackoffPolicy.Classify(401, null)); // no es fallo de red
    }

    [Fact]
    public void FalloDeDns_SeClasificaComoDns()
    {
        var dns = new SocketException((int)SocketError.HostNotFound);
        Assert.Equal(NetworkFailureKind.Dns, NetworkBackoffPolicy.Classify(0, dns));
        // envuelto en otra excepción también
        var wrapped = new Exception("outer", dns);
        Assert.Equal(NetworkFailureKind.Dns, NetworkBackoffPolicy.Classify(0, wrapped));
    }

    [Fact]
    public void Transient_Escala_YRespetaElTope()
    {
        var t0 = new DateTime(2026, 7, 30, 0, 0, 0, DateTimeKind.Utc);
        // El reloj debe avanzar MÁS ALLÁ de la ventana (30s) antes del 2º fallo, si no el
        // 2º Register cae dentro del backoff y se ignora como straggler. Register#2 lee el
        // reloj dos veces (check + set), por eso dos entradas t0+2min.
        var clock = new[] { t0, t0.AddMinutes(2), t0.AddMinutes(2) };
        var p = Fixed(clock, rng: 0.0); // sin jitter

        p.Register(503, null); // fallo 1 -> ventana hasta t0+30s
        p.Register(503, null); // fallo 2 (ya pasada la ventana) -> escala a 2
        Assert.Equal(2, p.ConsecutiveFailures);
        Assert.Equal(NetworkFailureKind.Transient, p.LastKind);
    }

    [Fact]
    public void CambioDeClase_ReiniciaLaEscalada()
    {
        var t0 = new DateTime(2026, 7, 30, 0, 0, 0, DateTimeKind.Utc);
        var clock = new[] { t0, t0.AddHours(1), t0.AddHours(2), t0.AddHours(3), t0.AddHours(4) };
        var p = Fixed(clock);

        p.Register(503, null);                 // Transient, fail=1
        p.Register(503, null);                 // Transient, fail=2
        var kind = p.Register(429, null);      // cambia a Throttled -> reinicia contador a 1
        Assert.Equal(NetworkFailureKind.Throttled, kind);
        Assert.Equal(1, p.ConsecutiveFailures);
    }

    [Fact]
    public void Exito_ReseteaTodo()
    {
        var t0 = new DateTime(2026, 7, 30, 0, 0, 0, DateTimeKind.Utc);
        var p = Fixed(new[] { t0, t0.AddHours(1), t0.AddHours(2) });
        p.Register(503, null);
        p.Register(200, null); // éxito
        Assert.Equal(0, p.ConsecutiveFailures);
        Assert.False(p.IsBackingOff);
        Assert.Equal(NetworkFailureKind.Success, p.LastKind);
    }

    [Fact]
    public void StragglerDeLaMismaVentana_NoInflaElContador()
    {
        var t0 = new DateTime(2026, 7, 30, 0, 0, 0, DateTimeKind.Utc);
        // ambos Register ocurren en t0 (misma ventana), el segundo cae DENTRO del backoff fijado
        var p = new NetworkBackoffPolicy(() => t0, () => 0.0);
        p.Register(503, null); // fija backoff hasta t0+30s
        p.Register(503, null); // straggler concurrente: mismo instante, misma clase -> se ignora
        Assert.Equal(1, p.ConsecutiveFailures); // NO subió a 2
    }
}
