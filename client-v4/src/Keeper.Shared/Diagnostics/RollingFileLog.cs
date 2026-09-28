using System.Globalization;
using System.Text;

namespace Keeper.Shared.Diagnostics;

// Log de texto local, un archivo por dia UTC ({prefix}-yyyyMMdd.log). Existe para diagnosticar en el
// equipo (DWService) lo que no llega al servidor: arranque, fallos de sync y el auto-update, que
// detiene el servicio. Nunca lanza: un disco lleno o un ACL roto no deben tumbar al agente.
public sealed class RollingFileLog
{
    private readonly object _gate = new();
    private readonly string _directory;
    private readonly string _prefix;
    private readonly int _retentionDays;
    private readonly long _maxBytesPerDay;
    private readonly Func<DateTimeOffset> _clock;
    private string? _currentDay;
    private bool _truncated;

    public RollingFileLog(string directory, string prefix, int retentionDays = 14, long maxBytesPerDay = 20 * 1024 * 1024,
        Func<DateTimeOffset>? clock = null)
    {
        if (string.IsNullOrWhiteSpace(prefix) || prefix.IndexOfAny(Path.GetInvalidFileNameChars()) >= 0)
            throw new ArgumentException("invalid_log_prefix", nameof(prefix));
        _directory = Path.GetFullPath(directory);
        _prefix = prefix;
        _retentionDays = Math.Max(1, retentionDays);
        _maxBytesPerDay = Math.Max(4096, maxBytesPerDay);
        _clock = clock ?? (() => DateTimeOffset.UtcNow);
    }

    public string Directory => _directory;

    public string PathFor(DateTimeOffset utc) =>
        Path.Combine(_directory, $"{_prefix}-{utc.UtcDateTime.ToString("yyyyMMdd", CultureInfo.InvariantCulture)}.log");

    public void Write(string level, string category, string message)
    {
        try
        {
            var now = _clock();
            lock (_gate)
            {
                var day = now.UtcDateTime.ToString("yyyyMMdd", CultureInfo.InvariantCulture);
                if (day != _currentDay)
                {
                    System.IO.Directory.CreateDirectory(_directory);
                    _currentDay = day;
                    _truncated = false;
                    Prune(now);
                }
                if (_truncated) return;
                var path = PathFor(now);
                var line = Format(now, level, category, message);
                var length = File.Exists(path) ? new FileInfo(path).Length : 0;
                if (length + Encoding.UTF8.GetByteCount(line) > _maxBytesPerDay)
                {
                    _truncated = true;
                    line = Format(now, "WARN", "RollingFileLog", "log_truncated: limite diario alcanzado; se reanuda manana");
                }
                using var stream = new FileStream(path, FileMode.Append, FileAccess.Write, FileShare.ReadWrite | FileShare.Delete);
                var bytes = Encoding.UTF8.GetBytes(line);
                stream.Write(bytes, 0, bytes.Length);
            }
        }
        catch (Exception ex) when (ex is IOException or UnauthorizedAccessException or System.Security.SecurityException) { }
    }

    private static string Format(DateTimeOffset now, string level, string category, string message)
    {
        // Una entrada por linea: los saltos embebidos (stack traces) se indentan para que grep/Select-String
        // sigan separando entradas por fecha.
        var body = message.Replace("\r\n", "\n", StringComparison.Ordinal).Replace("\n", "\n    ", StringComparison.Ordinal);
        return $"{now.UtcDateTime.ToString("yyyy-MM-dd'T'HH:mm:ss.fff'Z'", CultureInfo.InvariantCulture)} {level,-5} [{category}] {body}{Environment.NewLine}";
    }

    private void Prune(DateTimeOffset now)
    {
        var cutoff = now.UtcDateTime.Date.AddDays(-(_retentionDays - 1));
        foreach (var file in System.IO.Directory.EnumerateFiles(_directory, _prefix + "-*.log"))
        {
            var stamp = Path.GetFileNameWithoutExtension(file)[(_prefix.Length + 1)..];
            if (stamp.Length == 8 && DateTime.TryParseExact(stamp, "yyyyMMdd", CultureInfo.InvariantCulture,
                    DateTimeStyles.AssumeUniversal | DateTimeStyles.AdjustToUniversal, out var date) && date < cutoff)
            {
                try { File.Delete(file); } catch (Exception ex) when (ex is IOException or UnauthorizedAccessException) { }
            }
        }
    }
}
