using System.Net;
using System.Net.Http.Headers;
using System.Security.Cryptography;
using System.Text.Json;
using System.Text.Json.Nodes;
using Keeper.Agent.Hosting;
using Keeper.Agent.Policy;
using Keeper.Agent.Storage;
using Keeper.Agent.Transport;
using Keeper.Shared.Contracts;
using Keeper.Shared.Protocol;

try
{
    var directory = args[1];
    if (args[0] == "keygen")
    {
        using var generated = ECDsa.Create(ECCurve.NamedCurves.nistP256);
        using var identity = new HttpMessageSigner(generated);
        await File.WriteAllTextAsync(Path.Combine(directory, "device-private.pem"), generated.ExportPkcs8PrivateKeyPem());
        await File.WriteAllTextAsync(Path.Combine(directory, "public.json"), JsonSerializer.Serialize(
            new { public_key = identity.PublicKey, keyid = identity.KeyId }, ProtocolJson.Options));
        return 0;
    }
    using var timeout = new CancellationTokenSource(TimeSpan.FromSeconds(90));
    var ct = timeout.Token;
    var fixture = JsonNode.Parse(await File.ReadAllTextAsync(Path.Combine(directory, "fixture.json"), ct))!;
    var device = Guid.Parse(fixture["device_id"]!.GetValue<string>());
    var tenant = Guid.Parse(fixture["tenant_id"]!.GetValue<string>());
    var expectedVersion = fixture["policy_version"]!.GetValue<long>();
    var port = int.Parse(args[2]);
    using var key = ECDsa.Create();
    key.ImportFromPem(await File.ReadAllTextAsync(Path.Combine(directory, "device-private.pem"), ct));
    using var signer = new HttpMessageSigner(key);
    Check(signer.KeyId == fixture["keyid"]!.GetValue<string>(), "JWK thumbprint C# = PHP / device_keys");

    using var network = new HttpClientHandler { UseProxy = false, AllowAutoRedirect = false };
    using var proxy = new LoopbackProxyHandler(network, port);
    using var observer = new WireObserver(proxy, args.Contains("--corrupt-login"));
    using var http = new HttpClient(observer) { BaseAddress = new Uri($"https://127.0.0.1:{port}/v1/"), Timeout = TimeSpan.FromSeconds(20) };
    using var outbox = new DurableOutbox(Path.Combine(directory, "outbox.json"));
    var store = new SignedFilePolicyStore(Path.Combine(directory, "policy-cache.json"), key);
    await using var host = new ModuleHost([], new ModuleContext(outbox, TimeProvider.System, Console.WriteLine, ct));
    await host.InitAsync();
    var policy = new PolicyCoordinator(store, host, device);
    var client = new SyncClient(http, signer, device, outbox, policy, TimeProvider.System);

    await client.ExecuteAsync(ct);
    Check(observer.Calls.Count == 3 && observer.Calls[0].Path == "/v1/client/auth/challenges" &&
        observer.Calls[1].Path == "/v1/client/login" && observer.Calls[2].Path == "/v1/client/sync",
        "real challenge -> login -> sync (no policy fallback)");
    var challenge = JsonNode.Parse(observer.Calls[0].Response)!;
    var login = observer.Calls[1];
    Check(login.Status == 200 && login.SignatureInput.Contains($"nonce=\"{challenge["nonce"]!.GetValue<string>()}\"", StringComparison.Ordinal),
        "PHP accepts C# RFC 9421 login signature with server challenge");
    var token = JsonSerializer.Deserialize<DeviceToken>(login.Response, ProtocolJson.Options)!;
    Check(token.TokenType == "Bearer" && !string.IsNullOrWhiteSpace(token.AccessToken) && token.ExpiresIn > 0 &&
        token.DeviceId == device && token.TenantId == tenant, "login returns bound device_token");
    var first = JsonNode.Parse(observer.Calls[2].Response)!;
    Check(first["policy_version"]!.GetValue<long>() == expectedVersion &&
        JsonNode.DeepEquals(first["policy"], fixture["policy"]) && policy.CurrentVersion == expectedVersion,
        "first sync delivers exact compiled policy and applies it");
    Check((await store.LoadAsync(ct))?.PolicyVersion == expectedVersion, "real signed policy cache persists policy");

    await client.ExecuteAsync(ct);
    Check(observer.Calls.Count == 4 && client.RequestCount == 4, "second sync reuses token without login/fallback");
    var second = observer.Calls[3];
    var sent = JsonNode.Parse(second.Request)!;
    var received = JsonNode.Parse(second.Response)!.AsObject();
    Check(sent["policy_version"]!.GetValue<long>() == expectedVersion && received.ContainsKey("policy") &&
        received["policy"] is null && received["policy_version"]!.GetValue<long>() == expectedVersion &&
        policy.CurrentVersion == expectedVersion, "second sync sends same policy_version and receives policy=null");

    var now = DateTimeOffset.FromUnixTimeSeconds(DateTimeOffset.UtcNow.ToUnixTimeSeconds());
    var episode = new Episode { EventId = Guid.NewGuid(), StartedAt = now.AddMinutes(-2), EndedAt = now.AddMinutes(-1),
        ProcessName = "integration.exe", ActiveSeconds = 50, IdleSeconds = 10 };
    var body = JsonSerializer.SerializeToUtf8Bytes(new { episodes = new[] { episode } }, ProtocolJson.Options);
    var idempotency = Guid.NewGuid();
    async Task<string> Batch(Guid id, bool corrupt = false)
    {
        using var request = new HttpRequestMessage(HttpMethod.Post, new Uri(http.BaseAddress!, "client/episodes:batch"));
        request.Content = new ByteArrayContent(body);
        request.Content.Headers.ContentType = new MediaTypeHeaderValue("application/json");
        request.Headers.Authorization = new AuthenticationHeaderValue("Bearer", token.AccessToken);
        request.Headers.Add("Idempotency-Key", id.ToString());
        signer.Sign(request, body, DateTimeOffset.UtcNow, HttpMessageSigner.CreateNonce());
        if (corrupt) WireObserver.CorruptSignature(request);
        using var response = await http.SendAsync(request, ct);
        var text = await response.Content.ReadAsStringAsync(ct);
        Check(corrupt ? response.StatusCode == HttpStatusCode.Unauthorized && JsonNode.Parse(text)?["code"]?.GetValue<string>() == "invalid_signature"
            : response.StatusCode == HttpStatusCode.OK, corrupt ? "negative control: corrupted signature rejected with 401 invalid_signature"
            : "PHP accepts C# authenticated batch signature (HTTP 200)");
        return text;
    }
    void Ack(string response, string expected)
    {
        var acks = JsonNode.Parse(response)!["acks"]!.AsArray();
        Check(acks.Count == 1 && acks[0]!["event_id"]!.GetValue<string>() == episode.EventId.ToString() &&
            acks[0]!["status"]!.GetValue<string>() == expected && !acks[0]!["retryable"]!.GetValue<bool>(), $"episode ACK={expected}");
    }
    var accepted = await Batch(idempotency);
    Ack(accepted, "accepted");
    var replay = await Batch(idempotency);
    Check(accepted == replay, "same Idempotency-Key replays exact response bytes");
    Ack(await Batch(Guid.NewGuid()), "duplicate");
    await Batch(Guid.NewGuid(), corrupt: true);
    await File.WriteAllTextAsync(Path.Combine(directory, "result.json"), JsonSerializer.Serialize(new
        { tenant_id = tenant, device_id = device, event_id = episode.EventId }, ProtocolJson.Options), ct);
    Console.WriteLine("PASS C# live interoperability assertions");
    return 0;
}
catch (Exception error)
{
    Console.Error.WriteLine($"FAIL {error.GetType().Name}: {error.Message}; cause={error.GetBaseException().Message}");
    return 1;
}

static void Check(bool condition, string name)
{
    if (!condition) throw new InvalidOperationException(name);
    Console.WriteLine("PASS " + name);
}

internal sealed record WireCall(string Path, int Status, string Request, string Response, string SignatureInput);

// Test-only reverse-proxy hop: preserve the signed external authority and every signed header/body byte.
// PHP derives @target-uri from KEEPER_ORIGIN, just as it does behind production TLS termination.
internal sealed class LoopbackProxyHandler(HttpMessageHandler network, int port) : DelegatingHandler(network)
{
    protected override async Task<HttpResponseMessage> SendAsync(HttpRequestMessage request, CancellationToken ct)
    {
        var external = request.RequestUri!;
        if (external.Scheme != "https" || external.Host != "127.0.0.1" || external.Port != port)
            throw new InvalidOperationException("Only the isolated loopback backend may be forwarded");
        request.Headers.Host = external.Authority;
        request.Headers.Add("X-Forwarded-Proto", "https");
        request.RequestUri = new UriBuilder(external) { Scheme = "http", Port = port }.Uri;
        try { return await base.SendAsync(request, ct); }
        finally { request.RequestUri = external; }
    }
}

// Observes real HTTP exchanges; never fabricates a response or replaces the network transport.
internal sealed class WireObserver(HttpMessageHandler network, bool corruptLogin) : DelegatingHandler(network)
{
    public List<WireCall> Calls { get; } = [];
    protected override async Task<HttpResponseMessage> SendAsync(HttpRequestMessage request, CancellationToken ct)
    {
        if (corruptLogin && request.RequestUri!.AbsolutePath == "/v1/client/login") CorruptSignature(request);
        var response = await base.SendAsync(request, ct);
        var body = await response.Content.ReadAsStringAsync(ct);
        Calls.Add(new WireCall(request.RequestUri!.AbsolutePath, (int)response.StatusCode,
            request.Content is null ? "" : await request.Content.ReadAsStringAsync(ct), body,
            request.Headers.TryGetValues("Signature-Input", out var values) ? values.Single() : ""));
        if (!response.IsSuccessStatusCode)
        {
            var problem = JsonNode.Parse(body);
            Console.Error.WriteLine($"HTTP {(int)response.StatusCode} {request.RequestUri.AbsolutePath}: code={problem?["code"]}; request_id={problem?["request_id"]}");
        }
        return response;
    }

    public static void CorruptSignature(HttpRequestMessage request)
    {
        var value = request.Headers.GetValues("Signature").Single();
        var raw = Convert.FromBase64String(value[6..^1]);
        raw[0] ^= 1;
        request.Headers.Remove("Signature");
        request.Headers.Add("Signature", "sig1=:" + Convert.ToBase64String(raw) + ":");
    }
}
