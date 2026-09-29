// Keeper.ReleaseTool: firma de releases del agente v4 (manifest JWS ES256, NO Authenticode).
//
//   keygen       --out <dir>
//   trust        --public <release-key.public.json> --payload <dir agent> --sequence N [--channel stable]
//   sign         --key <release-signing-key.dpapi> --public <release-key.public.json> --package <zip>
//                --version X.Y.Z --sequence N --url https://.../pkg.zip [--channel stable] [--arch x64]
//                [--min-agent 4.0.0] --out <release.json>
//   backend-keys --public <release-key.public.json>
//   verify       --public <release-key.public.json> --release <release.json> --package <zip>
//                [--installed-sequence N] [--agent-version X.Y.Z]
//   export       --key <release-signing-key.dpapi> --public <release-key.public.json> --out <respaldo.p8>
//   import       --in <respaldo.p8> --public <release-key.public.json> --out <dir>
//
// export/import: respaldo PORTABLE de la clave privada (PKCS#8 cifrado, PEM "ENCRYPTED PRIVATE KEY",
// AES-256-CBC + PBKDF2-SHA256 600.000 iteraciones; legible tambien con openssl). El .dpapi NO sirve como
// respaldo: solo lo descifra el perfil de Windows que lo creo. La contrasena se pide en consola sin eco;
// nunca por argumentos. Con stdin redirigido se lee de stdin (solo para pruebas automatizadas).
//
// La clave privada queda protegida con DPAPI (usuario actual de ESTA maquina). Si se pierde, la flota
// instalada con el trust actual no acepta updates: respaldarla por un canal seguro fuera del repo.
using System.Globalization;
using System.IO.Compression;
using System.Security.Cryptography;
using System.Text;
using System.Text.Json;
using System.Text.Json.Nodes;
using Keeper.Agent.Modules.Update;
using Keeper.Agent.Storage;
using Keeper.Shared.Protocol;

namespace Keeper.ReleaseTool;

public static class Program
{
    private static readonly byte[] Entropy = Encoding.ASCII.GetBytes("AZCKeeper-release-signing-v1");
    private static readonly JsonSerializerOptions Indented = new() { WriteIndented = true };

    public static async Task<int> Main(string[] args)
    {
        try
        {
            if (args.Length == 0) throw new ArgumentException("comando requerido: keygen | trust | sign | backend-keys");
            var o = Parse(args.Skip(1).ToArray());
            switch (args[0])
            {
                case "keygen": KeyGen(Required(o, "out")); break;
                case "trust": Trust(o); break;
                case "sign": await SignAsync(o); break;
                case "backend-keys": Console.WriteLine(BackendKeys(PublicKey.Load(Required(o, "public")))); break;
                case "export": Export(o); break;
                case "import": Import(o); break;
                case "verify":
                {
                    // Verifica una release TAL COMO LLEGA (p. ej. copiada de la respuesta de /client/sync),
                    // con el mismo ReleaseVerifier del agente y la secuencia instalada indicada.
                    var pub = PublicKey.Load(Required(o, "public"));
                    var release = JsonSerializer.Deserialize<Release>(File.ReadAllText(Required(o, "release")), ProtocolJson.Options)
                        ?? throw new InvalidDataException("release ilegible");
                    using var verifier = pub.CreateVerifier();
                    await using var stream = File.OpenRead(Required(o, "package"));
                    await ReleaseVerifier.VerifyAsync(release, stream, new Dictionary<string, ECDsa> { [pub.KeyId] = verifier },
                        long.Parse(o.GetValueOrDefault("installed-sequence", (release.Sequence - 1).ToString(CultureInfo.InvariantCulture)), CultureInfo.InvariantCulture),
                        o.GetValueOrDefault("channel", release.Channel), release.Architecture,
                        Version.Parse(o.GetValueOrDefault("agent-version", release.MinAgentVersion)), CancellationToken.None);
                    Console.WriteLine($"verify OK: {release.Version} sequence {release.Sequence}");
                    break;
                }
                default: throw new ArgumentException($"comando desconocido: {args[0]}");
            }
            return 0;
        }
        catch (Exception ex) when (ex is ArgumentException or IOException or CryptographicException or InvalidDataException or JsonException or FormatException)
        {
            Console.Error.WriteLine($"Keeper.ReleaseTool: {ex.Message}");
            return 1;
        }
    }

    private static void KeyGen(string directory)
    {
        Directory.CreateDirectory(directory);
        var privatePath = Path.Combine(directory, "release-signing-key.dpapi");
        var publicPath = Path.Combine(directory, "release-key.public.json");
        if (File.Exists(privatePath) || File.Exists(publicPath)) throw new IOException("ya existe una clave en ese directorio; no se sobrescribe");
        using var key = ECDsa.Create(ECCurve.NamedCurves.nistP256);
        var pub = PublicKey.From(key);
        File.WriteAllBytes(privatePath, ProtectedData.Protect(key.ExportPkcs8PrivateKey(), Entropy, DataProtectionScope.CurrentUser));
        File.WriteAllText(publicPath, pub.ToJson());
        Console.WriteLine($"key_id: {pub.KeyId}");
        Console.WriteLine($"privada (DPAPI usuario actual): {privatePath}");
        Console.WriteLine($"publica: {publicPath}");
    }

    private const int MinPasswordLength = 12;
    private static readonly PbeParameters BackupPbe = new(PbeEncryptionAlgorithm.Aes256Cbc, HashAlgorithmName.SHA256, 600_000);

    private static ECDsa LoadPrivate(string dpapiPath)
    {
        var pkcs8 = ProtectedData.Unprotect(File.ReadAllBytes(dpapiPath), Entropy, DataProtectionScope.CurrentUser);
        try
        {
            var key = ECDsa.Create();
            key.ImportPkcs8PrivateKey(pkcs8, out _);
            return key;
        }
        finally { CryptographicOperations.ZeroMemory(pkcs8); }
    }

    private static void Export(Dictionary<string, string> o)
    {
        var pub = PublicKey.Load(Required(o, "public"));
        var output = Path.GetFullPath(Required(o, "out"));
        if (File.Exists(output)) throw new IOException("el archivo de respaldo ya existe; no se sobrescribe");
        using var key = LoadPrivate(Required(o, "key"));
        if (PublicKey.From(key).KeyId != pub.KeyId) throw new CryptographicException("la clave privada no corresponde a la publica indicada");
        var password = ReadPassword("Contrasena del respaldo (min. 12): ", confirm: true);
        try
        {
            var pem = PemEncoding.Write("ENCRYPTED PRIVATE KEY", key.ExportEncryptedPkcs8PrivateKey(password, BackupPbe));
            // Comprobacion antes de escribir: el respaldo debe abrirse con la misma contrasena y dar la misma clave.
            using (var check = ECDsa.Create())
            {
                check.ImportFromEncryptedPem(pem, password);
                if (PublicKey.From(check).KeyId != pub.KeyId) throw new CryptographicException("el respaldo no reproduce la clave");
            }
            using var stream = new FileStream(output, FileMode.CreateNew, FileAccess.Write, FileShare.None);
            stream.Write(Encoding.ASCII.GetBytes(new string(pem) + "\n"));
        }
        finally { Array.Clear(password); }
        Console.WriteLine($"respaldo: {output} (key_id {pub.KeyId})");
        Console.WriteLine("Guardalo FUERA de esta maquina y la contrasena por separado.");
    }

    private static void Import(Dictionary<string, string> o)
    {
        var pub = PublicKey.Load(Required(o, "public"));
        var directory = Path.GetFullPath(Required(o, "out"));
        var privatePath = Path.Combine(directory, "release-signing-key.dpapi");
        if (File.Exists(privatePath)) throw new IOException("ya existe release-signing-key.dpapi en el destino; no se sobrescribe");
        var pem = File.ReadAllText(Required(o, "in"));
        var password = ReadPassword("Contrasena del respaldo: ", confirm: false);
        using var key = ECDsa.Create();
        try { key.ImportFromEncryptedPem(pem, password); }
        catch (CryptographicException) { throw new CryptographicException("contrasena incorrecta o respaldo danado"); }
        finally { Array.Clear(password); }
        if (PublicKey.From(key).KeyId != pub.KeyId) throw new CryptographicException("el respaldo no corresponde a la clave publica indicada");
        Directory.CreateDirectory(directory);
        var pkcs8 = key.ExportPkcs8PrivateKey();
        try
        {
            using var stream = new FileStream(privatePath, FileMode.CreateNew, FileAccess.Write, FileShare.None);
            stream.Write(ProtectedData.Protect(pkcs8, Entropy, DataProtectionScope.CurrentUser));
        }
        finally { CryptographicOperations.ZeroMemory(pkcs8); }
        var publicCopy = Path.Combine(directory, "release-key.public.json");
        if (!File.Exists(publicCopy)) File.WriteAllText(publicCopy, pub.ToJson());
        using (var restored = LoadPrivate(privatePath))
            if (PublicKey.From(restored).KeyId != pub.KeyId) throw new CryptographicException("la clave restaurada no verifica");
        Console.WriteLine($"restaurada: {privatePath} (key_id {pub.KeyId}, DPAPI del usuario actual)");
    }

    private static char[] ReadPassword(string prompt, bool confirm)
    {
        if (Console.IsInputRedirected)
        {
            var line = Console.In.ReadLine() ?? "";
            var redirected = line.ToCharArray();
            if (confirm && Console.In.ReadLine() is { } again && again != line) throw new ArgumentException("las contrasenas no coinciden");
            if (redirected.Length < MinPasswordLength) throw new ArgumentException($"la contrasena debe tener al menos {MinPasswordLength} caracteres");
            return redirected;
        }
        var first = ReadHidden(prompt);
        if (first.Length < MinPasswordLength) { Array.Clear(first); throw new ArgumentException($"la contrasena debe tener al menos {MinPasswordLength} caracteres"); }
        if (!confirm) return first;
        var second = ReadHidden("Repetir contrasena: ");
        try
        {
            if (!first.AsSpan().SequenceEqual(second)) { Array.Clear(first); throw new ArgumentException("las contrasenas no coinciden"); }
            return first;
        }
        finally { Array.Clear(second); }
    }

    private static char[] ReadHidden(string prompt)
    {
        Console.Write(prompt);
        var buffer = new List<char>();
        while (true)
        {
            var info = Console.ReadKey(intercept: true);
            if (info.Key == ConsoleKey.Enter) break;
            if (info.Key == ConsoleKey.Backspace) { if (buffer.Count > 0) buffer.RemoveAt(buffer.Count - 1); continue; }
            if (!char.IsControl(info.KeyChar)) buffer.Add(info.KeyChar);
        }
        Console.WriteLine();
        var result = buffer.ToArray();
        buffer.Clear();
        return result;
    }

    private static void Trust(Dictionary<string, string> o)
    {
        var pub = PublicKey.Load(Required(o, "public"));
        var payload = Path.GetFullPath(Required(o, "payload"));
        var sequence = long.Parse(Required(o, "sequence"), CultureInfo.InvariantCulture);
        var channel = o.GetValueOrDefault("channel", "stable");
        var path = WriteTrust(payload, pub, sequence, channel);
        Console.WriteLine($"trust: {path} (sequence {sequence}, canal {channel})");
    }

    // Trust anclado en la instalacion: clave de release + hashes de los binarios (TamperGuard y el
    // lanzador de Keeper.Session los exigen). InstalledSequence = secuencia de ESTE paquete, para que el
    // agente ya actualizado no vuelva a aceptar la misma release.
    public static string WriteTrust(string payload, PublicKey pub, long sequence, string channel)
    {
        if (!File.Exists(Path.Combine(payload, "Keeper.Agent.exe"))) throw new InvalidDataException("el payload no contiene Keeper.Agent.exe");
        var hashes = new SortedDictionary<string, string>(StringComparer.OrdinalIgnoreCase);
        foreach (var file in Directory.EnumerateFiles(payload, "*", SearchOption.AllDirectories))
        {
            var extension = Path.GetExtension(file);
            if (!extension.Equals(".exe", StringComparison.OrdinalIgnoreCase) && !extension.Equals(".dll", StringComparison.OrdinalIgnoreCase)) continue;
            using var stream = File.OpenRead(file);
            hashes[Path.GetRelativePath(payload, file)] = Convert.ToHexString(SHA256.HashData(stream)).ToLowerInvariant();
        }
        var document = new InstallationTrustDocument(new Dictionary<string, string> { [pub.KeyId] = pub.Spki },
            new Dictionary<string, string>(hashes, StringComparer.OrdinalIgnoreCase), sequence, channel);
        using (InstalledTrust.FromDocument(document, payload)) { }  // mismo parser que el agente
        var path = Path.Combine(payload, "installation-trust.json");
        File.WriteAllText(path, JsonSerializer.Serialize(document, Indented));
        using (InstalledTrust.Load(payload)) { }
        return path;
    }

    private static async Task SignAsync(Dictionary<string, string> o)
    {
        var pub = PublicKey.Load(Required(o, "public"));
        using var key = LoadPrivate(Required(o, "key"));
        if (PublicKey.From(key).KeyId != pub.KeyId) throw new CryptographicException("la clave privada no corresponde a la publica indicada");
        var package = Path.GetFullPath(Required(o, "package"));
        var sequence = long.Parse(Required(o, "sequence"), CultureInfo.InvariantCulture);
        var channel = o.GetValueOrDefault("channel", "stable");
        var minAgent = o.GetValueOrDefault("min-agent", "4.0.0");
        CheckPackage(package, pub, sequence, channel);
        var release = Sign(key, pub.KeyId, package, Required(o, "version"), sequence, Required(o, "url"), channel,
            o.GetValueOrDefault("arch", "x64") == "arm64" ? ReleaseArchitecture.Arm64 : ReleaseArchitecture.X64, minAgent,
            DateTimeOffset.UtcNow);
        await VerifyAsync(release, package, pub, sequence - 1, channel, Version.Parse(minAgent));
        var output = Required(o, "out");
        File.WriteAllText(output, JsonSerializer.Serialize(release, ProtocolJson.Options));
        Console.WriteLine($"release: {output}");
        Console.WriteLine($"id {release.Id}, version {release.Version}, sequence {release.Sequence}, {release.SizeBytes} bytes, sha256 {release.Sha256}");
        Console.WriteLine("autoverificado con ReleaseVerifier del agente: OK");
    }

    public static Release Sign(ECDsa key, string keyId, string package, string version, long sequence, string url, string channel,
        ReleaseArchitecture architecture, string minAgent, DateTimeOffset now)
    {
        if (!Uri.TryCreate(url, UriKind.Absolute, out var uri) || uri.Scheme != "https") throw new ArgumentException("--url debe ser HTTPS absoluta");
        if (sequence < 1) throw new ArgumentException("--sequence debe ser >= 1");
        _ = Version.Parse(minAgent);
        byte[] hash;
        using (var stream = File.OpenRead(package)) hash = SHA256.HashData(stream);
        // Segundos enteros: el backend guarda TIMESTAMP(6) y reconstruye published_at; sin fraccion no hay
        // diferencia de redondeo entre el manifest firmado y lo que el agente recibe en el sync.
        var published = new DateTimeOffset(now.UtcTicks - now.UtcTicks % TimeSpan.TicksPerSecond, TimeSpan.Zero);
        var release = new Release
        {
            Id = Guid.NewGuid(), Version = version, Channel = channel, Sequence = sequence, MinAgentVersion = minAgent,
            Architecture = architecture, ArtifactUrl = url, SizeBytes = new FileInfo(package).Length,
            Sha256 = Convert.ToHexString(hash).ToLowerInvariant(), KeyId = keyId, ManifestJws = "", PublishedAt = published
        };
        var payload = JsonSerializer.SerializeToNode(release, ProtocolJson.Options)!.AsObject();
        payload.Remove("manifest_jws");
        var header = new JsonObject { ["alg"] = "ES256", ["kid"] = keyId };
        var input = B64(Encoding.UTF8.GetBytes(header.ToJsonString())) + "." + B64(JsonSerializer.SerializeToUtf8Bytes(payload));
        var signature = key.SignData(Encoding.ASCII.GetBytes(input), HashAlgorithmName.SHA256, DSASignatureFormat.IeeeP1363FixedFieldConcatenation);
        return release with { ManifestJws = input + "." + B64(signature) };
    }

    public static async Task VerifyAsync(Release release, string package, PublicKey pub, long installedSequence, string channel, Version agentVersion)
    {
        using var verifier = pub.CreateVerifier();
        await using var stream = File.OpenRead(package);
        // Ida y vuelta por JSON: es lo que el agente recibe del servidor.
        var received = JsonSerializer.Deserialize<Release>(JsonSerializer.Serialize(release, ProtocolJson.Options), ProtocolJson.Options)!;
        await ReleaseVerifier.VerifyAsync(received, stream, new Dictionary<string, ECDsa> { [pub.KeyId] = verifier },
            installedSequence, channel, release.Architecture, agentVersion, CancellationToken.None);
    }

    // El ZIP debe ser un paquete de update aplicable y traer un trust coherente con la release que se firma:
    // misma clave (si no, el agente queda sin poder recibir el siguiente update) y su propia secuencia
    // (si no, el agente actualizado vuelve a descargar e instalar la misma release en bucle).
    public static void CheckPackage(string package, PublicKey pub, long sequence, string channel)
    {
        using var zip = ZipFile.OpenRead(package);
        ZipArchiveEntry? Find(string name) => zip.GetEntry(name) ?? zip.GetEntry(name.Replace('/', '\\'));
        foreach (var name in new[] { "agent/Keeper.Agent.exe", "agent/Keeper.Session.exe", "agent/installation-trust.json" })
            if (Find(name) is null) throw new InvalidDataException($"paquete incompleto: falta {name}");
        // Formato compartido: bootstrapper dentro de agent/; formato anterior: en la raiz.
        if (Find("agent/Keeper.Bootstrapper.exe") is null && Find("Keeper.Bootstrapper.exe") is null)
            throw new InvalidDataException("paquete incompleto: falta Keeper.Bootstrapper.exe");
        using var reader = Find("agent/installation-trust.json")!.Open();
        var trust = JsonSerializer.Deserialize<InstallationTrustDocument>(reader) ?? throw new InvalidDataException("trust ilegible");
        if (!trust.ReleasePublicKeys.TryGetValue(pub.KeyId, out var spki) || spki != pub.Spki)
            throw new InvalidDataException("el trust del paquete no contiene esta clave de release");
        if (trust.InstalledSequence != sequence || trust.Channel != channel)
            throw new InvalidDataException($"el trust del paquete declara sequence {trust.InstalledSequence}/{trust.Channel}; la release es {sequence}/{channel}");
    }

    public static string BackendKeys(PublicKey pub) =>
        new JsonObject { [pub.KeyId] = new JsonObject { ["kty"] = "EC", ["crv"] = "P-256", ["x"] = pub.X, ["y"] = pub.Y } }
            .ToJsonString(Indented);

    private static Dictionary<string, string> Parse(string[] args)
    {
        var result = new Dictionary<string, string>(StringComparer.Ordinal);
        for (var i = 0; i < args.Length; i += 2)
        {
            if (!args[i].StartsWith("--", StringComparison.Ordinal) || i + 1 >= args.Length) throw new ArgumentException($"argumento invalido: {args[i]}");
            result[args[i][2..]] = args[i + 1];
        }
        return result;
    }

    private static string Required(Dictionary<string, string> o, string name) =>
        o.TryGetValue(name, out var value) && value.Length > 0 ? value : throw new ArgumentException($"falta --{name}");

    internal static string B64(byte[] bytes) => Convert.ToBase64String(bytes).TrimEnd('=').Replace('+', '-').Replace('/', '_');
}

public sealed record PublicKey(string KeyId, string Spki, string X, string Y)
{
    public static PublicKey From(ECDsa key)
    {
        var p = key.ExportParameters(false);
        var x = Program.B64(p.Q.X!);
        var y = Program.B64(p.Q.Y!);
        // RFC 7638: miembros requeridos en orden lexicografico, sin espacios.
        var thumbprint = Program.B64(SHA256.HashData(Encoding.UTF8.GetBytes("{\"crv\":\"P-256\",\"kty\":\"EC\",\"x\":\"" + x + "\",\"y\":\"" + y + "\"}")));
        return new PublicKey(thumbprint, Convert.ToBase64String(key.ExportSubjectPublicKeyInfo()), x, y);
    }

    public static PublicKey Load(string path)
    {
        var pub = JsonSerializer.Deserialize<PublicKey>(File.ReadAllText(path)) ?? throw new InvalidDataException("clave publica ilegible");
        using var key = pub.CreateVerifier();
        if (From(key) != pub) throw new InvalidDataException("clave publica inconsistente (key_id/x/y no corresponden al SPKI)");
        return pub;
    }

    public ECDsa CreateVerifier()
    {
        var key = ECDsa.Create();
        key.ImportSubjectPublicKeyInfo(Convert.FromBase64String(Spki), out _);
        if (key.KeySize != 256) { key.Dispose(); throw new CryptographicException("la clave no es P-256"); }
        return key;
    }

    public string ToJson() => JsonSerializer.Serialize(this, new JsonSerializerOptions { WriteIndented = true });
}
