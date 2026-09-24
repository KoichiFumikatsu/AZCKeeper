using System.Net;
using System.Net.Http.Headers;
using System.Text.Json;
using Keeper.Agent.Policy;
using Keeper.Agent.Storage;
using Keeper.Shared.Protocol;

namespace Keeper.Agent.Transport;

public sealed class TransportException(HttpStatusCode status, TimeSpan? retryAfter) : Exception($"HTTP {(int)status}")
{
    public HttpStatusCode Status { get; } = status;
    public TimeSpan? RetryAfter { get; } = retryAfter;
}

public interface ISyncCycle
{
    long RequestCount { get; }
    Task<int> ExecuteAsync(CancellationToken ct);
}

public sealed class SyncClient(HttpClient http, HttpMessageSigner signer, Guid deviceId,
    DurableOutbox outbox, PolicyCoordinator policy, TimeProvider clock) : ISyncCycle
{
    private DeviceToken? _token;
    private DateTimeOffset _expiresAt;
    public Func<SyncResponse, Guid, CancellationToken, Task>? OnResponse { get; init; }
    public long RequestCount { get; private set; }

    public async Task<int> ExecuteAsync(CancellationToken ct)
    {
        if (_token is null || _expiresAt <= clock.GetUtcNow().AddSeconds(60)) await LoginAsync(ct);
        var batch = await outbox.PrepareAsync(policy.CurrentVersion, ct);
        var issuedAt = clock.GetUtcNow();
        SyncResponse response;
        try
        {
            response = await PostAsync<SyncResponse>("client/sync", batch.Body, true, batch.IdempotencyKey, null, ct);
        }
        catch (TransportException ex) when (ex.Status == HttpStatusCode.Unauthorized)
        {
            _token = null;
            throw;
        }
        if (response.ProtocolVersion != 1 || response.PolicyVersion < 1 || response.NextSyncAfterSeconds is < 120 or > 3600)
            throw new InvalidDataException("invalid_sync_response");
        await outbox.CompleteAsync(response.EpisodeAcks.Concat(response.LogAcks).Concat(response.CommandAcks)
            .Concat(response.SecurityAck is null ? [] : new[] { response.SecurityAck }), ct);
        if (response.Token is not null) SetToken(response.Token, issuedAt);
        var effective = response.Policy;
        if (effective is null && (policy.CurrentVersion is null || response.PolicyVersion > policy.CurrentVersion))
            effective = await GetPolicyAsync(ct);
        if (effective is not null && effective.TenantId != _token!.TenantId)
            throw new InvalidDataException("policy_tenant_mismatch");
        if (effective is not null && effective.DeviceId != deviceId)
            throw new InvalidDataException("policy_device_mismatch");
        await policy.ApplyAsync(response.PolicyVersion, effective, ct);
        if (OnResponse is not null) await OnResponse(response, _token!.TenantId, ct);
        return checked((int)response.NextSyncAfterSeconds);
    }

    private async Task LoginAsync(CancellationToken ct)
    {
        var challenge = await PostAsync<Challenge>("client/auth/challenges", Serialize(new ChallengeRequest { DeviceId = deviceId }), false, null, null, ct);
        if (challenge.ExpiresAt <= clock.GetUtcNow()) throw new InvalidDataException("challenge_expired");
        var login = new DeviceLogin { DeviceId = deviceId, PublicKey = signer.PublicKey, AgentVersion = "4.0.0-prototype", Hostname = Environment.MachineName };
        var issuedAt = clock.GetUtcNow();
        var token = await PostAsync<DeviceToken>("client/login", Serialize(login), false, null, challenge.Nonce, ct);
        SetToken(token, issuedAt);
    }

    private void SetToken(DeviceToken token, DateTimeOffset issuedAt)
    {
        if (token.DeviceId != deviceId || token.TenantId == Guid.Empty || token.TokenType != "Bearer" ||
            token.ExpiresIn is <= 0 or > 604800 || string.IsNullOrWhiteSpace(token.AccessToken) || token.AccessToken.Length > 16384 ||
            _token is not null && token.TenantId != _token.TenantId)
            throw new InvalidDataException("invalid_device_token");
        _token = token;
        _expiresAt = issuedAt.AddSeconds(token.ExpiresIn);
    }

    private async Task<T> PostAsync<T>(string path, byte[] body, bool authenticated, Guid? idempotencyKey, string? nonce, CancellationToken ct) where T : class
    {
        if (http.BaseAddress is not { Scheme: "https" } root || !root.AbsolutePath.EndsWith('/'))
            throw new InvalidOperationException("HTTPS API base must end in /v1/");
        using var request = new HttpRequestMessage(HttpMethod.Post, new Uri(root, path));
        request.Content = new ByteArrayContent(body);
        request.Content.Headers.ContentType = new MediaTypeHeaderValue("application/json");
        if (authenticated) request.Headers.Authorization = new AuthenticationHeaderValue("Bearer", _token!.AccessToken);
        if (idempotencyKey is not null) request.Headers.Add("Idempotency-Key", idempotencyKey.Value.ToString());
        if (authenticated || nonce is not null) signer.Sign(request, body, clock.GetUtcNow(), nonce ?? HttpMessageSigner.CreateNonce());
        return await SendAsync<T>(request, 256 * 1024, ct) ?? throw new InvalidDataException("empty_response");
    }

    private async Task<EffectivePolicy?> GetPolicyAsync(CancellationToken ct)
    {
        using var request = new HttpRequestMessage(HttpMethod.Get, new Uri(http.BaseAddress!, "client/policy"));
        request.Headers.Authorization = new AuthenticationHeaderValue("Bearer", _token!.AccessToken);
        if (policy.Current is not null) request.Headers.TryAddWithoutValidation("If-None-Match", policy.Current.Etag);
        signer.Sign(request, [], clock.GetUtcNow(), HttpMessageSigner.CreateNonce());
        return await SendAsync<EffectivePolicy>(request, 1024 * 1024, ct, allowNotModified: true);
    }

    private async Task<T?> SendAsync<T>(HttpRequestMessage request, int limit, CancellationToken ct, bool allowNotModified = false) where T : class
    {
        RequestCount++;
        using var response = await http.SendAsync(request, HttpCompletionOption.ResponseHeadersRead, ct);
        // Null preserves both the cached policy and its version without reapplying it.
        if (allowNotModified && response.StatusCode == HttpStatusCode.NotModified) return null;
        if (!response.IsSuccessStatusCode)
        {
            var retry = response.Headers.RetryAfter;
            throw new TransportException(response.StatusCode, retry?.Delta ?? (retry?.Date - clock.GetUtcNow()));
        }
        if (response.Content.Headers.ContentLength > limit) throw new InvalidDataException("response_too_large");
        await using var stream = await response.Content.ReadAsStreamAsync(ct);
        using var buffer = new MemoryStream();
        var chunk = new byte[8192];
        int length;
        while ((length = await stream.ReadAsync(chunk, ct)) > 0)
        {
            if (buffer.Length + length > limit) throw new InvalidDataException("response_too_large");
            buffer.Write(chunk, 0, length);
        }
        return JsonSerializer.Deserialize<T>(buffer.GetBuffer().AsSpan(0, (int)buffer.Length), ProtocolJson.Options)
            ?? throw new InvalidDataException("empty_response");
    }

    private static byte[] Serialize<T>(T value) => JsonSerializer.SerializeToUtf8Bytes(value, ProtocolJson.Options);
}
