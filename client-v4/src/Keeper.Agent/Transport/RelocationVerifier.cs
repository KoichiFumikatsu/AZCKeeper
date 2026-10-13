using System.Globalization;
using System.Security.Cryptography;
using System.Text;
using System.Text.Json;
using Keeper.Shared.Contracts;

namespace Keeper.Agent.Transport;

// Orden de mudanza de servidor (4.0.24). from_base/to_base son origenes (https://host/); la API es {base}v1/.
public sealed record RelocationManifest(Guid Id, long Sequence, Uri FromBase, Uri ToBase, IReadOnlyList<Guid> Tenants,
    IReadOnlyList<Guid> ExcludeDevices, DateTimeOffset NotBefore, DateTimeOffset ExpiresAt)
{
    public Uri ToApi => new(ToBase, "v1/");
}

public sealed class RelocationRejectedException(string reason, Guid? relocationId = null) : Exception(reason)
{
    public string Reason { get; } = reason;
    public Guid? RelocationId { get; } = relocationId;
}

// Verificacion de la mudanza: JWS ES256 firmado con la clave de RELEASES del trust instalado (la misma que firma los
// manifiestos de version, ReleaseVerifier). Que el servidor la entregue no basta: sin esa firma un servidor comprometido
// podria llevarse la flota a otro lado. Reglas: destino https bajo un sufijo fijo (ApiBaseOverride.AllowedHostSuffixes),
// origen = la base actual del agente, tenant incluido, equipo no excluido, sequence mayor que la ultima aplicada y
// dentro de not_before..expires_at.
public static class RelocationVerifier
{
    private static readonly string[] Fields = ["type", "id", "sequence", "from_base", "to_base", "tenants", "exclude_devices", "not_before", "expires_at"];

    public static RelocationManifest Verify(string jws, IReadOnlyDictionary<string, ECDsa> trustedKeys, Uri currentApi, Guid? tenantId,
        Guid deviceId, long lastSequence, DateTimeOffset now)
    {
        var m = Parse(jws, trustedKeys);
        string? reason = null;
        if (m.ToBase.Scheme != Uri.UriSchemeHttps) reason = "to_base_not_https";
        else if (!ApiBaseOverride.AllowedHost(m.ToBase.Host)) reason = "to_base_not_allowed";
        else if (!SameOrigin(m.FromBase, currentApi)) reason = "from_base_mismatch";
        else if (SameOrigin(m.ToBase, currentApi)) reason = "same_server";
        else if (tenantId is not { } tenant || !m.Tenants.Contains(tenant)) reason = "tenant_not_included";
        else if (m.ExcludeDevices.Contains(deviceId)) reason = "device_excluded";
        else if (m.Sequence <= lastSequence) reason = "stale_sequence";
        else if (now < m.NotBefore) reason = "not_yet_valid";
        else if (now >= m.ExpiresAt) reason = "expired";
        return reason is null ? m : throw new RelocationRejectedException(reason, m.Id);
    }

    // Firma y forma; no mira el estado del agente (lo usa tambien la herramienta de firma para autoverificarse).
    public static RelocationManifest Parse(string jws, IReadOnlyDictionary<string, ECDsa> trustedKeys)
    {
        if (jws.Length is < 20 or > 16384) throw new RelocationRejectedException("invalid_jws");
        var parts = jws.Split('.');
        if (parts.Length != 3) throw new RelocationRejectedException("invalid_jws");
        ECDsa key;
        byte[] signature;
        try
        {
            using var header = JsonDocument.Parse(Decode(parts[0]));
            var h = header.RootElement;
            if (h.ValueKind != JsonValueKind.Object || !h.TryGetProperty("alg", out var alg) || alg.ValueKind != JsonValueKind.String || alg.GetString() != "ES256" ||
                !h.TryGetProperty("kid", out var kid) || kid.ValueKind != JsonValueKind.String || h.TryGetProperty("crit", out _) || h.TryGetProperty("b64", out _))
                throw new RelocationRejectedException("invalid_jws");
            if (!trustedKeys.TryGetValue(kid.GetString()!, out key!) || key.KeySize != 256) throw new RelocationRejectedException("untrusted_key");
            signature = Decode(parts[2]);
        }
        catch (Exception ex) when (ex is JsonException or FormatException) { throw new RelocationRejectedException("invalid_jws"); }
        if (signature.Length != 64 || !key.VerifyData(Encoding.ASCII.GetBytes(parts[0] + "." + parts[1]), signature,
                HashAlgorithmName.SHA256, DSASignatureFormat.IeeeP1363FixedFieldConcatenation))
            throw new RelocationRejectedException("invalid_signature");
        try
        {
            using var payload = JsonDocument.Parse(Decode(parts[1]));
            return Manifest(payload.RootElement) ?? throw new RelocationRejectedException("invalid_manifest");
        }
        catch (Exception ex) when (ex is JsonException or FormatException or InvalidOperationException) { throw new RelocationRejectedException("invalid_manifest"); }
    }

    private static RelocationManifest? Manifest(JsonElement p)
    {
        if (p.ValueKind != JsonValueKind.Object) return null;
        var names = p.EnumerateObject().Select(x => x.Name).ToArray();
        if (names.Distinct(StringComparer.Ordinal).Count() != names.Length || names.Any(n => !Fields.Contains(n))) return null;
        if (Text(p, "type") != "relocation" || !Guid.TryParseExact(Text(p, "id"), "D", out var id) ||
            !p.TryGetProperty("sequence", out var sequence) || sequence.ValueKind != JsonValueKind.Number || !sequence.TryGetInt64(out var seq) || seq < 1 ||
            Base(Text(p, "from_base")) is not { } from || Base(Text(p, "to_base")) is not { } to ||
            Ids(p, "tenants", required: true) is not { Count: > 0 } tenants || Ids(p, "exclude_devices", required: false) is not { } excluded ||
            Instant(Text(p, "not_before")) is not { } notBefore || Instant(Text(p, "expires_at")) is not { } expiresAt || expiresAt <= notBefore)
            return null;
        return new RelocationManifest(id, seq, from, to, tenants, excluded, notBefore, expiresAt);
    }

    private static string? Text(JsonElement p, string name) =>
        p.TryGetProperty(name, out var value) && value.ValueKind == JsonValueKind.String ? value.GetString() : null;

    // Origen exacto: esquema://host[:puerto]/ sin ruta, query, fragmento ni credenciales.
    private static Uri? Base(string? value) =>
        value is not null && Uri.TryCreate(value, UriKind.Absolute, out var uri) && uri.Scheme is "https" or "http" &&
        uri.AbsolutePath == "/" && uri.Query.Length == 0 && uri.Fragment.Length == 0 && uri.UserInfo.Length == 0 &&
        string.Equals(uri.AbsoluteUri, value, StringComparison.OrdinalIgnoreCase) ? uri : null;

    private static List<Guid>? Ids(JsonElement p, string name, bool required)
    {
        if (!p.TryGetProperty(name, out var array)) return required ? null : [];
        if (array.ValueKind != JsonValueKind.Array || array.GetArrayLength() > 1000) return null;
        var result = new List<Guid>();
        foreach (var item in array.EnumerateArray())
        {
            if (item.ValueKind != JsonValueKind.String || !Guid.TryParseExact(item.GetString(), "D", out var value) || result.Contains(value)) return null;
            result.Add(value);
        }
        return result;
    }

    private static DateTimeOffset? Instant(string? value) =>
        value is not null && DateTimeOffset.TryParse(value, CultureInfo.InvariantCulture, DateTimeStyles.RoundtripKind, out var at) &&
        (value.EndsWith('Z') || value.Length > 6 && value[^6] is '+' or '-') ? at : null;

    public static bool SameOrigin(Uri a, Uri b) =>
        string.Equals(a.Scheme, b.Scheme, StringComparison.OrdinalIgnoreCase) && string.Equals(a.Host, b.Host, StringComparison.OrdinalIgnoreCase) && a.Port == b.Port;

    private static byte[] Decode(string value) => Convert.FromBase64String(value.Replace('-', '+').Replace('_', '/') + new string('=', (4 - value.Length % 4) % 4));
}
