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
