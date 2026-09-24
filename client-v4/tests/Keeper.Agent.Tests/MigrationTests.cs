using System.Diagnostics;
using System.Security.Cryptography;
using System.Security.Cryptography.X509Certificates;
using System.Text;
using System.Text.Json;
using System.Text.Json.Nodes;
using Keeper.Agent.Migration;
using Keeper.Agent.Storage;
using Keeper.Shared.Protocol;

namespace Keeper.Agent.Tests;

[CollectionDefinition("Migration scripts", DisableParallelization = true)]
public sealed class MigrationScriptCollection;

[Collection("Migration scripts")]
public sealed class MigrationTests
{
    [Theory]
    [InlineData("../outside")]
    [InlineData("/absolute")]
    [InlineData("C:/absolute")]
    [InlineData("folder/../../outside")]
    [InlineData("file:stream")]
    [InlineData("folder\\file")]
    [InlineData("file.")]
    [InlineData("folder /file")]
    public void RejectsArchivePathsThatCouldEscapeOrAliasStaging(string path)
    {
        using var directory = new TestDirectory();
        Assert.Throws<InvalidDataException>(() => BridgePackageVerifier.SafeEntryPath(directory.Root, path));
    }

    [Fact]
    public void AcceptsNestedArchiveFile()
    {
        using var directory = new TestDirectory();
        Assert.Equal(Path.Combine(directory.Root, "nested", "Bootstrap.ps1"), BridgePackageVerifier.SafeEntryPath(directory.Root, "nested/Bootstrap.ps1"));
    }

    [Theory]
    [InlineData(1, 0, 2, true)]
    [InlineData(1, 2, 2, false)]
    [InlineData(3, 0, 2, false)]
    [InlineData(1, 5, 4, false)]
    [InlineData(3, 5, 6, true)]
    public async Task BridgeUsesBothInstalledAndDurableRollbackFloors(long installed, long accepted, long sequence, bool valid)
    {
        using var key = ECDsa.Create(ECCurve.NamedCurves.nistP256);
        using var trust = InstalledTrust.FromDocument(new(new() { ["release"] = Convert.ToBase64String(key.ExportSubjectPublicKeyInfo()) }, new(), installed, "stable"), Path.GetTempPath());
        var bytes = Encoding.UTF8.GetBytes("bridge archive");
        var release = Sign(key, bytes, sequence);
        using var stream = new MemoryStream(bytes);
        var operation = () => BridgePackageVerifier.VerifyAsync(release, stream, trust, accepted, ReleaseArchitecture.X64, default);
        if (valid) await operation();
        else await Assert.ThrowsAsync<CryptographicException>(operation);
    }

    [Theory]
    [InlineData("archive")]
    [InlineData("sequence")]
    [InlineData("channel")]
    public async Task RejectsTamperedBridgeBeforeExecution(string changed)
    {
        using var key = ECDsa.Create(ECCurve.NamedCurves.nistP256);
        using var trust = InstalledTrust.FromDocument(new(new() { ["release"] = Convert.ToBase64String(key.ExportSubjectPublicKeyInfo()) }, new(), 1, "stable"), Path.GetTempPath());
        var bytes = "bridge archive"u8.ToArray();
        var release = Sign(key, bytes, 2);
        if (changed == "archive") bytes[0] ^= 1;
        if (changed == "sequence") release = release with { Sequence = 3 };
        if (changed == "channel") release = release with { Channel = "beta" };
        using var stream = new MemoryStream(bytes);
        await Assert.ThrowsAsync<CryptographicException>(() => BridgePackageVerifier.VerifyAsync(release, stream, trust, 0, ReleaseArchitecture.X64, default));
    }

    [Theory]
    [InlineData("valid")]
    [InlineData("wrong_pin")]
    [InlineData("wrong_name")]
    [InlineData("expired")]
    [InlineData("wrong_usage")]
    public void PublisherRequiresPinnedCertificateNameValidityAndCodeSigningUsage(string scenario)
    {
        using var key = RSA.Create(2048);
        var request = new CertificateRequest("CN=Grupo AZC", key, HashAlgorithmName.SHA256, RSASignaturePadding.Pkcs1);
        request.CertificateExtensions.Add(new X509EnhancedKeyUsageExtension(new OidCollection { new(scenario == "wrong_usage" ? "1.3.6.1.5.5.7.3.1" : "1.3.6.1.5.5.7.3.3") }, false));
        var now = DateTimeOffset.UtcNow;
        using var certificate = request.CreateSelfSigned(now.AddDays(-2), scenario == "expired" ? now.AddDays(-1) : now.AddDays(1));
        var pin = new BridgePublisher(scenario == "wrong_pin" ? new string('0', 64) : certificate.GetCertHashString(HashAlgorithmName.SHA256), scenario == "wrong_name" ? "CN=Other" : certificate.Subject);
        if (scenario == "valid") BridgePackageVerifier.VerifyPublisher(certificate, pin, now);
        else Assert.Throws<CryptographicException>(() => BridgePackageVerifier.VerifyPublisher(certificate, pin, now));
    }

    [Fact]
    public async Task PowerShellJournalAuditParserAndNativeCompilationRunWithoutElevation()
    {
        var root = FindRoot();
        var start = new ProcessStartInfo("powershell.exe") { UseShellExecute = false, CreateNoWindow = true, RedirectStandardError = true, RedirectStandardOutput = true, WorkingDirectory = root };
        foreach (var argument in new[] { "-NoProfile", "-NonInteractive", "-ExecutionPolicy", "Bypass", "-File", Path.Combine(root, "tests", "migration-invariants.ps1") }) start.ArgumentList.Add(argument);
        using var process = Process.Start(start)!;
        var output = process.StandardOutput.ReadToEndAsync();
        var error = process.StandardError.ReadToEndAsync();
        using var timeout = new CancellationTokenSource(TimeSpan.FromSeconds(60));
        try { await process.WaitForExitAsync(timeout.Token); }
        catch { process.Kill(entireProcessTree: true); throw; }
        Assert.True(process.ExitCode == 0, await output + await error);
    }

    private static string FindRoot()
    {
        for (var directory = new DirectoryInfo(AppContext.BaseDirectory); directory is not null; directory = directory.Parent)
            if (File.Exists(Path.Combine(directory.FullName, "Keeper.sln"))) return directory.FullName;
        throw new DirectoryNotFoundException();
    }

    private static Release Sign(ECDsa key, byte[] bytes, long sequence)
    {
        var release = new Release { Id = Guid.NewGuid(), Version = "4.0.1", Channel = "stable", Sequence = sequence,
            MinAgentVersion = "4.0.0", Architecture = ReleaseArchitecture.X64, ArtifactUrl = "https://keeper.test/bridge.zip",
            SizeBytes = bytes.Length, Sha256 = Convert.ToHexString(SHA256.HashData(bytes)).ToLowerInvariant(), KeyId = "release",
            ManifestJws = "", PublishedAt = DateTimeOffset.UtcNow };
        var payload = JsonSerializer.SerializeToNode(release, ProtocolJson.Options)!.AsObject();
        payload.Remove("manifest_jws");
        var input = Encode("{\"alg\":\"ES256\",\"kid\":\"release\"}"u8.ToArray()) + "." + Encode(JsonSerializer.SerializeToUtf8Bytes(payload));
        return release with { ManifestJws = input + "." + Encode(key.SignData(Encoding.ASCII.GetBytes(input), HashAlgorithmName.SHA256, DSASignatureFormat.IeeeP1363FixedFieldConcatenation)) };
    }

    private static string Encode(byte[] value) => Convert.ToBase64String(value).TrimEnd('=').Replace('+', '-').Replace('/', '_');
}
