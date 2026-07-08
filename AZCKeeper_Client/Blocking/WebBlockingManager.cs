using System;
using System.IO;
using System.Linq;
using System.Security.Cryptography;
using System.Text;
using System.Text.Json;
using AZCKeeper_Cliente.Config;
using AZCKeeper_Cliente.Logging;

namespace AZCKeeper_Cliente.Blocking
{
    /// <summary>
    /// Administra la política local de bloqueo web basada en dominios.
    /// Enforcement por URLBlocklist nativo de navegador (Chrome/Edge/Brave), sin proxy ni admin.
    /// Persiste la última configuración válida para operar aunque la API no esté disponible.
    /// </summary>
    internal sealed class WebBlockingManager
    {
        private static readonly string TracePath =
            Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.ApplicationData), "AZCKeeper", "Logs", "webblocking-trace.log");

        private readonly string _cacheDirectory;
        private readonly string _cacheFilePath;
        private readonly SystemProxyManager _legacyProxyCleanup;
        private readonly BrowserPolicyBlocker _browserPolicy;

        private WebBlockingCache _currentCache;

        public WebBlockingManager()
        {
            string appData = Environment.GetFolderPath(Environment.SpecialFolder.ApplicationData);
            _cacheDirectory = Path.Combine(appData, "AZCKeeper", "Cache");
            _cacheFilePath = Path.Combine(_cacheDirectory, "web_block_cache.json");
            _legacyProxyCleanup = new SystemProxyManager(_cacheDirectory);
            _browserPolicy = new BrowserPolicyBlocker();
        }

        public void Initialize(ConfigManager.WebBlockingConfig config, string apiBaseUrl)
        {
            AppendTrace($"Initialize() Enabled={config?.Enabled}, Domains={(config?.Domains?.Length ?? 0)}, ApiBaseUrl={apiBaseUrl}");

            // Migración única desde builds con PAC/proxy loopback (<= 3.0.2.4).
            _legacyProxyCleanup.MigrateAwayFromPac();

            _currentCache = LoadCacheFromDisk()
                ?? BuildCache(config, config?.PolicyVersion ?? 0);

            ApplyLocalState(_currentCache, source: "startup");
        }

        public void ApplyRemotePolicy(ConfigManager.WebBlockingConfig config, int policyVersion, string apiBaseUrl)
        {
            AppendTrace($"ApplyRemotePolicy() Enabled={config?.Enabled}, Domains={(config?.Domains?.Length ?? 0)}, PolicyVersion={policyVersion}");
            var nextCache = BuildCache(config, policyVersion);
            string nextHash = nextCache.DomainsHash ?? string.Empty;
            string currentHash = _currentCache?.DomainsHash ?? string.Empty;

            bool unchanged = _currentCache != null &&
                _currentCache.PolicyVersion == nextCache.PolicyVersion &&
                string.Equals(currentHash, nextHash, StringComparison.OrdinalIgnoreCase) &&
                _currentCache.Enabled == nextCache.Enabled;

            if (unchanged)
            {
                // Anti-manipulación: re-aplicar la política en cada handshake aunque no cambie.
                Reassert(_currentCache);
                return;
            }

            SaveCacheToDisk(nextCache);
            _currentCache = nextCache;

            ApplyLocalState(_currentCache, source: "remote-policy");
        }

        public string[] GetCachedDomains()
        {
            return _currentCache?.Domains ?? Array.Empty<string>();
        }

        public void Shutdown()
        {
            // No limpiamos la política al cerrar: el bloqueo debe persistir aunque el
            // cliente no esté corriendo. Solo se retira cuando la política remota lo indica.
        }

        private WebBlockingCache BuildCache(ConfigManager.WebBlockingConfig config, int policyVersion)
        {
            var sanitizedDomains = (config?.Domains ?? Array.Empty<string>())
                .Where(x => !string.IsNullOrWhiteSpace(x))
                .Select(x => x.Trim().ToLowerInvariant())
                .Distinct(StringComparer.OrdinalIgnoreCase)
                .OrderBy(x => x, StringComparer.OrdinalIgnoreCase)
                .ToArray();

            return new WebBlockingCache
            {
                Enabled = config?.Enabled == true && sanitizedDomains.Length > 0,
                SyncIntervalSeconds = Math.Max(300, config?.SyncIntervalSeconds ?? 600),
                PolicyVersion = Math.Max(0, policyVersion),
                LastUpdatedUtc = DateTime.UtcNow.ToString("O"),
                Domains = sanitizedDomains,
                DomainsHash = ComputeDomainsHash(sanitizedDomains)
            };
        }

        private void ApplyLocalState(WebBlockingCache cache, string source)
        {
            AppendTrace($"ApplyLocalState() Source={source}, CacheEnabled={cache?.Enabled}, CacheDomains={(cache?.Domains?.Length ?? 0)}");
            if (cache == null)
            {
                LocalLogger.Warn($"WebBlockingManager: cache null en {source}.");
                return;
            }

            try
            {
                if (!cache.Enabled)
                {
                    _browserPolicy.Clear();
                    LocalLogger.Info($"WebBlockingManager: bloqueo web deshabilitado ({source}).");
                    return;
                }

                _browserPolicy.Apply(cache.Domains);
                LocalLogger.Info($"WebBlockingManager: política aplicada por URLBlocklist ({source}). PolicyVersion={cache.PolicyVersion}, Domains={cache.Domains.Length}");
            }
            catch (Exception ex)
            {
                LocalLogger.Error(ex, $"WebBlockingManager: error aplicando política ({source}).");
                AppendTrace($"ApplyLocalState() error: {ex.Message}");
            }
        }

        // Re-aplicación silenciosa (sin log ni disco) para el anti-manipulación por handshake.
        private void Reassert(WebBlockingCache cache)
        {
            try
            {
                if (cache == null || !cache.Enabled)
                    _browserPolicy.Clear();
                else
                    _browserPolicy.Apply(cache.Domains);
            }
            catch (Exception ex)
            {
                LocalLogger.Error(ex, "WebBlockingManager.Reassert(): error re-aplicando política.");
            }
        }

        private WebBlockingCache LoadCacheFromDisk()
        {
            try
            {
                if (!File.Exists(_cacheFilePath))
                    return null;

                string json = File.ReadAllText(_cacheFilePath);
                if (string.IsNullOrWhiteSpace(json))
                    return null;

                var cache = JsonSerializer.Deserialize<WebBlockingCache>(json);
                if (cache == null)
                    return null;

                cache.Domains ??= Array.Empty<string>();
                cache.DomainsHash ??= ComputeDomainsHash(cache.Domains);
                cache.SyncIntervalSeconds = Math.Max(300, cache.SyncIntervalSeconds);
                return cache;
            }
            catch (Exception ex)
            {
                LocalLogger.Error(ex, "WebBlockingManager.LoadCacheFromDisk(): error cargando cache.");
                return null;
            }
        }

        private void SaveCacheToDisk(WebBlockingCache cache)
        {
            try
            {
                Directory.CreateDirectory(_cacheDirectory);

                string json = JsonSerializer.Serialize(cache, new JsonSerializerOptions
                {
                    WriteIndented = true
                });

                string tmp = _cacheFilePath + ".tmp";
                File.WriteAllText(tmp, json, Encoding.UTF8);

                if (File.Exists(_cacheFilePath))
                    File.Delete(_cacheFilePath);

                File.Move(tmp, _cacheFilePath);
            }
            catch (Exception ex)
            {
                LocalLogger.Error(ex, "WebBlockingManager.SaveCacheToDisk(): error guardando cache.");
            }
        }

        private static string ComputeDomainsHash(string[] domains)
        {
            using var sha = SHA256.Create();
            string joined = string.Join("\n", domains ?? Array.Empty<string>());
            byte[] bytes = Encoding.UTF8.GetBytes(joined);
            byte[] hash = sha.ComputeHash(bytes);
            return Convert.ToHexString(hash);
        }

        private static void AppendTrace(string message)
        {
            try
            {
                Directory.CreateDirectory(Path.GetDirectoryName(TracePath) ?? ".");
                File.AppendAllText(TracePath, $"{DateTime.Now:yyyy-MM-dd HH:mm:ss.fff} {message}{Environment.NewLine}");
            }
            catch
            {
            }
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
