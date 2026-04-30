using System;
using System.Collections.Generic;
using System.Linq;

namespace AZCKeeper_Cliente.WebBlocking
{
    internal static class PacBuilder
    {
        public static string Build(IReadOnlyCollection<string> blockedDomains, string blockingProxyHost, int blockingProxyPort)
        {
            var normalized = (blockedDomains ?? Array.Empty<string>())
                .Where(x => !string.IsNullOrWhiteSpace(x))
                .Select(EscapeJs)
                .ToArray();

            var rules = normalized.Length == 0
                ? string.Empty
                : string.Join(",\r\n", normalized.Select(x => $"    \"{x}\""));

            return $@"var blockedDomains = [
{rules}
];

function domainMatches(host, pattern) {{
    host = host.toLowerCase();
    pattern = pattern.toLowerCase();

    if (pattern.indexOf('*.') === 0) {{
        var root = pattern.substring(2);
        return host === root || dnsDomainIs(host, '.' + root);
    }}

    return host === pattern || dnsDomainIs(host, '.' + pattern);
}}

function FindProxyForURL(url, host) {{
    host = (host || '').toLowerCase();

    for (var i = 0; i < blockedDomains.length; i++) {{
        if (domainMatches(host, blockedDomains[i])) {{
            return 'PROXY {blockingProxyHost}:{blockingProxyPort}';
        }}
    }}

    return 'DIRECT';
}}";
        }

        private static string EscapeJs(string input)
        {
            return (input ?? string.Empty)
                .Replace("\\", "\\\\")
                .Replace("\"", "\\\"");
        }
    }
}
