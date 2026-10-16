using System.Net;
using System.Security.Cryptography;
using System.Text;
using System.Text.Json;
using Keeper.Agent.Hosting;
using Keeper.Agent.Policy;
using Keeper.Agent.Storage;
using Keeper.Agent.Transport;
using Keeper.Shared.Protocol;

namespace Keeper.Agent.Tests;

// Reloj de Windows desfasado: el agente firma con la hora del servidor (Date / server_time) y, ante un 401
// invalid_signature con desfase, reintenta UNA vez. La ventana de +-60 s del servidor no cambia.
public sealed class ClockSkewTests
{
    private static readonly DateTimeOffset ServerNow = DateTimeOffset.FromUnixTimeSeconds(1_760_000_000);

    [Fact]
    public void MeasureAcceptsValidDatesAndIgnoresMissingAbsurdOrOutOfRange()
    {
        var local = new TestClock();
        var clock = new ServerClock(local);
        var now = local.GetUtcNow();
        Assert.Equal(TimeSpan.FromMinutes(5), clock.Measure(now.AddMinutes(5)));
        Assert.Equal(TimeSpan.FromHours(-47), clock.Measure(now.AddHours(-47)));
        Assert.Null(clock.Measure(null));
        Assert.Null(clock.Measure(now.AddHours(49)));
        Assert.Null(clock.Measure(now.AddDays(-3)));
        Assert.Null(clock.Measure(new DateTimeOffset(1999, 1, 1, 0, 0, 0, TimeSpan.Zero)));
        Assert.Null(clock.Observe(now.AddDays(10), confirmed: true));
        Assert.Equal(TimeSpan.Zero, clock.Offset);
    }

    [Fact]
    public void ObserveIgnoresDateNoiseAndConfirmedOffsetSurvivesRevert()
    {
        var local = new TestClock();
        var clock = new ServerClock(local);
        clock.Observe(local.GetUtcNow().AddSeconds(1), confirmed: false);
        Assert.Equal(TimeSpan.Zero, clock.Offset);
        clock.Observe(local.GetUtcNow().AddMinutes(5), confirmed: true);
        Assert.Equal(TimeSpan.FromMinutes(5), clock.Confirmed);
        clock.Observe(local.GetUtcNow().AddHours(20), confirmed: false);
        Assert.Equal(TimeSpan.FromHours(20), clock.Offset);
        clock.Revert();
        Assert.Equal(TimeSpan.FromMinutes(5), clock.Offset);
        // TimeSync corrigio la hora de Windows +5 min: el desfase de las firmas baja lo mismo.
        clock.ShiftLocal(TimeSpan.FromMinutes(5));
        Assert.Equal(TimeSpan.Zero, clock.Offset);
        Assert.Equal(TimeSpan.Zero, clock.Confirmed);
    }

    [Fact]
    public void SignerUsesServerOffsetOnlyWhenClockIsSet()
    {
        var local = new TestClock();
        using var key = ECDsa.Create(ECCurve.NamedCurves.nistP256);
        var clock = new ServerClock(local);
        clock.Observe(local.GetUtcNow().AddMinutes(-5), confirmed: true);
        using var plain = new HttpMessageSigner(key);
        using var corrected = new HttpMessageSigner(key) { Clock = clock };
        Assert.Equal(local.GetUtcNow().ToUnixTimeSeconds(), Created(plain));
        Assert.Equal(local.GetUtcNow().AddMinutes(-5).ToUnixTimeSeconds(), Created(corrected));

        long Created(HttpMessageSigner signer)
        {
            using var request = new HttpRequestMessage(HttpMethod.Get, "https://keeper.test/v1/client/policy");
            signer.Sign(request, [], local.GetUtcNow(), HttpMessageSigner.CreateNonce());
            var signature = HttpMessageSigner.ReadSignature(request)!.Value;
            Assert.Contains($"expires={signature.Created + 60}", request.Headers.GetValues("Signature-Input").Single());
            return signature.Created;
        }
    }

    [Theory]
    [InlineData(300)]
    [InlineData(-300)]
    public async Task InvalidSignatureWithSkewedDateIsResignedAndRetriedOnce(int skewSeconds)
    {
        // El reloj local va skew segundos por delante del servidor.
        var server = new TestClock();
        var local = new TestClock();
        local.ShiftWallClock(TimeSpan.FromSeconds(skewSeconds));
        using var signer = new HttpMessageSigner(ECDsa.Create(ECCurve.NamedCurves.nistP256)) { Clock = new ServerClock(local) };
        var fake = new SkewServer(server);
        using var http = Client(signer, fake);
        using var response = await SendSigned(http, signer, local, "nonce-0123456789abcdef");
        Assert.Equal(HttpStatusCode.OK, response.StatusCode);
        Assert.Equal(2, fake.Requests.Count);
        Assert.Equal(["nonce-0123456789abcdef", "nonce-0123456789abcdef"], fake.Requests.Select(r => r.Nonce));
        Assert.Equal(server.GetUtcNow().ToUnixTimeSeconds(), fake.Requests[1].Created);
        Assert.Equal(TimeSpan.FromSeconds(-skewSeconds), signer.Clock!.Confirmed);
        Assert.Contains("protocol_version", await response.Content.ReadAsStringAsync());
    }

    [Fact]
    public async Task NoRetryWhenDateShowsNoSkewOrIsMissingOrAbsurd()
    {
        foreach (var date in new DateTimeOffset?[] { null, ServerNow, ServerNow.AddDays(5) })
        {
            var local = new TestClock();
            using var signer = new HttpMessageSigner(ECDsa.Create(ECCurve.NamedCurves.nistP256)) { Clock = new ServerClock(local) };
            var calls = 0;
            using var http = Client(signer, new Stub(_ =>
            {
                calls++;
                var response = Problem(HttpStatusCode.Unauthorized, "invalid_signature");
                response.Headers.Date = date is null ? null : date == ServerNow ? local.GetUtcNow().AddSeconds(5) : local.GetUtcNow().AddDays(5);
                return response;
            }));
            using var result = await SendSigned(http, signer, local, HttpMessageSigner.CreateNonce());
            Assert.Equal(HttpStatusCode.Unauthorized, result.StatusCode);
            Assert.Equal(1, calls);
            Assert.Equal(TimeSpan.Zero, signer.Clock!.Offset);
            // El cuerpo del Problem sigue legible para quien lo trata (SyncClient/IntakeEnrollment).
            Assert.Contains("invalid_signature", await result.Content.ReadAsStringAsync());
        }
    }

    [Fact]
    public async Task NoRetryForOtherUnauthorizedCodesOrUnsignedRequests()
    {
        var local = new TestClock();
        using var signer = new HttpMessageSigner(ECDsa.Create(ECCurve.NamedCurves.nistP256)) { Clock = new ServerClock(local) };
        var calls = 0;
        using var http = Client(signer, new Stub(_ =>
        {
            calls++;
            var response = Problem(HttpStatusCode.Unauthorized, "invalid_token");
            response.Headers.Date = local.GetUtcNow().AddMinutes(10);
            return response;
        }));
        using (var result = await SendSigned(http, signer, local, HttpMessageSigner.CreateNonce()))
            Assert.Equal(HttpStatusCode.Unauthorized, result.StatusCode);
        Assert.Equal(1, calls);
        Assert.Equal(TimeSpan.Zero, signer.Clock!.Offset);
        using (var unsigned = await http.PostAsync("client/auth/challenges", new StringContent("{}")))
            Assert.Equal(HttpStatusCode.Unauthorized, unsigned.StatusCode);
        Assert.Equal(2, calls);
    }

    [Fact]
    public async Task FailedRetryRevertsToTheLastConfirmedOffsetAndNeverLoops()
    {
        var local = new TestClock();
        using var signer = new HttpMessageSigner(ECDsa.Create(ECCurve.NamedCurves.nistP256)) { Clock = new ServerClock(local) };
        var calls = 0;
        using var http = Client(signer, new Stub(_ =>
        {
            calls++;
            // Date manipulada: siempre dice que la firma esta 2 h corrida.
            var response = Problem(HttpStatusCode.Unauthorized, "invalid_signature");
            response.Headers.Date = local.GetUtcNow().AddHours(2 * calls);
            return response;
        }));
        using var result = await SendSigned(http, signer, local, HttpMessageSigner.CreateNonce());
        Assert.Equal(HttpStatusCode.Unauthorized, result.StatusCode);
        Assert.Equal(2, calls);
        Assert.Equal(TimeSpan.Zero, signer.Clock!.Offset);
        Assert.Equal(TimeSpan.Zero, signer.Clock.Confirmed);
    }

    [Theory]
    [InlineData(300)]
    [InlineData(-300)]
    public async Task SyncWorksWithWindowsClockFiveMinutesOff(int skewSeconds)
    {
        var server = new TestClock();
        var local = new TestClock();
        local.ShiftWallClock(TimeSpan.FromSeconds(skewSeconds));
        using var directory = new TestDirectory();
        using var outbox = new DurableOutbox(directory.File("outbox"));
        using var signer = new HttpMessageSigner(ECDsa.Create(ECCurve.NamedCurves.nistP256)) { Clock = new ServerClock(local) };
        await using var host = new ModuleHost([], Samples.Context(local));
        var policy = new PolicyCoordinator(new MemoryPolicyStore(), host, Samples.Device);
        var fake = new SkewServer(server);
        using var http = Client(signer, fake);
        var client = new SyncClient(http, signer, Samples.Device, outbox, policy, local, null, new Tokens());
        Assert.Equal(120, await client.ExecuteAsync(default));
        Assert.Equal(0, fake.Rejected);
        Assert.Equal(["/v1/client/auth/challenges", "/v1/client/login", "/v1/client/sync"], fake.Requests.Select(r => r.Path));
        Assert.All(fake.Requests.Where(r => r.Created is not null), r => Assert.Equal(server.GetUtcNow().ToUnixTimeSeconds(), r.Created));
        Assert.Equal(TimeSpan.FromSeconds(-skewSeconds), signer.Clock!.Confirmed);
        // Segundo ciclo con token: firma directo con la hora del servidor.
        await client.ExecuteAsync(default);
        Assert.Equal(0, fake.Rejected);
    }

    [Theory]
    [InlineData(300)]
    [InlineData(-300)]
    public async Task SyncRecoversWithOneRetryWhenTheFirstSignedRequestIsRejected(int skewSeconds)
    {
        // Token guardado (no hay challenge previo que aporte Date): el primer sync sale con la hora local, recibe 401
        // invalid_signature y se reintenta una vez con la hora del servidor.
        var server = new TestClock();
        var local = new TestClock();
        local.ShiftWallClock(TimeSpan.FromSeconds(skewSeconds));
        using var directory = new TestDirectory();
        using var outbox = new DurableOutbox(directory.File("outbox"));
        using var signer = new HttpMessageSigner(ECDsa.Create(ECCurve.NamedCurves.nistP256)) { Clock = new ServerClock(local) };
        await using var host = new ModuleHost([], Samples.Context(local));
        var policy = new PolicyCoordinator(new MemoryPolicyStore(), host, Samples.Device);
        var fake = new SkewServer(server);
        using var http = Client(signer, fake);
        var tokens = new Tokens();
        await tokens.SaveAsync(new StoredDeviceToken(SkewServer.Token, local.GetUtcNow()), default);
        var client = new SyncClient(http, signer, Samples.Device, outbox, policy, local, null, tokens);
        Assert.Equal(120, await client.ExecuteAsync(default));
        Assert.Equal(1, fake.Rejected);
        Assert.Equal(["/v1/client/sync", "/v1/client/sync"], fake.Requests.Select(r => r.Path));
        Assert.NotNull(tokens.Value);
    }

    [Theory]
    [InlineData(300)]
    [InlineData(-300)]
    public async Task IntakeEnrollmentWorksWithWindowsClockFiveMinutesOff(int skewSeconds)
    {
        var server = new TestClock();
        var local = new TestClock();
        local.ShiftWallClock(TimeSpan.FromSeconds(skewSeconds));
        using var dir = new TestDirectory();
        using var signer = new HttpMessageSigner(ECDsa.Create(ECCurve.NamedCurves.nistP256)) { Clock = new ServerClock(local) };
        var fake = new SkewServer(server);
        using var http = Client(signer, fake);
        var tokens = new Tokens();
        var logs = new List<string>();
        var device = await new IntakeEnrollment(http, signer, "kek_company-secret-0123456789", local, logs.Add, () => "SN-1",
            // Un fallo no debe colgar la prueba (RunAsync reintenta para siempre): al primer reintento se corta.
            (wait, _) => throw new InvalidOperationException(string.Join(" | ", logs))).RunAsync(tokens, Path.Combine(dir.Root, "device-id.txt"), default);
        Assert.Equal(Samples.Device, device);
        // Solo la primera solicitud de alta sale con la hora local: 401 + reintento inmediato; el resto ya va corregido.
        Assert.Equal(1, fake.Rejected);
        Assert.Equal(["/v1/client/enrollment-requests", "/v1/client/enrollment-requests", "/v1/client/auth/challenges", "/v1/client/login"],
            fake.Requests.Select(r => r.Path));
    }

    private static HttpClient Client(HttpMessageSigner signer, HttpMessageHandler inner) =>
        new(new ClockSkewHandler(signer) { InnerHandler = inner }) { BaseAddress = new Uri("https://keeper.test/v1/") };

    private static Task<HttpResponseMessage> SendSigned(HttpClient http, HttpMessageSigner signer, TimeProvider local, string nonce)
    {
        var body = Encoding.UTF8.GetBytes("{\"a\":1}");
        var request = new HttpRequestMessage(HttpMethod.Post, new Uri(http.BaseAddress!, "client/sync")) { Content = new ByteArrayContent(body) };
        request.Content.Headers.ContentType = new("application/json");
        request.Headers.Add("Idempotency-Key", Guid.NewGuid().ToString());
        signer.Sign(request, body, local.GetUtcNow(), nonce);
        return http.SendAsync(request);
    }

    private static HttpResponseMessage Problem(HttpStatusCode status, string code) => new(status)
    {
        Content = new StringContent(JsonSerializer.Serialize(new { type = "about:blank", title = code, status = (int)status, code }), Encoding.UTF8, "application/problem+json")
    };

    private sealed class Stub(Func<HttpRequestMessage, HttpResponseMessage> respond) : HttpMessageHandler
    {
        protected override Task<HttpResponseMessage> SendAsync(HttpRequestMessage request, CancellationToken ct) => Task.FromResult(respond(request));
    }

    private sealed class Tokens : IDeviceTokenStore
    {
        public StoredDeviceToken? Value { get; private set; }
        public Task<StoredDeviceToken?> LoadAsync(CancellationToken ct) => Task.FromResult(Value);
        public Task SaveAsync(StoredDeviceToken token, CancellationToken ct) { Value = token; return Task.CompletedTask; }
        public Task DeleteAsync(CancellationToken ct) { Value = null; return Task.CompletedTask; }
    }

    // Servidor simulado con su propio reloj: como Signature.php, rechaza |created - ahora| > 60 s con 401
    // invalid_signature (sin guardar el nonce) y no acepta un nonce dos veces. Toda respuesta lleva Date.
    private sealed class SkewServer(TestClock clock) : HttpMessageHandler
    {
        public static DeviceToken Token => new()
        {
            DeviceId = Samples.Device, TenantId = Samples.Tenant, AccessToken = "skew-token", TokenType = "Bearer", ExpiresIn = 3600
        };
        private readonly HashSet<string> _nonces = [];
        public List<(string Path, long? Created, string? Nonce)> Requests { get; } = [];
        public int Rejected { get; private set; }

        protected override Task<HttpResponseMessage> SendAsync(HttpRequestMessage request, CancellationToken ct)
        {
            var path = request.RequestUri!.AbsolutePath;
            var signature = HttpMessageSigner.ReadSignature(request);
            Requests.Add((path, signature?.Created, signature?.Nonce));
            HttpResponseMessage response;
            if (signature is { } s && (Math.Abs(clock.GetUtcNow().ToUnixTimeSeconds() - s.Created) > 60 || !_nonces.Add(s.Nonce)))
            {
                Rejected++;
                response = Problem(HttpStatusCode.Unauthorized, "invalid_signature");
            }
            else response = path switch
            {
                "/v1/client/auth/challenges" => Json(new Challenge { Nonce = "challenge-nonce-0123456789", ExpiresAt = clock.GetUtcNow().AddSeconds(60) }),
                "/v1/client/login" => Json(Token),
                "/v1/client/sync" => Json(new SyncResponse
                {
                    ProtocolVersion = 1, ServerTime = clock.GetUtcNow(), NextSyncAfterSeconds = 120, PolicyVersion = 1, Policy = Samples.Policy(),
                    Release = null, Commands = [], EpisodeAcks = [], LogAcks = [], CommandAcks = [], SecurityAck = null, ActivityAck = null
                }),
                "/v1/client/enrollment-requests" => new HttpResponseMessage(HttpStatusCode.OK) { Content = new StringContent(JsonSerializer.Serialize(new
                {
                    status = "approved", request_id = Guid.NewGuid(), retry_after_seconds = 0, enrollment_ticket = "ticket-1",
                    device_id = (Guid?)null, ask_document = false
                }), Encoding.UTF8, "application/json") },
                _ => new HttpResponseMessage(HttpStatusCode.NotFound)
            };
            response.Headers.Date = clock.GetUtcNow();
            return Task.FromResult(response);
        }

        private static HttpResponseMessage Json<T>(T value) => new(HttpStatusCode.OK)
        {
            Content = new ByteArrayContent(JsonSerializer.SerializeToUtf8Bytes(value, ProtocolJson.Options))
        };
    }
}
