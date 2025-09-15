using System;
using System.Collections.Concurrent;
using System.IO;
using System.Net.Http;
using System.Text;
using System.Threading.Tasks;
using Newtonsoft.Json;

namespace ModularApp.Core
{
    public enum LogLevel { Debug = 0, Info = 1, Warn = 2, Error = 3 }

    public interface ILogger : IDisposable
    {
        void Debug(string msg);
        void Info(string msg);
        void Warn(string msg);
        void Error(string msg);
        void SafeFallbackLog(Exception ex);
    }

    public static class LoggerFactory
    {
        public static CompositeLogger CreateFromConfig(AppConfig cfg)
        {
            var comp = new CompositeLogger(cfg.Logging.Level, cfg); // pasa cfg
            comp.AddSink(new FileLogSink(cfg.Logging.Path));
            if (cfg.Logging.EnableDiscord && !string.IsNullOrWhiteSpace(cfg.Logging.DiscordWebhook))
                comp.AddSink(new DiscordLogSink(cfg.Logging.DiscordWebhook));
            comp.AddSink(MemoryLogSink.Instance);
            return comp;
        }
    }

    public sealed class CompositeLogger : ILogger
    {
        private readonly LogLevel _min;
        private readonly AppConfig _cfg;
        private readonly ConcurrentBag<ILogSink> _sinks = new ConcurrentBag<ILogSink>();

        public CompositeLogger(LogLevel min, AppConfig cfg) { _min = min; _cfg = cfg; }
        public void AddSink(ILogSink sink) { _sinks.Add(sink); }

        public void Debug(string msg) { if (_min <= LogLevel.Debug) Write("DEBUG", msg); }
        public void Info(string msg) { if (_min <= LogLevel.Info) Write("INFO ", msg); }
        public void Warn(string msg) { if (_min <= LogLevel.Warn) Write("WARN ", msg); }
        public void Error(string msg) { if (_min <= LogLevel.Error) Write("ERROR", msg); }

        private void Write(string level, string msg)
        {
            var ts = DateTime.Now.ToString("yyyy-MM-dd HH:mm:ss.fff");
            var tag = LogContext.CurrentTag(_cfg);               // <<< añade tag de usuario
            var line = $"{ts} [{level}] [{tag}] {msg}";
            foreach (var s in _sinks) s.Write(line);
        }

        public void SafeFallbackLog(Exception ex)
        {
            try
            {
                var dir = Path.Combine(AppContext.BaseDirectory, "logs");
                Directory.CreateDirectory(dir);
                File.AppendAllText(Path.Combine(dir, "fatal.log"),
                    DateTime.Now.ToString("o") + " " + ex + Environment.NewLine);
            }
            catch { }
        }

        public void Dispose()
        {
            foreach (var s in _sinks)
                (s as IDisposable)?.Dispose();
        }
    }

    public interface ILogSink { void Write(string line); }

    public sealed class FileLogSink : ILogSink
    {
        private readonly string _path;
        private readonly object _lock = new object();
        public FileLogSink(string path)
        {
            _path = Path.GetFullPath(path);
            Directory.CreateDirectory(Path.GetDirectoryName(_path));
        }
        public void Write(string line)
        {
            lock (_lock) File.AppendAllText(_path, line + Environment.NewLine, Encoding.UTF8);
        }
    }

    public sealed class DiscordLogSink : ILogSink, IDisposable
    {
        private static readonly HttpClient _http = new HttpClient();
        private readonly string _webhook;
        public DiscordLogSink(string webhook) { _webhook = webhook; }

        public async void Write(string line)
        {
            try
            {
                var payload = new { content = "`AZC_Keeper` " + line };
                string json = JsonConvert.SerializeObject(payload);
                using var content = new StringContent(json, Encoding.UTF8, "application/json");
                await _http.PostAsync(_webhook, content);
            }
            catch { }
        }

        public void Dispose() { }
    }

    public sealed class MemoryLogSink : ILogSink
    {
        public static readonly MemoryLogSink Instance = new MemoryLogSink();
        private readonly ConcurrentQueue<string> _buffer = new ConcurrentQueue<string>();
        public void Write(string line)
        {
            _buffer.Enqueue(line);
            while (_buffer.Count > 500)
                _buffer.TryDequeue(out _);
        }
        public string[] Snapshot() => _buffer.ToArray();
    }
}
