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
    /// Cache de la politica de dominios recibida del backend.
    ///
    /// NO aplica bloqueo. Tres arquitecturas sin privilegio fallaron (hosts+proxy,
    /// URLBlocklist en HKCU, PAC blackhole) y el PAC ademas falla ABIERTO: si el
    /// servidor local no responde, WinInet navega directo sin avisar. El enforcement
    /// pasa a AZCKeeperAgent (servicio elevado, Fase 1) via HKLM\SOFTWARE\Policies.
    ///
    /// Esta clase conserva la politica para el reporte de estado y limpia los
    /// residuos que dejaron los intentos anteriores en los equipos ya desplegados.
    /// </summary>
    internal sealed class WebBlockingManager
    {
        private readonly string _cacheDirectory;
        private readonly string _cacheFilePath;
        private readonly SystemProxyManager _systemProxy;

        private WebBlockingCache _currentCache;
        private readonly object _applyLock = new object();

        public bool Enabled => _currentCache?.Enabled == true;
        public int DomainCount => _currentCache?.Domains?.Length ?? 0;

        public WebBlockingManager()
        {
            string appData = Environment.GetFolderPath(Environment.SpecialFolder.ApplicationData);
            _cacheDirectory = Path.Combine(appData, "AZCKeeper", "Cache");
            _cacheFilePath = Path.Combine(_cacheDirectory, "web_block_cache.json");
            _systemProxy = new SystemProxyManager(_cacheDirectory);
        }

        public void Initialize(ConfigManager.WebBlockingConfig config, string apiBaseUrl)
        {
            lock (_applyLock)
            {
                // Limpieza de residuos de los intentos previos en equipos ya desplegados.
                // SystemProxyManager no expone MigrateAwayFromPac (se quito en 10f03cc y
                // nunca se re-agrego); Restore() ya cubre este caso: si no hay backup,
                // su red de seguridad limpia AutoConfigURL/ProxyServer apuntando a loopback.
                CleanupLegacyUrlBlocklist();
                try { _systemProxy.Restore(); } catch { }

                _currentCache = LoadCacheFromDisk() ?? BuildCache(config, config?.PolicyVersion ?? 0);
                LocalLogger.Info($"WebBlockingManager: politica cacheada. Enabled={_currentCache.Enabled}, Domains={_currentCache.Domains.Length}. Enforcement delegado a AZCKeeperAgent.");
            }
        }

        public void ApplyRemotePolicy(ConfigManager.WebBlockingConfig config, int policyVersion, string apiBaseUrl)
        {
            lock (_applyLock)
            {
                var next = BuildCache(config, policyVersion);

                bool unchanged = _currentCache != null &&
                    _currentCache.PolicyVersion == next.PolicyVersion &&
                    string.Equals(_currentCache.DomainsHash ?? "", next.DomainsHash ?? "", StringComparison.OrdinalIgnoreCase) &&
                    _currentCache.Enabled == next.Enabled;

                if (unchanged) return;

                SaveCacheToDisk(next);
                _currentCache = next;
                LocalLogger.Info($"WebBlockingManager: politica actualizada. Version={next.PolicyVersion}, Domains={next.Domains.Length}");
            }
        }

        public string[] GetCachedDomains() => _currentCache?.Domains ?? Array.Empty<string>();

        public void Shutdown()
        {
            // Ya no hay PAC ni servidor local que detener.
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
                // File.Move con overwrite es atomico; el delete-then-move anterior dejaba
                // ventana sin archivo si el proceso moria entre ambas operaciones.
                File.Move(tmp, _cacheFilePath, overwrite: true);
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
