using System.Net;
using System.Net.Http.Headers;
using System.Security.Cryptography;
using System.Text;
using System.Text.Json;
using Keeper.Agent.Migration;
using Keeper.Agent.Transport;

namespace Keeper.Agent.Tests;

public sealed class MigrationEnrollmentTests
{
    [Theory]
    [InlineData(true)]
    [InlineData(false)]
    public async Task TicketAndResumeLoginUseDeviceProofWithoutAdministrativeBearer(bool withTicket)
    {
        var tenant = Guid.NewGuid(); var device = Guid.NewGuid();
        var handler = new EnrollmentHandler(tenant, device);
        using var http = new HttpClient(handler) { BaseAddress = new Uri("https://keeper.test/v1/") };
        using var key = ECDsa.Create(ECCurve.NamedCurves.nistP256);
        using var signer = new HttpMessageSigner(key);
        var token = await MigrationEnrollment.LoginAsync(http, signer, tenant, device, withTicket ? "one-use-ticket" : null, default);
        Assert.Equal(device, token.DeviceId);
        Assert.Equal(2, handler.Requests.Count);
        using var login = JsonDocument.Parse(handler.Requests[1].Body);
        if (withTicket) Assert.Equal("one-use-ticket", login.RootElement.GetProperty("enrollment_ticket").GetString());
        else Assert.Equal(device, login.RootElement.GetProperty("device_id").GetGuid());
        Assert.False(handler.Requests[0].Signed);
        Assert.True(handler.Requests[1].Signed);
        Assert.Matches("^[a-f0-9]{64}$", MigrationEnrollment.Thumbprint(signer));
        Assert.Equal(signer.KeyId, Convert.ToBase64String(Convert.FromHexString(MigrationEnrollment.Thumbprint(signer))).TrimEnd('=').Replace('+', '-').Replace('/', '_'));
    }

    [Fact]
    public async Task ForeignTenantTokenCannotConfirmEnrollment()
    {
        using var http = new HttpClient(new EnrollmentHandler(Guid.NewGuid(), Guid.NewGuid())) { BaseAddress = new Uri("https://keeper.test/v1/") };
        using var signer = new HttpMessageSigner(ECDsa.Create(ECCurve.NamedCurves.nistP256));
        await Assert.ThrowsAsync<InvalidDataException>(() => MigrationEnrollment.LoginAsync(http, signer, Guid.NewGuid(), Guid.NewGuid(), "ticket", default));
    }

    [Theory]
    [InlineData("Bearer")]
    [InlineData("Cookie")]
    public async Task AdministrativeCredentialCannotBeReusedForDeviceLogin(string credential)
    {
        var handler = new EnrollmentHandler(Guid.NewGuid(), Guid.NewGuid());
        using var http = new HttpClient(handler) { BaseAddress = new Uri("https://keeper.test/v1/") };
        if (credential == "Bearer") http.DefaultRequestHeaders.Authorization = new AuthenticationHeaderValue("Bearer", "admin-token");
        else http.DefaultRequestHeaders.Add("Cookie", "session=admin");
        using var signer = new HttpMessageSigner(ECDsa.Create(ECCurve.NamedCurves.nistP256));
        await Assert.ThrowsAsync<InvalidDataException>(() => MigrationEnrollment.LoginAsync(http, signer, Guid.NewGuid(), Guid.NewGuid(), "ticket", default));
        Assert.Empty(handler.Requests);
    }

    private sealed class EnrollmentHandler(Guid tenant, Guid device) : HttpMessageHandler
    {
        public List<(string Body, bool Signed)> Requests { get; } = [];
        protected override async Task<HttpResponseMessage> SendAsync(HttpRequestMessage request, CancellationToken cancellationToken)
        {
            Assert.Null(request.Headers.Authorization);
            Assert.False(request.Headers.Contains("Cookie"));
            Requests.Add((await request.Content!.ReadAsStringAsync(cancellationToken), request.Headers.Contains("Signature")));
            object response = Requests.Count == 1
                ? new { nonce = "migration-nonce", expires_at = DateTimeOffset.UtcNow.AddMinutes(1) }
                : new { access_token = "device-token", token_type = "Bearer", expires_in = 3600, tenant_id = tenant, device_id = device };
            return new HttpResponseMessage(HttpStatusCode.OK) { Content = new StringContent(JsonSerializer.Serialize(response), Encoding.UTF8, "application/json") };
        }
    }
}
