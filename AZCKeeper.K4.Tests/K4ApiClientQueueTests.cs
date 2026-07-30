using System;
using System.IO;
using System.Net;
using System.Net.Http;
using System.Threading;
using System.Threading.Tasks;
using AZCKeeper.K4.Contracts;
using AZCKeeper.K4.Core;
using AZCKeeper.K4.Shell;
using Xunit;

namespace AZCKeeper.K4.Tests;

/// <summary>
/// Sin red, los datos no se pierden: un envío que falla se ENCOLA y DrainAsync lo reenvía
/// cuando vuelve la red. Y durante backoff no se abre socket, solo se encola.
/// </summary>
public class K4ApiClientQueueTests : IDisposable
{
    private readonly string _dir;

    public K4ApiClientQueueTests()
    {
        _dir = Path.Combine(Path.GetTempPath(), "k4apiq_" + Guid.NewGuid().ToString("N"));
    }

    public void Dispose()
    {
        try { Directory.Delete(_dir, recursive: true); } catch { }
    }

    /// <summary>Handler con estado: devuelve el status que le pongan; cuenta llamadas.</summary>
    private sealed class ToggleHandler : HttpMessageHandler
    {
        public int Status = 200;
        public int Calls;
        protected override Task<HttpResponseMessage> SendAsync(HttpRequestMessage req, CancellationToken ct)
        {
            Calls++;
            return Task.FromResult(new HttpResponseMessage((HttpStatusCode)Status)
            {
                Content = new StringContent("{\"ok\":true}")
            });
        }
    }

    private static EpisodeDto Ep() =>
        new("2026-07-30 10:00:00", "2026-07-30 10:03:00", 180, "app.exe", "t", false);

    [Fact]
    public async Task EnvioQueFalla_SeEncola()
    {
        var h = new ToggleHandler { Status = 503 };
        var q = new OfflineQueue(_dir);
        var api = new K4ApiClient("http://x/api", "g", new HttpClient(h), queue: q);

        var ok = await api.SendEpisodesAsync(new[] { Ep() });
        Assert.False(ok);
        Assert.Equal(1, q.PendingCount()); // no se perdió: quedó en la cola
    }

    [Fact]
    public async Task DrainAsync_ReenviaLoEncolado_YLoBorraAl200()
    {
        // Un 503 dispara backoff, que bloquea el drenado hasta pasar la ventana (correcto).
        // Reloj inyectado para avanzar el tiempo sin esperar 30s reales.
        var t = new DateTime(2026, 7, 30, 0, 0, 0, DateTimeKind.Utc);
        var backoff = new NetworkBackoffPolicy(() => t, () => 0.0);
        var h = new ToggleHandler { Status = 503 };
        var q = new OfflineQueue(_dir);
        var api = new K4ApiClient("http://x/api", "g", new HttpClient(h), backoff, q);

        await api.SendActivityDayAsync(new ActivityDayDto(
            "2026-07-30", -300, true, true, false, false, 100, 20, 0, 100, 20, null, null));
        Assert.Equal(1, q.PendingCount());

        h.Status = 200;            // "vuelve la red"
        t = t.AddSeconds(31);      // pasa la ventana de backoff
        await api.DrainAsync();
        Assert.Equal(0, q.PendingCount()); // reenviado y borrado
    }

    [Fact]
    public async Task DuranteBackoff_NoAbreSocket_SoloEncola()
    {
        // backoff ya activo: un fallo transitorio previo lo dispara.
        var backoff = new NetworkBackoffPolicy(() => DateTime.UtcNow, () => 0.0);
        backoff.Register(503, null); // fija ventana de backoff hacia adelante

        var h = new ToggleHandler { Status = 200 };
        var q = new OfflineQueue(_dir);
        var api = new K4ApiClient("http://x/api", "g", new HttpClient(h), backoff, q);

        var ok = await api.SendEpisodesAsync(new[] { Ep() });
        Assert.False(ok);
        Assert.Equal(0, h.Calls);        // NO abrió socket durante el backoff
        Assert.Equal(1, q.PendingCount()); // pero encoló para después
    }
}
