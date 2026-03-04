using System;
using System.IO;
using System.IO.Compression;
using System.Net.Http;
using System.Text.Json;
using System.Diagnostics;
using System.Threading;
using System.Threading.Tasks;
using AZCKeeper_Cliente.Logging;
using AZCKeeper_Cliente.Config;
using AZCKeeper_Cliente.Network;

namespace AZCKeeper_Cliente.Update
{
    /// <summary>
    /// UpdateManager verifica y descarga actualizaciones del cliente.
    /// Comunicación:
    /// - CoreService lo inicia/detiene según configuración.
    /// - ApiClient se usa para consultar /client/version.
    /// - ConfigManager aporta versión actual y flags de Updates.
    /// </summary>
    internal class UpdateManager
    {
        private System.Timers.Timer _timer;
        private readonly ConfigManager _config;
        private readonly ApiClient _apiClient;
        private int _intervalMinutes;
        private bool _isDownloading = false;
        private readonly SemaphoreSlim _checkGate = new SemaphoreSlim(1, 1);

        /// <summary>
        /// Crea el manager con config, ApiClient y el intervalo en minutos.
        /// </summary>
        public UpdateManager(ConfigManager config, ApiClient apiClient, int intervalMinutes)
        {
            _config = config;
            _apiClient = apiClient;
            _intervalMinutes = intervalMinutes;
        }

        /// <summary>
        /// Inicia el timer de chequeo y ejecuta una verificación inmediata.
        /// Solo inicia si EnableAutoUpdate está activo.
        /// </summary>
        public void Start()
        {
            var updatesConfig = _config.CurrentConfig.Updates;
            
            // Solo iniciar timer si EnableAutoUpdate está activo
            if (updatesConfig == null || !updatesConfig.EnableAutoUpdate)
            {
                LocalLogger.Info("UpdateManager: creado pero no iniciado (EnableAutoUpdate=false). Modo manual.");
                return;
            }

            _intervalMinutes = Math.Max(1, _intervalMinutes);
            _timer = new System.Timers.Timer(_intervalMinutes * 60_000);
            _timer.Elapsed += async (s, e) => await CheckForUpdatesAsync();
            _timer.Start();

            // Verificar inmediatamente al iniciar
            _ = CheckForUpdatesAsync();

            string mode = updatesConfig.AutoDownload ? "automático" : "notificación";
            LocalLogger.Info($"UpdateManager: iniciado (verifica cada {_intervalMinutes}min, modo {mode}).");
        }

        /// <summary>
        /// Detiene el timer de actualización.
        /// </summary>
        public void Stop()
        {
            _timer?.Stop();
            _timer?.Dispose();
        }

        /// <summary>
        /// Actualiza el intervalo de chequeo en minutos.
        /// </summary>
        public void UpdateInterval(int minutes)
        {
            _intervalMinutes = Math.Max(1, minutes);
            if (_timer != null)
            {
                _timer.Interval = _intervalMinutes * 60_000;
                LocalLogger.Info($"UpdateManager: intervalo actualizado a {_intervalMinutes}min.");
            }
        }

        /// <summary>
        /// Consulta endpoint de versión y decide si descargar/instalar.
        /// </summary>
        private async Task CheckForUpdatesAsync()
        {
            if (_isDownloading) return;
            if (!await _checkGate.WaitAsync(0)) return;

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
            finally
            {
                _checkGate.Release();
            }
        }
        /// <summary>
        /// Descarga el paquete ZIP, extrae y lanza el updater externo.
        /// </summary>
        private async Task DownloadAndInstallAsync(string url, string version)
        {
            _isDownloading = true;

            string currentVersion = _config.CurrentConfig.Version;

            try
            {
                LocalLogger.Info($"UpdateManager: descargando actualización v{version} desde {url}...");

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

                long sizeKb = new FileInfo(zipPath).Length / 1024;
                LocalLogger.Info($"UpdateManager: descarga completada ({sizeKb}KB). Extrayendo...");

                // Extraer ZIP
                string extractPath = Path.Combine(updateDir, $"v{version}");
                if (Directory.Exists(extractPath))
                    Directory.Delete(extractPath, true);

                ZipFile.ExtractToDirectory(zipPath, extractPath);

                // Verificar que exista el updater
                string updaterPath = Path.Combine(extractPath, "AZCKeeperUpdater.exe");
                if (!File.Exists(updaterPath))
                {
                    LocalLogger.Error($"UpdateManager: updater no encontrado en el paquete v{version}. Actualización abortada.");
                    return;
                }

                // Obtener ruta del ejecutable actual de forma segura (MainModule puede ser null en x64)
                string currentExe = System.Diagnostics.Process.GetCurrentProcess().MainModule?.FileName
                    ?? System.Reflection.Assembly.GetExecutingAssembly().Location;
                string currentDir = Path.GetDirectoryName(currentExe);

                var psi = new System.Diagnostics.ProcessStartInfo
                {
                    FileName = updaterPath,
                    Arguments = $"\"{currentDir}\" \"{extractPath}\" \"{currentExe}\"",
                    // UseShellExecute = true: necesario para ejecutar correctamente un .exe externo
                    // WindowStyle.Hidden: oculta la ventana sin impedir la ejecución
                    UseShellExecute = true,
                    WindowStyle = System.Diagnostics.ProcessWindowStyle.Hidden,
                    CreateNoWindow = false,
                    WorkingDirectory = updateDir
                };

                // Log de inicio de actualización — va al webhook Discord para trazabilidad en producción.
                // Incluye usuario, equipo y versiones para saber exactamente quién actualizó y desde dónde.
                LocalLogger.Warn($"UpdateManager: 🚀 INICIANDO ACTUALIZACIÓN {currentVersion} → v{version}. Lanzando updater...");
                LocalLogger.Info($"UpdateManager: ProcessStartInfo → FileName={psi.FileName}, Args={psi.Arguments}, Shell={psi.UseShellExecute}, WorkDir={psi.WorkingDirectory}");

                System.Diagnostics.Process proc = null;
                try
                {
                    proc = System.Diagnostics.Process.Start(psi);
                }
                catch (Exception startEx)
                {
                    LocalLogger.Error(startEx, $"UpdateManager: ❌ Process.Start lanzó excepción. El updater NO se inició. FileName={psi.FileName}");
                    return;
                }

                if (proc == null)
                {
                    LocalLogger.Error($"UpdateManager: ❌ Process.Start devolvió null. El updater no pudo iniciarse. FileName={psi.FileName}");
                    return;
                }

                LocalLogger.Info($"UpdateManager: proceso updater iniciado correctamente (PID={proc.Id}).");

                // Dar tiempo al updater para iniciar antes de cerrar el cliente
                await Task.Delay(1500);

                // Log final antes de cerrar — si el proceso llegó aquí, el updater arrancó correctamente.
                // Si el updater falla internamente, el próximo arranque del cliente lo detectará por versión.
                LocalLogger.Warn($"UpdateManager: ✅ Updater lanzado (PID={proc.Id}). Cerrando cliente para aplicar v{version}...");

                System.Windows.Forms.Application.Exit();
            }
            catch (HttpRequestException ex)
            {
                // Error de red al descargar el ZIP — no es un bug, es transitorio
                LocalLogger.Warn($"UpdateManager: ❌ Error de red al descargar v{version}. Se reintentará en el próximo ciclo. {ex.Message}");
            }
            catch (Exception ex)
            {
                // Error inesperado — requiere revisión manual
                LocalLogger.Error(ex, $"UpdateManager: ❌ Error al descargar/instalar v{version}. Actualización fallida.");
            }
            finally
            {
                _isDownloading = false;
            }
        }
        /// <summary>
        /// DTO de respuesta para /client/version.
        /// </summary>
        class VersionResponse
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