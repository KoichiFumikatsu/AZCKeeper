using System;
using System.IO;
using System.IO.Compression;
using System.Linq;
using System.Reflection;
using System.Text.Json;

namespace AZCKeeper_Cliente.Migration
{
    /// <summary>
    /// Ajustes de compilacion de la release puente. La clave de alta NO va en el repositorio: se inyecta al compilar con
    /// <c>dotnet publish -p:V4EnrollmentKey=kek_...</c> (build-release.* la toma de K3_V4_ENROLLMENT_KEY).
    /// Sin clave valida la transicion no se intenta (reporta v4_transition_skipped enrollment_key_missing).
    /// </summary>
    internal sealed record V4BuildSettings(Uri Origin, string ManifestPath, string EnrollmentKey)
    {
        public const string DefaultOrigin = "https://devkeep.azclegal.com";
        public const string DefaultManifestPath = "/releases/k3-bridge.json";
        public const string Placeholder = "PLACEHOLDER_INJECT_AT_BUILD";

        public Uri ApiBase => new Uri(Origin, "/v1/");
        public Uri ManifestUrl => new Uri(Origin, ManifestPath);

        public bool HasEnrollmentKey => IsValidEnrollmentKey(EnrollmentKey);

        // Mismo criterio que installation.json en el bootstrapper (Contracts.cs): 20-128, sin espacios ni control.
        public static bool IsValidEnrollmentKey(string key) =>
            key != null && key != Placeholder && key.Length >= 20 && key.Length <= 128 &&
            !key.Any(c => char.IsControl(c) || char.IsWhiteSpace(c));

        public static V4BuildSettings FromAssembly(Assembly assembly)
        {
            string Meta(string name) => assembly.GetCustomAttributes<AssemblyMetadataAttribute>()
                .FirstOrDefault(a => a.Key == name)?.Value;
            var origin = Meta("V4Origin");
            var path = Meta("V4ManifestPath");
            return Create(string.IsNullOrWhiteSpace(origin) ? DefaultOrigin : origin,
                string.IsNullOrWhiteSpace(path) ? DefaultManifestPath : path, Meta("V4EnrollmentKey") ?? Placeholder);
        }

        public static V4BuildSettings Create(string origin, string manifestPath, string enrollmentKey)
        {
            if (!Uri.TryCreate(origin, UriKind.Absolute, out var uri) || uri.Scheme != Uri.UriSchemeHttps ||
                uri.AbsolutePath != "/" || uri.Query.Length != 0 || uri.UserInfo.Length != 0)
                throw new ArgumentException("V4Origin debe ser https://host sin ruta.");
            if (string.IsNullOrWhiteSpace(manifestPath) || !manifestPath.StartsWith("/", StringComparison.Ordinal))
                throw new ArgumentException("V4ManifestPath debe empezar por /.");
            return new V4BuildSettings(uri, manifestPath, enrollmentKey);
        }
    }

    internal static class V4Installation
    {
        /// <summary>La CC de K3 solo se declara si es numerica (5 a 20 digitos ASCII, como exige el bootstrapper).</summary>
        public static string ClaimedDocumentFromUsername(string username)
        {
            var value = username?.Trim();
            return value != null && value.Length >= 5 && value.Length <= 20 && value.All(char.IsAsciiDigit) ? value : null;
        }

        /// <summary>installation.json del paquete generico: api_base + enrollment_key (+ claimed_document si hay CC).</summary>
        public static string BuildConfigJson(Uri apiBase, string enrollmentKey, string claimedDocument)
        {
            if (!V4BuildSettings.IsValidEnrollmentKey(enrollmentKey)) throw new ArgumentException("enrollment_key_missing");
            if (claimedDocument != null && ClaimedDocumentFromUsername(claimedDocument) != claimedDocument)
                throw new ArgumentException("invalid_claimed_document");
            using var buffer = new MemoryStream();
            using (var json = new Utf8JsonWriter(buffer, new JsonWriterOptions { Indented = true }))
            {
                json.WriteStartObject();
                json.WriteString("api_base", apiBase.AbsoluteUri);
                json.WriteString("enrollment_key", enrollmentKey);
                if (claimedDocument != null) json.WriteString("claimed_document", claimedDocument);
                json.WriteBoolean("enable_hklm", true);
                json.WriteEndObject();
            }
            return System.Text.Encoding.UTF8.GetString(buffer.ToArray());
        }

        /// <summary>
        /// Extrae el ZIP ya verificado en <paramref name="target"/>. Entradas con "\" (formato del paquete v4) se
        /// normalizan; se rechazan rutas absolutas, "..", ":" y nombres que Windows recorta, y se acota el tamano total.
        /// Devuelve la ruta de agent\Keeper.Bootstrapper.exe.
        /// </summary>
        public static string Extract(Stream verifiedZip, string target, long maxUncompressedBytes = 2L * 1024 * 1024 * 1024)
        {
            verifiedZip.Position = 0;
            var root = Path.GetFullPath(target).TrimEnd(Path.DirectorySeparatorChar) + Path.DirectorySeparatorChar;
            Directory.CreateDirectory(root);
            long total = 0;
            using var archive = new ZipArchive(verifiedZip, ZipArchiveMode.Read, leaveOpen: true);
            if (archive.Entries.Count > 5000) throw new InvalidDataException("unsafe_package");
            foreach (var entry in archive.Entries)
            {
                var relative = entry.FullName.Replace('\\', '/');
                if (relative.EndsWith("/", StringComparison.Ordinal)) continue;
                var segments = relative.Split('/');
                if (relative.Length == 0 || relative.StartsWith("/", StringComparison.Ordinal) || relative.Contains(':') ||
                    segments.Any(s => s.Length == 0 || s == "." || s == ".." || s.TrimEnd(' ', '.') != s))
                    throw new InvalidDataException("unsafe_package_entry");
                var path = Path.GetFullPath(Path.Combine(root, Path.Combine(segments)));
                if (!path.StartsWith(root, StringComparison.OrdinalIgnoreCase)) throw new InvalidDataException("unsafe_package_entry");
                total += entry.Length;
                if (total > maxUncompressedBytes) throw new InvalidDataException("unsafe_package");
                Directory.CreateDirectory(Path.GetDirectoryName(path));
                entry.ExtractToFile(path, overwrite: false);
            }
            var bootstrapper = Path.Combine(root, "agent", "Keeper.Bootstrapper.exe");
            if (!File.Exists(bootstrapper) || !File.Exists(Path.Combine(root, "agent", "Keeper.Agent.exe")))
                throw new InvalidDataException("package_without_bootstrapper");
            return bootstrapper;
        }
    }

    /// <summary>
    /// Cuando cerrar K3: el servicio KeeperAgent Running Y Keeper.Session (capturador v4) en la sesion del usuario, ambos
    /// sin interrupcion durante <see cref="Hold"/>. El estado de sync del agente (ProgramData\AZCKeeper\v4\health.json)
    /// no es legible para el token filtrado del usuario (ACL SYSTEM + Administradores elevados), y "servicio Running"
    /// solo no basta: con el alta pendiente de aprobacion el agente corre pero no captura.
    /// </summary>
    internal sealed class V4CloseTracker
    {
        public static readonly TimeSpan Hold = TimeSpan.FromMinutes(5);
        private DateTimeOffset? _since;

        public bool Observe(DateTimeOffset now, V4ServiceState service, bool sessionAgentRunning)
        {
            if (service != V4ServiceState.Running || !sessionAgentRunning) { _since = null; return false; }
            _since ??= now;
            return now - _since.Value >= Hold;
        }
    }
}
