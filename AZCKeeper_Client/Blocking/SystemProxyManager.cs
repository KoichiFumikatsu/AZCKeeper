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
    /// Limpieza de la era del PAC/proxy (builds &lt;= 3.0.2.4). Restaura el proxy del
    /// sistema (HKCU) desde el backup dejado por esas versiones y elimina cualquier
    /// AutoConfigURL/ProxyServer residual que apunte a nuestro loopback. El bloqueo web
    /// actual NO usa proxy; esta clase solo existe para la migración de la flota.
    /// </summary>
    internal sealed class SystemProxyManager
    {
        private const string InternetSettingsPath = @"Software\Microsoft\Windows\CurrentVersion\Internet Settings";
        private readonly string _backupFilePath;

        public SystemProxyManager(string cacheDirectory)
        {
            _backupFilePath = Path.Combine(cacheDirectory, "system_proxy_backup.json");
        }

        /// <summary>
        /// Migración desde la era del PAC (builds &lt;= 3.0.2.4): restaura el backup si existe
        /// y, como red de seguridad, fuerza la limpieza de cualquier AutoConfigURL o ProxyServer
        /// que apunte a nuestro loopback (127.0.0.1) aunque no hubiera backup (bug alreadyOurs).
        /// </summary>
        public void MigrateAwayFromPac()
        {
            Restore();

            try
            {
                using var key = Registry.CurrentUser.OpenSubKey(InternetSettingsPath, writable: true);
                if (key == null)
                    return;

                bool changed = false;

                string autoConfig = key.GetValue("AutoConfigURL", string.Empty)?.ToString() ?? string.Empty;
                if (autoConfig.IndexOf("127.0.0.1", StringComparison.OrdinalIgnoreCase) >= 0)
                {
                    try { key.DeleteValue("AutoConfigURL", throwOnMissingValue: false); } catch { }
                    changed = true;
                }

                string proxyServer = key.GetValue("ProxyServer", string.Empty)?.ToString() ?? string.Empty;
                if (proxyServer.IndexOf("127.0.0.1", StringComparison.OrdinalIgnoreCase) >= 0)
                {
                    key.SetValue("ProxyEnable", 0, RegistryValueKind.DWord);
                    key.SetValue("ProxyServer", string.Empty, RegistryValueKind.String);
                    changed = true;
                }

                if (changed)
                {
                    RefreshWinInetSettings();
                    LocalLogger.Info("SystemProxyManager: residuo de PAC/proxy loopback limpiado (migración).");
                }
            }
            catch (Exception ex)
            {
                LocalLogger.Error(ex, "SystemProxyManager.MigrateAwayFromPac(): error limpiando residuo.");
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
