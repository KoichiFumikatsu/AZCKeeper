using System;
using System.IO;
using System.Net;
using System.Net.Http;
using System.Threading;
using System.Threading.Tasks;
using AZCKeeper.K4.Core;
using AZCKeeper.K4.Shell;
using Xunit;

namespace AZCKeeper.K4.Tests;

/// <summary>
/// El courier lado cliente lee el reporte del agente elevado y lo entrega para reenviar.
/// Nunca debe caerse porque el agente no esté o su archivo esté dañado/viejo.
/// </summary>
public class AgentReportReaderTests : IDisposable
{
    private readonly string _dir;
    private readonly string _file;

    public AgentReportReaderTests()
    {
        _dir = Path.Combine(Path.GetTempPath(), "k4agent_" + Guid.NewGuid().ToString("N"));
        Directory.CreateDirectory(_dir);
        _file = Path.Combine(_dir, "agent-report.json");
    }

    public void Dispose() { try { Directory.Delete(_dir, true); } catch { } }

    private static DateTime Now() => new(2026, 7, 30, 12, 0, 0, DateTimeKind.Utc);

    [Fact]
    public void SinArchivo_PresentFalse()
    {
        var r = new AgentReportReader(_file, nowUtc: Now);
        var s = r.Read();
        Assert.False(s.Present);
        Assert.Null(s.Enforcement);
    }

    [Fact]
    public void ArchivoFresco_PresentTrue_NoStale_TraeElBloque()
    {
        File.WriteAllText(_file,
            "{\"elevated\":true,\"canEnforce\":true,\"reportedAt\":\"2026-07-30T11:55:00Z\",\"applied\":[\"chrome.DownloadRestrictions\"],\"failed\":[]}");
        var s = new AgentReportReader(_file, nowUtc: Now).Read();

        Assert.True(s.Present);
        Assert.False(s.Stale); // 5 min de antigüedad < 15
        Assert.NotNull(s.Enforcement);
        Assert.True(s.Enforcement!["elevated"]!.GetValue<bool>());
    }

    [Fact]
    public void ArchivoViejo_MarcaStale()
    {
        File.WriteAllText(_file,
            "{\"elevated\":true,\"canEnforce\":true,\"reportedAt\":\"2026-07-30T11:00:00Z\"}"); // 1h atrás
        var s = new AgentReportReader(_file, nowUtc: Now).Read();
        Assert.True(s.Present);
        Assert.True(s.Stale); // > 15 min
    }

    [Fact]
    public void SinReportedAt_SeTrataComoStale()
    {
        File.WriteAllText(_file, "{\"elevated\":false,\"canEnforce\":false}");
        var s = new AgentReportReader(_file, nowUtc: Now).Read();
        Assert.True(s.Present);
        Assert.True(s.Stale); // sin timestamp válido = sospechoso
    }

    [Fact]
    public void JsonCorrupto_PresentFalse_NoRevienta()
    {
        File.WriteAllText(_file, "no soy json {");
        var s = new AgentReportReader(_file, nowUtc: Now).Read();
        Assert.False(s.Present);
    }
}

/// <summary>El ciclo del core reenvía el estado del agente a client/security/report.</summary>
public class CoreSecurityReportTests
{
    private sealed class CapturingHandler : HttpMessageHandler
    {
        public string? SecurityBody;
        protected override async Task<HttpResponseMessage> SendAsync(HttpRequestMessage req, CancellationToken ct)
        {
            var path = req.RequestUri!.AbsolutePath;
            if (path.Contains("security/report"))
                SecurityBody = await req.Content!.ReadAsStringAsync(ct);

            var json = path.Contains("handshake")
                ? "{\"ok\":true,\"effectiveConfig\":{\"modules\":{}}}"
                : "{\"ok\":true}";
            return new HttpResponseMessage(HttpStatusCode.OK) { Content = new StringContent(json) };
        }
    }

    [Fact]
    public async Task ConAgentePresente_ReenviaElBloqueDeEnforcement()
    {
        var dir = Path.Combine(Path.GetTempPath(), "k4csr_" + Guid.NewGuid().ToString("N"));
        Directory.CreateDirectory(dir);
        var file = Path.Combine(dir, "agent-report.json");
        File.WriteAllText(file,
            "{\"elevated\":true,\"canEnforce\":true,\"reportedAt\":\"2026-07-30T11:59:00Z\",\"applied\":[],\"failed\":[]}");

        var h = new CapturingHandler();
        var api = new K4ApiClient("http://x/api", "g", new HttpClient(h));
        api.RestoreToken("t");
        var reader = new AgentReportReader(file, nowUtc: () => new DateTime(2026, 7, 30, 12, 0, 0, DateTimeKind.Utc));
        var core = new CoreService(api, new ModuleHost(), "K4TEST", "eq", "4.0.0.0", agentReader: reader);

        var ok = await core.RunOnceAsync();

        Assert.True(ok);
        Assert.NotNull(h.SecurityBody);
        Assert.Contains("\"agentPresent\":true", h.SecurityBody);
        Assert.Contains("\"agentEnforcement\"", h.SecurityBody);
        Assert.Contains("\"canEnforce\":true", h.SecurityBody);

        try { Directory.Delete(dir, true); } catch { }
    }

    [Fact]
    public async Task SinAgente_ReportaAgentPresentFalse()
    {
        var h = new CapturingHandler();
        var api = new K4ApiClient("http://x/api", "g", new HttpClient(h));
        api.RestoreToken("t");
        // reader apunta a un archivo inexistente
        var reader = new AgentReportReader(Path.Combine(Path.GetTempPath(), "no_existe_" + Guid.NewGuid().ToString("N") + ".json"));
        var core = new CoreService(api, new ModuleHost(), "K4TEST", "eq", "4.0.0.0", agentReader: reader);

        await core.RunOnceAsync();

        Assert.Contains("\"agentPresent\":false", h.SecurityBody);
        Assert.Contains("\"agentEnforcement\":null", h.SecurityBody);
    }
}
