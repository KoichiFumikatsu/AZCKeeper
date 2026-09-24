using System.Net.Http.Headers;
using System.Security.Cryptography;
using System.Text;
using Keeper.Shared.Protocol;

namespace Keeper.Agent.Transport;

public sealed class HttpMessageSigner : IDisposable
{
    private readonly ECDsa _key;
    public PublicKey PublicKey { get; }
    public string KeyId { get; }

    public HttpMessageSigner(ECDsa key)
    {
        _key = key;
        var parameters = key.ExportParameters(false);
        if (parameters.Curve.Oid.Value != ECCurve.NamedCurves.nistP256.Oid.Value)
            throw new ArgumentException("P-256 required", nameof(key));
        PublicKey = new PublicKey { Kty = "EC", Crv = "P-256", X = Base64Url(parameters.Q.X!), Y = Base64Url(parameters.Q.Y!) };
        // RFC 7638 requires lexicographic JWK member order and no whitespace.
        var canonical = $"{{\"crv\":\"P-256\",\"kty\":\"EC\",\"x\":\"{PublicKey.X}\",\"y\":\"{PublicKey.Y}\"}}";
        KeyId = Base64Url(SHA256.HashData(Encoding.UTF8.GetBytes(canonical)));
    }

    public void Sign(HttpRequestMessage request, ReadOnlySpan<byte> body, DateTimeOffset now, string nonce)
    {
        if (request.RequestUri is not { IsAbsoluteUri: true, Scheme: "https" })
            throw new ArgumentException("HTTPS required");
        if (nonce.Length is < 1 or > 160 || nonce.Any(c => c < 0x21 || c > 0x7e || c is '"' or '\\'))
            throw new ArgumentException("Invalid nonce", nameof(nonce));
        var parts = new List<(string Name, string Value)>
        {
            ("@method", request.Method.Method), ("@target-uri", request.RequestUri.AbsoluteUri)
        };
        if (request.Headers.Authorization is not null) parts.Add(("authorization", request.Headers.Authorization.ToString()));
        if (request.Content is not null)
        {
            var digest = $"sha-256=:{Convert.ToBase64String(SHA256.HashData(body))}:";
            request.Content.Headers.Remove("Content-Digest");
            request.Content.Headers.Add("Content-Digest", digest);
            parts.Add(("content-digest", digest));
            parts.Add(("content-type", request.Content.Headers.ContentType?.ToString() ?? throw new InvalidOperationException("content_type_required")));
        }
        if (request.Headers.TryGetValues("Idempotency-Key", out var values)) parts.Add(("idempotency-key", values.Single()));
        var input = $"({string.Join(" ", parts.Select(p => $"\"{p.Name}\""))});created={now.ToUnixTimeSeconds()};expires={now.ToUnixTimeSeconds() + 60};nonce=\"{nonce}\";keyid=\"{KeyId}\";alg=\"ecdsa-p256-sha256\"";
        var signatureBase = string.Join("\n", parts.Select(p => $"\"{p.Name}\": {p.Value}")) + $"\n\"@signature-params\": {input}";
        var signature = _key.SignData(Encoding.UTF8.GetBytes(signatureBase), HashAlgorithmName.SHA256,
            DSASignatureFormat.IeeeP1363FixedFieldConcatenation);
        request.Headers.Remove("Signature-Input");
        request.Headers.Remove("Signature");
        request.Headers.Add("Signature-Input", "sig1=" + input);
        request.Headers.Add("Signature", "sig1=:" + Convert.ToBase64String(signature) + ":");
    }

    public void Dispose() => _key.Dispose();

    public static string CreateNonce() => Base64Url(RandomNumberGenerator.GetBytes(16));
    private static string Base64Url(byte[] bytes) => Convert.ToBase64String(bytes).TrimEnd('=').Replace('+', '-').Replace('/', '_');
}
