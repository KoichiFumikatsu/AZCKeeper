using System;
using System.Collections.Generic;
using System.IO;
using System.Linq;
using System.Security.Cryptography;
using System.Text;
using System.Text.Json;
using AZCKeeper_Cliente.Contracts;
using AZCKeeper_Cliente.Logging;

namespace AZCKeeper_Cliente.Security
{
    /// <summary>
    /// Evita reenviar el estado de seguridad cuando no cambio. Los controles de un equipo
    /// son practicamente estaticos; sin esto el handshake arrastra un POST cada 300s por
    /// equipo contra un hosting compartido que ya bloqueo la IP de la oficina por volumen.
    /// Mantiene un latido: cada HeartbeatHours se reenvia aunque no haya cambios, para que
    /// el panel distinga "sin cambios" de "equipo que dejo de reportar".
    /// </summary>
    internal sealed class SecurityReportCache
    {
        private const int HeartbeatHours = 24;

        private readonly string _cacheFilePath;
        private string _lastHash;
        private DateTime _lastSentUtc = DateTime.MinValue;

        public SecurityReportCache(string cacheDirectory)
        {
            if (!string.IsNullOrWhiteSpace(cacheDirectory))
            {
                _cacheFilePath = Path.Combine(cacheDirectory, "security_report_cache.json");
                LoadFromDisk();
            }
        }

        /// <summary>Hash estable del estado: ordena por clave para no depender del orden de lectura.</summary>
        public string ComputeHash(IReadOnlyDictionary<string, SecurityControlState> controls)
        {
            var sb = new StringBuilder();
            foreach (var kv in (controls ?? new Dictionary<string, SecurityControlState>())
                     .OrderBy(k => k.Key, StringComparer.Ordinal))
            {
                sb.Append(kv.Key).Append('=')
                  .Append(kv.Value?.Present == true ? '1' : '0').Append(':')
                  .Append(FormatValue(kv.Value?.Value)).Append('\n');
            }

            using var sha = SHA256.Create();
            return Convert.ToHexString(sha.ComputeHash(Encoding.UTF8.GetBytes(sb.ToString())));
        }

        private static string FormatValue(object value)
        {
            if (value == null) return "";
            if (value is string[] arr) return string.Join("|", arr);
            return Convert.ToString(value, System.Globalization.CultureInfo.InvariantCulture) ?? "";
        }

        public bool ShouldSend(string hash, DateTime nowUtc)
        {
            if (_lastHash == null) return true;
            if (!string.Equals(_lastHash, hash, StringComparison.Ordinal)) return true;
            return (nowUtc - _lastSentUtc).TotalHours >= HeartbeatHours;
        }

        public void MarkSent(string hash, DateTime nowUtc)
        {
            _lastHash = hash;
            _lastSentUtc = nowUtc;
            SaveToDisk();
        }

        private void LoadFromDisk()
        {
            try
            {
                if (_cacheFilePath == null || !File.Exists(_cacheFilePath)) return;
                string json = File.ReadAllText(_cacheFilePath);
                if (string.IsNullOrWhiteSpace(json)) return;
                var state = JsonSerializer.Deserialize<CacheState>(json);
                if (state == null) return;
                _lastHash = state.Hash;
                if (DateTime.TryParse(state.LastSentUtc, null,
                        System.Globalization.DateTimeStyles.RoundtripKind, out DateTime parsed))
                {
                    _lastSentUtc = parsed;
                }
            }
            catch (Exception ex)
            {
                LocalLogger.Error(ex, "SecurityReportCache.LoadFromDisk(): error.");
            }
        }

        private void SaveToDisk()
        {
            try
            {
                if (_cacheFilePath == null) return;
                Directory.CreateDirectory(Path.GetDirectoryName(_cacheFilePath));
                string json = JsonSerializer.Serialize(new CacheState
                {
                    Hash = _lastHash,
                    LastSentUtc = _lastSentUtc.ToString("O")
                });
                string tmp = _cacheFilePath + ".tmp";
                File.WriteAllText(tmp, json, Encoding.UTF8);
                File.Move(tmp, _cacheFilePath, overwrite: true);
            }
            catch (Exception ex)
            {
                LocalLogger.Error(ex, "SecurityReportCache.SaveToDisk(): error.");
            }
        }

        private sealed class CacheState
        {
            public string Hash { get; set; }
            public string LastSentUtc { get; set; }
        }
    }
}
