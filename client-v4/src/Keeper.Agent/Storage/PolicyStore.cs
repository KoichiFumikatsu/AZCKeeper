using System.Security.Cryptography;
using System.Text.Json;
using Keeper.Agent.Storage;
using Keeper.Shared.Protocol;

namespace Keeper.Agent.Storage;

public sealed record CachedPolicy(long PolicyVersion, EffectivePolicy Policy);
public interface IPolicyStore
{
    Task<CachedPolicy?> LoadAsync(CancellationToken ct);
    Task SaveAsync(CachedPolicy policy, CancellationToken ct);
}

public sealed record SignedPolicyCache(byte[] Payload, byte[] Signature);

// Local device signature protects cache integrity; it is not a backend policy signature.
public sealed class SignedFilePolicyStore(string path, ECDsa key, Action<string>? log = null) : IPolicyStore
{
    public async Task<CachedPolicy?> LoadAsync(CancellationToken ct)
    {
        if (!File.Exists(path)) return null;
        if (new FileInfo(path).Length > 2 * 1024 * 1024) throw new InvalidDataException("policy_cache_too_large");
        var envelope = JsonSerializer.Deserialize<SignedPolicyCache>(await File.ReadAllBytesAsync(path, ct), ProtocolJson.Options)
            ?? throw new InvalidDataException("invalid_policy_cache");
        if (!key.VerifyData(envelope.Payload, envelope.Signature, HashAlgorithmName.SHA256,
                DSASignatureFormat.IeeeP1363FixedFieldConcatenation))
            throw new CryptographicException("invalid_policy_cache_signature");
        try
        {
            return JsonSerializer.Deserialize<CachedPolicy>(envelope.Payload, ProtocolJson.Options)
                ?? throw new InvalidDataException("invalid_policy_cache_payload");
        }
        catch (JsonException ex)
        {
            // Firma valida (lo escribio este equipo) pero esquema distinto: la dejo otra version del agente,
            // p.ej. tras una vuelta atras. Se aparta (no se pierde la evidencia) para no impedir el login;
            // el servidor envia una politica fresca.
            var rejected = path + ".rejected";
            string outcome;
            try { File.Move(path, rejected, overwrite: true); outcome = $"apartada como \"{rejected}\""; }
            catch (Exception moveError) when (moveError is IOException or UnauthorizedAccessException)
            {
                File.Delete(path);
                outcome = $"no se pudo apartar ({moveError.Message}); borrada";
            }
            log?.Invoke($"policy_cache warn: la politica guardada tiene firma valida pero no se puede leer ({ex.Message}); {outcome}; se pedira una fresca al servidor");
            return null;
        }
    }

    public Task SaveAsync(CachedPolicy policy, CancellationToken ct)
    {
        var payload = JsonSerializer.SerializeToUtf8Bytes(policy, ProtocolJson.Options);
        var signature = key.SignData(payload, HashAlgorithmName.SHA256, DSASignatureFormat.IeeeP1363FixedFieldConcatenation);
        return AtomicFile.WriteAsync(path, JsonSerializer.SerializeToUtf8Bytes(new SignedPolicyCache(payload, signature), ProtocolJson.Options), ct);
    }
}
