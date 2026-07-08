using System;
using System.Linq;
using Microsoft.Win32;
using AZCKeeper_Cliente.Logging;

namespace AZCKeeper_Cliente.Blocking
{
    /// <summary>
    /// Bloqueo web nativo por política de navegador (URLBlocklist), sin proxy ni admin.
    /// Escribe en HKCU\SOFTWARE\Policies\{navegador}\URLBlocklist para Chrome, Edge y Brave.
    /// El bloqueo ocurre DENTRO del navegador, después de resolver DNS, por lo que sobrevive
    /// a VPN y DoH. Re-aplicar en cada handshake es el mecanismo anti-manipulación.
    /// Firefox y Opera quedan fuera por decisión de despliegue.
    /// </summary>
    internal sealed class BrowserPolicyBlocker
    {
        // Raíces de política por navegador de la familia Chromium (relativas a HKCU).
        private static readonly string[] PolicyRoots =
        {
            @"SOFTWARE\Policies\Google\Chrome",
            @"SOFTWARE\Policies\Microsoft\Edge",
            @"SOFTWARE\Policies\BraveSoftware\Brave",
        };
        private const string BlocklistSubKey = "URLBlocklist";

        /// <summary>Aplica (o re-aplica) la lista de dominios bloqueados. Idempotente.</summary>
        public void Apply(string[] domains)
        {
            string[] entries = BuildEntries(domains);
            foreach (string root in PolicyRoots)
                WriteBlocklist(root, entries);
        }

        /// <summary>Elimina la política de bloqueo de todos los navegadores.</summary>
        public void Clear()
        {
            foreach (string root in PolicyRoots)
            {
                try
                {
                    Registry.CurrentUser.DeleteSubKeyTree(root + "\\" + BlocklistSubKey, throwOnMissingSubKey: false);
                }
                catch (Exception ex)
                {
                    LocalLogger.Error(ex, $"BrowserPolicyBlocker.Clear(): error en {root}.");
                }
            }
        }

        // Chromium: un host sin punto inicial bloquea el host y todos sus subdominios.
        // Para "*.dominio" quitamos el comodín: se bloquea también la raíz (más restrictivo).
        // ponytail: over-block de la raíz en wildcards; para web-blocking es el efecto deseado.
        private static string[] BuildEntries(string[] domains)
        {
            return (domains ?? Array.Empty<string>())
                .Where(d => !string.IsNullOrWhiteSpace(d))
                .Select(d => d.Trim().ToLowerInvariant().TrimStart('*', '.'))
                .Where(d => d.Length > 0)
                .Distinct(StringComparer.OrdinalIgnoreCase)
                .ToArray();
        }

        private static void WriteBlocklist(string policyRoot, string[] entries)
        {
            string path = policyRoot + "\\" + BlocklistSubKey;
            try
            {
                // Recrear la subclave limpia para que dominios removidos desaparezcan.
                Registry.CurrentUser.DeleteSubKeyTree(path, throwOnMissingSubKey: false);

                if (entries.Length == 0)
                    return;

                using var key = Registry.CurrentUser.CreateSubKey(path);
                if (key == null)
                    return;

                for (int i = 0; i < entries.Length; i++)
                    key.SetValue((i + 1).ToString(), entries[i], RegistryValueKind.String);
            }
            catch (Exception ex)
            {
                LocalLogger.Error(ex, $"BrowserPolicyBlocker.WriteBlocklist(): error en {path}.");
            }
        }
    }
}
