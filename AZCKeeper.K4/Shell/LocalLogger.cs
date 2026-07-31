using System.Collections.Concurrent;
using System.Text;

namespace AZCKeeper.K4.Shell;

/// <summary>
/// Registro de un evento del cliente. Seq es un numero monotono creciente: el modo
/// diagnostico pide "lo nuevo desde el ultimo envio" con RecentSince(seq), asi no reenvia
/// lo mismo cada 4s.
/// </summary>
public sealed record LogEntry(long Seq, DateTime Ts, string Level, string Source, string Message);

/// <summary>
/// El modulo de logging del cliente K4, de vuelta. En K4 el "log" era Console.WriteLine, que
/// bajo WinExe (sin consola) se perdia entero. Este logger:
///
///  - mantiene un ANILLO en memoria (ultimas N entradas) SIEMPRE, sin importar si alguien mira;
///    es de ahi de donde el snapshot de diagnostico saca "los logs en vivo".
///  - escribe a un ARCHIVO diario en %LOCALAPPDATA%\AZCKeeper\Logs, best-effort, para que el
///    forense sobreviva aunque nadie este diagnosticando.
///  - recuerda el ULTIMO error por 'source' (modulo), para que el snapshot pueda mostrar
///    "windowTracking: real=OFF, ultimo error=...".
///
/// Es thread-safe: los modulos corren en timers concurrentes. El metodo Log(msg) legacy sigue
/// existiendo (mapea a Info) para no reescribir los call-sites que ya lo usan.
/// </summary>
public sealed class LocalLogger
{
    public const int DefaultCapacity = 500;

    private readonly int _capacity;
    private readonly string? _logDir;
    private readonly object _lock = new();
    private readonly LinkedList<LogEntry> _ring = new();
    private readonly ConcurrentDictionary<string, string> _lastErrorBySource = new();
    private long _seq;

    /// <param name="logDir">Carpeta de archivos; null usa K4Paths.LogsDir. Los tests pasan un temporal.</param>
    /// <param name="capacity">Tamano del anillo en memoria.</param>
    public LocalLogger(string? logDir = null, int capacity = DefaultCapacity)
    {
        _capacity = Math.Max(1, capacity);
        _logDir = logDir ?? SafeDefaultDir();
    }

    private static string? SafeDefaultDir()
    {
        try { return K4Paths.LogsDir; } catch { return null; }
    }

    // --- API de escritura ---

    public void Debug(string source, string message) => Write("Debug", source, message);
    public void Info(string source, string message)  => Write("Info", source, message);
    public void Warn(string source, string message)  => Write("Warn", source, message);
    public void Error(string source, string message) => Write("Error", source, message);

    /// <summary>Compat: los call-sites existentes hacen Log("texto"). Mapea a Info/source vacio.</summary>
    public void Log(string message) => Write("Info", "", message);

    private void Write(string level, string source, string message)
    {
        LogEntry entry;
        lock (_lock)
        {
            entry = new LogEntry(++_seq, DateTime.UtcNow, level, source, message);
            _ring.AddLast(entry);
            while (_ring.Count > _capacity) _ring.RemoveFirst();
        }
        if (level == "Error" && !string.IsNullOrEmpty(source))
            _lastErrorBySource[source] = message;
        AppendToFile(entry);
    }

    // --- API de lectura (para el snapshot de diagnostico) ---

    /// <summary>Entradas con Seq &gt; afterSeq, en orden. Vacio si no hay nada nuevo.</summary>
    public IReadOnlyList<LogEntry> RecentSince(long afterSeq)
    {
        lock (_lock)
        {
            var list = new List<LogEntry>();
            foreach (var e in _ring) if (e.Seq > afterSeq) list.Add(e);
            return list;
        }
    }

    /// <summary>El mayor Seq emitido hasta ahora (para que el llamador avance su cursor).</summary>
    public long CurrentSeq { get { lock (_lock) return _seq; } }

    /// <summary>Ultimo mensaje de error registrado para un 'source' (modulo), o null.</summary>
    public string? LastErrorFor(string source)
        => _lastErrorBySource.TryGetValue(source, out var m) ? m : null;

    // --- Archivo ---

    private void AppendToFile(LogEntry e)
    {
        if (_logDir is null) return;
        try
        {
            Directory.CreateDirectory(_logDir);
            var file = Path.Combine(_logDir, $"keeper_{e.Ts:yyyyMMdd}.log");
            var line = $"{e.Ts:yyyy-MM-dd HH:mm:ss} [{e.Level}] {e.Source}{(string.IsNullOrEmpty(e.Source) ? "" : ": ")}{e.Message}{Environment.NewLine}";
            File.AppendAllText(file, line, Encoding.UTF8);
        }
        catch { /* el logging nunca debe tumbar al cliente */ }
    }
}
