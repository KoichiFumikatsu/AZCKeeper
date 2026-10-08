using System;
using System.Collections.Generic;
using System.IO;
using System.IO.Compression;
using System.Linq;
using System.Security.Cryptography;
using System.Text;
using System.Text.Json;
using System.Threading;
using System.Threading.Tasks;
using AZCKeeper_Cliente.Logging;
using AZCKeeper_Cliente.Migration;
using Xunit;

namespace AZCKeeper.Tests
{
    /// <summary>Release puente K3 -> Keeper v4: preflight, verificacion, CC, installation.json y cierre, sin Windows real.</summary>
    [Collection("LocalLogger")]
    public class V4TransitionTests : IDisposable
    {
        private const string Key = "kek_test-enrollment-key-0123456789";
        private const string Origin = "https://devkeep.test";
        private const string Artifact = Origin + "/releases/AZCKeeper_v4_bootstrap_4.0.20.zip";
        private static readonly V4BuildSettings Settings = V4BuildSettings.Create(Origin, "/releases/k3-bridge.json", Key);

        private readonly ECDsa _signer = ECDsa.Create(ECCurve.NamedCurves.nistP256);
        private readonly string _work = Path.Combine(Path.GetTempPath(), "k3-v4-tests-" + Guid.NewGuid().ToString("N"));
        private V4ReleaseTrust Trust => new V4ReleaseTrust("test-kid", _signer.ExportParameters(false), 21, new Version(4, 0, 20));

        public void Dispose()
        {
            _signer.Dispose();
            if (Directory.Exists(_work)) Directory.Delete(_work, true);
        }

        // ------------------------------------------------------------------ preflight

        [Theory]
        [InlineData(0, 1, 0, true, null)]
        [InlineData(0, 1, 2, true, null)]
        [InlineData(0, 1, 4, true, null)]
        [InlineData(0, 1, 5, true, null)]
        [InlineData(null, null, null, true, null)]
        [InlineData(0, 0, 0, true, null)]
        [InlineData(2, 1, 0, true, null)]
        [InlineData(0, 1, 1, true, "uac_requires_credentials")]
        [InlineData(0, 1, 3, true, "uac_requires_credentials")]
        [InlineData(1, 1, 0, true, "smart_app_control_on")]
        [InlineData(0, 1, 0, false, "user_not_admin")]
        [InlineData(1, 1, 3, false, "smart_app_control_on")]
        public void PreflightDecidesSkipOrProceedForEachValue(int? sac, int? lua, int? cpba, bool admin, string expected)
        {
            var result = new V4PreflightResult(lua, cpba, sac, admin, V4ServiceState.Missing);
            Assert.Equal(expected, result.SkipReason());
        }

        [Fact]
        public void PreflightReadsTheDocumentedRegistryValues()
        {
            var probe = new FakeProbe { Admin = true, Service = V4ServiceState.Stopped };
            probe.Dwords[(V4PreflightResult.UacPath, "EnableLUA")] = 1;
            probe.Dwords[(V4PreflightResult.UacPath, "ConsentPromptBehaviorAdmin")] = 0;
            probe.Dwords[(V4PreflightResult.SacPath, "VerifiedAndReputablePolicyState")] = 0;
            var read = V4PreflightResult.Read(probe, "KeeperAgent");
            Assert.Equal("uac_lua=1 uac_cpba=0 sac=0 admin=yes service=stopped", read.Describe());
            Assert.Null(read.SkipReason());
        }

        // ------------------------------------------------------------------ verificacion del paquete

        [Fact]
        public async Task GoodPackageVerifies()
        {
            var zip = Package();
            var release = V4ReleaseVerifier.VerifyManifest(Manifest(zip), Trust, Settings.Origin);
            Assert.Equal("4.0.20", release.Version);
            Assert.Equal(new Uri(Artifact), release.ArtifactUrl);
            using var stream = new MemoryStream(zip);
            await V4ReleaseVerifier.VerifyPackageAsync(stream, release, CancellationToken.None);
        }

        [Fact]
        public async Task BadShaIsRejected()
        {
            var zip = Package();
            var release = V4ReleaseVerifier.VerifyManifest(Manifest(zip, sha: new string('0', 64)), Trust, Settings.Origin);
            using var stream = new MemoryStream(zip);
            var error = await Assert.ThrowsAsync<CryptographicException>(() => V4ReleaseVerifier.VerifyPackageAsync(stream, release, CancellationToken.None));
            Assert.Equal("package_hash_mismatch", error.Message);
        }

        [Theory]
        [InlineData(-1)]
        [InlineData(1)]
        public async Task BadSizeIsRejected(int delta)
        {
            var zip = Package();
            var release = V4ReleaseVerifier.VerifyManifest(Manifest(zip, size: zip.Length + delta), Trust, Settings.Origin);
            using var stream = new MemoryStream(zip);
            var error = await Assert.ThrowsAsync<CryptographicException>(() => V4ReleaseVerifier.VerifyPackageAsync(stream, release, CancellationToken.None));
            Assert.Equal("package_size_mismatch", error.Message);
        }

        [Fact]
        public void BadSignatureIsRejected()
        {
            var zip = Package();
            using var other = ECDsa.Create(ECCurve.NamedCurves.nistP256);
            var forged = Manifest(zip, signer: other);
            Assert.Equal("invalid_manifest_signature", Assert.Throws<CryptographicException>(() => V4ReleaseVerifier.VerifyManifest(forged, Trust, Settings.Origin)).Message);
            // Payload alterado despues de firmar (p. ej. otro sha) con la firma original.
            var good = JsonDocument.Parse(Manifest(zip)).RootElement.GetProperty("manifest_jws").GetString().Split('.');
            var tampered = Encoding.UTF8.GetBytes(JsonSerializer.Serialize(new { manifest_jws = good[0] + "." + B64(Payload(zip, sha: new string('1', 64))) + "." + good[2] }));
            Assert.Equal("invalid_manifest_signature", Assert.Throws<CryptographicException>(() => V4ReleaseVerifier.VerifyManifest(tampered, Trust, Settings.Origin)).Message);
        }

        [Theory]
        [InlineData("https://evil.test/releases/AZCKeeper_v4_bootstrap_4.0.20.zip", null, null, null, "untrusted_artifact_url")]
        [InlineData("https://devkeep.test/otra/AZCKeeper_v4_bootstrap_4.0.20.zip", null, null, null, "untrusted_artifact_url")]
        [InlineData("http://devkeep.test/releases/AZCKeeper_v4_bootstrap_4.0.20.zip", null, null, null, "untrusted_artifact_url")]
        [InlineData(Artifact, "4.0.19", 20L, null, "release_too_old")]
        [InlineData(Artifact, null, null, "beta", "wrong_channel_or_architecture")]
        public void ManifestPolicyIsEnforced(string url, string version, long? sequence, string channel, string expected)
        {
            var manifest = Manifest(Package(), url: url, version: version, sequence: sequence, channel: channel);
            Assert.Equal(expected, Assert.Throws<CryptographicException>(() => V4ReleaseVerifier.VerifyManifest(manifest, Trust, Settings.Origin)).Message);
        }

        [Fact]
        public void KeyFromAnotherKidIsNotTrusted()
        {
            var manifest = Manifest(Package(), kid: "otro-kid");
            Assert.Equal("untrusted_key_or_alg", Assert.Throws<CryptographicException>(() => V4ReleaseVerifier.VerifyManifest(manifest, Trust, Settings.Origin)).Message);
        }

        // Manifiesto real publicado (release-4.0.19.json): la clave embebida lo valida; el antirrollback embebido lo
        // rechaza porque 4.0.19 aun no entiende claimed_document.
        [Fact]
        public void EmbeddedKeyVerifiesRealPublishedManifestAndFloorRejectsIt()
        {
            const string jws = "eyJhbGciOiJFUzI1NiIsImtpZCI6Ik13b0I0M0Zvd3FOSUxOaTJtZEgwQ2c4Y094UlBNZEdxVGV2UkRQYmF0VWcifQ.eyJpZCI6IjM5ZTkxZWM5LWZiYjktNDU5YS1iNjIzLTI0MjdlNjEyZThhMSIsInZlcnNpb24iOiI0LjAuMTkiLCJjaGFubmVsIjoic3RhYmxlIiwic2VxdWVuY2UiOjIwLCJtaW5fYWdlbnRfdmVyc2lvbiI6IjQuMC4wIiwiYXJjaGl0ZWN0dXJlIjoieDY0IiwiYXJ0aWZhY3RfdXJsIjoiaHR0cHM6Ly9kZXZrZWVwLmF6Y2xlZ2FsLmNvbS9yZWxlYXNlcy9BWkNLZWVwZXJfdjRfYm9vdHN0cmFwXzQuMC4xOS56aXAiLCJzaXplX2J5dGVzIjo2NTU5ODc4Mywic2hhMjU2IjoiNjE5NzdkZTY3YWYyNzUxMDVlMzEwNzFmZDNjZWYyNjgxYTQyYzcyMzM1MmRiYWE5YjEwZTE2NjNjNWI3MWJmYiIsImtleV9pZCI6Ik13b0I0M0Zvd3FOSUxOaTJtZEgwQ2c4Y094UlBNZEdxVGV2UkRQYmF0VWciLCJwdWJsaXNoZWRfYXQiOiIyMDI2LTEwLTA4VDE4OjM3OjE1LjAwMDAwMFx1MDAyQjAwOjAwIn0.DzwAsJCiYWcgJFC9nTzIa3zIVs8TJTJiSLpAZ6trmLpmxICoeia_NPVAqevN4VkH9vxfoTUxzrqeu7YuoN5Zhw";
            var manifest = Encoding.UTF8.GetBytes(JsonSerializer.Serialize(new { manifest_jws = jws }));
            var devkeep = new Uri(V4BuildSettings.DefaultOrigin);
            var permissive = new V4ReleaseTrust(V4ReleaseTrust.Embedded.KeyId, V4ReleaseTrust.Embedded.PublicKey, 20, new Version(4, 0, 19));
            var release = V4ReleaseVerifier.VerifyManifest(manifest, permissive, devkeep);
            Assert.Equal(65598783, release.SizeBytes);
            Assert.Equal("release_too_old", Assert.Throws<CryptographicException>(() => V4ReleaseVerifier.VerifyManifest(manifest, V4ReleaseTrust.Embedded, devkeep)).Message);
        }

        [Fact]
        public void ExtractionRejectsTraversal()
        {
            var zip = Zip(("agent\\Keeper.Bootstrapper.exe", "x"), ("agent\\Keeper.Agent.exe", "x"), ("..\\evil.txt", "x"));
            using var stream = new MemoryStream(zip);
            Assert.Throws<InvalidDataException>(() => V4Installation.Extract(stream, Path.Combine(_work, "x")));
            Assert.False(File.Exists(Path.Combine(_work, "evil.txt")));
        }

        // ------------------------------------------------------------------ CC e installation.json

        [Theory]
        [InlineData("1020304050", "1020304050")]
        [InlineData(" 1020304050 ", "1020304050")]
        [InlineData("12345", "12345")]
        [InlineData("1234", null)]
        [InlineData("123456789012345678901", null)]
        [InlineData("1.020.304.050", null)]
        [InlineData("juan.perez", null)]
        [InlineData("10203O4050", null)]
        [InlineData("", null)]
        [InlineData(null, null)]
        public void OnlyNumericCcIsDeclared(string username, string expected) =>
            Assert.Equal(expected, V4Installation.ClaimedDocumentFromUsername(username));

        [Fact]
        public void InstallationJsonHasApiBaseKeyAndDocument()
        {
            using var json = JsonDocument.Parse(V4Installation.BuildConfigJson(Settings.ApiBase, Key, "1020304050"));
            var root = json.RootElement;
            Assert.Equal("https://devkeep.test/v1/", root.GetProperty("api_base").GetString());
            Assert.Equal(Key, root.GetProperty("enrollment_key").GetString());
            Assert.Equal("1020304050", root.GetProperty("claimed_document").GetString());
            Assert.True(root.GetProperty("enable_hklm").GetBoolean());
            Assert.Equal(4, root.EnumerateObject().Count());
            Assert.False(root.TryGetProperty("device_id", out _));
        }

        [Fact]
        public void InstallationJsonWithoutDocumentOmitsTheField()
        {
            using var json = JsonDocument.Parse(V4Installation.BuildConfigJson(Settings.ApiBase, Key, null));
            Assert.False(json.RootElement.TryGetProperty("claimed_document", out _));
            Assert.Throws<ArgumentException>(() => V4Installation.BuildConfigJson(Settings.ApiBase, V4BuildSettings.Placeholder, null));
            Assert.Throws<ArgumentException>(() => V4Installation.BuildConfigJson(Settings.ApiBase, Key, "1.020.304"));
        }

        [Fact]
        public void DefaultBuildHasNoRealKeyAndPointsToDevkeep()
        {
            var settings = V4BuildSettings.FromAssembly(typeof(V4TransitionManager).Assembly);
            Assert.False(settings.HasEnrollmentKey);
            Assert.Equal("https://devkeep.azclegal.com/v1/", settings.ApiBase.AbsoluteUri);
            Assert.Equal("https://devkeep.azclegal.com/releases/k3-bridge.json", settings.ManifestUrl.AbsoluteUri);
        }

        // ------------------------------------------------------------------ cierre

        [Fact]
        public void CloseNeedsServiceAndSessionForFiveUninterruptedMinutes()
        {
            var tracker = new V4CloseTracker();
            var t = DateTimeOffset.UnixEpoch;
            Assert.False(tracker.Observe(t, V4ServiceState.Running, false));
            Assert.False(tracker.Observe(t, V4ServiceState.Running, true));
            Assert.False(tracker.Observe(t.AddMinutes(4), V4ServiceState.Running, true));
            Assert.False(tracker.Observe(t.AddMinutes(4.5), V4ServiceState.Stopped, true));
            Assert.False(tracker.Observe(t.AddMinutes(5), V4ServiceState.Running, true));
            Assert.False(tracker.Observe(t.AddMinutes(9.9), V4ServiceState.Running, true));
            Assert.True(tracker.Observe(t.AddMinutes(10), V4ServiceState.Running, true));
        }

        // ------------------------------------------------------------------ orquestacion

        [Fact]
        public async Task SmartAppControlOnSkipsOncePerStartWithoutTouchingAnything()
        {
            var h = Harness(); h.Probe.Dwords[(V4PreflightResult.SacPath, "VerifiedAndReputablePolicyState")] = 1;
            await h.Run(); h.Now = h.Now.AddDays(1); await h.Run();
            Assert.Equal(V4TransitionManager.Phase.Stopped, h.Manager.CurrentPhase);
            Assert.Single(h.Reports, r => r.StartsWith("v4_preflight:"));
            Assert.Single(h.Reports, r => r == "v4_transition_skipped: smart_app_control_on");
            Assert.Empty(h.Downloads); Assert.Equal(0, h.Launches);
        }

        [Fact]
        public async Task BuildWithoutEnrollmentKeySkips()
        {
            var h = Harness(settings: V4BuildSettings.Create(Origin, "/releases/k3-bridge.json", V4BuildSettings.Placeholder));
            await h.Run();
            Assert.Contains("v4_transition_skipped: enrollment_key_missing", h.Reports);
            Assert.Empty(h.Downloads);
        }

        [Fact]
        public async Task HappyPathWritesDocumentOnlyWhileBootstrapperRunsThenClosesK3()
        {
            var h = Harness();
            await h.Run();
            Assert.Equal(1, h.Launches);
            Assert.Equal("--config \"" + h.ConfigSeen + "\"", h.ArgumentsSeen);
            Assert.EndsWith(Path.Combine("pkg", "agent", "Keeper.Bootstrapper.exe"), h.ExecutableSeen);
            using (var config = JsonDocument.Parse(h.ConfigContent))
                Assert.Equal("1020304050", config.RootElement.GetProperty("claimed_document").GetString());
            Assert.False(File.Exists(h.ConfigSeen));
            Assert.Empty(Directory.EnumerateFileSystemEntries(_work));
            Assert.DoesNotContain(h.Reports, r => r.Contains("1020304050"));
            Assert.Contains(h.Reports, r => r.StartsWith("v4_transition_installed:"));
            Assert.Equal(V4TransitionManager.Phase.Watching, h.Manager.CurrentPhase);

            h.Probe.Service = V4ServiceState.Running; h.Probe.Session = true;
            h.Now = h.Now.AddSeconds(30); await h.Run();
            Assert.Equal(0, h.Closed);
            h.Now = h.Now.AddMinutes(5); await h.Run();
            Assert.Equal(1, h.Closed);
            Assert.Contains(h.Reports, r => r.StartsWith("v4_transition_done:"));
            Assert.Equal(V4TransitionManager.Phase.Done, h.Manager.CurrentPhase);
        }

        [Fact]
        public async Task RunningAgentGoesStraightToCloseWithoutDownloading()
        {
            var h = Harness(); h.Probe.Service = V4ServiceState.Running;
            await h.Run();
            Assert.Empty(h.Downloads);
            Assert.Equal(V4TransitionManager.Phase.Watching, h.Manager.CurrentPhase);
            // Alta pendiente: el servicio corre pero Session no captura -> K3 sigue y lo avisa una vez.
            h.Now = h.Now.AddMinutes(31); await h.Run(); await h.Run();
            Assert.Single(h.Reports, r => r.StartsWith("v4_transition_waiting:"));
            Assert.Equal(0, h.Closed);
        }

        [Fact]
        public async Task BadPackageFailsWithoutRunningAnythingAndRetriesInSixHours()
        {
            var h = Harness(corruptZip: true);
            await h.Run();
            Assert.Equal(0, h.Launches);
            Assert.Contains("v4_transition_failed: package_hash_mismatch; reintento en 6 h", h.Reports);
            Assert.Equal(h.Now + V4TransitionManager.RetryInterval, h.Manager.Due);
            h.Now = h.Now.AddHours(5); await h.Run();
            Assert.Equal(2, h.Downloads.Count);
            h.Now = h.Now.AddHours(1); await h.Run();
            Assert.Equal(4, h.Downloads.Count);
            Assert.Empty(Directory.EnumerateFileSystemEntries(_work));
        }

        [Fact]
        public async Task CancelledUacStopsUntilNextStartAndLeavesNoDocument()
        {
            var h = Harness(); h.LaunchBehavior = () => throw new V4ElevationCancelledException();
            await h.Run(); h.Now = h.Now.AddHours(7); await h.Run();
            Assert.Equal(1, h.Launches);
            Assert.Single(h.Reports, r => r.StartsWith("v4_transition_cancelled:"));
            Assert.Equal(V4TransitionManager.Phase.Stopped, h.Manager.CurrentPhase);
            Assert.False(File.Exists(h.ConfigSeen));
            Assert.Empty(Directory.EnumerateFileSystemEntries(_work));
        }

        [Theory]
        [InlineData(1, "bootstrapper_exit_1")]
        [InlineData(2, "bootstrapper_exit_2")]
        [InlineData(740, "not_elevated")]
        public async Task BootstrapperFailureIsReportedAndDocumentDeleted(int code, string reason)
        {
            var h = Harness(); h.LaunchBehavior = () => code;
            await h.Run();
            Assert.Contains($"v4_transition_failed: {reason}; reintento en 6 h", h.Reports);
            Assert.False(File.Exists(h.ConfigSeen));
            Assert.Equal(V4TransitionManager.Phase.Idle, h.Manager.CurrentPhase);
        }

        [Fact]
        public async Task TimeoutDeletesDocumentAndNextAttemptSweepsTheFolder()
        {
            var h = Harness(); h.LaunchBehavior = () => null;
            await h.Run();
            Assert.Contains("v4_transition_failed: bootstrapper_timeout; reintento en 6 h", h.Reports);
            Assert.False(File.Exists(h.ConfigSeen));
            Assert.NotEmpty(Directory.EnumerateFileSystemEntries(_work));
            h.LaunchBehavior = () => 0; h.Now = h.Now.AddHours(6); await h.Run();
            Assert.Empty(Directory.EnumerateFileSystemEntries(_work));
        }

        [Fact]
        public async Task NonNumericUsernameInstallsWithoutDocument()
        {
            var h = Harness(username: "juan.perez");
            await h.Run();
            using var config = JsonDocument.Parse(h.ConfigContent);
            Assert.False(config.RootElement.TryGetProperty("claimed_document", out _));
            Assert.Contains(h.Reports, r => r.Contains("cedula_declarada=no"));
        }

        // ------------------------------------------------------------------ ayudas

        private sealed class TestHarness
        {
            public FakeProbe Probe;
            public V4TransitionManager Manager;
            public DateTimeOffset Now = new DateTimeOffset(2026, 10, 8, 12, 0, 0, TimeSpan.Zero);
            public List<string> Reports = new List<string>();
            public List<Uri> Downloads = new List<Uri>();
            public int Launches, Closed;
            public string ConfigSeen, ConfigContent, ArgumentsSeen, ExecutableSeen;
            public Func<int?> LaunchBehavior = () => 0;
            public Task Run() => Manager.TickAsync(CancellationToken.None);
        }

        private TestHarness Harness(V4BuildSettings settings = null, bool corruptZip = false, string username = "1020304050")
        {
            var zip = Package();
            var manifest = Manifest(zip);
            if (corruptZip) { zip = (byte[])zip.Clone(); zip[10] ^= 0xFF; }
            var h = new TestHarness { Probe = new FakeProbe { Admin = true, Service = V4ServiceState.Missing } };
            h.Probe.Dwords[(V4PreflightResult.UacPath, "EnableLUA")] = 1;
            h.Probe.Dwords[(V4PreflightResult.UacPath, "ConsentPromptBehaviorAdmin")] = 0;
            h.Probe.Dwords[(V4PreflightResult.SacPath, "VerifiedAndReputablePolicyState")] = 0;
            var downloader = new FakeDownloader(h.Downloads, new Dictionary<string, byte[]>
            {
                [Origin + "/releases/k3-bridge.json"] = manifest, [Artifact] = zip
            });
            var launcher = new FakeLauncher((exe, args) =>
            {
                h.Launches++;
                h.ExecutableSeen = exe; h.ArgumentsSeen = args;
                h.ConfigSeen = Path.Combine(Path.GetDirectoryName(Path.GetDirectoryName(exe)), "installation.json");
                h.ConfigContent = File.ReadAllText(h.ConfigSeen);
                return h.LaunchBehavior();
            });
            h.Manager = new V4TransitionManager(settings ?? Settings, Trust, h.Probe, downloader, launcher, () => username,
                (level, message) => h.Reports.Add(message), () => h.Closed++, () => h.Now, _work);
            h.Manager.Start(TimeSpan.Zero);
            h.Manager.Dispose(); // los tests llaman TickAsync a mano; el timer real no interviene
            return h;
        }

        private sealed class FakeProbe : IV4SystemProbe
        {
            public Dictionary<(string, string), int> Dwords = new Dictionary<(string, string), int>();
            public bool Admin, Session;
            public V4ServiceState Service;
            public int? ReadHklmDword(string path, string name) => Dwords.TryGetValue((path, name), out var v) ? v : null;
            public bool UserIsAdministrator() => Admin;
            public V4ServiceState QueryService(string name) => Service;
            public bool SessionAgentRunning() => Session;
        }

        private sealed class FakeDownloader : IV4Downloader
        {
            private readonly List<Uri> _log; private readonly Dictionary<string, byte[]> _files;
            public FakeDownloader(List<Uri> log, Dictionary<string, byte[]> files) { _log = log; _files = files; }
            public Task<byte[]> GetSmallAsync(Uri url, int maxBytes, CancellationToken ct) { _log.Add(url); return Task.FromResult(_files[url.AbsoluteUri]); }
            public async Task DownloadAsync(Uri url, Stream destination, long maxBytes, CancellationToken ct)
            {
                _log.Add(url);
                var bytes = _files[url.AbsoluteUri];
                if (bytes.Length > maxBytes) throw new InvalidDataException("download_too_large");
                await destination.WriteAsync(bytes, ct);
            }
        }

        private sealed class FakeLauncher : IV4Launcher
        {
            private readonly Func<string, string, int?> _run;
            public FakeLauncher(Func<string, string, int?> run) => _run = run;
            public int? RunElevated(string executable, string arguments, string workingDirectory, TimeSpan timeout) => _run(executable, arguments);
        }

        private static byte[] Package() => Zip(("install.cmd", "@echo off"), ("installation.json", "{\"api_base\":\"https://backend.example.invalid/v1/\"}"),
            ("agent\\Keeper.Bootstrapper.exe", "MZ-bootstrapper"), ("agent\\Keeper.Agent.exe", "MZ-agent"));

        private static byte[] Zip(params (string Name, string Content)[] entries)
        {
            using var buffer = new MemoryStream();
            using (var archive = new ZipArchive(buffer, ZipArchiveMode.Create, true))
                foreach (var (name, content) in entries)
                    using (var writer = new StreamWriter(archive.CreateEntry(name).Open())) writer.Write(content);
            return buffer.ToArray();
        }

        private byte[] Payload(byte[] zip, string url = null, string version = null, long? sequence = null, string channel = null,
            string sha = null, long? size = null, string kid = null) =>
            Encoding.UTF8.GetBytes(JsonSerializer.Serialize(new Dictionary<string, object>
            {
                ["id"] = Guid.NewGuid(), ["version"] = version ?? "4.0.20", ["channel"] = channel ?? "stable", ["sequence"] = sequence ?? 21L,
                ["min_agent_version"] = "4.0.0", ["architecture"] = "x64", ["artifact_url"] = url ?? Artifact,
                ["size_bytes"] = size ?? zip.Length, ["sha256"] = sha ?? Convert.ToHexString(SHA256.HashData(zip)).ToLowerInvariant(),
                ["key_id"] = kid ?? "test-kid", ["published_at"] = "2026-10-08T00:00:00+00:00"
            }));

        private byte[] Manifest(byte[] zip, string url = null, string version = null, long? sequence = null, string channel = null,
            string sha = null, long? size = null, ECDsa signer = null, string kid = null)
        {
            var header = B64(Encoding.UTF8.GetBytes(JsonSerializer.Serialize(new { alg = "ES256", kid = kid ?? "test-kid" })));
            var payload = B64(Payload(zip, url, version, sequence, channel, sha, size, kid));
            var signature = (signer ?? _signer).SignData(Encoding.ASCII.GetBytes(header + "." + payload), HashAlgorithmName.SHA256,
                DSASignatureFormat.IeeeP1363FixedFieldConcatenation);
            return Encoding.UTF8.GetBytes(JsonSerializer.Serialize(new { manifest_jws = header + "." + payload + "." + B64(signature) }));
        }

        private static string B64(byte[] bytes) => Convert.ToBase64String(bytes).TrimEnd('=').Replace('+', '-').Replace('/', '_');
    }
}
