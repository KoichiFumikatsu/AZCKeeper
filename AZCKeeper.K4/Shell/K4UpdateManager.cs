using System.IO.Compression;
using System.Text.Json;

namespace AZCKeeper.K4.Shell;

/// <summary>Lo que el server dice sobre la versión disponible (respuesta de client/version).</summary>
public sealed record ServerVersion(
    string? LatestVersion, string? DownloadUrl, string? MinimumVersion, bool ForceUpdate);

/// <summary>La decisión de actualizar, ya razonada. Reason es para el log/panel.</summary>
public sealed record UpdateDecision(
    bool ShouldDownload, bool Critical, string? Version, string? Url, string Reason);

/// <summary>
/// Chequeo y aplicación de actualizaciones del cliente. La DECISIÓN (Decide) es pura y
/// testeable; la descarga+swap es I/O y se verifica a mano. El swap lo hace el helper
/// externo AZCKeeperUpdater.exe: el cliente no puede sobrescribirse a sí mismo mientras
/// corre, así que lanza el helper y sale; el helper espera el cierre, copia y relanza.
///
/// Todo per-user (%LOCALAPPDATA%\AZCKeeper4\Updates), sin admin ni UAC. La descarga
/// evita el proxy del sistema (por si un módulo de bloqueo lo apuntó a un local).
/// </summary>
public sealed class K4UpdateManager
{
    private readonly string _baseUrl;
    private readonly string _currentVersion;
    private readonly bool _autoDownload;
    private readonly Func<string, Task<string?>> _httpGet;
    private readonly Action<string>? _log;

    private const long MinPayloadBytes = 1_000_000; // <1MB = respuesta de error, no un release

    public K4UpdateManager(string baseUrl, string currentVersion, bool autoDownload,
        Func<string, Task<string?>>? httpGet = null, Action<string>? log = null)
    {
        _baseUrl = baseUrl.TrimEnd('/');
        _currentVersion = currentVersion;
        _autoDownload = autoDownload;
        _httpGet = httpGet ?? DefaultGet;
        _log = log;
    }

    /// <summary>
    /// Decide si actualizar comparando versiones. Puro: sin red, sin disco. Se descarga si
    /// es crítico (por debajo del mínimo exigido), si el server lo fuerza, o si el equipo
    /// tiene auto-descarga activada. Nunca se "baja" de versión.
    /// </summary>
    public static UpdateDecision Decide(string currentVersion, ServerVersion? server, bool autoDownload)
    {
        if (server is null || string.IsNullOrWhiteSpace(server.LatestVersion))
            return new UpdateDecision(false, false, null, null, "sin respuesta de version");

        Version cur = Version.TryParse(currentVersion, out var c) ? c : new Version(0, 0, 0, 0);
        if (!Version.TryParse(server.LatestVersion, out var latest))
            return new UpdateDecision(false, false, server.LatestVersion, server.DownloadUrl, "version del server invalida");

        if (latest <= cur)
            return new UpdateDecision(false, false, server.LatestVersion, server.DownloadUrl, "al dia");

        bool critical = Version.TryParse(server.MinimumVersion, out var min) && cur < min;
        bool should = critical || server.ForceUpdate || autoDownload;
        string reason = critical ? "critico: por debajo del minimo exigido"
            : server.ForceUpdate ? "forzado por el servidor"
            : autoDownload ? "auto-descarga activada"
            : "disponible (descarga manual)";
        return new UpdateDecision(should, critical, server.LatestVersion, server.DownloadUrl, reason);
    }

    /// <summary>Consulta client/version y decide. allowBeta suma el canal beta.</summary>
    public async Task<UpdateDecision> CheckAsync(bool allowBeta = false)
    {
        var url = $"{_baseUrl}/client/version" + (allowBeta ? "?allowBeta=true" : "");
        var body = await _httpGet(url);
        if (body is null) return new UpdateDecision(false, false, null, null, "sin respuesta de version");

        ServerVersion? sv;
        try
        {
            using var doc = JsonDocument.Parse(body);
            var r = doc.RootElement;
            sv = new ServerVersion(
                LatestVersion: Str(r, "latestVersion"),
                DownloadUrl:   Str(r, "downloadUrl"),
                MinimumVersion: Str(r, "minimumVersion"),
                ForceUpdate: r.TryGetProperty("forceUpdate", out var f) && f.ValueKind == JsonValueKind.True);
        }
        catch (JsonException) { return new UpdateDecision(false, false, null, null, "json de version invalido"); }

        var decision = Decide(_currentVersion, sv, _autoDownload);
        _log?.Invoke($"update: {decision.Reason} (actual {_currentVersion}, server {sv?.LatestVersion ?? "?"})");
        return decision;
    }

    /// <summary>
    /// Descarga el ZIP, lo valida, lo extrae y lanza el helper de swap. Devuelve true si el
    /// helper quedó lanzado (el caller DEBE cerrar el cliente para que el swap ocurra).
    /// I/O: se verifica a mano, no en tests automatizados.
    /// </summary>
    public async Task<bool> ApplyAsync(UpdateDecision d, string? helperPath = null)
    {
        if (!d.ShouldDownload || string.IsNullOrWhiteSpace(d.Url) || string.IsNullOrWhiteSpace(d.Version))
        {
            _log?.Invoke("update: nada que aplicar");
            return false;
        }

        try
        {
            Directory.CreateDirectory(K4Paths.UpdatesDir);
            var zipPath = Path.Combine(K4Paths.UpdatesDir, $"AZCKeeper4_v{d.Version}.zip");

            // Descarga evitando el proxy del sistema.
            using (var http = new HttpClient(new HttpClientHandler { UseProxy = false }))
            {
                var bytes = await http.GetByteArrayAsync(d.Url);
                if (bytes.LongLength < MinPayloadBytes)
                {
                    _log?.Invoke($"update: descarga sospechosamente pequeña ({bytes.LongLength} bytes), abortando");
                    return false;
                }
                await File.WriteAllBytesAsync(zipPath, bytes);
            }

            var extractDir = Path.Combine(K4Paths.UpdatesDir, $"v{d.Version}");
            if (Directory.Exists(extractDir)) Directory.Delete(extractDir, recursive: true);
            ZipFile.ExtractToDirectory(zipPath, extractDir);

            var helper = helperPath ?? Path.Combine(extractDir, "AZCKeeperUpdater.exe");
            if (!File.Exists(helper))
            {
                _log?.Invoke("update: falta AZCKeeperUpdater.exe en el paquete, abortando");
                return false;
            }

            // helper <targetDir> <sourceDir> <oldExe>
            var psi = new System.Diagnostics.ProcessStartInfo(helper)
            {
                UseShellExecute = true,
                Arguments = $"\"{K4Paths.InstallDir}\" \"{extractDir}\" \"{K4Paths.AppExe}\"",
            };
            System.Diagnostics.Process.Start(psi);
            _log?.Invoke($"update: helper lanzado para v{d.Version}; el cliente debe cerrarse");
            return true;
        }
        catch (Exception ex)
        {
            _log?.Invoke($"update: error aplicando: {ex.Message}");
            return false;
        }
    }

    private static string? Str(JsonElement r, string name)
        => r.TryGetProperty(name, out var v) && v.ValueKind == JsonValueKind.String ? v.GetString() : null;

    private static async Task<string?> DefaultGet(string url)
    {
        try
        {
            using var http = new HttpClient(new HttpClientHandler { UseProxy = false }) { Timeout = TimeSpan.FromSeconds(20) };
            var res = await http.GetAsync(url);
            return res.IsSuccessStatusCode ? await res.Content.ReadAsStringAsync() : null;
        }
        catch { return null; }
    }
}
