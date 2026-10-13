using System.Net;
using System.Net.Sockets;
using System.Security.Cryptography;
using System.Text;
using System.Text.Json;
using System.Text.Json.Nodes;
using Keeper.Agent.Hosting;
using Keeper.Agent.Policy;
using Keeper.Agent.Storage;
using Keeper.Agent.Transport;
using Keeper.Shared.Contracts;
using Keeper.Shared.Protocol;

namespace Keeper.Agent.Tests;

// Mudanza de servidor por orden firmada (4.0.24): verificacion, prueba del destino, api-base.json, reintentos y cola.
public sealed class RelocationTests
{
    private const string Kid = "release-kid";
    private static readonly Uri Devkeep = new("https://devkeep.azclegal.com/v1/");
    private static readonly Uri Keep = new("https://keep.azclegal.com/v1/");

    private sealed class World : IAsyncDisposable
    {
        public TestDirectory Directory { get; } = new();
        public TestClock Clock { get; } = new();
        public ECDsa ReleaseKey { get; } = ECDsa.Create(ECCurve.NamedCurves.nistP256);
        public Dictionary<string, ECDsa> Keys { get; }
        public DurableOutbox Outbox { get; }
        public HttpMessageSigner Signer { get; } = new(ECDsa.Create(ECCurve.NamedCurves.nistP256));
        public ModuleHost Host { get; }
        public PolicyCoordinator Policy { get; }
        public List<(string Host, string Path)> Requests { get; } = [];
        public List<SyncRequest> KeepSyncs { get; } = [];
        public List<string> Log { get; } = [];
        public Func<HttpRequestMessage, Task<HttpResponseMessage>> Origin { get; set; }
        public Func<HttpRequestMessage, Task<HttpResponseMessage>> Destination { get; set; }
        public string? Offered { get; set; }
        public ServerRelocation Relocation { get; }

        public World(long lastSequence = 0)
        {
            Keys = new() { [Kid] = ReleaseKey };
            Outbox = new DurableOutbox(Directory.File("outbox.json"));
            Host = new ModuleHost([], Samples.Context(Clock));
            Policy = new PolicyCoordinator(new MemoryPolicyStore(), Host, Samples.Device);
            Origin = request => Task.FromResult(request.RequestUri!.AbsolutePath switch
            {
                "/v1/client/auth/challenges" => Json(new Challenge { Nonce = "old-nonce", ExpiresAt = Clock.GetUtcNow().AddMinutes(1) }),
                "/v1/client/login" => Json(new DeviceToken { DeviceId = Samples.Device, TenantId = Samples.Tenant, AccessToken = "old-token", TokenType = "Bearer", ExpiresIn = 3600 }),
                _ => Json(SyncResponse() with { Relocation = Offered })
            });
            Destination = DestinationOk;
            Relocation = new ServerRelocation(Devkeep, lastSequence, Devkeep.AbsoluteUri, Connect, Keys, Samples.Device,
                () => Policy.Current?.TenantId, Directory.Root, Outbox, Clock, Log.Add);
        }

        private IRelocatableSync Connect(Uri api)
        {
            var http = new HttpClient(new Stub(async request =>
            {
                Requests.Add((request.RequestUri!.Host, request.RequestUri.AbsolutePath));
                return request.RequestUri.Host == "keep.azclegal.com" ? await Destination(request) : await Origin(request);
            })) { BaseAddress = api };
            return new SyncConnection(http, new SyncClient(http, Signer, Samples.Device, Outbox, Policy, Clock, null, new MemoryTokenStore())
            {
                OnResponse = (response, tenant, ct) => Relocation.OfferAsync(response.Relocation, tenant, ct)
            });
        }

        public async Task<HttpResponseMessage> DestinationOk(HttpRequestMessage request)
        {
            var path = request.RequestUri!.AbsolutePath;
            if (path == "/v1/client/auth/challenges") return Json(new Challenge { Nonce = "keep-nonce", ExpiresAt = Clock.GetUtcNow().AddMinutes(1) });
            if (path == "/v1/client/login")
            {
                Assert.Contains("keyid=\"" + Signer.KeyId + "\"", request.Headers.GetValues("Signature-Input").Single());
                return Json(new DeviceToken { DeviceId = Samples.Device, TenantId = Samples.Tenant, AccessToken = "keep-token", TokenType = "Bearer", ExpiresIn = 3600 });
            }
            Assert.Equal("/v1/client/sync", path);
            Assert.Equal("keep-token", request.Headers.Authorization!.Parameter);
            var body = JsonSerializer.Deserialize<SyncRequest>(await request.Content!.ReadAsByteArrayAsync(), ProtocolJson.Options)!;
            KeepSyncs.Add(body);
            Ack Accepted(Guid id) => new() { EventId = id, Status = AckStatus.Accepted, Retryable = false };
            return Json(SyncResponse() with
            {
                EpisodeAcks = (body.Episodes ?? []).Select(e => Accepted(e.EventId)).ToArray(),
                LogAcks = (body.Logs ?? []).Select(e => Accepted(e.EventId)).ToArray()
            });
        }

        public SyncResponse SyncResponse() => new()
        {
            ProtocolVersion = 1, ServerTime = Clock.GetUtcNow(), NextSyncAfterSeconds = 120, PolicyVersion = 1, Policy = Samples.Policy(),
            Release = null, Commands = [], EpisodeAcks = [], LogAcks = [], CommandAcks = [], SecurityAck = null, ActivityAck = null
        };

        public JsonObject Payload(long sequence = 1) => new()
        {
            ["type"] = "relocation", ["id"] = Guid.NewGuid().ToString(), ["sequence"] = sequence,
            ["from_base"] = "https://devkeep.azclegal.com/", ["to_base"] = "https://keep.azclegal.com/",
            ["tenants"] = new JsonArray(Samples.Tenant.ToString()), ["exclude_devices"] = new JsonArray(Guid.NewGuid().ToString()),
            ["not_before"] = Clock.GetUtcNow().AddHours(-1).ToString("yyyy-MM-ddTHH:mm:ssZ"), ["expires_at"] = Clock.GetUtcNow().AddDays(7).ToString("yyyy-MM-ddTHH:mm:ssZ")
        };

        public string Sign(JsonObject payload, ECDsa? key = null, string kid = Kid)
        {
            var input = B64(Encoding.UTF8.GetBytes(new JsonObject { ["alg"] = "ES256", ["kid"] = kid }.ToJsonString())) + "." + B64(Encoding.UTF8.GetBytes(payload.ToJsonString()));
            var signature = (key ?? ReleaseKey).SignData(Encoding.ASCII.GetBytes(input), HashAlgorithmName.SHA256, DSASignatureFormat.IeeeP1363FixedFieldConcatenation);
            return input + "." + B64(signature);
        }

        public async Task<List<LogEntry>> RelocationLogs() =>
            (await Outbox.InspectAsync()).Events.Where(e => e.Log?.Component == ServerRelocation.Component).Select(e => e.Log!).ToList();

        public async ValueTask DisposeAsync()
        {
            Relocation.Dispose();
            await Host.DisposeAsync();
            Signer.Dispose();
            ReleaseKey.Dispose();
            Outbox.Dispose();
            Directory.Dispose();
        }
    }

    [Fact]
    public async Task SignedOrderInSyncRelocatesPersistsBaseAndResendsQueue()
    {
        await using var w = new World();
        var payload = w.Payload();
        w.Offered = w.Sign(payload);
        var episode = Samples.Episode();
        await w.Outbox.EnqueueAsync(episode, default);
        await w.Relocation.ExecuteAsync(default);

        Assert.Equal(Keep, w.Relocation.CurrentApi);
        Assert.Equal(["/v1/client/auth/challenges", "/v1/client/login", "/v1/client/sync"], w.Requests.Where(r => r.Host == "keep.azclegal.com").Select(r => r.Path));
        // El episodio no confirmado por el origen se reenvio al destino, junto con el aviso "relocated" (error_code = host).
        var sent = Assert.Single(w.KeepSyncs);
        Assert.Contains(sent.Episodes!, e => e.EventId == episode.EventId);
        var relocated = Assert.Single(sent.Logs!, l => l.Code == "relocated");
        Assert.Equal("keep.azclegal.com", relocated.Fields!.ErrorCode);
        Assert.Empty((await w.Outbox.InspectAsync()).Events);
        var stored = ApiBaseOverride.Read(w.Directory.Root)!;
        Assert.Equal(Keep.AbsoluteUri, stored.ApiBase);
        Assert.Equal(Devkeep.AbsoluteUri, stored.InstalledBase);
        Assert.Equal(1, stored.Sequence);
        Assert.Equal(Guid.Parse(payload["id"]!.GetValue<string>()), stored.RelocationId);
        // Siguiente ciclo: directo al destino.
        var before = w.Requests.Count(r => r.Host == "devkeep.azclegal.com");
        await w.Relocation.ExecuteAsync(default);
        Assert.Equal(before, w.Requests.Count(r => r.Host == "devkeep.azclegal.com"));
        Assert.Equal(2, w.KeepSyncs.Count);
    }

    public static TheoryData<string, string> Rejections => new()
    {
        { "rogue_key", "invalid_signature" }, { "unknown_kid", "untrusted_key" }, { "tampered", "invalid_signature" },
        { "foreign_host", "to_base_not_allowed" }, { "suffix_trick", "to_base_not_allowed" }, { "http", "to_base_not_https" },
        { "other_from", "from_base_mismatch" }, { "foreign_tenant", "tenant_not_included" }, { "excluded", "device_excluded" },
        { "old_sequence", "stale_sequence" }, { "expired", "expired" }, { "future", "not_yet_valid" }, { "release_manifest", "invalid_manifest" },
        { "extra_field", "invalid_manifest" }, { "path_in_base", "invalid_manifest" }
    };

    [Theory]
    [MemberData(nameof(Rejections))]
    public async Task OrderFailingAnyRuleIsRejected(string variant, string reason)
    {
        await using var w = new World();
        var p = w.Payload(sequence: 3);
        using var rogue = ECDsa.Create(ECCurve.NamedCurves.nistP256);
        var jws = variant switch
        {
            "rogue_key" => w.Sign(p, rogue),
            "unknown_kid" => w.Sign(p, kid: "other"),
            "tampered" => Tamper(w.Sign(p)),
            "foreign_host" => w.Sign(With(p, "to_base", "https://keep.example.com/")),
            "suffix_trick" => w.Sign(With(p, "to_base", "https://keepazclegal.com/")),
            "http" => w.Sign(With(p, "to_base", "http://keep.azclegal.com/")),
            "other_from" => w.Sign(With(p, "from_base", "https://otro.azclegal.com/")),
            "foreign_tenant" => w.Sign(With(p, "tenants", new JsonArray(Guid.NewGuid().ToString()))),
            "excluded" => w.Sign(With(p, "exclude_devices", new JsonArray(Samples.Device.ToString()))),
            "old_sequence" => w.Sign(With(p, "sequence", 2)),
            "expired" => w.Sign(With(p, "expires_at", w.Clock.GetUtcNow().AddMinutes(-1).ToString("yyyy-MM-ddTHH:mm:ssZ"))),
            "future" => w.Sign(With(With(p, "not_before", w.Clock.GetUtcNow().AddHours(1).ToString("yyyy-MM-ddTHH:mm:ssZ")), "expires_at", w.Clock.GetUtcNow().AddDays(2).ToString("yyyy-MM-ddTHH:mm:ssZ"))),
            "release_manifest" => w.Sign(With(p, "type", "release")),
            "extra_field" => w.Sign(With(p, "note", "x")),
            "path_in_base" => w.Sign(With(p, "to_base", "https://keep.azclegal.com/v1/")),
            _ => throw new ArgumentOutOfRangeException(nameof(variant))
        };
        var error = Assert.Throws<RelocationRejectedException>(() =>
            RelocationVerifier.Verify(jws, w.Keys, Devkeep, Samples.Tenant, Samples.Device, 2, w.Clock.GetUtcNow()));
        Assert.Equal(reason, error.Reason);
        // Mismo resultado de punta a punta: se ignora, se avisa y no se toca el destino ni api-base.json.
        await using var e2e = new World(lastSequence: 2);
        e2e.Keys[Kid] = w.ReleaseKey;
        e2e.Offered = jws;
        await e2e.Relocation.ExecuteAsync(default);
        await e2e.Relocation.ExecuteAsync(default);
        Assert.Equal(Devkeep, e2e.Relocation.CurrentApi);
        Assert.DoesNotContain(e2e.Requests, r => r.Host == "keep.azclegal.com");
        Assert.Null(ApiBaseOverride.Read(e2e.Directory.Root));
        var rejected = Assert.Single(await e2e.RelocationLogs());
        Assert.Equal(("relocation_rejected", LogEntryLevel.Warn, reason), (rejected.Code, rejected.Level, rejected.Fields!.ErrorCode));
    }

    [Theory]
    [InlineData("http_401")]
    [InlineData("dns")]
    [InlineData("tls")]
    public async Task UnreachableDestinationStaysRetriesEveryFifteenMinutesAndKeepsQueue(string failure)
    {
        await using var w = new World();
        w.Offered = w.Sign(w.Payload());
        var episode = Samples.Episode();
        await w.Outbox.EnqueueAsync(episode, default);
        var keepAttempts = 0;
        w.Destination = request =>
        {
            keepAttempts++;
            return failure switch
            {
                "http_401" => Task.FromResult(request.RequestUri!.AbsolutePath.EndsWith("challenges", StringComparison.Ordinal)
                    ? Json(new Challenge { Nonce = "n", ExpiresAt = w.Clock.GetUtcNow().AddMinutes(1) }) : new HttpResponseMessage(HttpStatusCode.Unauthorized)),
                "dns" => throw new HttpRequestException("dns", new SocketException((int)SocketError.HostNotFound)),
                _ => throw new HttpRequestException("tls", new System.Security.Authentication.AuthenticationException("cert"))
            };
        };
        await w.Relocation.ExecuteAsync(default);
        Assert.Equal(Devkeep, w.Relocation.CurrentApi);
        Assert.True(w.Relocation.HasPending);
        Assert.Null(ApiBaseOverride.Read(w.Directory.Root));
        var attempts = keepAttempts;
        Assert.True(attempts > 0);
        // La cola sigue intacta (el origen no confirmo el episodio) y el fallo se avisa con su motivo.
        Assert.Contains((await w.Outbox.InspectAsync()).Events, e => e.Episode?.EventId == episode.EventId);
        var failed = Assert.Single(await w.RelocationLogs());
        Assert.Equal(("relocation_failed", LogEntryLevel.Warn, failure), (failed.Code, failed.Level, failed.Fields!.ErrorCode));

        w.Clock.Advance(TimeSpan.FromMinutes(5));
        await w.Relocation.ExecuteAsync(default);
        Assert.Equal(attempts, keepAttempts);
        w.Clock.Advance(TimeSpan.FromMinutes(11));
        await w.Relocation.ExecuteAsync(default);
        Assert.True(keepAttempts > attempts);
        Assert.Single(await w.RelocationLogs());  // mismo motivo: no se repite el aviso al servidor

        w.Destination = w.DestinationOk;
        w.Clock.Advance(TimeSpan.FromMinutes(16));
        await w.Relocation.ExecuteAsync(default);
        Assert.Equal(Keep, w.Relocation.CurrentApi);
        Assert.Contains(w.KeepSyncs.SelectMany(s => s.Episodes ?? []), e => e.EventId == episode.EventId);
    }

    [Fact]
    public async Task OrderStopsBeingRetriedAfterItExpires()
    {
        await using var w = new World();
        w.Offered = w.Sign(With(w.Payload(), "expires_at", w.Clock.GetUtcNow().AddMinutes(20).ToString("yyyy-MM-ddTHH:mm:ssZ")));
        w.Destination = _ => throw new HttpRequestException("down");
        await w.Relocation.ExecuteAsync(default);
        Assert.True(w.Relocation.HasPending);
        w.Offered = null;
        w.Clock.Advance(TimeSpan.FromMinutes(21));
        var before = w.Requests.Count(r => r.Host == "keep.azclegal.com");
        await w.Relocation.ExecuteAsync(default);
        Assert.False(w.Relocation.HasPending);
        Assert.Equal(before, w.Requests.Count(r => r.Host == "keep.azclegal.com"));
        Assert.Equal(Devkeep, w.Relocation.CurrentApi);
    }

    [Theory]
    [InlineData("/v1/client/sync")]
    [InlineData("/v1/client/auth/challenges")]
    public async Task ConflictDeviceRelocatedTriggersRelocation(string relocatedPath)
    {
        await using var w = new World();
        await w.Policy.ApplyAsync(1, Samples.Policy(), default);  // tenant conocido por la politica en cache
        var jws = w.Sign(w.Payload());
        w.Origin = request =>
        {
            if (request.RequestUri!.AbsolutePath == "/v1/client/auth/challenges" && relocatedPath != request.RequestUri.AbsolutePath)
                return Task.FromResult(Json(new Challenge { Nonce = "n", ExpiresAt = w.Clock.GetUtcNow().AddMinutes(1) }));
            if (request.RequestUri.AbsolutePath == "/v1/client/login")
                return Task.FromResult(Json(new DeviceToken { DeviceId = Samples.Device, TenantId = Samples.Tenant, AccessToken = "old", TokenType = "Bearer", ExpiresIn = 3600 }));
            var problem = new JsonObject { ["type"] = "about:blank", ["title"] = "device_relocated", ["status"] = 409, ["code"] = "device_relocated", ["request_id"] = Guid.NewGuid().ToString(), ["relocation"] = jws };
            return Task.FromResult(new HttpResponseMessage(HttpStatusCode.Conflict) { Content = new StringContent(problem.ToJsonString(), Encoding.UTF8, "application/problem+json") });
        };
        var episode = Samples.Episode();
        await w.Outbox.EnqueueAsync(episode, default);
        Assert.Equal(120, await w.Relocation.ExecuteAsync(default));
        Assert.Equal(Keep, w.Relocation.CurrentApi);
        Assert.Contains(w.KeepSyncs.SelectMany(s => s.Episodes ?? []), e => e.EventId == episode.EventId);
        // Con el 409 en el sync, el lote pendiente se reenvia tal cual al destino (misma idempotencia); los avisos van en el siguiente.
        await w.Relocation.ExecuteAsync(default);
        var codes = w.KeepSyncs.SelectMany(s => s.Logs ?? []).Select(l => l.Code).ToArray();
        Assert.Contains("device_relocated", codes);
        Assert.Contains("relocated", codes);
    }

    [Fact]
    public async Task ConflictWithoutRelocationBodyIsAPlainTransportError()
    {
        await using var w = new World();
        w.Origin = request => Task.FromResult(request.RequestUri!.AbsolutePath switch
        {
            "/v1/client/auth/challenges" => Json(new Challenge { Nonce = "n", ExpiresAt = w.Clock.GetUtcNow().AddMinutes(1) }),
            "/v1/client/login" => Json(new DeviceToken { DeviceId = Samples.Device, TenantId = Samples.Tenant, AccessToken = "old", TokenType = "Bearer", ExpiresIn = 3600 }),
            _ => new HttpResponseMessage(HttpStatusCode.Conflict) { Content = new StringContent("{\"code\":\"idempotency_conflict\"}") }
        });
        var error = await Assert.ThrowsAsync<TransportException>(() => w.Relocation.ExecuteAsync(default));
        Assert.Equal(HttpStatusCode.Conflict, error.Status);
        Assert.Equal(Devkeep, w.Relocation.CurrentApi);
    }

    [Fact]
    public void StartupPrefersApiBaseJsonWhileInstalledBaseMatches()
    {
        using var directory = new TestDirectory();
        Assert.Equal((Devkeep, 0L), ServerRelocation.StartupBase(directory.Root, Devkeep.AbsoluteUri));
        new ApiBaseOverride(Keep.AbsoluteUri, Devkeep.AbsoluteUri, 4, Guid.NewGuid(), DateTimeOffset.UnixEpoch).Write(directory.Root);
        Assert.Equal((Keep, 4L), ServerRelocation.StartupBase(directory.Root, Devkeep.AbsoluteUri));
        // Reinstalado contra otro servidor: manda la variable.
        Assert.Equal((new Uri("https://otro.azclegal.com/v1/"), 0L), ServerRelocation.StartupBase(directory.Root, "https://otro.azclegal.com/v1/"));
        Assert.Equal((null, 0L), ServerRelocation.StartupBase(directory.Root, null));
        Assert.Throws<InvalidOperationException>(() => ServerRelocation.StartupBase(directory.Root, "http://devkeep.azclegal.com/v1/"));
        // Archivo con destino fuera de la lista o danado: se ignora.
        new ApiBaseOverride("https://evil.example.com/v1/", Devkeep.AbsoluteUri, 5, Guid.NewGuid(), DateTimeOffset.UnixEpoch).Write(directory.Root);
        Assert.Equal((Devkeep, 0L), ServerRelocation.StartupBase(directory.Root, Devkeep.AbsoluteUri));
        File.WriteAllText(directory.File(ApiBaseOverride.FileName), "{not json");
        Assert.Equal((Devkeep, 0L), ServerRelocation.StartupBase(directory.Root, Devkeep.AbsoluteUri));
        Assert.Equal(Devkeep.AbsoluteUri, ApiBaseOverride.Resolve(directory.Root, Devkeep.AbsoluteUri));
    }

    [Fact]
    public async Task RelocatedAgentRejectsReplayOfSameOrder()
    {
        await using var w = new World(lastSequence: 1);
        var p = w.Payload(sequence: 1);
        var error = Assert.Throws<RelocationRejectedException>(() =>
            RelocationVerifier.Verify(w.Sign(p), w.Keys, Devkeep, Samples.Tenant, Samples.Device, 1, w.Clock.GetUtcNow()));
        Assert.Equal("stale_sequence", error.Reason);
        // Ya en el destino, la misma orden ya no coincide con la base actual.
        error = Assert.Throws<RelocationRejectedException>(() =>
            RelocationVerifier.Verify(w.Sign(w.Payload(sequence: 9)), w.Keys, Keep, Samples.Tenant, Samples.Device, 1, w.Clock.GetUtcNow()));
        Assert.Equal("from_base_mismatch", error.Reason);
        await Task.CompletedTask;
    }

    [Fact]
    public void FailureReasonsAreShortAndAcceptedByTheServer()
    {
        foreach (var reason in new[]
        {
            ServerRelocation.FailureReason(new TransportException(HttpStatusCode.ServiceUnavailable, null)),
            ServerRelocation.FailureReason(new TaskCanceledException()),
            ServerRelocation.FailureReason(new HttpRequestException("x")),
            ServerRelocation.FailureReason(new InvalidDataException("invalid_device_token")),
            ServerRelocation.FailureReason(new DeviceRelocatedException("x"))
        })
            Assert.Matches("^[a-z][a-z0-9_]{0,40}$", reason);
    }

    private static JsonObject With(JsonObject payload, string name, JsonNode? value)
    {
        var copy = JsonNode.Parse(payload.ToJsonString())!.AsObject();
        copy[name] = value;
        return copy;
    }

    private static string Tamper(string jws)
    {
        var parts = jws.Split('.');
        var payload = JsonNode.Parse(Encoding.UTF8.GetString(Convert.FromBase64String(parts[1].Replace('-', '+').Replace('_', '/') + new string('=', (4 - parts[1].Length % 4) % 4))))!.AsObject();
        payload["to_base"] = "https://evil.azclegal.com/";
        return parts[0] + "." + B64(Encoding.UTF8.GetBytes(payload.ToJsonString())) + "." + parts[2];
    }

    private static string B64(byte[] bytes) => Convert.ToBase64String(bytes).TrimEnd('=').Replace('+', '-').Replace('/', '_');

    private static HttpResponseMessage Json<T>(T value) => new(HttpStatusCode.OK)
    {
        Content = new ByteArrayContent(JsonSerializer.SerializeToUtf8Bytes(value, ProtocolJson.Options))
    };

    private sealed class Stub(Func<HttpRequestMessage, Task<HttpResponseMessage>> respond) : HttpMessageHandler
    {
        protected override Task<HttpResponseMessage> SendAsync(HttpRequestMessage request, CancellationToken cancellationToken) => respond(request);
    }

    private sealed class MemoryTokenStore : IDeviceTokenStore
    {
        private StoredDeviceToken? _value;
        public Task<StoredDeviceToken?> LoadAsync(CancellationToken ct) => Task.FromResult(_value);
        public Task SaveAsync(StoredDeviceToken token, CancellationToken ct) { _value = token; return Task.CompletedTask; }
        public Task DeleteAsync(CancellationToken ct) { _value = null; return Task.CompletedTask; }
    }
}
