using System;
using System.IO;
using System.Linq;
using System.Threading.Tasks;
using AZCKeeper.K4.Shell;
using Xunit;

namespace AZCKeeper.K4.Tests;

/// <summary>
/// La cola offline no puede perder datos sin red ni reintentar para siempre un payload
/// que el servidor rechaza. Estos tests fijan FIFO, el borrado al enviar, la escalada de
/// reintentos y el corte por dead-letter.
/// </summary>
public class OfflineQueueTests : IDisposable
{
    private readonly string _dir;
    private readonly OfflineQueue _q;

    public OfflineQueueTests()
    {
        _dir = Path.Combine(Path.GetTempPath(), "k4q_" + Guid.NewGuid().ToString("N"));
        _q = new OfflineQueue(_dir);
    }

    public void Dispose()
    {
        try { Directory.Delete(_dir, recursive: true); } catch { }
    }

    [Fact]
    public void Enqueue_Peek_EsFifo()
    {
        _q.Enqueue("client/episodes/batch", "{\"n\":1}");
        _q.Enqueue("client/episodes/batch", "{\"n\":2}");
        _q.Enqueue("client/activity-day", "{\"n\":3}");

        var items = _q.Peek();
        Assert.Equal(3, items.Count);
        Assert.Equal("{\"n\":1}", items[0].PayloadJson); // el más viejo primero
        Assert.Equal("{\"n\":3}", items[2].PayloadJson);
    }

    [Fact]
    public void MarkSent_BorraElItem()
    {
        _q.Enqueue("e", "{\"n\":1}");
        var id = _q.Peek().Single().Id;
        _q.MarkSent(id);
        Assert.Equal(0, _q.PendingCount());
        Assert.Empty(_q.Peek());
    }

    [Fact]
    public void MarkRetried_IncrementaYEventualmenteEsDeadLetter()
    {
        _q.Enqueue("e", "{\"n\":1}");
        for (int i = 0; i < OfflineQueue.MaxRetries; i++)
        {
            var id = _q.Peek().Single().Id;   // sigue disponible mientras retryCount < Max
            _q.MarkRetried(id, "fallo " + i);
        }
        // tras MaxRetries ya no se ofrece (dead letter)
        Assert.Empty(_q.Peek());
        Assert.Equal(0, _q.PendingCount());
    }

    [Fact]
    public void CleanupDeadLetters_BorraLosAgotados()
    {
        _q.Enqueue("e", "{\"n\":1}");
        var id = _q.Peek().Single().Id;
        for (int i = 0; i < OfflineQueue.MaxRetries; i++) _q.MarkRetried(id, "x");

        var removed = _q.CleanupDeadLetters();
        Assert.Equal(1, removed);
        Assert.Empty(Directory.GetFiles(_dir, "*.json")); // no queda rastro
    }

    [Fact]
    public void ArchivoCorrupto_NoRevientaYSeLimpia()
    {
        File.WriteAllText(Path.Combine(_dir, "00000000000000000001_000001.json"), "no soy json {");
        Assert.Empty(_q.Peek());          // se ignora al leer
        Assert.Equal(1, _q.CleanupDeadLetters()); // y se limpia como basura
    }

    [Fact]
    public async Task EsThreadSafe_BajoEncoladoConcurrente()
    {
        var tasks = Enumerable.Range(0, 50).Select(i =>
            Task.Run(() => _q.Enqueue("e", "{\"n\":" + i + "}"))).ToArray();
        await Task.WhenAll(tasks);
        Assert.Equal(50, _q.PendingCount()); // ninguno se perdió ni colisionó
    }
}
