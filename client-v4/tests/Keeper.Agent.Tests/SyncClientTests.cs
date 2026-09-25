using System.Net;
using System.Security.Cryptography;
using System.Text.Json;
using Keeper.Agent.Hosting;
using Keeper.Agent.Modules.Devices;
using Keeper.Agent.Modules.Security;
using Keeper.Agent.Modules.Update;
using Keeper.Agent.Policy;
using Keeper.Agent.Storage;
using Keeper.Agent.Transport;
using Keeper.Shared.Protocol;

namespace Keeper.Agent.Tests;

public sealed class SyncClientTests
{
    [Theory]
    [InlineData(null)]
    [InlineData("one-use-ticket")]
    public async Task EnrollmentOrDeviceLoginThenTokenSurvivesRefreshAndRestart(string? ticket)
    {
        using var directory = new TestDirectory();
        using var outbox = new DurableOutbox(directory.File("outbox"));
        using var signer = new HttpMessageSigner(ECDsa.Create(ECCurve.NamedCurves.nistP256));
        var clock = new TestClock();
        await using var host = new ModuleHost([], Samples.Context(clock));
        var policy = new PolicyCoordinator(new MemoryPolicyStore(), host, Samples.Device);
        var store = new MemoryTokenStore();
        var logins = 0;
        var challenges = 0;
        var syncs = 0;
        using var handler = new StubHandler(async request =>
        {
            using var body = JsonDocument.Parse(await request.Content!.ReadAsStringAsync());
            var root = body.RootElement;
            if (request.RequestUri!.AbsolutePath == "/v1/client/sync")
            {
                if (syncs == 0) Assert.Equal(0, store.Saves);
                syncs++;
                Assert.Equal("Bearer", request.Headers.Authorization!.Scheme);
                Assert.Equal(Token().AccessToken, request.Headers.Authorization.Parameter);
                Assert.False(root.TryGetProperty("enrollment_ticket", out _));
                Assert.True(request.Headers.Contains("Signature"));
                return Json(Response(clock) with { Policy = Samples.Policy() });
            }
            Assert.Null(request.Headers.Authorization);
            var expectedTicket = logins == 0 ? ticket : null;
            if (expectedTicket is not null)
            {
                Assert.Equal(expectedTicket, root.GetProperty("enrollment_ticket").GetString());
                Assert.False(root.TryGetProperty("device_id", out _));
            }
            else
            {
                Assert.Equal(Samples.Device, root.GetProperty("device_id").GetGuid());
                Assert.False(root.TryGetProperty("enrollment_ticket", out _));
            }
            if (request.RequestUri.AbsolutePath == "/v1/client/auth/challenges")
            {
                challenges++;
                Assert.False(request.Headers.Contains("Signature"));
                return Json(new Challenge { Nonce = "enrollment-nonce", ExpiresAt = clock.GetUtcNow().AddMinutes(1) });
            }
            Assert.Equal("/v1/client/login", request.RequestUri.AbsolutePath);
            Assert.Equal(signer.PublicKey, root.GetProperty("public_key").Deserialize<PublicKey>(ProtocolJson.Options));
            Assert.Contains("nonce=\"enrollment-nonce\"", request.Headers.GetValues("Signature-Input").Single());
            Assert.True(request.Headers.Contains("Signature"));
            logins++;
            return Json(Token());
        });
        using var http = new HttpClient(handler) { BaseAddress = new Uri("https://keeper.test/v1/") };
        SyncClient Client() => new(http, signer, Samples.Device, outbox, policy, clock, ticket, store);
        var client = Client();
        await client.ExecuteAsync(default);
        Assert.Equal(Token(), store.Value!.Token);
        await client.ExecuteAsync(default);
        client = Client();
        await client.ExecuteAsync(default);
        Assert.Equal(1, logins);
        clock.Advance(TimeSpan.FromHours(2));
        client = Client();
        await client.ExecuteAsync(default);
        Assert.Equal(2, logins);
        Assert.Equal(2, challenges);
        Assert.Equal(4, syncs);
    }

    [Theory]
    [InlineData(false, HttpStatusCode.Unauthorized)]
    [InlineData(true, HttpStatusCode.Unauthorized)]
    [InlineData(true, HttpStatusCode.Conflict)]
    [InlineData(false, HttpStatusCode.UnprocessableEntity)]
    public async Task RejectedEnrollmentStopsNetworkRetriesAndPreservesOutbox(bool rejectLogin, HttpStatusCode status)
    {
        using var directory = new TestDirectory();
        using var outbox = new DurableOutbox(directory.File("outbox"));
        await outbox.EnqueueAsync(Samples.Episode(), default);
        using var signer = new HttpMessageSigner(ECDsa.Create(ECCurve.NamedCurves.nistP256));
        var clock = new TestClock();
        await using var host = new ModuleHost([], Samples.Context(clock));
        var policy = new PolicyCoordinator(new MemoryPolicyStore(), host, Samples.Device);
        var store = new MemoryTokenStore();
        using var handler = new StubHandler(request => Task.FromResult(
            rejectLogin && request.RequestUri!.AbsolutePath.EndsWith("challenges", StringComparison.Ordinal)
                ? Json(new Challenge { Nonce = "nonce", ExpiresAt = clock.GetUtcNow().AddMinutes(1) })
                : new HttpResponseMessage(status)));
        using var http = new HttpClient(handler) { BaseAddress = new Uri("https://keeper.test/v1/") };
        const string ticket = "used-or-expired-secret-ticket";
        var client = new SyncClient(http, signer, Samples.Device, outbox, policy, clock, ticket, store);
        for (var attempt = 0; attempt < 5; attempt++)
        {
            var error = await Assert.ThrowsAsync<EnrollmentException>(() => client.ExecuteAsync(default));
            Assert.Contains("ticket invalid, expired, already used", error.Message);
            Assert.Contains("restart", error.Message);
            Assert.DoesNotContain(ticket, error.Message);
            Assert.Equal(NetworkFailureKind.Authorization, NetworkBackoffPolicy.Classify(error));
            clock.Advance(TimeSpan.FromHours(1));
        }
        Assert.Equal(rejectLogin ? 2 : 1, client.RequestCount);
        Assert.Null(store.Value);
        Assert.Single((await outbox.InspectAsync()).Events);
    }

    [Fact]
    public async Task TransientEnrollmentRateLimitKeepsTicketAndDoesNotRetryInline()
    {
        using var directory = new TestDirectory();
        using var outbox = new DurableOutbox(directory.File("outbox"));
        using var signer = new HttpMessageSigner(ECDsa.Create(ECCurve.NamedCurves.nistP256));
        var clock = new TestClock();
        await using var host = new ModuleHost([], Samples.Context(clock));
        var policy = new PolicyCoordinator(new MemoryPolicyStore(), host, Samples.Device);
        using var handler = new StubHandler(async request =>
        {
            using var body = JsonDocument.Parse(await request.Content!.ReadAsStringAsync());
            Assert.Equal("ticket", body.RootElement.GetProperty("enrollment_ticket").GetString());
            var response = new HttpResponseMessage(HttpStatusCode.TooManyRequests);
            response.Headers.RetryAfter = new System.Net.Http.Headers.RetryConditionHeaderValue(TimeSpan.FromMinutes(10));
            return response;
        });
        using var http = new HttpClient(handler) { BaseAddress = new Uri("https://keeper.test/v1/") };
        var client = new SyncClient(http, signer, Samples.Device, outbox, policy, clock, "ticket");
        for (var attempt = 1; attempt <= 2; attempt++)
        {
            var error = await Assert.ThrowsAsync<TransportException>(() => client.ExecuteAsync(default));
            Assert.Equal(TimeSpan.FromMinutes(10), error.RetryAfter);
            Assert.Equal(attempt, client.RequestCount);
        }
    }

    private sealed class MemoryTokenStore : IDeviceTokenStore
    {
        public StoredDeviceToken? Value { get; set; }
        public Exception? LoadFailure { get; init; }
        public int Loads { get; private set; }
        public int Saves { get; private set; }
        public int Deletes { get; private set; }
        public Task<StoredDeviceToken?> LoadAsync(CancellationToken ct)
        {
            Loads++;
            return LoadFailure is null ? Task.FromResult(Value) : Task.FromException<StoredDeviceToken?>(LoadFailure);
        }
        public Task SaveAsync(StoredDeviceToken token, CancellationToken ct) { Value = token; Saves++; return Task.CompletedTask; }
        public Task DeleteAsync(CancellationToken ct) { Value = null; Deletes++; return Task.CompletedTask; }
    }

    [Theory]
    [InlineData("dpapi")]
    [InlineData("json")]
    [InlineData("invalid-data")]
    [InlineData("format")]
    [InlineData("device")]
    [InlineData("tenant")]
    [InlineData("type")]
    [InlineData("expiry")]
    [InlineData("empty")]
    [InlineData("oversized")]
    [InlineData("missing-token")]
    [InlineData("overflow")]
    public async Task CorruptStoredTokenIsDiscardedOnceAndFreshEnrollmentCanRetry(string corruption)
    {
        using var directory = new TestDirectory();
        using var outbox = new DurableOutbox(directory.File("outbox"));
        using var signer = new HttpMessageSigner(ECDsa.Create(ECCurve.NamedCurves.nistP256));
        var clock = new TestClock();
        await using var host = new ModuleHost([], Samples.Context(clock));
        var policy = new PolicyCoordinator(new MemoryPolicyStore(), host, Samples.Device);
        var storedToken = corruption switch
        {
            "device" => Token() with { DeviceId = Guid.NewGuid() },
            "tenant" => Token() with { TenantId = Guid.Empty },
            "type" => Token() with { TokenType = "Basic" },
            "expiry" => Token() with { ExpiresIn = 0 },
            "empty" => Token() with { AccessToken = " " },
            "oversized" => Token() with { AccessToken = new string('a', 16385) },
            "missing-token" => null!,
            _ => Token()
        };
        var store = new MemoryTokenStore
        {
            Value = new StoredDeviceToken(storedToken, corruption == "overflow" ? DateTimeOffset.MaxValue : clock.GetUtcNow()),
            LoadFailure = corruption switch
            {
                "dpapi" => new CryptographicException("fake DPAPI failure"),
                "json" => new JsonException("fake malformed JSON"),
                "invalid-data" => new InvalidDataException("fake invalid payload"),
                "format" => new FormatException("fake invalid format"),
                _ => null
            }
        };
        var challenges = 0;
        var logins = 0;
        using var handler = new StubHandler(async request =>
        {
            if (request.RequestUri!.AbsolutePath == "/v1/client/sync")
                return Json(Response(clock) with { Policy = Samples.Policy() });
            Assert.Null(store.Value);
            Assert.Equal(1, store.Deletes);
            using var body = JsonDocument.Parse(await request.Content!.ReadAsStringAsync());
            Assert.Equal("fresh-ticket", body.RootElement.GetProperty("enrollment_ticket").GetString());
            if (request.RequestUri.AbsolutePath == "/v1/client/auth/challenges")
            {
                if (++challenges == 1) return new HttpResponseMessage(HttpStatusCode.ServiceUnavailable);
                return Json(new Challenge { Nonce = "nonce", ExpiresAt = clock.GetUtcNow().AddMinutes(1) });
            }
            Assert.Equal("/v1/client/login", request.RequestUri.AbsolutePath);
            logins++;
            return Json(Token());
        });
        using var http = new HttpClient(handler) { BaseAddress = new Uri("https://keeper.test/v1/") };
        var client = new SyncClient(http, signer, Samples.Device, outbox, policy, clock, "fresh-ticket", store);

        var error = await Assert.ThrowsAsync<TransportException>(() => client.ExecuteAsync(default));
        Assert.Equal(HttpStatusCode.ServiceUnavailable, error.Status);
        Assert.Equal(0, store.Saves);
        Assert.Equal(120, await client.ExecuteAsync(default));
        Assert.Equal(120, await client.ExecuteAsync(default));
        Assert.Equal(1, store.Loads);
        Assert.Equal(1, store.Deletes);
        Assert.Equal(1, logins);
        Assert.Equal(Token(), store.Value!.Token);
    }

    [Theory]
    [InlineData(false, false)]
    [InlineData(false, true)]
    [InlineData(true, false)]
    [InlineData(true, true)]
    public async Task UnauthorizedSyncDeletesTokenAndRecoversWithFreshLogin(bool persisted, bool restart)
    {
        using var directory = new TestDirectory();
        using var outbox = new DurableOutbox(directory.File("outbox"));
        await outbox.EnqueueAsync(Samples.Episode(), default);
        using var signer = new HttpMessageSigner(ECDsa.Create(ECCurve.NamedCurves.nistP256));
        var clock = new TestClock();
        await using var host = new ModuleHost([], Samples.Context(clock));
        var policy = new PolicyCoordinator(new MemoryPolicyStore(), host, Samples.Device);
        var rejected = Token() with { AccessToken = "rejected" };
        var store = new MemoryTokenStore { Value = persisted ? new StoredDeviceToken(rejected, clock.GetUtcNow()) : null };
        var syncs = 0;
        var logins = 0;
        using var handler = new StubHandler(request =>
        {
            switch (request.RequestUri!.AbsolutePath)
            {
                case "/v1/client/auth/challenges":
                    Assert.Null(store.Value);
                    return Task.FromResult(Json(new Challenge { Nonce = "nonce", ExpiresAt = clock.GetUtcNow().AddMinutes(1) }));
                case "/v1/client/login":
                    logins++;
                    return Task.FromResult(Json(syncs == 0 ? rejected : Token()));
                case "/v1/client/sync":
                    Assert.Equal(0, store.Saves);
                    Assert.Equal(syncs == 0 ? rejected.AccessToken : Token().AccessToken, request.Headers.Authorization!.Parameter);
                    return Task.FromResult(++syncs == 1 ? new HttpResponseMessage(HttpStatusCode.Unauthorized)
                        : Json(Response(clock) with { Policy = Samples.Policy() }));
                default: throw new InvalidOperationException("Unexpected HTTP request");
            }
        });
        using var http = new HttpClient(handler) { BaseAddress = new Uri("https://keeper.test/v1/") };
        SyncClient Client() => new(http, signer, Samples.Device, outbox, policy, clock, tokenStore: store);
        var client = Client();

        var error = await Assert.ThrowsAsync<TransportException>(() => client.ExecuteAsync(default));
        Assert.Equal(HttpStatusCode.Unauthorized, error.Status);
        Assert.Null(store.Value);
        Assert.Equal(1, store.Deletes);
        Assert.Single((await outbox.InspectAsync()).Events);
        if (restart) client = Client();
        Assert.Equal(120, await client.ExecuteAsync(default));
        Assert.Equal(restart ? 2 : 1, store.Loads);
        Assert.Equal(persisted ? 1 : 2, logins);
        Assert.Equal(Token(), store.Value!.Token);
    }

    [Fact]
    public async Task LoginAndFailedSyncsNeverPersistTokenButAcceptedRotationSurvivesRestart()
    {
        using var directory = new TestDirectory();
        using var outbox = new DurableOutbox(directory.File("outbox"));
        using var signer = new HttpMessageSigner(ECDsa.Create(ECCurve.NamedCurves.nistP256));
        var clock = new TestClock();
        await using var host = new ModuleHost([], Samples.Context(clock));
        var policy = new PolicyCoordinator(new MemoryPolicyStore(), host, Samples.Device);
        var store = new MemoryTokenStore();
        var rotated = Token() with { AccessToken = "rotated" };
        var syncs = 0;
        var logins = 0;
        using var handler = new StubHandler(request =>
        {
            switch (request.RequestUri!.AbsolutePath)
            {
                case "/v1/client/auth/challenges":
                    return Task.FromResult(Json(new Challenge { Nonce = "nonce", ExpiresAt = clock.GetUtcNow().AddMinutes(1) }));
                case "/v1/client/login":
                    logins++;
                    return Task.FromResult(Json(Token()));
                case "/v1/client/sync":
                    Assert.Equal(syncs < 3 ? Token().AccessToken : rotated.AccessToken, request.Headers.Authorization!.Parameter);
                    if (++syncs <= 3) Assert.Equal(0, store.Saves);
                    return Task.FromResult(syncs switch
                    {
                        1 => new HttpResponseMessage(HttpStatusCode.ServiceUnavailable),
                        2 => Json(Response(clock) with { ProtocolVersion = 2 }),
                        _ => Json(Response(clock) with { Token = rotated, Policy = Samples.Policy() })
                    });
                default: throw new InvalidOperationException("Unexpected HTTP request");
            }
        });
        using var http = new HttpClient(handler) { BaseAddress = new Uri("https://keeper.test/v1/") };
        SyncClient Client() => new(http, signer, Samples.Device, outbox, policy, clock, tokenStore: store);
        var client = Client();

        await Assert.ThrowsAsync<TransportException>(() => client.ExecuteAsync(default));
        Assert.Null(store.Value);
        await Assert.ThrowsAsync<InvalidDataException>(() => client.ExecuteAsync(default));
        Assert.Null(store.Value);
        Assert.Equal(120, await client.ExecuteAsync(default));
        Assert.Equal(1, store.Saves);
        Assert.Equal(rotated, store.Value!.Token);
        Assert.Equal(clock.GetUtcNow(), store.Value.IssuedAt);
        Assert.Equal(120, await Client().ExecuteAsync(default));
        Assert.Equal(1, logins);
    }

    [Theory]
    [InlineData(false, false)]
    [InlineData(false, true)]
    [InlineData(true, false)]
    [InlineData(true, true)]
    public async Task NewTenantReplacesStoredTokenAndCachedPolicy(bool rotateInSync, bool fetchPolicy)
    {
        using var directory = new TestDirectory();
        using var outbox = new DurableOutbox(directory.File("outbox"));
        using var signer = new HttpMessageSigner(ECDsa.Create(ECCurve.NamedCurves.nistP256));
        var clock = new TestClock();
        var module = new TestModule();
        await using var host = new ModuleHost([module], Samples.Context(clock));
        var policy = new PolicyCoordinator(new MemoryPolicyStore(), host, Samples.Device);
        await policy.ApplyAsync(5, Samples.Policy(), default);
        var old = new StoredDeviceToken(Token(), clock.GetUtcNow().AddHours(rotateInSync ? 0 : -2));
        var store = new MemoryTokenStore { Value = old };
        var replacement = Token() with { TenantId = Guid.NewGuid(), AccessToken = "new-tenant-token" };
        var replacementPolicy = Samples.Policy() with { TenantId = replacement.TenantId };
        var syncs = 0;
        var logins = 0;
        using var handler = new StubHandler(request =>
        {
            switch (request.RequestUri!.AbsolutePath)
            {
                case "/v1/client/auth/challenges":
                    return Task.FromResult(Json(new Challenge { Nonce = "nonce", ExpiresAt = clock.GetUtcNow().AddMinutes(1) }));
                case "/v1/client/login":
                    logins++;
                    return Task.FromResult(Json(replacement));
                case "/v1/client/sync":
                    Assert.Equal(rotateInSync && syncs == 0 ? Token().AccessToken : replacement.AccessToken,
                        request.Headers.Authorization!.Parameter);
                    if (++syncs == 1) Assert.Equal(old, store.Value);
                    return Task.FromResult(Json(Response(clock) with
                    {
                        Token = rotateInSync ? replacement : null,
                        Policy = fetchPolicy ? null : replacementPolicy
                    }));
                case "/v1/client/policy":
                    Assert.False(request.Headers.Contains("If-None-Match"));
                    Assert.Equal(replacement.AccessToken, request.Headers.Authorization!.Parameter);
                    return Task.FromResult(Json(replacementPolicy));
                default: throw new InvalidOperationException("Unexpected HTTP request");
            }
        });
        using var http = new HttpClient(handler) { BaseAddress = new Uri("https://keeper.test/v1/") };
        Guid? observedTenant = null;
        SyncClient Client() => new(http, signer, Samples.Device, outbox, policy, clock, tokenStore: store)
        {
            OnResponse = (_, tenant, _) => { observedTenant = tenant; return Task.CompletedTask; }
        };

        Assert.Equal(120, await Client().ExecuteAsync(default));
        Assert.Equal(replacement, store.Value!.Token);
        Assert.Equal(replacement.TenantId, policy.Current!.TenantId);
        Assert.Equal(1L, policy.CurrentVersion);
        Assert.Equal(replacement.TenantId, observedTenant);
        Assert.Equal(2, module.Applications);
        Assert.Equal(120, await Client().ExecuteAsync(default));
        Assert.Equal(rotateInSync ? 0 : 1, logins);
    }

    [Fact]
    public async Task InvalidCommandDoesNotAbortSyncOrPreventUpdateOffer()
    {
        using var directory = new TestDirectory();
        using var outbox = new DurableOutbox(directory.File("outbox"));
        using var signer = new HttpMessageSigner(ECDsa.Create(ECCurve.NamedCurves.nistP256));
        var clock = new TestClock();
        var context = new Keeper.Shared.Contracts.ModuleContext(outbox, clock, _ => { }, default);
        var deviceLock = new DeviceLock(directory.File("lock"));
        var executor = new CommandExecutor(directory.File("inbox"), Samples.Device, deviceLock, new WindowsDeviceActions(false));
        var updater = new UpdateManager(directory.Root, new Dictionary<string, ECDsa>());
        await using var host = new ModuleHost([deviceLock, executor, updater], context);
        await host.InitAsync();
        var coordinator = new PolicyCoordinator(new MemoryPolicyStore(), host, Samples.Device);
        var good = CommandRegressionTests.Command(clock) with { Type = CommandType.Lock };
        var bad = CommandRegressionTests.Command(clock) with { DeviceId = Guid.NewGuid() };
        var release = new Release
        {
            Id = Guid.NewGuid(), Version = "4.0.1", Channel = "stable", Sequence = 2, MinAgentVersion = "4.0.0",
            Architecture = ReleaseArchitecture.X64, ArtifactUrl = "https://example.test/package.msi", SizeBytes = 1,
            Sha256 = new string('0', 64), KeyId = "untrusted", ManifestJws = "invalid", PublishedAt = clock.GetUtcNow()
        };
        using var handler = new StubHandler(request => Task.FromResult(request.RequestUri!.AbsolutePath switch
        {
            "/v1/client/auth/challenges" => Json(new Challenge { Nonce = "challenge", ExpiresAt = clock.GetUtcNow().AddMinutes(1) }),
            "/v1/client/login" => Json(Token()),
            "/v1/client/sync" => Json(Response(clock) with { Policy = Samples.Policy(), Commands = [bad, good], Release = release }),
            _ => throw new InvalidOperationException("Unexpected HTTP request")
        }));
        using var http = new HttpClient(handler) { BaseAddress = new Uri("https://keeper.test/v1/") };
        var client = new SyncClient(http, signer, Samples.Device, outbox, coordinator, clock)
        {
            OnResponse = async (response, tenant, ct) =>
            {
                await executor.AcceptAsync(response.Commands, tenant, ct);
                updater.Offer(response.Release);
            }
        };
        Assert.Equal(120, await client.ExecuteAsync(default));
        await host.TickAsync(default);
        Assert.True(deviceLock.Locked);
        Assert.Equal("unsupported", updater.Snapshot().State);
        Assert.Contains((await outbox.InspectAsync()).Events, e => e.Command?.CommandId == bad.Id && e.Command.Result.Status == CommandResultStatus.Failed);
        Assert.Contains((await outbox.InspectAsync()).Events, e => e.Command?.CommandId == good.Id && e.Command.Result.Status == CommandResultStatus.Succeeded);
    }

    [Fact]
    public async Task LoginThenSyncRecoversMissingPolicyOnceAndEqualVersionDoesNotFetchOrApply()
    {
        using var directory = new TestDirectory();
        using var key = ECDsa.Create(ECCurve.NamedCurves.nistP256);
        using var outbox = new DurableOutbox(directory.File("outbox.json"));
        var clock = new TestClock();
        var module = new TestModule();
        await using var host = new ModuleHost([module], Samples.Context(clock));
        var policy = new PolicyCoordinator(new MemoryPolicyStore(), host, Samples.Device);
        var requests = new List<string>();
        var policyVersions = new List<long?>();
        var syncCalls = 0;
        using var handler = new StubHandler(async request =>
        {
            requests.Add(request.Method + " " + request.RequestUri!.AbsolutePath);
            switch (request.RequestUri.AbsolutePath)
            {
                case "/v1/client/auth/challenges":
                    Assert.False(request.Headers.Contains("Signature"));
                    return Json(new Challenge { Nonce = "server-challenge", ExpiresAt = clock.GetUtcNow().AddMinutes(1) });
                case "/v1/client/login":
                    Assert.Contains("nonce=\"server-challenge\"", request.Headers.GetValues("Signature-Input").Single());
                    return Json(Token());
                case "/v1/client/sync":
                    Assert.Equal("Bearer", request.Headers.Authorization!.Scheme);
                    Assert.True(request.Headers.Contains("Signature"));
                    Assert.True(request.Headers.Contains("Idempotency-Key"));
                    var body = JsonSerializer.Deserialize<SyncRequest>(await request.Content!.ReadAsByteArrayAsync(), ProtocolJson.Options)!;
                    policyVersions.Add(body.PolicyVersion);
                    return Json(Response(clock) with { PolicyVersion = ++syncCalls == 3 ? 2 : 1, Policy = syncCalls == 3 ? Samples.Policy() with { Version = "opaque-v2" } : null });
                case "/v1/client/policy":
                    Assert.Equal(HttpMethod.Get, request.Method);
                    Assert.True(request.Headers.Contains("Signature"));
                    Assert.Null(request.Content);
                    return Json(Samples.Policy());
                default: throw new InvalidOperationException("Unexpected HTTP request");
            }
        });
        using var http = new HttpClient(handler) { BaseAddress = new Uri("https://keeper.test/v1/") };
        using var signer = new HttpMessageSigner(key);
        var client = new SyncClient(http, signer, Samples.Device, outbox, policy, clock);
        Assert.Equal(120, await client.ExecuteAsync(default));
        Assert.Equal(4, client.RequestCount);
        await client.ExecuteAsync(default);
        Assert.Equal(1, module.Applications);
        await client.ExecuteAsync(default);
        Assert.Equal(2, module.Applications);
        Assert.Equal(6, client.RequestCount);
        Assert.Single(requests, r => r.StartsWith("GET", StringComparison.Ordinal));
        Assert.Equal(new long?[] { null, 1, 1 }, policyVersions);
    }

    [Fact]
    public async Task TimeoutRetryKeepsBodyAndIdempotencyKeyButRenewsSignatureNonce()
    {
        using var directory = new TestDirectory();
        using var key = ECDsa.Create(ECCurve.NamedCurves.nistP256);
        using var outbox = new DurableOutbox(directory.File("outbox.json"));
        var episode = Samples.Episode();
        await outbox.EnqueueAsync(episode, default);
        var clock = new TestClock();
        await using var host = new ModuleHost([], Samples.Context(clock));
        var policy = new PolicyCoordinator(new MemoryPolicyStore(), host, Samples.Device);
        var requests = new List<(byte[] Body, string Id, string SignatureInput)>();
        using var handler = new StubHandler(async request =>
        {
            if (request.RequestUri!.AbsolutePath.EndsWith("challenges", StringComparison.Ordinal))
                return Json(new Challenge { Nonce = "server-challenge", ExpiresAt = clock.GetUtcNow().AddMinutes(1) });
            if (request.RequestUri.AbsolutePath.EndsWith("login", StringComparison.Ordinal)) return Json(Token());
            requests.Add((await request.Content!.ReadAsByteArrayAsync(), request.Headers.GetValues("Idempotency-Key").Single(), request.Headers.GetValues("Signature-Input").Single()));
            if (requests.Count == 1) throw new HttpRequestException("Connection lost after server commit");
            return Json(Response(clock) with { Policy = Samples.Policy(), EpisodeAcks = [new Ack { EventId = episode.EventId, Status = AckStatus.Duplicate, Retryable = false }] });
        });
        using var http = new HttpClient(handler) { BaseAddress = new Uri("https://keeper.test/v1/") };
        using var signer = new HttpMessageSigner(key);
        var client = new SyncClient(http, signer, Samples.Device, outbox, policy, clock);
        await Assert.ThrowsAsync<HttpRequestException>(() => client.ExecuteAsync(default));
        await client.ExecuteAsync(default);
        Assert.Equal(requests[0].Body, requests[1].Body);
        Assert.Equal(requests[0].Id, requests[1].Id);
        Assert.NotEqual(requests[0].SignatureInput, requests[1].SignatureInput);
        Assert.Empty((await outbox.InspectAsync()).Events);
        Assert.Equal(4, client.RequestCount);
    }

    [Fact]
    public async Task RateLimitHasNoInlineRetryAndPreservesOutbox()
    {
        using var directory = new TestDirectory();
        using var key = ECDsa.Create(ECCurve.NamedCurves.nistP256);
        using var outbox = new DurableOutbox(directory.File("outbox.json"));
        await outbox.EnqueueAsync(Samples.Episode(), default);
        var clock = new TestClock();
        await using var host = new ModuleHost([], Samples.Context(clock));
        var policy = new PolicyCoordinator(new MemoryPolicyStore(), host, Samples.Device);
        using var handler = new StubHandler(request =>
        {
            var response = new HttpResponseMessage(HttpStatusCode.TooManyRequests);
            response.Headers.RetryAfter = new System.Net.Http.Headers.RetryConditionHeaderValue(TimeSpan.FromMinutes(10));
            return Task.FromResult(response);
        });
        using var http = new HttpClient(handler) { BaseAddress = new Uri("https://keeper.test/v1/") };
        using var signer = new HttpMessageSigner(key);
        var client = new SyncClient(http, signer, Samples.Device, outbox, policy, clock);
        var error = await Assert.ThrowsAsync<TransportException>(() => client.ExecuteAsync(default));
        Assert.Equal(TimeSpan.FromMinutes(10), error.RetryAfter);
        Assert.Equal(1, client.RequestCount);
        Assert.Single((await outbox.InspectAsync()).Events);
    }

    [Theory]
    [InlineData(false)]
    [InlineData(true)]
    public async Task ForeignDevicePolicyIsRejectedWithoutChangingCacheOrModules(bool fetchPolicy)
    {
        using var directory = new TestDirectory();
        using var signer = new HttpMessageSigner(ECDsa.Create(ECCurve.NamedCurves.nistP256));
        using var outbox = new DurableOutbox(directory.File("outbox.json"));
        var clock = new TestClock();
        var module = new TestModule();
        await using var host = new ModuleHost([module], Samples.Context(clock));
        var store = new MemoryPolicyStore();
        var policy = new PolicyCoordinator(store, host, Samples.Device);
        var current = Samples.Policy();
        await policy.ApplyAsync(1, current, default);
        var foreign = current with { DeviceId = Guid.NewGuid(), Version = "foreign-v2", Etag = "\"2\"" };
        using var handler = new StubHandler(request => Task.FromResult(request.RequestUri!.AbsolutePath switch
        {
            "/v1/client/auth/challenges" => Json(new Challenge { Nonce = "challenge", ExpiresAt = clock.GetUtcNow().AddMinutes(1) }),
            "/v1/client/login" => Json(Token()),
            "/v1/client/sync" => Json(Response(clock) with { PolicyVersion = 2, Policy = fetchPolicy ? null : foreign }),
            "/v1/client/policy" => Json(foreign),
            _ => throw new InvalidOperationException("Unexpected HTTP request")
        }));
        using var http = new HttpClient(handler) { BaseAddress = new Uri("https://keeper.test/v1/") };
        var client = new SyncClient(http, signer, Samples.Device, outbox, policy, clock);

        var error = await Assert.ThrowsAsync<InvalidDataException>(() => client.ExecuteAsync(default));

        Assert.Equal("policy_device_mismatch", error.Message);
        Assert.Same(current, policy.Current);
        Assert.Equal(1L, policy.CurrentVersion);
        Assert.Equal(1, store.Saves);
        Assert.Equal(1, module.Applications);
    }

    [Theory]
    [InlineData(false)]
    [InlineData(true)]
    public async Task NotModifiedPreservesCurrentPolicyAndDoesNotAbortSync(bool hasCache)
    {
        using var directory = new TestDirectory();
        using var signer = new HttpMessageSigner(ECDsa.Create(ECCurve.NamedCurves.nistP256));
        using var outbox = new DurableOutbox(directory.File("outbox.json"));
        var episode = Samples.Episode();
        await outbox.EnqueueAsync(episode, default);
        var clock = new TestClock();
        var module = new TestModule();
        await using var host = new ModuleHost([module], Samples.Context(clock));
        var store = new MemoryPolicyStore();
        var policy = new PolicyCoordinator(store, host, Samples.Device);
        var current = hasCache ? Samples.Policy() : null;
        if (current is not null) await policy.ApplyAsync(1, current, default);
        var policyRequests = 0;
        using var handler = new StubHandler(request =>
        {
            if (request.RequestUri!.AbsolutePath == "/v1/client/policy")
            {
                policyRequests++;
                if (hasCache) Assert.Equal(current!.Etag, request.Headers.GetValues("If-None-Match").Single());
                else Assert.False(request.Headers.Contains("If-None-Match"));
                return Task.FromResult(new HttpResponseMessage(HttpStatusCode.NotModified));
            }
            return Task.FromResult(request.RequestUri.AbsolutePath switch
            {
                "/v1/client/auth/challenges" => Json(new Challenge { Nonce = "challenge", ExpiresAt = clock.GetUtcNow().AddMinutes(1) }),
                "/v1/client/login" => Json(Token()),
                "/v1/client/sync" => Json(Response(clock) with
                {
                    PolicyVersion = 2,
                    EpisodeAcks = [new Ack { EventId = episode.EventId, Status = AckStatus.Duplicate, Retryable = false }]
                }),
                _ => throw new InvalidOperationException("Unexpected HTTP request")
            });
        });
        using var http = new HttpClient(handler) { BaseAddress = new Uri("https://keeper.test/v1/") };
        var client = new SyncClient(http, signer, Samples.Device, outbox, policy, clock);

        Assert.Equal(120, await client.ExecuteAsync(default));
        Assert.Equal(120, await client.ExecuteAsync(default));

        Assert.Equal(2, policyRequests);
        Assert.Same(current, policy.Current);
        Assert.Equal(hasCache ? (long?)1 : null, policy.CurrentVersion);
        Assert.Equal(hasCache ? 1 : 0, store.Saves);
        Assert.Equal(hasCache ? 1 : 0, module.Applications);
        Assert.Empty((await outbox.InspectAsync()).Events);
    }

    [Theory]
    [InlineData(-12)]
    [InlineData(12)]
    public async Task TwoHourTokensUseLocalClockForLoginAndSyncDespiteServerSkew(int serverOffsetHours)
    {
        using var directory = new TestDirectory();
        using var signer = new HttpMessageSigner(ECDsa.Create(ECCurve.NamedCurves.nistP256));
        using var outbox = new DurableOutbox(directory.File("outbox.json"));
        var clock = new TestClock();
        await using var host = new ModuleHost([], Samples.Context(clock));
        var policy = new PolicyCoordinator(new MemoryPolicyStore(), host, Samples.Device);
        var loginCalls = 0;
        var syncCalls = 0;
        var token = Token() with { ExpiresIn = 7200, AccessToken = new string('a', 2048) };
        using var handler = new StubHandler(request =>
        {
            switch (request.RequestUri!.AbsolutePath)
            {
                case "/v1/client/auth/challenges":
                    return Task.FromResult(Json(new Challenge { Nonce = "challenge", ExpiresAt = clock.GetUtcNow().AddMinutes(1) }));
                case "/v1/client/login":
                    loginCalls++;
                    return Task.FromResult(Json(token));
                case "/v1/client/sync":
                    syncCalls++;
                    Assert.Equal(token.AccessToken, request.Headers.Authorization!.Parameter);
                    return Task.FromResult(Json(Response(clock) with
                    {
                        Policy = Samples.Policy(),
                        Token = syncCalls == 2 ? token : null,
                        ServerTime = clock.GetUtcNow().AddHours(serverOffsetHours)
                    }));
                default: throw new InvalidOperationException("Unexpected HTTP request");
            }
        });
        using var http = new HttpClient(handler) { BaseAddress = new Uri("https://keeper.test/v1/") };
        var client = new SyncClient(http, signer, Samples.Device, outbox, policy, clock);

        Assert.Equal(120, await client.ExecuteAsync(default));
        clock.Advance(TimeSpan.FromSeconds(7139));
        Assert.Equal(120, await client.ExecuteAsync(default));
        Assert.Equal(1, loginCalls);
        clock.Advance(TimeSpan.FromSeconds(7139));
        Assert.Equal(120, await client.ExecuteAsync(default));
        Assert.Equal(1, loginCalls);
        clock.Advance(TimeSpan.FromSeconds(1));
        Assert.Equal(120, await client.ExecuteAsync(default));
        Assert.Equal(2, loginCalls);
    }

    private static DeviceToken Token() => new()
    {
        DeviceId = Samples.Device, TenantId = Samples.Tenant, AccessToken = "test-token", TokenType = "Bearer", ExpiresIn = 3600
    };

    private static SyncResponse Response(TestClock clock) => new()
    {
        ProtocolVersion = 1, ServerTime = clock.GetUtcNow(), NextSyncAfterSeconds = 120, PolicyVersion = 1,
        Policy = null, Release = null, Commands = [], EpisodeAcks = [], LogAcks = [], CommandAcks = [], SecurityAck = null, ActivityAck = null
    };

    private static HttpResponseMessage Json<T>(T value) => new(HttpStatusCode.OK)
    {
        Content = new ByteArrayContent(JsonSerializer.SerializeToUtf8Bytes(value, ProtocolJson.Options))
    };

    private sealed class StubHandler(Func<HttpRequestMessage, Task<HttpResponseMessage>> respond) : HttpMessageHandler
    {
        protected override Task<HttpResponseMessage> SendAsync(HttpRequestMessage request, CancellationToken cancellationToken) => respond(request);
    }
}
