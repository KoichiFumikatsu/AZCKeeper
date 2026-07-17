using System;
using System.Net.Http;
using System.Net.Sockets;
using AZCKeeper_Cliente.Network;
using Xunit;

namespace AZCKeeper.Tests
{
    /// <summary>
    /// Regresión del incidente 2026-07-16: un fallo de DNS intermitente en la sede
    /// ("No such host is known. (keep.azclegal.com:443)") escalaba por el mismo camino
    /// que un ban de firewall y silenciaba el equipo hasta 30 minutos — sin handshake,
    /// sin política y sin chequeo de update.
    /// </summary>
    public class NetworkBackoffPolicyTests
    {
        private static DateTime T0 => new DateTime(2026, 7, 16, 12, 0, 0, DateTimeKind.Utc);

        // rng fijo en 0 => sin jitter, backoff determinista.
        private static NetworkBackoffPolicy NewPolicy(Func<DateTime> now = null)
            => new NetworkBackoffPolicy(now ?? (() => T0), () => 0.0);

        // Reloj mutable: para simular fallos secuenciales REALES, que ocurren después de que
        // el backoff expira (SendViaBackoffAsync no reintenta mientras hay backoff activo).
        private sealed class Clock { public DateTime Now; public Clock(DateTime t) { Now = t; } }

        private static NetworkBackoffPolicy NewPolicy(Clock clock, double rng = 0.0)
            => new NetworkBackoffPolicy(() => clock.Now, () => rng);

        // Registra un fallo secuencial: avanza el reloj más allá del backoff vigente y falla.
        private static void FalloSecuencial(NetworkBackoffPolicy p, Clock c, int status, Exception ex)
        {
            if (c.Now < p.BackoffUntilUtc) c.Now = p.BackoffUntilUtc.AddSeconds(1);
            p.Register(status, ex);
        }

        private static Exception DnsException()
            => new HttpRequestException("No such host is known. (keep.azclegal.com:443)",
                                        new SocketException((int)SocketError.HostNotFound));

        private static Exception ResetException()
            => new HttpRequestException("connection reset",
                                        new SocketException((int)SocketError.ConnectionReset));

        // ---------- Clasificación ----------

        [Fact]
        public void Clasifica_dns_como_Dns()
        {
            Assert.Equal(NetworkFailureKind.Dns, NetworkBackoffPolicy.Classify(0, DnsException()));
        }

        [Fact]
        public void Clasifica_socket_exception_desnuda_como_Dns()
        {
            Assert.Equal(NetworkFailureKind.Dns,
                NetworkBackoffPolicy.Classify(0, new SocketException((int)SocketError.HostNotFound)));
        }

        [Fact]
        public void Clasifica_403_y_429_como_Throttled()
        {
            Assert.Equal(NetworkFailureKind.Throttled, NetworkBackoffPolicy.Classify(403, null));
            Assert.Equal(NetworkFailureKind.Throttled, NetworkBackoffPolicy.Classify(429, null));
        }

        [Fact]
        public void Clasifica_500_como_Transient()
        {
            Assert.Equal(NetworkFailureKind.Transient, NetworkBackoffPolicy.Classify(500, null));
        }

        [Fact]
        public void Clasifica_reset_de_conexion_como_Transient()
        {
            Assert.Equal(NetworkFailureKind.Transient, NetworkBackoffPolicy.Classify(0, ResetException()));
        }

        [Fact]
        public void Clasifica_200_y_401_como_Success()
        {
            Assert.Equal(NetworkFailureKind.Success, NetworkBackoffPolicy.Classify(200, null));
            Assert.Equal(NetworkFailureKind.Success, NetworkBackoffPolicy.Classify(401, null));
        }

        // ---------- El bug: DNS no puede costar 30 minutos ----------

        [Fact]
        public void Un_parpadeo_de_dns_no_silencia_el_equipo_30_minutos()
        {
            var policy = NewPolicy();

            // 10 fallos de DNS seguidos: el peor caso realista de un DNS intermitente.
            for (int i = 0; i < 10; i++)
                policy.Register(0, DnsException());

            var espera = policy.BackoffUntilUtc - T0;
            Assert.True(espera <= TimeSpan.FromSeconds(NetworkBackoffPolicy.DnsCapSeconds),
                $"DNS escaló a {espera.TotalSeconds:F0}s; el tope debe ser {NetworkBackoffPolicy.DnsCapSeconds}s.");
        }

        [Fact]
        public void Backoff_de_dns_arranca_corto()
        {
            var policy = NewPolicy();
            policy.Register(0, DnsException());

            Assert.Equal(TimeSpan.FromSeconds(NetworkBackoffPolicy.DnsBaseSeconds),
                         policy.BackoffUntilUtc - T0);
        }

        // ---------- Topes por clase ----------

        [Fact]
        public void Transient_topa_en_5_minutos()
        {
            var clock = new Clock(T0);
            var policy = NewPolicy(clock);
            for (int i = 0; i < 20; i++) FalloSecuencial(policy, clock, 500, null);

            Assert.Equal(TimeSpan.FromSeconds(300), policy.BackoffUntilUtc - clock.Now);
            Assert.Equal(300.0, NetworkBackoffPolicy.TransientCapSeconds);
        }

        [Fact]
        public void Throttled_conserva_el_tope_largo_de_30_minutos()
        {
            // 403/429 = el hosting nos está rate-limiteando o baneando: ahí esperar SÍ es correcto.
            var clock = new Clock(T0);
            var policy = NewPolicy(clock);
            for (int i = 0; i < 20; i++) FalloSecuencial(policy, clock, 429, null);

            Assert.Equal(TimeSpan.FromSeconds(1800), policy.BackoffUntilUtc - clock.Now);
        }

        [Fact]
        public void Transient_escala_exponencial_desde_30s()
        {
            var clock = new Clock(T0);
            var policy = NewPolicy(clock);

            FalloSecuencial(policy, clock, 500, null);
            Assert.Equal(TimeSpan.FromSeconds(30), policy.BackoffUntilUtc - clock.Now);

            FalloSecuencial(policy, clock, 500, null);
            Assert.Equal(TimeSpan.FromSeconds(60), policy.BackoffUntilUtc - clock.Now);

            FalloSecuencial(policy, clock, 500, null);
            Assert.Equal(TimeSpan.FromSeconds(120), policy.BackoffUntilUtc - clock.Now);
        }

        [Fact]
        public void Fallos_concurrentes_de_la_misma_caida_cuentan_una_sola_vez()
        {
            // Regresión 2026-07-17: handshake/activity-day/window-episode salían casi a la vez
            // y todos fallaban antes de que el primero fijara el backoff. Se veía "#1 #2 #3" en
            // el mismo segundo y el backoff escalaba 3× (140s) por UNA sola caída.
            var policy = NewPolicy();          // reloj fijo en T0: los tres fallan "a la vez"

            policy.Register(0, ResetException());   // #1 fija el backoff
            policy.Register(0, ResetException());   // straggler concurrente
            policy.Register(0, ResetException());   // straggler concurrente

            Assert.Equal(1, policy.ConsecutiveFailures);
            Assert.Equal(TimeSpan.FromSeconds(NetworkBackoffPolicy.TransientBaseSeconds),
                         policy.BackoffUntilUtc - T0);
        }

        [Fact]
        public void Un_fallo_real_posterior_si_escala()
        {
            // El anti-race no debe tragarse un fallo REAL posterior (tras expirar el backoff).
            var clock = new Clock(T0);
            var policy = NewPolicy(clock);

            policy.Register(500, null);                 // #1 → +30s
            policy.Register(500, null);                 // straggler dentro de la ventana → ignorado
            Assert.Equal(1, policy.ConsecutiveFailures);

            clock.Now = policy.BackoffUntilUtc.AddSeconds(1);  // backoff expiró, siguiente intento real
            policy.Register(500, null);                 // #2 real → +60s
            Assert.Equal(2, policy.ConsecutiveFailures);
        }

        // ---------- Reset ----------

        [Fact]
        public void Exito_resetea_el_backoff()
        {
            var policy = NewPolicy();
            for (int i = 0; i < 5; i++) policy.Register(500, null);
            Assert.True(policy.IsBackingOff);

            policy.Register(200, null);

            Assert.False(policy.IsBackingOff);
            Assert.Equal(0, policy.ConsecutiveFailures);
        }

        [Fact]
        public void Cambiar_de_clase_de_fallo_reinicia_el_contador()
        {
            // Un ban tras varios parpadeos de DNS no debe heredar el contador del DNS:
            // arranca su propia escalada.
            var policy = NewPolicy();
            for (int i = 0; i < 5; i++) policy.Register(0, DnsException());

            policy.Register(429, null);

            Assert.Equal(1, policy.ConsecutiveFailures);
            Assert.Equal(TimeSpan.FromSeconds(NetworkBackoffPolicy.ThrottledBaseSeconds),
                         policy.BackoffUntilUtc - T0);
        }

        [Fact]
        public void IsBackingOff_expira_con_el_tiempo()
        {
            var ahora = T0;
            var policy = NewPolicy(() => ahora);

            policy.Register(500, null); // 30s
            Assert.True(policy.IsBackingOff);

            ahora = T0.AddSeconds(31);
            Assert.False(policy.IsBackingOff);
        }

        [Fact]
        public void El_jitter_nunca_supera_el_tope_por_clase()
        {
            // rng en su máximo (1.0) => jitter máximo. Aun así no debe pasarse del tope.
            var policy = new NetworkBackoffPolicy(() => T0, () => 1.0);
            for (int i = 0; i < 20; i++) policy.Register(500, null);

            Assert.True(policy.BackoffUntilUtc - T0 <= TimeSpan.FromSeconds(300));
        }
    }
}
