using System;
using System.IO;
using System.Linq;
using System.Security.Cryptography;
using System.Text;
using System.Text.Json;
using AZCKeeper_Cliente.Config;
using AZCKeeper_Cliente.Logging;
using Microsoft.Win32;

namespace AZCKeeper_Cliente.Blocking
{
    /// <summary>
    /// Bloqueo web por PAC per-usuario (sin admin). Sirve un .pac local que manda los dominios
    /// designados (+ subdominios) a un puerto muerto y deja todo lo demás DIRECT. Persiste la
    /// última política para operar aunque la API no responda. Cierre limpio quita el PAC.
    /// </summary>
    internal sealed class WebBlockingManager
    {
        private static readonly string TracePath =
            Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.ApplicationData), "AZCKeeper", "Logs", "webblocking-trace.log");

        private readonly string _cacheDirectory;
        private readonly string _cacheFilePath;
        private readonly SystemProxyManager _systemProxy;
        private readonly LocalPacServer _pacServer;

        private WebBlockingCache _currentCache;

        // Serializa la ruta de aplicación de política (Initialize/ApplyRemotePolicy/Shutdown):
        // el timer de handshake puede reentrar y correr en paralelo, compitiendo por _currentCache,
        // el archivo .tmp de SaveCacheToDisk y el check-then-write de BackupCurrentSettingsIfNeeded.
        private readonly object _applyLock = new object();

        public bool Enabled => _currentCache?.Enabled == true;
        public int DomainCount => _currentCache?.Domains?.Length ?? 0;
        public int PacPort => _pacServer.Port;
        public bool PacActive => Enabled && _pacServer.IsRunning && _systemProxy.IsOurPacActive(_pacServer.Port);

        public WebBlockingManager()
        {
            string appData = Environment.GetFolderPath(Environment.SpecialFolder.ApplicationData);
            _cacheDirectory = Path.Combine(appData, "AZCKeeper", "Cache");
            _cacheFilePath = Path.Combine(_cacheDirectory, "web_block_cache.json");
            _systemProxy = new SystemProxyManager(_cacheDirectory);
            _pacServer = new LocalPacServer(_cacheDirectory);
        }

        public void Initialize(ConfigManager.WebBlockingConfig config, string apiBaseUrl)
        {
            lock (_applyLock)
            {
                AppendTrace($"Initialize() Enabled={config?.Enabled}, Domains={(config?.Domains?.Length ?? 0)}");
                CleanupLegacyUrlBlocklist(); // best-effort: borra residuo del intento URLBlocklist (3.0.2.5/2.6)

                _currentCache = LoadCacheFromDisk() ?? BuildCache(config, config?.PolicyVersion ?? 0);
                ApplyLocalState(_currentCache, "startup");
            }
        }

        public void ApplyRemotePolicy(ConfigManager.WebBlockingConfig config, int policyVersion, string apiBaseUrl)
        {
            lock (_applyLock)
            {
                AppendTrace($"ApplyRemotePolicy() Enabled={config?.Enabled}, Domains={(config?.Domains?.Length ?? 0)}, PolicyVersion={policyVersion}");
                var next = BuildCache(config, policyVersion);

                bool unchanged = _currentCache != null &&
                    _currentCache.PolicyVersion == next.PolicyVersion &&
                    string.Equals(_currentCache.DomainsHash ?? "", next.DomainsHash ?? "", StringComparison.OrdinalIgnoreCase) &&
                    _currentCache.Enabled == next.Enabled;

                if (unchanged) { Reassert(_currentCache); return; }

                SaveCacheToDisk(next);
                _currentCache = next;
                ApplyLocalState(_currentCache, "remote-policy");
            }
        }

        public string[] GetCachedDomains() => _currentCache?.Domains ?? Array.Empty<string>();

        public void Shutdown()
        {
            lock (_applyLock)
            {
                // Cierre limpio: quitar el PAC para no dejar un AutoConfigURL colgado.
                try { _pacServer.Stop(); } catch { }
                try { _systemProxy.Restore(); } catch { }
            }
        }

        private void ApplyLocalState(WebBlockingCache cache, string source)
        {
            AppendTrace($"ApplyLocalState() Source={source}, Enabled={cache?.Enabled}, Domains={(cache?.Domains?.Length ?? 0)}");
            if (cache == null) return;
            try
            {
                if (!cache.Enabled || cache.Domains.Length == 0)
                {
                    _pacServer.Stop();
                    _systemProxy.Restore();
                    LocalLogger.Info($"WebBlockingManager: bloqueo web deshabilitado ({source}).");
                    return;
                }

                string pac = PacContentBuilder.Build(cache.Domains);
                int port = _pacServer.StartOrUpdate(pac);
                _systemProxy.EnablePac($"http://127.0.0.1:{port}/proxy.pac");
                LocalLogger.Info($"WebBlockingManager: PAC aplicado ({source}). Port={port}, Domains={cache.Domains.Length}");
            }
            catch (Exception ex)
            {
                LocalLogger.Error(ex, $"WebBlockingManager: error aplicando política ({source}).");
            }
        }

        // Re-aplicación silenciosa (anti-manipulación por handshake).
        private void Reassert(WebBlockingCache cache)
        {
            try
            {
                if (cache == null || !cache.Enabled || cache.Domains.Length == 0)
                {
                    if (_pacServer.IsRunning) { _pacServer.Stop(); _systemProxy.Restore(); }
                    return;
                }
                string pac = PacContentBuilder.Build(cache.Domains);
                int port = _pacServer.StartOrUpdate(pac);
                if (!_systemProxy.IsOurPacActive(port))
                    _systemProxy.EnablePac($"http://127.0.0.1:{port}/proxy.pac");
            }
            catch (Exception ex)
            {
                LocalLogger.Error(ex, "WebBlockingManager.Reassert(): error.");
            }
        }

        private static void CleanupLegacyUrlBlocklist()
        {
            string[] roots =
            {
                @"SOFTWARE\Policies\Google\Chrome\URLBlocklist",
                @"SOFTWARE\Policies\Microsoft\Edge\URLBlocklist",
                @"SOFTWARE\Policies\BraveSoftware\Brave\URLBlocklist",
            };
            foreach (string r in roots)
            {
                try { Registry.CurrentUser.DeleteSubKeyTree(r, throwOnMissingSubKey: false); } catch { }
            }
        }

        private WebBlockingCache BuildCache(ConfigManager.WebBlockingConfig config, int policyVersion)
        {
            var domains = (config?.Domains ?? Array.Empty<string>())
                .Where(x => !string.IsNullOrWhiteSpace(x))
                .Select(x => x.Trim().ToLowerInvariant())
                .Distinct(StringComparer.OrdinalIgnoreCase)
                .OrderBy(x => x, StringComparer.OrdinalIgnoreCase)
                .ToArray();

            return new WebBlockingCache
            {
                Enabled = config?.Enabled == true && domains.Length > 0,
                SyncIntervalSeconds = Math.Max(300, config?.SyncIntervalSeconds ?? 600),
                PolicyVersion = Math.Max(0, policyVersion),
                LastUpdatedUtc = DateTime.UtcNow.ToString("O"),
                Domains = domains,
                DomainsHash = ComputeDomainsHash(domains)
            };
        }

        private WebBlockingCache LoadCacheFromDisk()
        {
            try
            {
                if (!File.Exists(_cacheFilePath)) return null;
                string json = File.ReadAllText(_cacheFilePath);
                if (string.IsNullOrWhiteSpace(json)) return null;
                var cache = JsonSerializer.Deserialize<WebBlockingCache>(json);
                if (cache == null) return null;
                cache.Domains ??= Array.Empty<string>();
                cache.DomainsHash ??= ComputeDomainsHash(cache.Domains);
                cache.SyncIntervalSeconds = Math.Max(300, cache.SyncIntervalSeconds);
                return cache;
            }
            catch (Exception ex)
            {
                LocalLogger.Error(ex, "WebBlockingManager.LoadCacheFromDisk(): error.");
                return null;
            }
        }

        private void SaveCacheToDisk(WebBlockingCache cache)
        {
            try
            {
                Directory.CreateDirectory(_cacheDirectory);
                string json = JsonSerializer.Serialize(cache, new JsonSerializerOptions { WriteIndented = true });
                string tmp = _cacheFilePath + ".tmp";
                File.WriteAllText(tmp, json, Encoding.UTF8);
                if (File.Exists(_cacheFilePath)) File.Delete(_cacheFilePath);
                File.Move(tmp, _cacheFilePath);
            }
            catch (Exception ex)
            {
                LocalLogger.Error(ex, "WebBlockingManager.SaveCacheToDisk(): error.");
            }
        }

        private static string ComputeDomainsHash(string[] domains)
        {
            using var sha = SHA256.Create();
            string joined = string.Join("\n", domains ?? Array.Empty<string>());
            return Convert.ToHexString(sha.ComputeHash(Encoding.UTF8.GetBytes(joined)));
        }

        private static void AppendTrace(string message)
        {
            try
            {
                Directory.CreateDirectory(Path.GetDirectoryName(TracePath) ?? ".");
                File.AppendAllText(TracePath, $"{DateTime.Now:yyyy-MM-dd HH:mm:ss.fff} {message}{Environment.NewLine}");
            }
            catch { }
        }

        private sealed class WebBlockingCache
        {
            public bool Enabled { get; set; }
            public int SyncIntervalSeconds { get; set; }
            public int PolicyVersion { get; set; }
            public string LastUpdatedUtc { get; set; }
            public string DomainsHash { get; set; }
            public string[] Domains { get; set; } = Array.Empty<string>();
        }
    }
}
