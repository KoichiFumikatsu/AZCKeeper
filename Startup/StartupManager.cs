using System;
using System.IO;
using Microsoft.Win32;
using AZCKeeper_Cliente.Logging;

namespace AZCKeeper_Cliente.Startup
{
    /// <summary>
    /// Maneja el inicio automático del cliente vía registro de Windows.
    /// Comunicación:
    /// - CoreService lo usa al aplicar políticas de startup desde handshake.
    /// - ConfigManager guarda flags que deciden si se habilita/deshabilita.
    /// </summary>
    internal class StartupManager
    {
        private const string AppName = "AZCKeeper_Cliente";
        private const string RegistryPath = @"SOFTWARE\Microsoft\Windows\CurrentVersion\Run";

        /// <summary>
        /// Habilita inicio automático en HKCU\...\Run.
        /// </summary>
        public static void EnableStartup()
        {
            try
            {
                // ✅ USAR RUTA EN APPDATA en lugar de Process.MainModule.FileName
                string appDataPath = Environment.GetFolderPath(Environment.SpecialFolder.ApplicationData);
                string exePath = Path.Combine(appDataPath, "AZCKeeper", "app", "AZCKeeper_Client.exe");

                // Si aún no está en AppData, usar la ruta actual (primera vez)
                if (!File.Exists(exePath))
                {
                    exePath = System.Diagnostics.Process.GetCurrentProcess().MainModule.FileName;
                }

                using (RegistryKey key = Registry.CurrentUser.OpenSubKey(RegistryPath, true))
                {
                    key?.SetValue(AppName, $"\"{exePath}\"");
                }

                LocalLogger.Info($"StartupManager: inicio automático habilitado. Path={exePath}");
            }
            catch (Exception ex)
            {
                LocalLogger.Error(ex, "StartupManager.EnableStartup(): error al habilitar startup.");
            }
        }

        /// <summary>
        /// Deshabilita inicio automático removiendo la clave del registro.
        /// </summary>
        public static void DisableStartup()
        {
            try
            {
                using (RegistryKey key = Registry.CurrentUser.OpenSubKey(RegistryPath, true))
                {
                    key?.DeleteValue(AppName, false);
                }

                LocalLogger.Info("StartupManager: inicio automático deshabilitado.");
            }
            catch (Exception ex)
            {
                LocalLogger.Error(ex, "StartupManager.DisableStartup(): error.");
            }
        }

        /// <summary>
        /// Indica si el inicio automático está habilitado en el registro.
        /// </summary>
        public static bool IsEnabled()
        {
            try
            {
                using (RegistryKey key = Registry.CurrentUser.OpenSubKey(RegistryPath, false))
                {
                    return key?.GetValue(AppName) != null;
                }
            }
            catch
            {
                return false;
            }
        }

        /// <summary>
        /// Retorna la ruta de instalación esperada en AppData.
        /// </summary>
        public static string GetInstallPath()
        {
            string appDataPath = Environment.GetFolderPath(Environment.SpecialFolder.ApplicationData);
            return Path.Combine(appDataPath, "AZCKeeper", "app");
        }
    }
}