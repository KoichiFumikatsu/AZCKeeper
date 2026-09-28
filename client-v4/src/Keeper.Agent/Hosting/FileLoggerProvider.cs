using Keeper.Shared.Diagnostics;
using Microsoft.Extensions.Logging;

namespace Keeper.Agent.Hosting;

// Puente ILogger -> RollingFileLog. Information o superior; el ruido de Microsoft.* queda en Warning.
public sealed class FileLoggerProvider(RollingFileLog log) : ILoggerProvider
{
    public ILogger CreateLogger(string categoryName) => new FileLogger(log, categoryName);
    public void Dispose() { }

    private sealed class FileLogger(RollingFileLog log, string category) : ILogger
    {
        private readonly string _short = category.Contains('.') ? category[(category.LastIndexOf('.') + 1)..] : category;
        private readonly LogLevel _minimum = category.StartsWith("Microsoft.", StringComparison.Ordinal) ? LogLevel.Warning : LogLevel.Information;

        public IDisposable? BeginScope<TState>(TState state) where TState : notnull => null;
        public bool IsEnabled(LogLevel logLevel) => logLevel != LogLevel.None && logLevel >= _minimum;

        public void Log<TState>(LogLevel logLevel, EventId eventId, TState state, Exception? exception, Func<TState, Exception?, string> formatter)
        {
            if (!IsEnabled(logLevel)) return;
            var message = formatter(state, exception);
            if (exception is not null) message += Environment.NewLine + exception;
            log.Write(Level(logLevel), _short, message);
        }

        private static string Level(LogLevel level) => level switch
        {
            LogLevel.Trace => "TRACE", LogLevel.Debug => "DEBUG", LogLevel.Information => "INFO",
            LogLevel.Warning => "WARN", LogLevel.Error => "ERROR", _ => "CRIT"
        };
    }
}

// Los mensajes del agente viajan como Action<string> (contrato de ModuleContext). Esta regla decide
// cuales son advertencias: fallos de sync, excepciones capturadas por ModuleHost y reportes Warn/Error.
public static class AgentLogLevel
{
    public static bool IsWarning(string message) =>
        message.StartsWith("sync_failed", StringComparison.Ordinal) ||
        message.EndsWith("Exception", StringComparison.Ordinal) ||
        message.Contains(" warn: ", StringComparison.Ordinal) ||
        message.Contains(" error: ", StringComparison.Ordinal);
}
