using System;
using System.Collections.Generic;
using System.IO;
using System.Linq;
using System.Text;
using AZCKeeper_Cliente.Logging;

namespace AZCKeeper_Cliente.Blocking
{
    /// <summary>
    /// Refuerzo secundario por archivo hosts.
    /// Solo aplica dominios exactos; los wildcards siguen dependiendo del proxy.
    /// Si no hay permisos para escribir, falla en silencio controlado y deja trazas mínimas.
    /// </summary>
    internal sealed class HostsFileBlocker
    {
        private const string BeginMarker = "# AZCKeeper WEB BLOCK BEGIN";
        private const string EndMarker = "# AZCKeeper WEB BLOCK END";

        private readonly string _hostsPath;

        public HostsFileBlocker()
        {
            string systemRoot = Environment.GetFolderPath(Environment.SpecialFolder.Windows);
            _hostsPath = Path.Combine(systemRoot, "System32", "drivers", "etc", "hosts");
        }

        public void Apply(string[] domains, string[] bypassHosts)
        {
            try
            {
                var normalized = BuildHostsEntries(domains, bypassHosts);
                string original = File.Exists(_hostsPath)
                    ? File.ReadAllText(_hostsPath, Encoding.ASCII)
                    : string.Empty;

                string cleaned = RemoveManagedBlock(original).TrimEnd();
                string next = cleaned;

                if (normalized.Count > 0)
                {
                    var sb = new StringBuilder();
                    if (!string.IsNullOrWhiteSpace(next))
                    {
                        sb.AppendLine(next);
                    }

                    sb.AppendLine(BeginMarker);
                    foreach (string domain in normalized)
                    {
                        sb.AppendLine($"127.0.0.1 {domain}");
                        sb.AppendLine($"0.0.0.0 {domain}");
                    }
                    sb.AppendLine(EndMarker);
                    next = sb.ToString();
                }

                WriteAtomically(next);
                LocalLogger.Info($"HostsFileBlocker: reglas aplicadas. ExactDomains={normalized.Count}");
            }
            catch (UnauthorizedAccessException)
            {
                LocalLogger.Warn("HostsFileBlocker: sin permisos para escribir hosts. Se mantiene solo enforcement por proxy.");
            }
            catch (Exception ex)
            {
                LocalLogger.Error(ex, "HostsFileBlocker.Apply(): error aplicando reglas.");
            }
        }

        public void Clear()
        {
            try
            {
                if (!File.Exists(_hostsPath))
                    return;

                string original = File.ReadAllText(_hostsPath, Encoding.ASCII);
                string cleaned = RemoveManagedBlock(original).TrimEnd();
                WriteAtomically(cleaned);
                LocalLogger.Info("HostsFileBlocker: bloque administrado removido.");
            }
            catch (UnauthorizedAccessException)
            {
                LocalLogger.Warn("HostsFileBlocker: sin permisos para limpiar hosts.");
            }
            catch (Exception ex)
            {
                LocalLogger.Error(ex, "HostsFileBlocker.Clear(): error limpiando hosts.");
            }
        }

        private List<string> BuildHostsEntries(string[] domains, string[] bypassHosts)
        {
            var bypass = new HashSet<string>((bypassHosts ?? Array.Empty<string>())
                .Where(x => !string.IsNullOrWhiteSpace(x))
                .Select(x => x.Trim().ToLowerInvariant()), StringComparer.OrdinalIgnoreCase);

            var entries = new HashSet<string>(StringComparer.OrdinalIgnoreCase);

            foreach (string raw in domains ?? Array.Empty<string>())
            {
                if (string.IsNullOrWhiteSpace(raw))
                    continue;

                string domain = raw.Trim().ToLowerInvariant();
                if (domain.StartsWith("*.", StringComparison.Ordinal))
                {
                    // hosts no soporta wildcard. Como refuerzo, no intentamos expandirlo artificialmente.
                    continue;
                }

                if (bypass.Contains(domain))
                    continue;

                entries.Add(domain);
            }

            return entries.OrderBy(x => x, StringComparer.OrdinalIgnoreCase).ToList();
        }

        private static string RemoveManagedBlock(string content)
        {
            if (string.IsNullOrEmpty(content))
                return string.Empty;

            int begin = content.IndexOf(BeginMarker, StringComparison.Ordinal);
            if (begin < 0)
                return content;

            int end = content.IndexOf(EndMarker, begin, StringComparison.Ordinal);
            if (end < 0)
                return content.Substring(0, begin);

            int endAfterMarker = end + EndMarker.Length;
            if (endAfterMarker < content.Length && content[endAfterMarker] == '\r')
                endAfterMarker++;
            if (endAfterMarker < content.Length && content[endAfterMarker] == '\n')
                endAfterMarker++;

            return content.Remove(begin, endAfterMarker - begin);
        }

        private void WriteAtomically(string content)
        {
            string tmp = _hostsPath + ".azc.tmp";
            File.WriteAllText(tmp, content + Environment.NewLine, Encoding.ASCII);

            if (File.Exists(_hostsPath))
                File.Delete(_hostsPath);

            File.Move(tmp, _hostsPath);
        }
    }
}
