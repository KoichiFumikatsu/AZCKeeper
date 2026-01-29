using System;
using System.IO;
using System.IO.Compression;
using System.Net.Http;
using System.Text.Json;
using System.Diagnostics;
using System.Threading.Tasks;
using AZCKeeper_Cliente.Logging;
using AZCKeeper_Cliente.Config;
using AZCKeeper_Cliente.Network;

namespace AZCKeeper_Cliente.Update
{
    internal class UpdateManager
    {
        private System.Timers.Timer _timer;
        private readonly ConfigManager _config;
        private readonly ApiClient _apiClient;
        private int _intervalMinutes;
        private bool _isDownloading = false;

        public UpdateManager(ConfigManager config, ApiClient apiClient, int intervalMinutes)
        {
            _config = config;
            _apiClient = apiClient;
            _intervalMinutes = intervalMinutes;
        }

        public void Start()
        {
            _timer = new System.Timers.Timer(_intervalMinutes * 60_000);
            _timer.Elapsed += async (s, e) => await CheckForUpdatesAsync();
            _timer.Start();

            // Verificar inmediatamente al iniciar
            _ = CheckForUpdatesAsync();

            LocalLogger.Info($"UpdateManager: iniciado (verifica cada {_intervalMinutes}min).");
        }

        public void Stop()
        {
            _timer?.Stop();
            _timer?.Dispose();
        }

        public void UpdateInterval(int minutes)
        {
            _intervalMinutes = minutes;
            if (_timer != null)
            {
                _timer.Interval = minutes * 60_000;
                LocalLogger.Info($"UpdateManager: intervalo actualizado a {minutes}min.");
            }
        }

        private async Task CheckForUpdatesAsync()
        {
            if (_isDownloading) return;

            try
            {
                var response = await _apiClient.GetAsync("client/version");
                if (string.IsNullOrWhiteSpace(response))
                {
                    LocalLogger.Warn("UpdateManager: respuesta vacía del servidor.");
                    return;
                }

                var data = JsonSerializer.Deserialize<VersionResponse>(response, new JsonSerializerOptions
                {
                    PropertyNameCaseInsensitive = true
                });

                if (data == null || !data.Ok)
                {
                    LocalLogger.Warn("UpdateManager: respuesta inválida del servidor.");
                    return;
                }

                var current = new Version(_config.CurrentConfig.Version);
                var latest = new Version(data.LatestVersion);
                var minimum = string.IsNullOrWhiteSpace(data.MinimumVersion)
                    ? new Version("0.0.0.0")
                    : new Version(data.MinimumVersion);

                LocalLogger.Info($"UpdateManager: versión actual={current}, disponible={latest}, mínima={minimum}");

                if (latest > current)
                {
                    bool isCritical = current < minimum;
                    LocalLogger.Warn($"UpdateManager: nueva versión {data.LatestVersion} disponible. Crítica={isCritical}");

                    var updatesConfig = _config.CurrentConfig.Updates;

                    // Actualizar si:
                    // - Es crítica (debajo de versión mínima)
                    // - AutoDownload está habilitado
                    // - ForceUpdate está activo
                    if (isCritical || updatesConfig?.AutoDownload == true || data.ForceUpdate)
                    {
                        await DownloadAndInstallAsync(data.DownloadUrl, data.LatestVersion);
                    }
                    else
                    {
                        LocalLogger.Info("UpdateManager: actualización disponible pero no configurada para descarga automática.");
                    }
                }
            }
            catch (Exception ex)
            {
                LocalLogger.Error(ex, "UpdateManager: error al verificar actualizaciones.");
            }
        }

        private async Task DownloadAndInstallAsync(string url, string version)
        {
            _isDownloading = true;

            try
            {
                LocalLogger.Info($"UpdateManager: descargando actualización desde {url}...");

                // Usar APPDATA en lugar de TEMP
                string appDataPath = Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData);
                string updateDir = Path.Combine(appDataPath, "AZCKeeper", "Updates");

                if (!Directory.Exists(updateDir))
                    Directory.CreateDirectory(updateDir);

                string zipPath = Path.Combine(updateDir, $"AZCKeeper_v{version}.zip");

                // Descargar ZIP
                using (var client = new HttpClient { Timeout = TimeSpan.FromMinutes(5) })
                {
                    var bytes = await client.GetByteArrayAsync(url);
                    await File.WriteAllBytesAsync(zipPath, bytes);
                }

                LocalLogger.Info($"UpdateManager: descarga completada ({new FileInfo(zipPath).Length / 1024}KB). Extrayendo...");

                // Extraer ZIP
                string extractPath = Path.Combine(updateDir, $"v{version}");
                if (Directory.Exists(extractPath))
                    Directory.Delete(extractPath, true);

                System.IO.Compression.ZipFile.ExtractToDirectory(zipPath, extractPath);

                // Verificar que exista el updater
                string updaterPath = Path.Combine(extractPath, "AZCKeeperUpdater.exe");
                if (!File.Exists(updaterPath))
                {
                    LocalLogger.Error("UpdateManager: updater no encontrado en el paquete de actualización.");
                    _isDownloading = false;
                    return;
                }

                // Lanzar updater con parámetros
                string currentExe = System.Diagnostics.Process.GetCurrentProcess().MainModule.FileName;
                string currentDir = Path.GetDirectoryName(currentExe);

                var psi = new System.Diagnostics.ProcessStartInfo
                {
                    FileName = updaterPath,
                    Arguments = $"\"{currentDir}\" \"{extractPath}\" \"{currentExe}\"",
                    UseShellExecute = true,
                    WorkingDirectory = updateDir
                };

                // =====================================================
                // FIX: Actualizar versión en config ANTES de reiniciar
                // Esto previene el bucle infinito de actualizaciones
                // =====================================================
                string oldVersion = _config.CurrentConfig.Version;
                _config.CurrentConfig.Version = version;
                _config.Save();
                LocalLogger.Info($"UpdateManager: versión actualizada en config de {oldVersion} a {version}");

                LocalLogger.Info("UpdateManager: lanzando updater. El cliente se cerrará...");
                System.Diagnostics.Process.Start(psi);

                // Cerrar aplicación para permitir actualización
                await Task.Delay(1000);
                System.Windows.Forms.Application.Exit();
            }
            catch (Exception ex)
            {
                LocalLogger.Error(ex, "UpdateManager: error al descargar/instalar actualización.");
                _isDownloading = false;
            }
        }

        private class VersionResponse
        {
            public bool Ok { get; set; }
            public string LatestVersion { get; set; }
            public string DownloadUrl { get; set; }
            public string ReleaseNotes { get; set; }
            public bool ForceUpdate { get; set; }
            public string MinimumVersion { get; set; }
            public string ReleaseDate { get; set; }
            public long FileSize { get; set; }
        }
    }
}