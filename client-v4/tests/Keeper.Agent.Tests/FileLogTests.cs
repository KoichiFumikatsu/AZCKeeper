using Keeper.Agent.Hosting;
using Keeper.Shared.Diagnostics;
using Microsoft.Extensions.Logging;

namespace Keeper.Agent.Tests;

public sealed class FileLogTests
{
    private static readonly DateTimeOffset Day1 = new(2026, 9, 28, 23, 59, 0, TimeSpan.Zero);

    [Fact]
    public void EscribeUnaLineaPorEntradaConFechaUtcNivelYCategoria()
    {
        using var directory = new TestDirectory();
        var log = new RollingFileLog(directory.Root, "agent", clock: () => Day1);
        log.Write("INFO", "AgentWorker", "Agent 4.0.1 started");
        log.Write("WARN", "AgentWorker", "linea\r\ncon stack");
        var lines = File.ReadAllLines(Path.Combine(directory.Root, "agent-20260928.log"));
        Assert.Equal(3, lines.Length);
        Assert.Equal("2026-09-28T23:59:00.000Z INFO  [AgentWorker] Agent 4.0.1 started", lines[0]);
        Assert.StartsWith("2026-09-28T23:59:00.000Z WARN  [AgentWorker] linea", lines[1]);
        Assert.Equal("    con stack", lines[2]);
    }

    [Fact]
    public void RotaPorDiaUtcYPodaLosArchivosFueraDeLaRetencion()
    {
        using var directory = new TestDirectory();
        File.WriteAllText(directory.File("agent-20260901.log"), "viejo");
        File.WriteAllText(directory.File("agent-20260915.log"), "fuera por un dia");
        File.WriteAllText(directory.File("agent-20260916.log"), "limite de retencion");
        File.WriteAllText(directory.File("bootstrapper-20260901.log"), "otro prefijo");
        File.WriteAllText(directory.File("agent-notes.log"), "nombre ajeno");
        var now = Day1;
        var log = new RollingFileLog(directory.Root, "agent", retentionDays: 14, clock: () => now);
        log.Write("INFO", "x", "dia 1");
        now = Day1.AddMinutes(2);  // 2026-09-29 00:01Z
        log.Write("INFO", "x", "dia 2");
        Assert.True(File.Exists(directory.File("agent-20260928.log")));
        Assert.True(File.Exists(directory.File("agent-20260929.log")));
        Assert.False(File.Exists(directory.File("agent-20260901.log")));
        Assert.False(File.Exists(directory.File("agent-20260915.log")));
        Assert.True(File.Exists(directory.File("agent-20260916.log")));
        Assert.True(File.Exists(directory.File("bootstrapper-20260901.log")));
        Assert.True(File.Exists(directory.File("agent-notes.log")));
    }

    [Fact]
    public void AlLlegarAlLimiteDiarioDejaUnaMarcaYDejaDeEscribir()
    {
        using var directory = new TestDirectory();
        var log = new RollingFileLog(directory.Root, "agent", maxBytesPerDay: 4096, clock: () => Day1);
        for (var i = 0; i < 200; i++) log.Write("INFO", "x", new string('a', 100));
        var path = directory.File("agent-20260928.log");
        var lines = File.ReadAllLines(path);
        Assert.Single(lines, line => line.Contains("log_truncated", StringComparison.Ordinal));
        Assert.Contains("log_truncated", lines[^1], StringComparison.Ordinal);
        Assert.True(new FileInfo(path).Length <= 4096 + 200);
    }

    [Fact]
    public void NuncaLanzaSiElDirectorioNoSePuedeCrear()
    {
        using var directory = new TestDirectory();
        var blocker = directory.File("no-es-directorio");
        File.WriteAllText(blocker, "archivo");
        var log = new RollingFileLog(Path.Combine(blocker, "logs"), "agent", clock: () => Day1);
        log.Write("ERROR", "x", "no debe lanzar");
    }

    [Theory]
    [InlineData("sync_failed: HttpRequestException", true)]
    [InlineData("WebEnforcer: UnauthorizedAccessException", true)]
    [InlineData("UpdateManager warn: release_key_untrusted", true)]
    [InlineData("UpdateManager error: release_verification_failed", true)]
    [InlineData("UpdateManager info: package_verified", false)]
    [InlineData("sync_ok: 3 peticion(es); proximo en 120s", false)]
    [InlineData("Agent 4.0.1 started: trust sin claves", false)]
    public void ClasificaAdvertenciasDelAgente(string message, bool warning) =>
        Assert.Equal(warning, AgentLogLevel.IsWarning(message));

    [Fact]
    public void ElProveedorFiltraInformationDeMicrosoftPeroNoDelAgente()
    {
        using var directory = new TestDirectory();
        using var provider = new FileLoggerProvider(new RollingFileLog(directory.Root, "agent", clock: () => Day1));
        var framework = provider.CreateLogger("Microsoft.Hosting.Lifetime");
        var agent = provider.CreateLogger("AgentWorker");
        framework.LogInformation("Application started");
        framework.LogError(new InvalidOperationException("boom"), "BackgroundService failed");
        agent.LogInformation("sync_ok");
        agent.LogDebug("ruido");
        var text = File.ReadAllText(directory.File("agent-20260928.log"));
        Assert.DoesNotContain("Application started", text, StringComparison.Ordinal);
        Assert.Contains("ERROR [Lifetime] BackgroundService failed", text, StringComparison.Ordinal);
        Assert.Contains("System.InvalidOperationException: boom", text, StringComparison.Ordinal);
        Assert.Contains("INFO  [AgentWorker] sync_ok", text, StringComparison.Ordinal);
        Assert.DoesNotContain("ruido", text, StringComparison.Ordinal);
    }
}
