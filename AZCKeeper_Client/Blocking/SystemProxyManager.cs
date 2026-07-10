using System;
using System.IO;
using System.Runtime.InteropServices;
using System.Text.Json;
using System.Linq;
using Microsoft.Win32;
using AZCKeeper_Cliente.Logging;

namespace AZCKeeper_Cliente.Blocking
{
    /// <summary>
    /// Gestiona la autoconfiguración de proxy a nivel de usuario actual (HKCU),
    /// usando AutoConfigURL (PAC) en lugar de un ProxyServer estático.
    /// Con PAC, solo los dominios bloqueados se enrutan al proxy local; el resto
    /// de la navegación queda DIRECT. No requiere permisos elevados (solo HKCU).
    /// Guarda un respaldo local para poder restaurar el estado anterior.
    /// </summary>
    internal sealed class SystemProxyManager
    {
        private const string InternetSettingsPath = @"Software\Microsoft\Windows\CurrentVersion\Internet Settings";
        private readonly string _backupFilePath;

        public SystemProxyManager(string cacheDirectory)
        {
            _backupFilePath = Path.Combine(cacheDirectory, "system_proxy_backup.json");
        }

        /// <summary>True si el AutoConfigURL actual es el PAC que servimos en el puerto dado.</summary>
        public bool IsOurPacActive(int port)
        {
            try
            {
                using var key = Registry.CurrentUser.OpenSubKey(InternetSettingsPath, writable: false);
                string url = key?.GetValue("AutoConfigURL", string.Empty)?.ToString() ?? string.Empty;
                return url.Equals($"http://127.0.0.1:{port}/proxy.pac", StringComparison.OrdinalIgnoreCase);
            }
            catch { return false; }
        }

        /// <summary>
        /// Habilita el PAC per-usuario apuntando a la URL indicada (http://127.0.0.1:port/proxy.pac).
        /// </summary>
        public void EnablePac(string pacUrl)
        {
            try
            {
                Directory.CreateDirectory(Path.GetDirectoryName(_backupFilePath) ?? ".");
                BackupCurrentSettingsIfNeeded(pacUrl);

                using var key = Registry.CurrentUser.OpenSubKey(InternetSettingsPath, writable: true);
                if (key == null)
                    return;

                // Limpiar cualquier proxy estático heredado de versiones previas que
                // apuntara a nuestro propio loopback. NO tocamos proxies estáticos
                // ajenos (corporativos): el backup los restaurará al deshabilitar.
                string currentProxy = key.GetValue("ProxyServer", string.Empty)?.ToString() ?? string.Empty;
                if (currentProxy.IndexOf("127.0.0.1", StringComparison.OrdinalIgnoreCase) >= 0)
                {
                    key.SetValue("ProxyEnable", 0, RegistryValueKind.DWord);
                    key.SetValue("ProxyServer", string.Empty, RegistryValueKind.String);
                }

                key.SetValue("AutoConfigURL", pacUrl, RegistryValueKind.String);

                RefreshWinInetSettings();
                LocalLogger.Info($"SystemProxyManager: PAC habilitado en {pacUrl}.");
            }
            catch (Exception ex)
            {
                LocalLogger.Error(ex, "SystemProxyManager.EnablePac(): error habilitando PAC.");
            }
        }

        public void Restore()
        {
            try
            {
                var backup = LoadBackup();
                if (backup == null)
                {
                    LocalLogger.Info("SystemProxyManager: no existe backup de proxy a restaurar.");
                    return;
                }

                using var key = Registry.CurrentUser.OpenSubKey(InternetSettingsPath, writable: true);
                if (key == null)
                    return;

                key.SetValue("ProxyEnable", backup.ProxyEnable ? 1 : 0, RegistryValueKind.DWord);
                key.SetValue("ProxyServer", backup.ProxyServer ?? string.Empty, RegistryValueKind.String);
                key.SetValue("ProxyOverride", backup.ProxyOverride ?? string.Empty, RegistryValueKind.String);

                if (string.IsNullOrWhiteSpace(backup.AutoConfigUrl))
                {
                    try { key.DeleteValue("AutoConfigURL", throwOnMissingValue: false); } catch { }
                }
                else
                {
                    key.SetValue("AutoConfigURL", backup.AutoConfigUrl, RegistryValueKind.String);
                }

                RefreshWinInetSettings();

                try
                {
                    File.Delete(_backupFilePath);
                }
                catch { }

                LocalLogger.Info("SystemProxyManager: configuración de proxy/PAC restaurada.");
            }
            catch (Exception ex)
            {
                LocalLogger.Error(ex, "SystemProxyManager.Restore(): error restaurando proxy.");
            }
        }

        private void BackupCurrentSettingsIfNeeded(string ourPacUrl)
        {
            if (File.Exists(_backupFilePath))
                return;

            using var key = Registry.CurrentUser.OpenSubKey(InternetSettingsPath, writable: false);
            if (key == null)
                return;

            bool proxyEnable = Convert.ToInt32(key.GetValue("ProxyEnable", 0)) == 1;
            string proxyServer = key.GetValue("ProxyServer", string.Empty)?.ToString() ?? string.Empty;
            string proxyOverride = key.GetValue("ProxyOverride", string.Empty)?.ToString() ?? string.Empty;
            string autoConfigUrl = key.GetValue("AutoConfigURL", string.Empty)?.ToString() ?? string.Empty;

            // Si el estado actual ya es "nuestro" (PAC en loopback o proxy estático en
            // 127.0.0.1 de una versión previa), no sobreescribir el backup real.
            bool alreadyOurs =
                autoConfigUrl.IndexOf("127.0.0.1", StringComparison.OrdinalIgnoreCase) >= 0 ||
                (proxyEnable && proxyServer.IndexOf("127.0.0.1", StringComparison.OrdinalIgnoreCase) >= 0);
            if (alreadyOurs)
                return;

            var backup = new ProxyBackup
            {
                ProxyEnable = proxyEnable,
                ProxyServer = proxyServer,
                ProxyOverride = proxyOverride,
                AutoConfigUrl = autoConfigUrl
            };

            string json = JsonSerializer.Serialize(backup, new JsonSerializerOptions
            {
                WriteIndented = true
            });
            File.WriteAllText(_backupFilePath, json);
        }

        private ProxyBackup LoadBackup()
        {
            try
            {
                if (!File.Exists(_backupFilePath))
                    return null;

                string json = File.ReadAllText(_backupFilePath);
                if (string.IsNullOrWhiteSpace(json))
                    return null;

                return JsonSerializer.Deserialize<ProxyBackup>(json);
            }
            catch
            {
                return null;
            }
        }

        private static void RefreshWinInetSettings()
        {
            try
            {
                InternetSetOption(IntPtr.Zero, InternetOptionSettingsChanged, IntPtr.Zero, 0);
                InternetSetOption(IntPtr.Zero, InternetOptionRefresh, IntPtr.Zero, 0);
            }
            catch { }
        }

        [DllImport("wininet.dll", SetLastError = true)]
        private static extern bool InternetSetOption(IntPtr hInternet, int dwOption, IntPtr lpBuffer, int dwBufferLength);

        private const int InternetOptionSettingsChanged = 39;
        private const int InternetOptionRefresh = 37;

        private sealed class ProxyBackup
        {
            public bool ProxyEnable { get; set; }
            public string ProxyServer { get; set; }
            public string ProxyOverride { get; set; }
            public string AutoConfigUrl { get; set; }
        }
    }
}
