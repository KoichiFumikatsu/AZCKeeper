using System;
using System.Runtime.InteropServices;
using Microsoft.Win32;

namespace AZCKeeper_Cliente.WebBlocking
{
    internal static class ProxyConfigurator
    {
        private const string InternetSettingsPath = @"Software\Microsoft\Windows\CurrentVersion\Internet Settings";
        private const int InternetOptionSettingsChanged = 39;
        private const int InternetOptionRefresh = 37;

        [DllImport("wininet.dll", SetLastError = true)]
        private static extern bool InternetSetOption(IntPtr hInternet, int dwOption, IntPtr lpBuffer, int dwBufferLength);

        public static void ApplyPac(string pacUrl)
        {
            using var key = Registry.CurrentUser.OpenSubKey(InternetSettingsPath, writable: true)
                ?? throw new InvalidOperationException("No se pudo abrir Internet Settings.");

            key.SetValue("AutoConfigURL", pacUrl, RegistryValueKind.String);
            key.SetValue("ProxyEnable", 0, RegistryValueKind.DWord);
            Refresh();
        }

        public static void ClearPac()
        {
            using var key = Registry.CurrentUser.OpenSubKey(InternetSettingsPath, writable: true);
            if (key == null) return;

            key.DeleteValue("AutoConfigURL", false);
            Refresh();
        }

        private static void Refresh()
        {
            InternetSetOption(IntPtr.Zero, InternetOptionSettingsChanged, IntPtr.Zero, 0);
            InternetSetOption(IntPtr.Zero, InternetOptionRefresh, IntPtr.Zero, 0);
        }
    }
}
