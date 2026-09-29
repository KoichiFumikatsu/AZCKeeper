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

// Baja el paquete de la release (artifact_url, HTTPS) a un archivo, con tope de tamaño.
public interface IReleaseDownloader { Task DownloadAsync(string url, string destination, long maxBytes, CancellationToken ct); }

// Extrae el ZIP verificado y lanza el bootstrapper como proceso independiente. Puede no retornar de
// forma observable: el bootstrapper detiene este servicio para reemplazar los binarios.
public interface IReleaseInstaller { void Install(string packagePath); }

public sealed class UpdateManager(string stagingDirectory, IReadOnlyDictionary<string, ECDsa> trustedKeys,
    long installedSequence = 0, string channel = "stable", IReleaseDownloader? downloader = null,
    IReleaseInstaller? installer = null, Version? agentVersion = null, string? blockedPath = null) : ModuleBase
{
    public override string Name => "UpdateManager";
    private readonly Version _agentVersion = agentVersion ?? new Version(4, 0, 0);
    private Release? _pending;
    private Guid? _verified;
    private bool _applied;
    private readonly HashSet<Guid> _failed = [];
    private DateTimeOffset _next;
    private string? _error;
    private Task? _downloading;
    private Release? _downloadingRelease;
    private DateTimeOffset _nextCleanup;
    private long? _reportedBlocked;

    // El bootstrapper escribe {data}\update-blocked.json al revertir una version que no sincronizo: esa secuencia
    // no se reintenta (solo una posterior), o el agente restaurado reinstalaria la rota cada ~12 minutos.
    private long? BlockedSequence()
    {
        if (blockedPath is null || !File.Exists(blockedPath)) return null;
        try
        {
            using var document = System.Text.Json.JsonDocument.Parse(File.ReadAllBytes(blockedPath));
            return document.RootElement.TryGetProperty("sequence", out var value) && value.TryGetInt64(out var sequence) ? sequence : null;
        }
        catch (Exception ex) when (ex is IOException or UnauthorizedAccessException or System.Text.Json.JsonException) { return null; }
    }

    // Cada update deja el ZIP y su extraccion (~200 MB). Se borra lo de mas de 1 h que no sea la release en curso;
    // lo reciente puede ser la carpeta desde la que aun corre el bootstrapper.
    private void CleanStaging()
    {
        var now = Context.Clock.GetUtcNow();
        if (now < _nextCleanup) return;
        _nextCleanup = now.AddHours(6);
        if (!Directory.Exists(stagingDirectory)) return;
        var keep = _pending?.Id.ToString("N");
        foreach (var entry in new DirectoryInfo(stagingDirectory).EnumerateFileSystemInfos())
        {
            if (keep is not null && entry.Name.StartsWith(keep, StringComparison.OrdinalIgnoreCase)) continue;
            if (now.UtcDateTime - entry.LastWriteTimeUtc < TimeSpan.FromHours(1)) continue;
            try { if (entry is DirectoryInfo d) d.Delete(recursive: true); else entry.Delete(); }
            catch (Exception ex) when (ex is IOException or UnauthorizedAccessException) { }
        }
    }

    public void Offer(Release? release)
    {
        if (release is null || release.Id == _pending?.Id) return;
        _pending = release;
        _next = DateTimeOffset.MinValue;
        _verified = null;
        _applied = false;
    }

    public override ModuleSnapshot Snapshot() => base.Snapshot() with { ErrorCode = _error };

    private string PackagePath(Release release) => Path.Combine(stagingDirectory, release.Id.ToString("N") + ".zip");

    public override async Task TickAsync(CancellationToken ct)
    {
        CleanStaging();
        if (_downloading is not null) { await PollDownloadAsync(ct); return; }
        var release = _pending;
        if (release is null || Context.Clock.GetUtcNow() < _next) return;
        _next = Context.Clock.GetUtcNow().AddMinutes(5);

        if (release.Id == _verified)
        {
            if (installer is null) { State = "verified_pending_install"; _error = null; return; }
            if (_applied) { State = "applying"; return; }
            _applied = true;
            await ReportAsync("update_applying", ct);
            State = "applying";
            Context.Log($"{Name}: lanzando --system-update para {release.Version}; el servicio se detendra");
            installer.Install(PackagePath(release));  // lanza el bootstrapper; puede detener este servicio
            return;
        }
        if (_failed.Contains(release.Id)) { State = "failed"; _error = "release_verification_failed"; return; }
        // Precheck barato ANTES de descargar: si el servidor ofrece una release que este agente ya tiene (o una
        // anterior, u otro canal), la verificacion completa la rechazaria igual, pero despues de bajar el paquete
        // entero, y _failed no sobrevive a un reinicio del servicio. La firma se sigue exigiendo al aplicar.
        if (release.Sequence <= installedSequence || release.Channel != channel) { State = "current"; _error = null; return; }
        if (BlockedSequence() == release.Sequence)
        {
            State = "failed"; _error = "release_rolled_back";
            if (_reportedBlocked != release.Sequence) { _reportedBlocked = release.Sequence; await ReportAsync(_error, ct, LogEntryLevel.Error); }
            return;
        }
        if (!trustedKeys.ContainsKey(release.KeyId))
        {
            if (State != "unsupported" || _error != "release_key_untrusted")
                await ReportAsync("release_key_untrusted", ct, LogEntryLevel.Warn);
            State = "unsupported"; _error = "release_key_untrusted"; return;
        }
        _error = null;
        var path = PackagePath(release);
        if (!File.Exists(path))
        {
            if (downloader is null) { State = "awaiting_package"; return; }
            Directory.CreateDirectory(stagingDirectory);
            _downloadingRelease = release;
            _downloading = downloader.DownloadAsync(release.ArtifactUrl, path + ".part", release.SizeBytes, Context.StoppingToken);
            State = "downloading";
            Context.Log($"{Name}: descargando release {release.Version} (sequence {release.Sequence}, {release.SizeBytes} bytes)");
            return;
        }
        try
        {
            await using var stream = new FileStream(path, FileMode.Open, FileAccess.Read, FileShare.Read);
            await ReleaseVerifier.VerifyAsync(release, stream, trustedKeys, installedSequence, channel,
                System.Runtime.InteropServices.RuntimeInformation.OSArchitecture == System.Runtime.InteropServices.Architecture.Arm64 ? ReleaseArchitecture.Arm64 : ReleaseArchitecture.X64,
                _agentVersion, ct);
        }
        catch (Exception ex) when (ex is not OperationCanceledException)
        {
            State = "failed";
            _error = "release_verification_failed";
            _failed.Add(release.Id);
            TryDelete(path);
            await ReportAsync(_error, ct, LogEntryLevel.Error);
            return;
        }
        _verified = release.Id;
        State = "verified_pending_install";
        await ReportAsync("package_verified", ct);
    }

    private async Task PollDownloadAsync(CancellationToken ct)
    {
        if (!_downloading!.IsCompleted) return;
        var finished = _downloading;
        var release = _downloadingRelease!;
        _downloading = null;
        _downloadingRelease = null;
        var part = PackagePath(release) + ".part";
        if (finished.IsCompletedSuccessfully)
        {
            try { File.Move(part, PackagePath(release), overwrite: true); }
            catch (IOException) { TryDelete(part); }
            State = "downloaded";  // el próximo tick verifica
        }
        else
        {
            TryDelete(part);
            State = "download_failed";
            _error = "download_failed";
            await ReportAsync(_error, ct, LogEntryLevel.Error);
        }
    }

    private static void TryDelete(string path) { try { if (File.Exists(path)) File.Delete(path); } catch (IOException) { } }
}
