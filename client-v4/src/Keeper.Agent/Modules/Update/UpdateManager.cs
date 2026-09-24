using System.Security.Cryptography;
using System.Text;
using System.Text.Json;
using Keeper.Shared.Contracts;
using Keeper.Shared.Protocol;

namespace Keeper.Agent.Modules.Update;

public static class ReleaseVerifier
{
    public static async Task VerifyAsync(Release release, Stream package, IReadOnlyDictionary<string, ECDsa> trustedKeys,
        long installedSequence, string channel, ReleaseArchitecture architecture, Version agentVersion, CancellationToken ct)
    {
        if (!trustedKeys.TryGetValue(release.KeyId, out var key) || key.KeySize != 256 || release.Sequence <= installedSequence ||
            release.Channel != channel || release.Architecture != architecture || !Version.TryParse(release.MinAgentVersion, out var minimum) ||
            agentVersion < minimum || !Uri.TryCreate(release.ArtifactUrl, UriKind.Absolute, out var uri) || uri.Scheme != "https" ||
            release.ManifestJws.Length > 16384 || release.SizeBytes <= 0)
            throw new CryptographicException("release_not_trusted");
        var parts = release.ManifestJws.Split('.');
        if (parts.Length != 3) throw new CryptographicException("invalid_manifest");
        using var header = JsonDocument.Parse(Decode(parts[0]));
        if (header.RootElement.GetProperty("alg").GetString() != "ES256" || header.RootElement.GetProperty("kid").GetString() != release.KeyId ||
            header.RootElement.TryGetProperty("crit", out _) || header.RootElement.TryGetProperty("b64", out _))
            throw new CryptographicException("unsupported_jws");
        var signature = Decode(parts[2]);
        if (signature.Length != 64 || !key.VerifyData(Encoding.ASCII.GetBytes(parts[0] + "." + parts[1]), signature,
            HashAlgorithmName.SHA256, DSASignatureFormat.IeeeP1363FixedFieldConcatenation)) throw new CryptographicException("invalid_manifest_signature");
        using var payload = JsonDocument.Parse(Decode(parts[1]));
        var expected = JsonSerializer.SerializeToElement(release, ProtocolJson.Options);
        var properties = payload.RootElement.EnumerateObject().ToArray();
        if (properties.Length != expected.EnumerateObject().Count() - 1 || properties.Select(p => p.Name).Distinct().Count() != properties.Length)
            throw new CryptographicException("invalid_manifest_fields");
        foreach (var field in expected.EnumerateObject().Where(p => p.Name != "manifest_jws"))
            if (!payload.RootElement.TryGetProperty(field.Name, out var value) || value.ValueKind != field.Value.ValueKind || value.ToString() != field.Value.ToString())
                throw new CryptographicException("manifest_metadata_mismatch");
        using var hash = IncrementalHash.CreateHash(HashAlgorithmName.SHA256);
        var buffer = new byte[64 * 1024];
        long total = 0;
        int count;
        while ((count = await package.ReadAsync(buffer, ct)) != 0)
        {
            total += count;
            if (total > release.SizeBytes) throw new CryptographicException("package_size_mismatch");
            hash.AppendData(buffer, 0, count);
        }
        if (total != release.SizeBytes || !CryptographicOperations.FixedTimeEquals(hash.GetHashAndReset(), Convert.FromHexString(release.Sha256)))
            throw new CryptographicException("package_hash_mismatch");
    }
    private static byte[] Decode(string value) => Convert.FromBase64String(value.Replace('-', '+').Replace('_', '/') + new string('=', (4 - value.Length % 4) % 4));
}

public sealed class UpdateManager(string stagingDirectory, IReadOnlyDictionary<string, ECDsa> trustedKeys,
    long installedSequence = 0, string channel = "stable") : ModuleBase
{
    public override string Name => "UpdateManager";
    private Release? _pending;
    private Guid? _verified;
    private readonly HashSet<Guid> _failed = [];
    private DateTimeOffset _next;
    private string? _error;
    public void Offer(Release? release)
    {
        if (release is null || release.Id == _pending?.Id) return;
        _pending = release;
        _next = DateTimeOffset.MinValue;
    }
    public override ModuleSnapshot Snapshot() => base.Snapshot() with { ErrorCode = _error };
    public override async Task TickAsync(CancellationToken ct)
    {
        var release = _pending;
        if (release is null || Context.Clock.GetUtcNow() < _next) return;
        _next = Context.Clock.GetUtcNow().AddMinutes(5);
        if (release.Id == _verified) { State = "verified_pending_install"; _error = null; return; }
        if (_failed.Contains(release.Id)) { State = "failed"; _error = "release_verification_failed"; return; }
        if (!trustedKeys.ContainsKey(release.KeyId))
        {
            if (State != "unsupported" || _error != "release_key_untrusted")
                await ReportAsync("release_key_untrusted", ct, LogEntryLevel.Warn);
            State = "unsupported"; _error = "release_key_untrusted"; return;
        }
        _error = null;
        var path = Path.Combine(stagingDirectory, release.Id.ToString("N") + ".msi");
        if (!File.Exists(path)) { State = "awaiting_package"; return; }
        try
        {
            await using var stream = new FileStream(path, FileMode.Open, FileAccess.Read, FileShare.Read);
            await ReleaseVerifier.VerifyAsync(release, stream, trustedKeys, installedSequence, channel,
                System.Runtime.InteropServices.RuntimeInformation.OSArchitecture == System.Runtime.InteropServices.Architecture.Arm64 ? ReleaseArchitecture.Arm64 : ReleaseArchitecture.X64,
                new Version(4, 0, 0), ct);
        }
        catch (Exception ex) when (ex is not OperationCanceledException)
        {
            State = "failed";
            _error = "release_verification_failed";
            _failed.Add(release.Id);
            await ReportAsync(_error, ct, LogEntryLevel.Error);
            return;
        }
        _verified = release.Id;
        State = "verified_pending_install";
        await ReportAsync("package_verified", ct);
    }
}
