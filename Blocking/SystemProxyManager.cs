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
    /// Gestiona el proxy del sistema a nivel de usuario actual (HKCU).
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

        public void Enable(string proxyAddress, string[] bypassHosts)
        {
            try
            {
                Directory.CreateDirectory(Path.GetDirectoryName(_backupFilePath) ?? ".");
                BackupCurrentSettingsIfNeeded(proxyAddress);

                using var key = Registry.CurrentUser.OpenSubKey(InternetSettingsPath, writable: true);
                if (key == null)
                    return;

                key.SetValue("ProxyEnable", 1, RegistryValueKind.DWord);
                key.SetValue("ProxyServer", $"http={proxyAddress};https={proxyAddress}", RegistryValueKind.String);
                key.SetValue("ProxyOverride", BuildProxyOverride(bypassHosts), RegistryValueKind.String);

                RefreshWinInetSettings();
                LocalLogger.Info($"SystemProxyManager: proxy del sistema habilitado en {proxyAddress}.");
            }
            catch (Exception ex)
            {
                LocalLogger.Error(ex, "SystemProxyManager.Enable(): error habilitando proxy.");
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

                RefreshWinInetSettings();

                try
                {
                    File.Delete(_backupFilePath);
                }
                catch { }

                LocalLogger.Info("SystemProxyManager: proxy del sistema restaurado.");
            }
            catch (Exception ex)
            {
                LocalLogger.Error(ex, "SystemProxyManager.Restore(): error restaurando proxy.");
            }
        }

        private void BackupCurrentSettingsIfNeeded(string ourProxyAddress)
        {
            if (File.Exists(_backupFilePath))
                return;

            using var key = Registry.CurrentUser.OpenSubKey(InternetSettingsPath, writable: false);
            if (key == null)
                return;

            bool proxyEnable = Convert.ToInt32(key.GetValue("ProxyEnable", 0)) == 1;
            string proxyServer = key.GetValue("ProxyServer", string.Empty)?.ToString() ?? string.Empty;
            string proxyOverride = key.GetValue("ProxyOverride", string.Empty)?.ToString() ?? string.Empty;

            // Si ya está usando exactamente nuestro proxy, no sobreescribir backup.
            if (proxyEnable && proxyServer.IndexOf(ourProxyAddress, StringComparison.OrdinalIgnoreCase) >= 0)
                return;

            var backup = new ProxyBackup
            {
                ProxyEnable = proxyEnable,
                ProxyServer = proxyServer,
                ProxyOverride = proxyOverride
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

        private static string BuildProxyOverride(string[] bypassHosts)
        {
            var entries = new System.Collections.Generic.List<string>
            {
                "<local>",
                "localhost",
                "127.0.0.1"
            };

            foreach (string host in bypassHosts ?? Array.Empty<string>())
            {
                if (string.IsNullOrWhiteSpace(host))
                    continue;

                string normalized = host.Trim().ToLowerInvariant();
                if (!entries.Any(x => string.Equals(x, normalized, StringComparison.OrdinalIgnoreCase)))
                    entries.Add(normalized);
            }

            return string.Join(";", entries);
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
        }
    }
}
