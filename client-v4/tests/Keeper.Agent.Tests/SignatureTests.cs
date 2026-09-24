using System.Net.Http.Headers;
using System.Security.Cryptography;
using System.Text;
using Keeper.Agent.Transport;

namespace Keeper.Agent.Tests;

public sealed class SignatureTests
{
    [Theory]
    [InlineData(true)]
    [InlineData(false)]
    public void SignatureVerifiesUsingPublicKeyAndIndependentRfc9421Base(bool withBody)
    {
        using var key = ECDsa.Create(ECCurve.NamedCurves.nistP256);
        using var verifier = ECDsa.Create(key.ExportParameters(false));
        using var signer = new HttpMessageSigner(key);
        using var request = new HttpRequestMessage(withBody ? HttpMethod.Post : HttpMethod.Get, "https://keeper.test/v1/client/sync?mode=test");
        request.Headers.Authorization = new AuthenticationHeaderValue("Bearer", "test-token");
        byte[] body = withBody ? "{\"policy_version\":1}"u8.ToArray() : [];
        if (withBody)
        {
            request.Content = new ByteArrayContent(body);
            request.Content.Headers.ContentType = new MediaTypeHeaderValue("application/json");
            request.Headers.Add("Idempotency-Key", "33333333-3333-4333-8333-333333333333");
        }
        signer.Sign(request, body, DateTimeOffset.FromUnixTimeSeconds(1700000000), "test-nonce-128-bits");
        var input = request.Headers.GetValues("Signature-Input").Single()[5..];
        var components = withBody
            ? "\"@method\" \"@target-uri\" \"authorization\" \"content-digest\" \"content-type\" \"idempotency-key\""
            : "\"@method\" \"@target-uri\" \"authorization\"";
        Assert.Equal($"({components});created=1700000000;expires=1700000060;nonce=\"test-nonce-128-bits\";keyid=\"{signer.KeyId}\";alg=\"ecdsa-p256-sha256\"", input);
        var signatureBase = $"\"@method\": {(withBody ? "POST" : "GET")}\n\"@target-uri\": https://keeper.test/v1/client/sync?mode=test\n\"authorization\": Bearer test-token\n";
        if (withBody)
        {
            var digest = "sha-256=:" + Convert.ToBase64String(SHA256.HashData(body)) + ":";
            Assert.Equal(digest, request.Content!.Headers.GetValues("Content-Digest").Single());
            signatureBase += $"\"content-digest\": {digest}\n\"content-type\": application/json\n\"idempotency-key\": 33333333-3333-4333-8333-333333333333\n";
        }
        signatureBase += "\"@signature-params\": " + input;
        var signature = Convert.FromBase64String(request.Headers.GetValues("Signature").Single()[6..^1]);
        Assert.Equal(64, signature.Length);
        Assert.True(verifier.VerifyData(Encoding.UTF8.GetBytes(signatureBase), signature, HashAlgorithmName.SHA256, DSASignatureFormat.IeeeP1363FixedFieldConcatenation));
        Assert.False(verifier.VerifyData(Encoding.UTF8.GetBytes(signatureBase.Replace("test-token", "modified-token", StringComparison.Ordinal)), signature, HashAlgorithmName.SHA256, DSASignatureFormat.IeeeP1363FixedFieldConcatenation));
        signature[0] ^= 1;
        Assert.False(verifier.VerifyData(Encoding.UTF8.GetBytes(signatureBase), signature, HashAlgorithmName.SHA256, DSASignatureFormat.IeeeP1363FixedFieldConcatenation));
    }

    [Fact]
    public void DisposeReleasesSigningKey()
    {
        using var key = ECDsa.Create(ECCurve.NamedCurves.nistP256);
        using var signer = new HttpMessageSigner(key);

        signer.Dispose();

        Assert.Throws<ObjectDisposedException>(() => key.ExportParameters(false));
    }

    [Fact]
    public void RejectsWrongCurveInsecureUriAndHeaderInjection()
    {
        using var wrong = ECDsa.Create(ECCurve.NamedCurves.nistP384);
        Assert.Throws<ArgumentException>(() => new HttpMessageSigner(wrong));
        using var key = ECDsa.Create(ECCurve.NamedCurves.nistP256);
        using var signer = new HttpMessageSigner(key);
        using var http = new HttpRequestMessage(HttpMethod.Get, "http://keeper.test/v1/client/policy");
        Assert.Throws<ArgumentException>(() => signer.Sign(http, [], DateTimeOffset.UtcNow, "nonce"));
        using var https = new HttpRequestMessage(HttpMethod.Get, "https://keeper.test/v1/client/policy");
        Assert.Throws<ArgumentException>(() => signer.Sign(https, [], DateTimeOffset.UtcNow, "nonce\r\ninjected"));
    }
}
