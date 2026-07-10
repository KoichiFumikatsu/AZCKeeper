using System;
using System.Linq;
using System.Text;
using System.Text.RegularExpressions;

namespace AZCKeeper_Cliente.Blocking
{
    /// <summary>
    /// Genera el contenido del PAC (proxy auto-config). Los dominios designados y sus
    /// subdominios se enrutan a un puerto muerto (bloqueo por fallo rápido); todo lo demás
    /// queda DIRECT. Match por sufijo exacto, sin shExpMatch, para no sobre-bloquear.
    /// </summary>
    internal static class PacContentBuilder
    {
        private const string Blackhole = "PROXY 127.0.0.1:9";
        private static readonly Regex ValidDomain = new Regex("^[a-z0-9.-]+$", RegexOptions.Compiled);

        /// <summary>Normaliza y valida la lista de dominios (minúsculas, sin *. inicial, solo [a-z0-9.-]).</summary>
        internal static string[] Sanitize(string[] domains)
        {
            return (domains ?? Array.Empty<string>())
                .Where(d => !string.IsNullOrWhiteSpace(d))
                .Select(d => d.Trim().ToLowerInvariant().TrimStart('*', '.').TrimEnd('.'))
                .Where(d => d.Length > 0 && ValidDomain.IsMatch(d))
                .Distinct(StringComparer.Ordinal)
                .ToArray();
        }

        /// <summary>Misma lógica de match que el PAC emitido (para pruebas y para el estado).</summary>
        internal static bool WouldBlock(string[] domains, string host)
        {
            if (string.IsNullOrWhiteSpace(host)) return false;
            host = host.Trim().ToLowerInvariant().TrimEnd('.');
            foreach (string d in Sanitize(domains))
            {
                if (host == d || (host.Length > d.Length && host.EndsWith("." + d, StringComparison.Ordinal)))
                    return true;
            }
            return false;
        }

        /// <summary>Construye el texto del PAC.</summary>
        internal static string Build(string[] domains)
        {
            string[] clean = Sanitize(domains);
            string list = string.Join(", ", clean.Select(d => "\"" + d + "\""));

            var sb = new StringBuilder();
            sb.AppendLine("function FindProxyForURL(url, host) {");
            sb.AppendLine("  host = host.toLowerCase();");
            sb.AppendLine("  if (host.charAt(host.length - 1) == \".\") host = host.substring(0, host.length - 1);");
            sb.AppendLine("  var blocked = [" + list + "];");
            sb.AppendLine("  for (var i = 0; i < blocked.length; i++) {");
            sb.AppendLine("    var b = blocked[i];");
            sb.AppendLine("    if (host == b || (host.length > b.length && host.substr(host.length - b.length - 1) == \".\" + b)) {");
            sb.AppendLine("      return \"" + Blackhole + "\";");
            sb.AppendLine("    }");
            sb.AppendLine("  }");
            sb.AppendLine("  return \"DIRECT\";");
            sb.AppendLine("}");
            return sb.ToString();
        }
    }
}
