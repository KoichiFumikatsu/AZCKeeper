using System.Text.Json;

namespace AZCKeeper.K4.Shell;

/// <summary>Un envío pendiente en la cola offline. El Id es el nombre del archivo que lo respalda.</summary>
public sealed record QueueItem(string Id, string Endpoint, string PayloadJson, int RetryCount);

/// <summary>
/// Cola de reintento persistente para envíos que fallaron (sin red o en backoff). Un
/// archivo JSON por item bajo %APPDATA%\AZCKeeper4\Queue — así marcar como enviado es un
/// borrado atómico de archivo, sin reescribir un índice compartido. FIFO por prefijo de
/// tiempo en el nombre. Thread-safe con lock. Sin dependencias nativas (K4 quitó SQLite).
///
/// Un item se descarta como "dead letter" tras MaxRetries: un payload que el servidor
/// rechaza siempre no debe reintentarse para siempre ni bloquear a los que sí entran.
/// </summary>
public sealed class OfflineQueue
{
    public const int MaxRetries = 5;

    private readonly string _dir;
    private readonly object _lock = new();
    private static long _seq;

    private static readonly JsonSerializerOptions JsonOpts = new() { WriteIndented = false };

    public OfflineQueue(string? dir = null)
    {
        _dir = dir ?? K4Paths.QueueDir;
        Directory.CreateDirectory(_dir);
    }

    private sealed record Stored(string Endpoint, string PayloadJson, int RetryCount, string CreatedAtUtc);

    /// <summary>Encola un envío. El nombre lleva el tiempo para orden FIFO y una secuencia anti-colisión.</summary>
    public void Enqueue(string endpoint, string payloadJson)
    {
        lock (_lock)
        {
            var seq = Interlocked.Increment(ref _seq);
            var name = $"{DateTime.UtcNow.Ticks:D19}_{seq:D6}.json";
            var body = JsonSerializer.Serialize(
                new Stored(endpoint, payloadJson, 0, DateTime.UtcNow.ToString("O")), JsonOpts);
            WriteAtomic(System.IO.Path.Combine(_dir, name), body);
        }
    }

    /// <summary>Los N más viejos que aún se pueden reintentar (retryCount &lt; MaxRetries), en orden FIFO.</summary>
    public IReadOnlyList<QueueItem> Peek(int max = 50)
    {
        lock (_lock)
        {
            var items = new List<QueueItem>();
            foreach (var file in OrderedFiles())
            {
                if (items.Count >= max) break;
                var item = TryRead(file);
                if (item is null) continue;                 // corrupto: se ignora
                if (item.RetryCount >= MaxRetries) continue; // dead letter: no se ofrece
                items.Add(item);
            }
            return items;
        }
    }

    public void MarkSent(string id)
    {
        lock (_lock) { Delete(System.IO.Path.Combine(_dir, id)); }
    }

    public void MarkRetried(string id, string error)
    {
        lock (_lock)
        {
            var path = System.IO.Path.Combine(_dir, id);
            var item = TryRead(path);
            if (item is null) return;
            var body = JsonSerializer.Serialize(
                new Stored(item.Endpoint, item.PayloadJson, item.RetryCount + 1, DateTime.UtcNow.ToString("O")), JsonOpts);
            WriteAtomic(path, body);
        }
    }

    /// <summary>Borra los que agotaron reintentos. Devuelve cuántos se descartaron.</summary>
    public int CleanupDeadLetters()
    {
        lock (_lock)
        {
            int removed = 0;
            foreach (var file in OrderedFiles())
            {
                var item = TryRead(file);
                if (item is null) { Delete(file); removed++; continue; } // corrupto también se limpia
                if (item.RetryCount >= MaxRetries) { Delete(file); removed++; }
            }
            return removed;
        }
    }

    public int PendingCount()
    {
        lock (_lock)
        {
            int n = 0;
            foreach (var file in OrderedFiles())
            {
                var item = TryRead(file);
                if (item is not null && item.RetryCount < MaxRetries) n++;
            }
            return n;
        }
    }

    // --- interno ---

    private IEnumerable<string> OrderedFiles()
    {
        string[] files;
        try { files = Directory.GetFiles(_dir, "*.json"); }
        catch (DirectoryNotFoundException) { return Array.Empty<string>(); }
        Array.Sort(files, StringComparer.Ordinal); // el prefijo de ticks da orden FIFO
        return files;
    }

    private QueueItem? TryRead(string path)
    {
        try
        {
            if (!File.Exists(path)) return null;
            var s = JsonSerializer.Deserialize<Stored>(File.ReadAllText(path), JsonOpts);
            if (s is null) return null;
            return new QueueItem(System.IO.Path.GetFileName(path), s.Endpoint, s.PayloadJson, s.RetryCount);
        }
        catch (Exception ex) when (ex is JsonException or IOException)
        {
            return null;
        }
    }

    private static void WriteAtomic(string path, string body)
    {
        var tmp = path + ".tmp";
        File.WriteAllText(tmp, body);
        File.Move(tmp, path, overwrite: true);
    }

    private static void Delete(string path)
    {
        try { if (File.Exists(path)) File.Delete(path); } catch { /* best-effort */ }
    }
}
