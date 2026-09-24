using System.Security.Cryptography;
using System.Security.Cryptography.X509Certificates;
using Keeper.Agent.Modules.Update;
using Keeper.Agent.Storage;
using Keeper.Shared.Protocol;

namespace Keeper.Agent.Migration;

public sealed record BridgePublisher(string CertificateSha256, string Subject);

public static class BridgePackageVerifier
{
    // The caller supplies installed/compiled trust, never trust downloaded with the release.
    public static Task VerifyAsync(Release release, Stream package, InstalledTrust trust, long highestAcceptedSequence,
        ReleaseArchitecture architecture, CancellationToken ct) =>
        ReleaseVerifier.VerifyAsync(release, package, trust.ReleaseKeys, Math.Max(trust.InstalledSequence, highestAcceptedSequence),
            trust.Channel, architecture, new Version(4, 0, 0), ct);

    public static void VerifyPublisher(X509Certificate2 certificate, BridgePublisher expected, DateTimeOffset now)
    {
        if (expected.CertificateSha256.Length != 64 || string.IsNullOrWhiteSpace(expected.Subject) ||
            !certificate.GetCertHashString(HashAlgorithmName.SHA256).Equals(expected.CertificateSha256, StringComparison.OrdinalIgnoreCase) ||
            certificate.Subject != expected.Subject || now < certificate.NotBefore.ToUniversalTime() || now > certificate.NotAfter.ToUniversalTime() ||
            !certificate.Extensions.OfType<X509EnhancedKeyUsageExtension>().Any(e => e.EnhancedKeyUsages.Cast<Oid>().Any(o => o.Value == "1.3.6.1.5.5.7.3.3")))
            throw new CryptographicException("bridge_publisher_not_trusted");
    }

    public static string SafeEntryPath(string directory, string relative)
    {
        var root = Path.GetFullPath(directory).TrimEnd(Path.DirectorySeparatorChar) + Path.DirectorySeparatorChar;
        var path = Path.GetFullPath(Path.Combine(root, relative));
        if (string.IsNullOrEmpty(relative) || relative.Contains(':') || relative.Contains('\\') || Path.IsPathRooted(relative) ||
            !path.StartsWith(root, StringComparison.OrdinalIgnoreCase) || relative.Split('/').Any(p => p is ".." or "." || p.TrimEnd(' ', '.') != p))
            throw new InvalidDataException("unsafe_bridge_entry");
        return path;
    }
}
