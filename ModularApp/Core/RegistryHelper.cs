using System;
using System.IO;
using Microsoft.Win32;

namespace ModularApp.Core
{
    public static class RegistryHelper
    {
        private const string RunKey = @"Software\Microsoft\Windows\CurrentVersion\Run";

        private static string BuildCmd(string exePath, string args)
        {
            exePath = Path.GetFullPath(exePath ?? "");
            args = args?.Trim() ?? "";
            return string.IsNullOrWhiteSpace(args) ? $"\"{exePath}\"" : $"\"{exePath}\" {args}";
        }

        public static void SetRunAtLogin(string appName, string exePath, string args = "")
        {
            using var key = Registry.CurrentUser.OpenSubKey(RunKey, true) ?? Registry.CurrentUser.CreateSubKey(RunKey, true);
            key.SetValue(appName, BuildCmd(exePath, args), RegistryValueKind.String);
        }

        public static bool RemoveRunAtLogin(string appName)
        {
            using var key = Registry.CurrentUser.OpenSubKey(RunKey, true);
            if (key == null) return false;
            if (key.GetValue(appName) == null) return false;
            key.DeleteValue(appName, false);
            return true;
        }

        public static bool EnsureRunAtLogin(string appName, string exePath, string args, out string reason)
        {
            reason = "ok";
            var desired = BuildCmd(exePath, args);
            using var key = Registry.CurrentUser.OpenSubKey(RunKey, true) ?? Registry.CurrentUser.CreateSubKey(RunKey, true);
            var cur = key.GetValue(appName) as string;

            static string Norm(string s) => (s ?? "").Trim();
            if (string.IsNullOrWhiteSpace(cur))
            {
                key.SetValue(appName, desired, RegistryValueKind.String);
                reason = "faltaba entrada";
                return false;
            }
            if (!string.Equals(Norm(cur), Norm(desired), StringComparison.Ordinal))
            {
                key.SetValue(appName, desired, RegistryValueKind.String);
                reason = "ruta/args no coincidían";
                return false;
            }
            return true;
        }
    }
}
