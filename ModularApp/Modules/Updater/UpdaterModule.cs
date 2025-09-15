using System;
using System.Diagnostics;
using System.Globalization;
using System.IO;
using System.Linq;
using System.Net;
using System.Net.Http;
using System.Reflection;
using System.Security.Cryptography;
using System.Text;
using System.Threading.Tasks;
using System.Windows.Forms;
using ModularApp.Core;
using Newtonsoft.Json.Linq;

namespace ModularApp.Modules.Updater
{
    public sealed class UpdaterModule : IAppModule, IDisposable
    {
        private AppCore _core;
        private System.Windows.Forms.Timer _periodic;
        private static readonly HttpClient _http = BuildHttpClient();

        private const string MANIFEST_URL =
            "https://github.com/KoichiFumikatsu/AZCKeeper/releases/latest/download/manifest.json";

        private const string MAIN_EXE_NAME = "AZCKEEPER.exe";
        private const string INSTALLED_VERSION_FILE = "installed.version";

        private static readonly TimeSpan CHECK_EVERY = TimeSpan.FromHours(6);
        private readonly string _tempDir = Path.Combine(Path.GetTempPath(), "AZCKeeper_Update");

        public string Name => "Updater";
        public bool Enabled => _core == null ? true : _core.Config.Modules.UpdaterEnabled;

        public void Init(AppCore core)
        {
            _core = core;
            Directory.CreateDirectory(_tempDir);
            _core?.Logger.Info($"[Updater] Init. TempDir: \"{_tempDir}\"  ManifestURL: {MANIFEST_URL}");
        }

        public void Start()
        {
            if (!Enabled)
            {
                _core?.Logger.Warn("[Updater] Deshabilitado por configuración.");
                return;
            }

            var t = new System.Windows.Forms.Timer { Interval = 5000 };
            t.Tick += async (_, __) => { t.Stop(); t.Dispose(); await SafeCheckAsync("startup"); };
            t.Start();

            _periodic = new System.Windows.Forms.Timer { Interval = (int)CHECK_EVERY.TotalMilliseconds };
            _periodic.Tick += async (_, __) => await SafeCheckAsync("periodic");
            _periodic.Start();

            _core?.Logger.Info("[Updater] Ready.");
        }

        public void Stop()
        {
            try { _periodic?.Stop(); _periodic?.Dispose(); } catch { }
        }

        public void Dispose() => Stop();

        public async Task CheckNow() => await SafeCheckAsync("manual");

        private async Task SafeCheckAsync(string reason)
        {
            try
            {
                _core?.Logger.Info($"[Updater] Check triggered ({reason})…");
                await CheckAndMaybeUpdateAsync();
            }
            catch (Exception ex)
            {
                _core?.Logger.Warn("[Updater] Check failed: " + ex.Message);
            }
        }

        private async Task CheckAndMaybeUpdateAsync()
        {
            // 1) Manifest remoto
            string manifestPath = Path.Combine(_tempDir, "manifest.json");
            var (mfOk, mfBytes, mfErr) = await DownloadTextWithRetriesAsync(MANIFEST_URL, manifestPath, "manifest");
            if (!mfOk)
            {
                _core?.Logger.Error($"[Updater] No se pudo descargar el manifest. Error: {mfErr}");
                return;
            }
            _core?.Logger.Info($"[Updater] Manifest guardado: \"{manifestPath}\" ({mfBytes} bytes)");

            // 2) Parse
            string json = await File.ReadAllTextAsync(manifestPath, Encoding.UTF8).ConfigureAwait(false);
            var root = JObject.Parse(json);

            string remoteVer =
                (string)root["version"] ??
                (string)root["Version"] ??
                (string)root["tag"] ??
                (string)root["tag_name"];

            JToken fileTok =
                (root["files"] as JArray)?.FirstOrDefault() ??
                root["asset"] ??
                root["file"];

            string url = (string)(fileTok?["url"] ?? root["url"]);
            string sha256 = (string)(fileTok?["sha256"] ?? root["sha256"]);
            string name = (string)(fileTok?["name"]) ?? url?.GetFileNameSafe();

            if (string.IsNullOrWhiteSpace(remoteVer) || string.IsNullOrWhiteSpace(url))
            {
                _core?.Logger.Warn("[Updater] Manifest incompleto. Faltan 'version' o 'url'.");
                return;
            }

            // 3) Versiones
            string remoteVerNorm = NormalizeSemver(remoteVer);
            string localVer = GetInstalledVersionOrAssembly();
            _core?.Logger.Info($"[Updater] Local={localVer} Remote={remoteVerNorm}");

            if (!IsNewer(remoteVerNorm, localVer))
            {
                _core?.Logger.Info("[Updater] Ya estás en la última versión.");
                return;
            }

            _core?.Logger.Warn($"[Updater] Nueva versión disponible: {remoteVerNorm}");

            // 4) Descargar ZIP
            if (string.IsNullOrEmpty(name)) name = $"update-{remoteVerNorm}.zip";
            string zipPath = Path.Combine(_tempDir, name);
            var (zipOk, zipBytes, zipErr) = await DownloadFileWithRetriesAsync(url, zipPath, "package");
            if (!zipOk)
            {
                _core?.Logger.Error($"[Updater] Falló la descarga del paquete. Error: {zipErr}");
                return;
            }
            _core?.Logger.Info($"[Updater] ZIP: \"{zipPath}\" ({zipBytes} bytes)");

            // 5) SHA256 opcional
            if (!string.IsNullOrWhiteSpace(sha256))
            {
                string got = ComputeSha256(zipPath);
                _core?.Logger.Info($"[Updater] SHA256 archivo: {got}");
                if (!got.Equals(sha256, StringComparison.OrdinalIgnoreCase))
                {
                    _core?.Logger.Error($"[Updater] SHA256 no coincide. Esperado={sha256} Got={got}");
                    try { File.Delete(zipPath); } catch { }
                    return;
                }
            }

            // 6) Lanzar updater externo y cerrar
            bool ok = LaunchExternalUpdater(zipPath, remoteVerNorm, showWindow: true);
            if (!ok)
            {
                _core?.Logger.Error("[Updater] No se pudo iniciar el actualizador externo.");
                return;
            }

            _core?.Logger.Warn("[Updater] Updater lanzado, cerrando Keeper…");
            Environment.Exit(0);
        }

        // ---------- helpers de descarga ----------

        private static HttpClient BuildHttpClient()
        {
            try { ServicePointManager.SecurityProtocol = SecurityProtocolType.Tls12 | SecurityProtocolType.Tls13; } catch { }
            var handler = new HttpClientHandler { AutomaticDecompression = DecompressionMethods.GZip | DecompressionMethods.Deflate };
            var cli = new HttpClient(handler) { Timeout = TimeSpan.FromSeconds(45) };
            try { cli.DefaultRequestHeaders.UserAgent.ParseAdd("AZCKeeper-Updater/1.0"); } catch { }
            return cli;
        }

        private async Task<(bool ok, long bytes, string error)> DownloadTextWithRetriesAsync(string url, string savePath, string tag, int maxAttempts = 3)
        {
            Directory.CreateDirectory(Path.GetDirectoryName(savePath));
            string tmpPath = savePath + ".part"; long bytes = 0;
            for (int attempt = 1; attempt <= maxAttempts; attempt++)
            {
                try
                {
                    _core?.Logger.Info($"[Updater] [{tag}] GET {url} (try {attempt}/{maxAttempts})");
                    using var resp = await _http.GetAsync(url, HttpCompletionOption.ResponseHeadersRead).ConfigureAwait(false);
                    _core?.Logger.Info($"[Updater] [{tag}] Status: {(int)resp.StatusCode} {resp.ReasonPhrase}");
                    if (!resp.IsSuccessStatusCode)
                    {
                        string body = await resp.Content.ReadAsStringAsync().ConfigureAwait(false);
                        _core?.Logger.Warn($"[Updater] [{tag}] HTTP {(int)resp.StatusCode} Body: {Truncate(body, 500)}");
                        await Task.Delay(Backoff(attempt)).ConfigureAwait(false);
                        continue;
                    }
                    using (var s = await resp.Content.ReadAsStreamAsync().ConfigureAwait(false))
                    using (var f = File.Create(tmpPath))
                        bytes = await CopyStreamAsync(s, f).ConfigureAwait(false);

                    if (File.Exists(savePath)) File.Delete(savePath);
                    File.Move(tmpPath, savePath);
                    return (true, bytes, null);
                }
                catch (Exception ex)
                {
                    _core?.Logger.Warn($"[Updater] [{tag}] Error descarga: {ex.Message}");
                    try { if (File.Exists(tmpPath)) File.Delete(tmpPath); } catch { }
                    await Task.Delay(Backoff(attempt)).ConfigureAwait(false);
                }
            }
            return (false, bytes, "Max reintentos alcanzados");
        }

        private async Task<(bool ok, long bytes, string error)> DownloadFileWithRetriesAsync(string url, string savePath, string tag, int maxAttempts = 3)
        {
            Directory.CreateDirectory(Path.GetDirectoryName(savePath));
            string tmpPath = savePath + ".part"; long bytes = 0;
            for (int attempt = 1; attempt <= maxAttempts; attempt++)
            {
                try
                {
                    _core?.Logger.Info($"[Updater] [{tag}] GET {url} (try {attempt}/{maxAttempts})");
                    using var resp = await _http.GetAsync(url, HttpCompletionOption.ResponseHeadersRead).ConfigureAwait(false);
                    _core?.Logger.Info($"[Updater] [{tag}] Status: {(int)resp.StatusCode} {resp.ReasonPhrase}");
                    if (!resp.IsSuccessStatusCode)
                    {
                        string body = await resp.Content.ReadAsStringAsync().ConfigureAwait(false);
                        _core?.Logger.Warn($"[Updater] [{tag}] HTTP {(int)resp.StatusCode} Body: {Truncate(body, 500)}");
                        await Task.Delay(Backoff(attempt)).ConfigureAwait(false);
                        continue;
                    }
                    using (var s = await resp.Content.ReadAsStreamAsync().ConfigureAwait(false))
                    using (var f = File.Create(tmpPath))
                        bytes = await CopyStreamAsync(s, f).ConfigureAwait(false);

                    if (File.Exists(savePath)) File.Delete(savePath);
                    File.Move(tmpPath, savePath);
                    _core?.Logger.Info($"[Updater] [{tag}] Descargado OK -> \"{savePath}\" ({bytes} bytes)");
                    return (true, bytes, null);
                }
                catch (Exception ex)
                {
                    _core?.Logger.Warn($"[Updater] [{tag}] Error descarga: {ex.Message}");
                    try { if (File.Exists(tmpPath)) File.Delete(tmpPath); } catch { }
                    await Task.Delay(Backoff(attempt)).ConfigureAwait(false);
                }
            }
            _core?.Logger.Warn($"[Updater] [{tag}] Falló la descarga tras {maxAttempts} intentos.");
            return (false, bytes, "Max reintentos alcanzados");
        }

        private static async Task<long> CopyStreamAsync(Stream src, Stream dest, int bufferSize = 81920)
        {
            byte[] buffer = new byte[bufferSize];
            int read; long total = 0;
            while ((read = await src.ReadAsync(buffer, 0, buffer.Length).ConfigureAwait(false)) > 0)
            { await dest.WriteAsync(buffer, 0, read).ConfigureAwait(false); total += read; }
            return total;
        }

        private static TimeSpan Backoff(int attempt)
        {
            int seconds = Math.Max(1, (int)Math.Pow(2, attempt) - 1);
            return TimeSpan.FromSeconds(seconds);
        }

        // ---------- lanzar updater externo ----------

        private static string QuoteArg(string s)
        {
            if (string.IsNullOrWhiteSpace(s)) return "\"\"";
            s = s.Trim().Trim('"').TrimEnd('\\');
            return s.Contains(" ") ? $"\"{s}\"" : s;
        }

        private bool LaunchExternalUpdater(string zipPath, string newVersion, bool showWindow = false)
        {
            try
            {
                var baseDir = AppContext.BaseDirectory;
                string keeperExe = Path.Combine(baseDir, MAIN_EXE_NAME);
                if (!File.Exists(keeperExe)) keeperExe = Application.ExecutablePath;
                _core?.Logger.Info($"[Updater] Restart exe: \"{keeperExe}\" Exists={File.Exists(keeperExe)}");

                string[] candidates = {
                    Path.Combine(baseDir, "AZC.Updater.exe"),
                    Path.Combine(baseDir, "updater", "AZC.Updater.exe"),
                    Path.Combine(baseDir, "Tools", "AZC.Updater.exe")
                };
                foreach (var c in candidates)
                    _core?.Logger.Info($"[Updater] Candidate: \"{c}\" Exists={File.Exists(c)}");

                string updaterExe = candidates.FirstOrDefault(File.Exists);
                if (string.IsNullOrEmpty(updaterExe)) { _core?.Logger.Error("[Updater] AZC.Updater.exe no encontrado."); return false; }
                if (!File.Exists(zipPath)) { _core?.Logger.Error($"[Updater] ZIP no existe: \"{zipPath}\""); return false; }

                string updaterBootLog = Path.Combine(Path.GetTempPath(), "AZC_Updater_boot.log");
                string updaterLog = Path.Combine(baseDir, "logs", "AZC_Updater.log");
                try { Directory.CreateDirectory(Path.GetDirectoryName(updaterLog)); } catch { }

                string args =
                    "--pid " + Process.GetCurrentProcess().Id +
                    " --dir " + QuoteArg(baseDir) +
                    " --zip " + QuoteArg(zipPath) +
                    " --preserve " + QuoteArg("appsettings.xml;logs") +
                    " --restart " + QuoteArg(keeperExe) +
                    " --log " + QuoteArg(updaterLog) +
                    " --version " + QuoteArg(newVersion);   // <<< clave para cortar el bucle

                var psi = new ProcessStartInfo
                {
                    FileName = updaterExe,
                    Arguments = args,
                    WorkingDirectory = Path.GetDirectoryName(updaterExe),
                    UseShellExecute = false,
                    CreateNoWindow = !showWindow,
                    WindowStyle = showWindow ? ProcessWindowStyle.Normal : ProcessWindowStyle.Hidden
                };

                _core?.Logger.Info($"[Updater] Exec: \"{psi.FileName}\"");
                _core?.Logger.Info($"[Updater] Args: {psi.Arguments}");
                _core?.Logger.Info($"[Updater] WorkDir: \"{psi.WorkingDirectory}\"");

                Process p = null;
                try { p = Process.Start(psi); }
                catch (System.ComponentModel.Win32Exception w32)
                {
                    _core?.Logger.Error($"[Updater] Start falló (Win32): {w32.Message} native=0x{w32.NativeErrorCode:X}");
                    try { psi.UseShellExecute = true; psi.Verb = ""; p = Process.Start(psi); _core?.Logger.Warn("[Updater] Reintento UseShellExecute=true funcionó"); }
                    catch (Exception ex2) { _core?.Logger.Error("[Updater] Reintento UseShellExecute=true falló: " + ex2.Message); return false; }
                }

                if (p == null) { _core?.Logger.Error("[Updater] Process.Start devolvió null."); return false; }

                System.Threading.Thread.Sleep(500);
                bool bootSeen = File.Exists(updaterBootLog);
                _core?.Logger.Info($"[Updater] Updater lanzado pid={p.Id}. BootLogExists={bootSeen} BootLog=\"{updaterBootLog}\"");

                if (bootSeen)
                {
                    try
                    {
                        var lines = File.ReadAllLines(updaterBootLog).Reverse().Take(5).Reverse();
                        foreach (var line in lines) _core?.Logger.Info("[Updater] BootLog >> " + line);
                    }
                    catch { }
                }
                else
                {
                    _core?.Logger.Warn("[Updater] No se detectó boot log. Posible AV/SmartScreen.");
                }

                return true;
            }
            catch (Exception ex)
            {
                _core?.Logger.Error("[Updater] Error al lanzar updater: " + ex);
                return false;
            }
        }

        // ---------- utilidades de versión ----------

        private string GetInstalledVersionOrAssembly()
        {
            try
            {
                var baseDir = AppContext.BaseDirectory;
                var path = Path.Combine(baseDir, INSTALLED_VERSION_FILE);
                if (File.Exists(path))
                {
                    var v = NormalizeSemver(File.ReadAllText(path)?.Trim());
                    if (!string.IsNullOrWhiteSpace(v)) return v;
                }
            }
            catch { }

            // fallback
            return GetAssemblyVersionNormalized();
        }

        private static string GetAssemblyVersionNormalized()
        {
            var asm = Assembly.GetExecutingAssembly();
            var info = asm.GetCustomAttributes(typeof(AssemblyInformationalVersionAttribute), false);
            string v = info is AssemblyInformationalVersionAttribute[] a && a.Length > 0
                ? a[0].InformationalVersion
                : asm.GetName().Version?.ToString() ?? "0.0.0";
            return NormalizeSemver(v);
        }

        private static string NormalizeSemver(string v)
        {
            if (string.IsNullOrWhiteSpace(v)) return "0.0.0";
            v = v.Trim();
            if (v.StartsWith("v", StringComparison.OrdinalIgnoreCase)) v = v.Substring(1);
            int cut = v.IndexOfAny(new[] { '-', '+' });
            if (cut > 0) v = v.Substring(0, cut);
            return v;
        }

        private static bool IsNewer(string remote, string local)
        {
            if (string.IsNullOrWhiteSpace(remote) || string.IsNullOrWhiteSpace(local)) return false;
            Version vR, vL;
            return Version.TryParse(remote, out vR) && Version.TryParse(local, out vL) && vR > vL;
        }

        private static string ComputeSha256(string file)
        {
            using (var sha = SHA256.Create())
            using (var fs = File.OpenRead(file))
            {
                var hash = sha.ComputeHash(fs);
                return BitConverter.ToString(hash).Replace("-", "").ToLowerInvariant();
            }
        }

        private static string Truncate(string s, int max)
        {
            if (string.IsNullOrEmpty(s)) return s;
            return s.Length <= max ? s : (s.Substring(0, max) + "…");
        }
    }

    internal static class PathExtensions
    {
        public static string GetFileNameSafe(this string urlOrPath)
        {
            try
            {
                if (string.IsNullOrWhiteSpace(urlOrPath)) return null;
                if (Uri.TryCreate(urlOrPath, UriKind.Absolute, out var uri))
                    return Path.GetFileName(uri.LocalPath);
                return Path.GetFileName(urlOrPath);
            }
            catch { return null; }
        }
    }
}
