using System;
using System.IO;
using System.Linq;
using System.Security.Cryptography;
using System.Text;
using System.Text.Json;
using System.Text.RegularExpressions;
using System.Threading;
using System.Threading.Tasks;

namespace AZCKeeper_Cliente.Migration
{
    /// <summary>Release v4 ya verificada: todos los campos salen del payload FIRMADO, no del JSON exterior.</summary>
    internal sealed record V4VerifiedRelease(string Version, long Sequence, Uri ArtifactUrl, long SizeBytes, string Sha256);

    /// <summary>
    /// Ancla de confianza de las releases v4, EMBEBIDA en K3 (nunca se toma del servidor).
    /// Clave publica: AZCKeeper-release-keys\release-key.public.json (ES256, P-256).
    /// </summary>
    internal sealed class V4ReleaseTrust
    {
        public const string EmbeddedKeyId = "MwoB43FowqNILNi2mdH0Cg8cOxRPMdGqTevRDPbatUg";
        private const string EmbeddedX = "1rt8dcLw_WlD1A12tzLrsXYyEqJ6IahMYgijcqS5OKg";
        private const string EmbeddedY = "lIqQ2j4_KtmyUrWmpX23us5LI7Vqg8Mz9Nwdj7eybh8";

        // Primera release v4 que entiende claimed_document (4.0.19 = sequence 20 la rechaza: installation.json no admite
        // campos desconocidos). Antirrollback: una release firmada mas vieja no se instala aunque la firma sea valida.
        public const long EmbeddedMinSequence = 21;
        public static readonly Version EmbeddedMinVersion = new Version(4, 0, 20);

        public const long MaxPackageBytes = 512L * 1024 * 1024;

        public string KeyId { get; }
        public ECParameters PublicKey { get; }
        public long MinSequence { get; }
        public Version MinVersion { get; }

        public V4ReleaseTrust(string keyId, ECParameters publicKey, long minSequence, Version minVersion)
        {
            KeyId = keyId; PublicKey = publicKey; MinSequence = minSequence; MinVersion = minVersion;
        }

        public static V4ReleaseTrust Embedded { get; } = new V4ReleaseTrust(EmbeddedKeyId, new ECParameters
        {
            Curve = ECCurve.NamedCurves.nistP256,
            Q = new ECPoint { X = V4ReleaseVerifier.Base64Url(EmbeddedX), Y = V4ReleaseVerifier.Base64Url(EmbeddedY) }
        }, EmbeddedMinSequence, EmbeddedMinVersion);
    }

    /// <summary>
    /// Port de Keeper.Agent ReleaseVerifier para K3: firma ES256 (JWS compacto) del manifiesto, canal/arquitectura,
    /// antirrollback, origen del paquete, tamano y SHA-256. Todo fallo lanza <see cref="CryptographicException"/>
    /// o <see cref="InvalidDataException"/> con un codigo corto (va al reporte, sin datos personales).
    /// </summary>
    internal static class V4ReleaseVerifier
    {
        private static readonly Regex PackageName = new Regex(@"^/releases/AZCKeeper_v4_bootstrap_[0-9]+(\.[0-9]+){1,3}\.zip$", RegexOptions.CultureInvariant);

        public static V4VerifiedRelease VerifyManifest(byte[] manifestJson, V4ReleaseTrust trust, Uri origin)
        {
            string jws;
            try
            {
                using var outer = JsonDocument.Parse(manifestJson);
                jws = outer.RootElement.GetProperty("manifest_jws").GetString();
            }
            catch (Exception ex) when (ex is JsonException or InvalidOperationException or System.Collections.Generic.KeyNotFoundException)
            {
                throw new InvalidDataException("invalid_manifest");
            }
            if (string.IsNullOrEmpty(jws) || jws.Length > 16384) throw new InvalidDataException("invalid_manifest");
            var parts = jws.Split('.');
            if (parts.Length != 3) throw new CryptographicException("invalid_manifest");

            byte[] headerBytes, payloadBytes, signature;
            try { headerBytes = Base64Url(parts[0]); payloadBytes = Base64Url(parts[1]); signature = Base64Url(parts[2]); }
            catch (FormatException) { throw new CryptographicException("invalid_manifest"); }

            try
            {
                using var header = JsonDocument.Parse(headerBytes);
                var h = header.RootElement;
                if (h.GetProperty("alg").GetString() != "ES256" || h.GetProperty("kid").GetString() != trust.KeyId ||
                    h.TryGetProperty("crit", out _) || h.TryGetProperty("b64", out _))
                    throw new CryptographicException("untrusted_key_or_alg");
            }
            catch (Exception ex) when (ex is JsonException or InvalidOperationException or System.Collections.Generic.KeyNotFoundException)
            {
                throw new CryptographicException("invalid_manifest");
            }

            using (var key = ECDsa.Create(trust.PublicKey))
            {
                if (signature.Length != 64 || !key.VerifyData(Encoding.ASCII.GetBytes(parts[0] + "." + parts[1]), signature,
                        HashAlgorithmName.SHA256, DSASignatureFormat.IeeeP1363FixedFieldConcatenation))
                    throw new CryptographicException("invalid_manifest_signature");
            }

            V4VerifiedRelease release;
            try
            {
                using var payload = JsonDocument.Parse(payloadBytes);
                var p = payload.RootElement;
                if (p.GetProperty("key_id").GetString() != trust.KeyId) throw new CryptographicException("untrusted_key_or_alg");
                if (p.GetProperty("channel").GetString() != "stable" || p.GetProperty("architecture").GetString() != "x64")
                    throw new CryptographicException("wrong_channel_or_architecture");
                var versionText = p.GetProperty("version").GetString();
                var sequence = p.GetProperty("sequence").GetInt64();
                if (!Version.TryParse(versionText, out var version) || version < trust.MinVersion || sequence < trust.MinSequence)
                    throw new CryptographicException("release_too_old");
                var size = p.GetProperty("size_bytes").GetInt64();
                if (size <= 0 || size > V4ReleaseTrust.MaxPackageBytes) throw new CryptographicException("invalid_package_size");
                var sha = p.GetProperty("sha256").GetString() ?? "";
                if (sha.Length != 64 || !sha.All(Uri.IsHexDigit)) throw new CryptographicException("invalid_manifest");
                if (!Uri.TryCreate(p.GetProperty("artifact_url").GetString(), UriKind.Absolute, out var artifact) ||
                    artifact.Scheme != Uri.UriSchemeHttps || !string.Equals(artifact.Host, origin.Host, StringComparison.OrdinalIgnoreCase) ||
                    artifact.Port != origin.Port || artifact.Query.Length != 0 || artifact.Fragment.Length != 0 || artifact.UserInfo.Length != 0 ||
                    !PackageName.IsMatch(artifact.AbsolutePath))
                    throw new CryptographicException("untrusted_artifact_url");
                release = new V4VerifiedRelease(versionText, sequence, artifact, size, sha.ToLowerInvariant());
            }
            catch (Exception ex) when (ex is JsonException or InvalidOperationException or System.Collections.Generic.KeyNotFoundException or FormatException)
            {
                throw new CryptographicException("invalid_manifest");
            }
            return release;
        }

        /// <summary>Tamano exacto y SHA-256 del paquete. Lee desde la posicion 0 del stream y lo deja al final.</summary>
        public static async Task VerifyPackageAsync(Stream package, V4VerifiedRelease release, CancellationToken ct)
        {
            package.Position = 0;
            using var hash = IncrementalHash.CreateHash(HashAlgorithmName.SHA256);
            var buffer = new byte[81920];
            long total = 0;
            int read;
            while ((read = await package.ReadAsync(buffer, ct).ConfigureAwait(false)) > 0)
            {
                total += read;
                if (total > release.SizeBytes) throw new CryptographicException("package_size_mismatch");
                hash.AppendData(buffer, 0, read);
            }
            if (total != release.SizeBytes) throw new CryptographicException("package_size_mismatch");
            if (!CryptographicOperations.FixedTimeEquals(hash.GetHashAndReset(), Convert.FromHexString(release.Sha256)))
                throw new CryptographicException("package_hash_mismatch");
        }

        public static byte[] Base64Url(string value) =>
            Convert.FromBase64String(value.Replace('-', '+').Replace('_', '/') + new string('=', (4 - value.Length % 4) % 4));
    }
}
