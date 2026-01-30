using System;
using System.ComponentModel;
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
        
        // Error monitoring counters
        private int _consecutiveCheckErrors = 0;
        private int _consecutiveDownloadErrors = 0;
        private const int MaxConsecutiveErrors = 3;

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
                LocalLogger.Info("UpdateManager: verificando actualizaciones...");
                
                var response = await _apiClient.GetAsync("client/version");
                if (string.IsNullOrWhiteSpace(response))
                {
                    _consecutiveCheckErrors++;
                    LocalLogger.Warn($"UpdateManager: respuesta vacía del servidor. Errores consecutivos: {_consecutiveCheckErrors}/{MaxConsecutiveErrors}");
                    
                    if (_consecutiveCheckErrors >= MaxConsecutiveErrors)
                    {
                        LocalLogger.Error($"UpdateManager: se alcanzó el límite de errores consecutivos ({MaxConsecutiveErrors}). Posible problema de conectividad.");
                    }
                    return;
                }

                var data = JsonSerializer.Deserialize<VersionResponse>(response, new JsonSerializerOptions
                {
                    PropertyNameCaseInsensitive = true
                });

                if (data == null || !data.Ok)
                {
                    _consecutiveCheckErrors++;
                    LocalLogger.Warn($"UpdateManager: respuesta inválida del servidor. Errores consecutivos: {_consecutiveCheckErrors}/{MaxConsecutiveErrors}");
                    
                    if (_consecutiveCheckErrors >= MaxConsecutiveErrors)
                    {
                        LocalLogger.Error($"UpdateManager: se alcanzó el límite de errores consecutivos ({MaxConsecutiveErrors}). Posible problema con el servidor.");
                    }
                    return;
                }

                // Reset error counter on successful check
                if (_consecutiveCheckErrors > 0)
                {
                    LocalLogger.Info($"UpdateManager: verificación exitosa después de {_consecutiveCheckErrors} errores.");
                    _consecutiveCheckErrors = 0;
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
                else
                {
                    LocalLogger.Info("UpdateManager: la aplicación está actualizada.");
                }
            }
            catch (HttpRequestException httpEx)
            {
                _consecutiveCheckErrors++;
                LocalLogger.Error(httpEx, $"UpdateManager: error de red al verificar actualizaciones. Errores consecutivos: {_consecutiveCheckErrors}/{MaxConsecutiveErrors}");
                
                if (_consecutiveCheckErrors >= MaxConsecutiveErrors)
                {
                    LocalLogger.Error($"UpdateManager: problemas persistentes de red detectados ({MaxConsecutiveErrors} intentos fallidos).");
                }
            }
            catch (JsonException jsonEx)
            {
                _consecutiveCheckErrors++;
                LocalLogger.Error(jsonEx, $"UpdateManager: error al parsear respuesta JSON. Errores consecutivos: {_consecutiveCheckErrors}/{MaxConsecutiveErrors}");
            }
            catch (Exception ex)
            {
                _consecutiveCheckErrors++;
                LocalLogger.Error(ex, $"UpdateManager: error inesperado al verificar actualizaciones. Errores consecutivos: {_consecutiveCheckErrors}/{MaxConsecutiveErrors}");
            }
        }
        private async Task DownloadAndInstallAsync(string url, string version)
        {
            _isDownloading = true;

            try
            {
                LocalLogger.Info($"UpdateManager: iniciando descarga de actualización v{version} desde {url}...");

                // ✅ USAR APPDATA en lugar de TEMP
                string appDataPath = Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData);
                string updateDir = Path.Combine(appDataPath, "AZCKeeper", "Updates");

                if (!Directory.Exists(updateDir))
                {
                    LocalLogger.Info($"UpdateManager: creando directorio de actualizaciones: {updateDir}");
                    Directory.CreateDirectory(updateDir);
                }

                string zipPath = Path.Combine(updateDir, $"AZCKeeper_v{version}.zip");

                // Descargar ZIP con monitoreo de progreso
                try
                {
                    LocalLogger.Info($"UpdateManager: descargando archivo a {zipPath}...");
                    
                    using (var client = new HttpClient { Timeout = TimeSpan.FromMinutes(5) })
                    {
                        var bytes = await client.GetByteArrayAsync(url);
                        await File.WriteAllBytesAsync(zipPath, bytes);
                        
                        long sizeKB = new FileInfo(zipPath).Length / 1024;
                        LocalLogger.Info($"UpdateManager: descarga completada exitosamente ({sizeKB}KB).");
                        
                        // Reset download error counter on success
                        if (_consecutiveDownloadErrors > 0)
                        {
                            LocalLogger.Info($"UpdateManager: descarga exitosa después de {_consecutiveDownloadErrors} errores previos.");
                            _consecutiveDownloadErrors = 0;
                        }
                    }
                }
                catch (HttpRequestException httpEx)
                {
                    _consecutiveDownloadErrors++;
                    LocalLogger.Error(httpEx, $"UpdateManager: error de red al descargar actualización. URL: {url}. Errores consecutivos: {_consecutiveDownloadErrors}/{MaxConsecutiveErrors}");
                    throw;
                }
                catch (TaskCanceledException timeoutEx)
                {
                    _consecutiveDownloadErrors++;
                    LocalLogger.Error(timeoutEx, $"UpdateManager: timeout al descargar actualización (>5min). URL: {url}. Errores consecutivos: {_consecutiveDownloadErrors}/{MaxConsecutiveErrors}");
                    throw;
                }
                catch (IOException ioEx)
                {
                    _consecutiveDownloadErrors++;
                    LocalLogger.Error(ioEx, $"UpdateManager: error de I/O al guardar actualización en {zipPath}. Errores consecutivos: {_consecutiveDownloadErrors}/{MaxConsecutiveErrors}");
                    throw;
                }

                // Extraer ZIP con monitoreo de errores
                try
                {
                    LocalLogger.Info($"UpdateManager: extrayendo actualización...");
                    
                    string extractPath = Path.Combine(updateDir, $"v{version}");
                    if (Directory.Exists(extractPath))
                    {
                        LocalLogger.Info($"UpdateManager: eliminando extracción previa en {extractPath}");
                        Directory.Delete(extractPath, true);
                    }

                    System.IO.Compression.ZipFile.ExtractToDirectory(zipPath, extractPath);
                    LocalLogger.Info($"UpdateManager: extracción completada en {extractPath}");

                    // Verificar que exista el updater
                    string updaterPath = Path.Combine(extractPath, "AZCKeeperUpdater.exe");
                    if (!File.Exists(updaterPath))
                    {
                        LocalLogger.Error($"UpdateManager: ERROR CRÍTICO - updater no encontrado en {updaterPath}. El paquete de actualización está corrupto o incompleto.");
                        _consecutiveDownloadErrors++;
                        return;
                    }

                    LocalLogger.Info($"UpdateManager: updater verificado en {updaterPath}");

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

                    LocalLogger.Info($"UpdateManager: lanzando updater con argumentos: {psi.Arguments}");
                    
                    try
                    {
                        var process = System.Diagnostics.Process.Start(psi);
                        if (process == null)
                        {
                            LocalLogger.Error("UpdateManager: ERROR CRÍTICO - no se pudo iniciar el proceso updater (retornó null).");
                            _consecutiveDownloadErrors++;
                            return;
                        }
                        
                        LocalLogger.Info($"UpdateManager: updater lanzado exitosamente (PID: {process.Id}). El cliente se cerrará en 1 segundo...");
                        
                        // Cerrar aplicación para permitir actualización
                        await Task.Delay(1000);
                        System.Windows.Forms.Application.Exit();
                    }
                    catch (System.ComponentModel.Win32Exception win32Ex)
                    {
                        _consecutiveDownloadErrors++;
                        LocalLogger.Error(win32Ex, $"UpdateManager: error Win32 al lanzar updater. Puede ser problema de permisos o archivo corrupto. Errores consecutivos: {_consecutiveDownloadErrors}/{MaxConsecutiveErrors}");
                        throw;
                    }
                }
                catch (InvalidDataException zipEx)
                {
                    _consecutiveDownloadErrors++;
                    LocalLogger.Error(zipEx, $"UpdateManager: archivo ZIP corrupto o inválido en {zipPath}. Errores consecutivos: {_consecutiveDownloadErrors}/{MaxConsecutiveErrors}");
                    throw;
                }
                catch (UnauthorizedAccessException accessEx)
                {
                    _consecutiveDownloadErrors++;
                    LocalLogger.Error(accessEx, $"UpdateManager: acceso denegado al extraer/ejecutar actualización. Puede requerir permisos de administrador. Errores consecutivos: {_consecutiveDownloadErrors}/{MaxConsecutiveErrors}");
                    throw;
                }
            }
            catch (Exception ex)
            {
                _consecutiveDownloadErrors++;
                LocalLogger.Error(ex, $"UpdateManager: error inesperado durante descarga/instalación. Errores consecutivos: {_consecutiveDownloadErrors}/{MaxConsecutiveErrors}");
                
                if (_consecutiveDownloadErrors >= MaxConsecutiveErrors)
                {
                    LocalLogger.Error($"UpdateManager: ALERTA - múltiples errores consecutivos detectados ({MaxConsecutiveErrors} intentos). Suspendiendo intentos de actualización automática.");
                }
            }
            finally
            {
                _isDownloading = false;
            }
        }
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