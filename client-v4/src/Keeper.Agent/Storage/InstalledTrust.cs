using System.Security.Cryptography;
using System.Text.Json;

namespace Keeper.Agent.Storage;

public sealed record InstallationTrustDocument(Dictionary<string, string> ReleasePublicKeys, Dictionary<string, string> BinaryHashes,
    long InstalledSequence, string Channel);

public sealed class InstalledTrust : IDisposable
{
    public Dictionary<string, ECDsa> ReleaseKeys { get; } = [];
    public Dictionary<string, string> BinaryHashes { get; } = new(StringComparer.OrdinalIgnoreCase);
    public long InstalledSequence { get; private set; }
    public string Channel { get; private set; } = "stable";
    public static InstalledTrust Load(string installationDirectory)
    {
        var root = Path.GetFullPath(installationDirectory).TrimEnd(Path.DirectorySeparatorChar) + Path.DirectorySeparatorChar;
        var path = Path.Combine(root, "installation-trust.json");
        if (!File.Exists(path)) return new InstalledTrust();
        var document = JsonSerializer.Deserialize<InstallationTrustDocument>(File.ReadAllBytes(path)) ?? throw new InvalidDataException("invalid_installation_trust");
        return FromDocument(document, root);
    }
    public static InstalledTrust FromDocument(InstallationTrustDocument document, string installationDirectory)
    {
        var result = new InstalledTrust();
        var root = Path.GetFullPath(installationDirectory).TrimEnd(Path.DirectorySeparatorChar) + Path.DirectorySeparatorChar;
        try
        {
            if (document.InstalledSequence < 1 || string.IsNullOrWhiteSpace(document.Channel)) throw new InvalidDataException("invalid_installation_release");
            result.InstalledSequence = document.InstalledSequence;
            result.Channel = document.Channel;
            foreach (var (id, publicKey) in document.ReleasePublicKeys)
            {
                var key = ECDsa.Create();
                try
                {
                    key.ImportSubjectPublicKeyInfo(Convert.FromBase64String(publicKey), out _);
                    if (key.KeySize != 256 || key.ExportParameters(false).Curve.Oid.Value != "1.2.840.10045.3.1.7") throw new CryptographicException("release_key_not_p256");
                    result.ReleaseKeys.Add(id, key);
                }
                catch { key.Dispose(); throw; }
            }
            foreach (var (relative, hash) in document.BinaryHashes)
            {
                var fullPath = Path.GetFullPath(Path.Combine(root, relative));
                if (Path.IsPathFullyQualified(relative) || !fullPath.StartsWith(root, StringComparison.OrdinalIgnoreCase) ||
                    hash.Length != 64 || !hash.All(Uri.IsHexDigit)) throw new InvalidDataException("invalid_trusted_binary");
                result.BinaryHashes.Add(fullPath, hash);
            }
            return result;
        }
        catch { result.Dispose(); throw; }
    }
    public void Dispose() { foreach (var key in ReleaseKeys.Values) key.Dispose(); }
}
