using System;
using System.Linq;
using System.Net;
using AZCKeeper_Cliente.Config;
using AZCKeeper_Cliente.Logging;

namespace AZCKeeper_Cliente.WebBlocking
{
    /// <summary>
    /// Servicio orquestador del bloqueo web.
    /// En esta primera etapa solo conserva y normaliza el estado recibido
    /// desde handshake. Las siguientes etapas conectarán PAC/proxy aquí.
    /// </summary>
    internal sealed class WebBlockService
    {
        private const string PacUrl = "http://127.0.0.1:8899/proxy.pac";
        private const string BlockingProxyHost = "127.0.0.1";
        private const int BlockingProxyPort = 8877;

        private readonly LocalPacServer _pacServer;
        private readonly BlockingProxyServer _blockingProxyServer;
        private WebBlockSnapshot _current = WebBlockSnapshot.Empty;

        public WebBlockService()
        {
            _pacServer = new LocalPacServer(IPAddress.Loopback, 8899);
            _blockingProxyServer = new BlockingProxyServer(IPAddress.Loopback, BlockingProxyPort);
        }

        public WebBlockSnapshot Current => _current;

        public void UpdateConfiguration(ConfigManager.WebBlockingConfig config)
        {
            if (config == null)
            {
                LocalLogger.Warn("WebBlockService.UpdateConfiguration(): config null.");
                _current = WebBlockSnapshot.Empty;
                TryDisablePac();
                return;
            }

            var domains = (config.BlockedDomains ?? Array.Empty<string>())
                .Where(x => !string.IsNullOrWhiteSpace(x))
                .Select(x => x.Trim().ToLowerInvariant())
                .Distinct(StringComparer.OrdinalIgnoreCase)
                .ToArray();

            _current = new WebBlockSnapshot(
                enabled: config.Enabled,
                blockedDomains: domains,
                source: config.Source,
                lastUpdatedUtc: config.LastUpdatedUtc);

            ApplyCurrentState();
            LocalLogger.Info($"WebBlockService: configuración actualizada. Enabled={_current.Enabled}, Domains={_current.BlockedDomains.Length}");
        }

        private void ApplyCurrentState()
        {
            try
            {
                if (!_current.Enabled)
                {
                    TryDisablePac();
                    return;
                }

                _blockingProxyServer.EnsureStarted();
                _pacServer.EnsureStarted();

                string pacContent = PacBuilder.Build(_current.BlockedDomains, BlockingProxyHost, BlockingProxyPort);
                _pacServer.UpdatePac(pacContent);
                ProxyConfigurator.ApplyPac(PacUrl);
            }
            catch (Exception ex)
            {
                LocalLogger.Error(ex, "WebBlockService: error aplicando estado.");
            }
        }

        private static void TryDisablePac()
        {
            try
            {
                ProxyConfigurator.ClearPac();
            }
            catch (Exception ex)
            {
                LocalLogger.Error(ex, "WebBlockService: error limpiando PAC.");
            }
        }

        public void Stop()
        {
            TryDisablePac();
            _pacServer.Dispose();
            _blockingProxyServer.Dispose();
        }
    }

    internal sealed class WebBlockSnapshot
    {
        public static readonly WebBlockSnapshot Empty = new WebBlockSnapshot(false, Array.Empty<string>(), null, null);

        public WebBlockSnapshot(bool enabled, string[] blockedDomains, string source, string lastUpdatedUtc)
        {
            Enabled = enabled;
            BlockedDomains = blockedDomains ?? Array.Empty<string>();
            Source = source;
            LastUpdatedUtc = lastUpdatedUtc;
        }

        public bool Enabled { get; }
        public string[] BlockedDomains { get; }
        public string Source { get; }
        public string LastUpdatedUtc { get; }
    }
}
