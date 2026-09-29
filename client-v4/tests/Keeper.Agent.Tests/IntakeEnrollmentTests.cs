using System.Net;
using System.Security.Cryptography;
using System.Text;
using System.Text.Json;
using Keeper.Agent.Storage;
using Keeper.Agent.Transport;

namespace Keeper.Agent.Tests;

public sealed class IntakeEnrollmentTests
{
    [Fact]
    public async Task PendingThenApprovedLogsInWithTicketAndPersistsServerDeviceId()
    {
        using var dir = new TestDirectory();
        var device = Guid.NewGuid();
        var handler = new IntakeHandler(device, ["pending", "pending", "approved"]);
        using var http = new HttpClient(handler) { BaseAddress = new Uri("https://keeper.test/v1/") };
        using var signer = new HttpMessageSigner(ECDsa.Create(ECCurve.NamedCurves.nistP256));
        var waits = new List<TimeSpan>(); var logs = new List<string>();
        var tokens = new MemoryTokens();
        var path = Path.Combine(dir.Root, "device-id.txt");
        var result = await new IntakeEnrollment(http, signer, "kek_company-secret-0123456789", TimeProvider.System, logs.Add, () => "SN-1",
            (wait, _) => { waits.Add(wait); return Task.CompletedTask; }).RunAsync(tokens, path, default);
        Assert.Equal(device, result);
        Assert.Equal(device, IntakeEnrollment.ReadDeviceId(path));
        Assert.Equal(device, tokens.Saved!.Token.DeviceId);
        Assert.Equal([TimeSpan.FromSeconds(300), TimeSpan.FromSeconds(300)], waits);
        Assert.Single(logs, l => l.Contains("esperando", StringComparison.Ordinal));
        var requests = handler.Requests.Where(r => r.Path.EndsWith("enrollment-requests", StringComparison.Ordinal)).ToList();
        Assert.Equal(3, requests.Count);
        Assert.All(requests, r => Assert.True(r.Signed));
        using var body = JsonDocument.Parse(requests[0].Body);
        Assert.Equal("kek_company-secret-0123456789", body.RootElement.GetProperty("enrollment_key").GetString());
        Assert.Equal("SN-1", body.RootElement.GetProperty("serial_number").GetString());
        Assert.Equal(signer.PublicKey.X, body.RootElement.GetProperty("public_key").GetProperty("x").GetString());
        using var login = JsonDocument.Parse(handler.Requests[^1].Body);
        Assert.Equal("ticket-1", login.RootElement.GetProperty("enrollment_ticket").GetString());
        Assert.True(handler.Requests[^1].Signed);
    }

    [Fact]
    public async Task EnrolledStatusRecoversLostDeviceIdWithoutTicket()
    {
        using var dir = new TestDirectory();
        var device = Guid.NewGuid();
        var handler = new IntakeHandler(device, ["enrolled"]);
        using var http = new HttpClient(handler) { BaseAddress = new Uri("https://keeper.test/v1/") };
        using var signer = new HttpMessageSigner(ECDsa.Create(ECCurve.NamedCurves.nistP256));
        var tokens = new MemoryTokens();
        var path = Path.Combine(dir.Root, "device-id.txt");
        Assert.Equal(device, await new IntakeEnrollment(http, signer, "kek_company-secret-0123456789", TimeProvider.System, _ => { }, () => null,
            (_, _) => Task.CompletedTask).RunAsync(tokens, path, default));
        Assert.Null(tokens.Saved);
        Assert.Single(handler.Requests);
    }

    [Fact]
    public async Task RevokedKeyBacksOffOneHourAndNetworkErrorsTwoMinutes()
    {
        using var dir = new TestDirectory();
        var device = Guid.NewGuid();
        var handler = new IntakeHandler(device, ["401", "503", "approved"]);
        using var http = new HttpClient(handler) { BaseAddress = new Uri("https://keeper.test/v1/") };
        using var signer = new HttpMessageSigner(ECDsa.Create(ECCurve.NamedCurves.nistP256));
        var waits = new List<TimeSpan>(); var logs = new List<string>();
        await new IntakeEnrollment(http, signer, "kek_company-secret-0123456789", TimeProvider.System, logs.Add, () => null,
            (wait, _) => { waits.Add(wait); return Task.CompletedTask; }).RunAsync(new MemoryTokens(), Path.Combine(dir.Root, "id.txt"), default);
        Assert.Equal([TimeSpan.FromHours(1), IntakeEnrollment.NetworkRetry], waits);
        Assert.All(logs.Where(l => l.Contains("HTTP", StringComparison.Ordinal)), l => Assert.Contains(" warn: ", l, StringComparison.Ordinal));
    }

    [Fact]
    public void MissingOrCorruptDeviceIdFileMeansNotEnrolled()
    {
        using var dir = new TestDirectory();
        var path = Path.Combine(dir.Root, "device-id.txt");
        Assert.Equal(Guid.Empty, IntakeEnrollment.ReadDeviceId(path));
        File.WriteAllText(path, "not-a-guid");
        Assert.Equal(Guid.Empty, IntakeEnrollment.ReadDeviceId(path));
    }

    private sealed class MemoryTokens : IDeviceTokenStore
    {
        public StoredDeviceToken? Saved { get; private set; }
        public Task<StoredDeviceToken?> LoadAsync(CancellationToken ct) => Task.FromResult(Saved);
        public Task SaveAsync(StoredDeviceToken token, CancellationToken ct) { Saved = token; return Task.CompletedTask; }
        public Task DeleteAsync(CancellationToken ct) { Saved = null; return Task.CompletedTask; }
    }

    private sealed class IntakeHandler(Guid device, string[] script) : HttpMessageHandler
    {
        private int _step;
        public List<(string Path, string Body, bool Signed)> Requests { get; } = [];
        protected override async Task<HttpResponseMessage> SendAsync(HttpRequestMessage request, CancellationToken cancellationToken)
        {
            var path = request.RequestUri!.AbsolutePath;
            Requests.Add((path, await request.Content!.ReadAsStringAsync(cancellationToken), request.Headers.Contains("Signature")));
            object response;
            if (path.EndsWith("enrollment-requests", StringComparison.Ordinal))
            {
                var step = script[_step++];
                if (int.TryParse(step, out var code)) return new HttpResponseMessage((HttpStatusCode)code);
                response = new
                {
                    status = step, request_id = Guid.NewGuid(), retry_after_seconds = step == "pending" ? 300 : 0,
                    enrollment_ticket = step == "approved" ? "ticket-1" : null, device_id = step == "enrolled" ? device : (Guid?)null
                };
            }
            else if (path.EndsWith("challenges", StringComparison.Ordinal)) response = new { nonce = "intake-nonce-0123456789", expires_at = DateTimeOffset.UtcNow.AddMinutes(1) };
            else response = new { access_token = "device-token", token_type = "Bearer", expires_in = 3600, tenant_id = Guid.NewGuid(), device_id = device };
            return new HttpResponseMessage(HttpStatusCode.OK) { Content = new StringContent(JsonSerializer.Serialize(response), Encoding.UTF8, "application/json") };
        }
    }
}
