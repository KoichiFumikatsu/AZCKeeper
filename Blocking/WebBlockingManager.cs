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
    /// Se encarga de persistir la última configuración válida para operar
    /// aunque la API no esté disponible temporalmente.
    /// </summary>
    internal sealed class WebBlockingManager
    {
        private const int ProxyPort = 8877;
        private static readonly string TracePath =
            Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.ApplicationData), "AZCKeeper", "Logs", "webblocking-trace.log");

        private readonly string _cacheDirectory;
        private readonly string _cacheFilePath;
        private readonly SystemProxyManager _systemProxyManager;
        private readonly LocalWebBlockProxy _proxy;
        private readonly HostsFileBlocker _hostsFileBlocker;

        private WebBlockingCache _currentCache;

        public WebBlockingManager()
        {
            string appData = Environment.GetFolderPath(Environment.SpecialFolder.ApplicationData);
            _cacheDirectory = Path.Combine(appData, "AZCKeeper", "Cache");
            _cacheFilePath = Path.Combine(_cacheDirectory, "web_block_cache.json");
            _systemProxyManager = new SystemProxyManager(_cacheDirectory);
            _proxy = new LocalWebBlockProxy();
            _hostsFileBlocker = new HostsFileBlocker();
        }

        public void Initialize(ConfigManager.WebBlockingConfig config, string apiBaseUrl)
        {
            AppendTrace($"Initialize() Enabled={config?.Enabled}, Domains={(config?.Domains?.Length ?? 0)}, ApiBaseUrl={apiBaseUrl}");
            _currentCache = LoadCacheFromDisk()
                ?? BuildCache(config, config?.PolicyVersion ?? 0);

            ApplyLocalState(_currentCache, source: "startup", apiBaseUrl: apiBaseUrl);
        }

        public void ApplyRemotePolicy(ConfigManager.WebBlockingConfig config, int policyVersion, string apiBaseUrl)
        {
            AppendTrace($"ApplyRemotePolicy() Enabled={config?.Enabled}, Domains={(config?.Domains?.Length ?? 0)}, PolicyVersion={policyVersion}");
            var nextCache = BuildCache(config, policyVersion);
            string nextHash = nextCache.DomainsHash ?? string.Empty;
            string currentHash = _currentCache?.DomainsHash ?? string.Empty;

            if (_currentCache != null &&
                _currentCache.PolicyVersion == nextCache.PolicyVersion &&
                string.Equals(currentHash, nextHash, StringComparison.OrdinalIgnoreCase) &&
                _currentCache.Enabled == nextCache.Enabled)
            {
                LocalLogger.Info($"WebBlockingManager: sin cambios. PolicyVersion={nextCache.PolicyVersion}, Domains={nextCache.Domains.Length}");
                return;
            }

            SaveCacheToDisk(nextCache);
            _currentCache = nextCache;

            ApplyLocalState(_currentCache, source: "remote-policy", apiBaseUrl: apiBaseUrl);
        }

        public string[] GetCachedDomains()
        {
            return _currentCache?.Domains ?? Array.Empty<string>();
        }

        public void Shutdown()
        {
            try
            {
                _proxy.Stop();
                _systemProxyManager.Restore();
                _hostsFileBlocker.Clear();
            }
            catch (Exception ex)
            {
                LocalLogger.Error(ex, "WebBlockingManager.Shutdown(): error.");
            }
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

        private void ApplyLocalState(WebBlockingCache cache, string source, string apiBaseUrl)
        {
            AppendTrace($"ApplyLocalState() Source={source}, CacheEnabled={cache?.Enabled}, CacheDomains={(cache?.Domains?.Length ?? 0)}");
            if (cache == null)
            {
                LocalLogger.Warn($"WebBlockingManager: cache null en {source}.");
                return;
            }

            if (!cache.Enabled)
            {
                _proxy.Stop();
                _systemProxyManager.Restore();
                _hostsFileBlocker.Clear();
                LocalLogger.Info($"WebBlockingManager: bloqueo web deshabilitado ({source}).");
                return;
            }

            try
            {
                int boundPort = _proxy.StartOrUpdate(ProxyPort, cache.Domains);
                string[] bypassHosts = BuildBypassHosts(apiBaseUrl);
                _systemProxyManager.Enable($"127.0.0.1:{boundPort}", bypassHosts);
                _hostsFileBlocker.Apply(cache.Domains, bypassHosts);
                AppendTrace($"ApplyLocalState() applied. Port={boundPort}");
                LocalLogger.Info($"WebBlockingManager: política aplicada ({source}). PolicyVersion={cache.PolicyVersion}, Domains={cache.Domains.Length}, ProxyPort={boundPort}");
            }
            catch (Exception ex)
            {
                _proxy.Stop();
                _systemProxyManager.Restore();
                _hostsFileBlocker.Clear();
                LocalLogger.Error(ex, $"WebBlockingManager: error aplicando proxy local ({source}). Se restaura conectividad normal.");
                AppendTrace($"ApplyLocalState() error: {ex.Message}");
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

        private static string[] BuildBypassHosts(string apiBaseUrl)
        {
            try
            {
                if (string.IsNullOrWhiteSpace(apiBaseUrl))
                    return Array.Empty<string>();

                if (!Uri.TryCreate(apiBaseUrl, UriKind.Absolute, out var uri))
                    return Array.Empty<string>();

                return new[] { uri.Host };
            }
            catch
            {
                return Array.Empty<string>();
            }
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
