using System.Net.Http.Headers;
using System.Security.Cryptography;
using System.Text;
using System.Text.Json;
using Keeper.Agent.Transport;
using Keeper.Shared.Protocol;

namespace Keeper.Agent.Migration;

public static class MigrationEnrollment
{
    public static string Thumbprint(HttpMessageSigner signer)
    {
        var key = signer.PublicKey;
        return Convert.ToHexString(SHA256.HashData(Encoding.UTF8.GetBytes($"{{\"crv\":\"P-256\",\"kty\":\"EC\",\"x\":\"{key.X}\",\"y\":\"{key.Y}\"}}"))).ToLowerInvariant();
    }

    public static async Task<DeviceToken> LoginAsync(HttpClient http, HttpMessageSigner signer, Guid tenant, Guid device,
        string? ticket, CancellationToken ct)
    {
        if (http.BaseAddress is not { Scheme: "https" } root || !root.AbsolutePath.EndsWith("/v1/", StringComparison.Ordinal) ||
            root.UserInfo.Length != 0 || root.Query.Length != 0 || root.Fragment.Length != 0 || http.DefaultRequestHeaders.Authorization is not null ||
            http.DefaultRequestHeaders.Contains("Cookie") || tenant == Guid.Empty || device == Guid.Empty)
            throw new InvalidDataException("invalid_migration_api");
        var challenge = await Send<Challenge>("client/auth/challenges", new ChallengeRequest
            { DeviceId = ticket is null ? device : null, EnrollmentTicket = ticket }, null);
        if (challenge.ExpiresAt <= DateTimeOffset.UtcNow) throw new InvalidDataException("migration_challenge_expired");
        var token = await Send<DeviceToken>("client/login", new DeviceLogin
        {
            DeviceId = ticket is null ? device : null, EnrollmentTicket = ticket, PublicKey = signer.PublicKey,
            AgentVersion = "4.0.0", Hostname = Environment.MachineName
        }, challenge.Nonce);
        if (token.DeviceId != device || token.TenantId != tenant || token.TokenType != "Bearer" ||
            token.ExpiresIn <= 0 || string.IsNullOrWhiteSpace(token.AccessToken)) throw new InvalidDataException("migration_identity_mismatch");
        return token;

        async Task<T> Send<T>(string path, object body, string? nonce)
        {
            var bytes = JsonSerializer.SerializeToUtf8Bytes(body, ProtocolJson.Options);
            using var request = new HttpRequestMessage(HttpMethod.Post, new Uri(root, path));
            request.Content = new ByteArrayContent(bytes);
            request.Content.Headers.ContentType = new MediaTypeHeaderValue("application/json");
            if (nonce is not null) signer.Sign(request, bytes, DateTimeOffset.UtcNow, nonce);
            using var response = await http.SendAsync(request, ct);
            response.EnsureSuccessStatusCode();
            await response.Content.LoadIntoBufferAsync(64 * 1024);
            return JsonSerializer.Deserialize<T>(await response.Content.ReadAsByteArrayAsync(ct), ProtocolJson.Options)
                ?? throw new InvalidDataException("empty_migration_response");
        }
    }
}
