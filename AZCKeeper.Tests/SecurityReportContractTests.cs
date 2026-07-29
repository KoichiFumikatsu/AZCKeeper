using System.Collections.Generic;
using System.Text.Json;
using AZCKeeper_Cliente.Contracts;
using Xunit;

namespace AZCKeeper.Tests
{
    /// <summary>
    /// Fija el contrato de serialización entre ApiClient.ReportSecurityStateAsync (C#) y
    /// SecurityReport::sanitizeControls() (PHP, AZCKeeper_Client/Web/src/Endpoints/SecurityReport.php).
    ///
    /// Usa las mismas JsonSerializerOptions que ApiClient._jsonOptions (PropertyNamingPolicy
    /// CamelCase + PropertyNameCaseInsensitive) para que un cambio ahí que rompa el contrato
    /// con el PHP se detecte aquí, no en producción vía un 401/400 silencioso. Este cruce entre
    /// dos lenguajes es exactamente donde se coló el bug crítico de auth de ReportSecurityStateAsync
    /// (ver review final Fase 0): no había ninguna prueba que verificara la forma real del JSON.
    /// </summary>
    public class SecurityReportContractTests
    {
        // Misma configuración que ApiClient._jsonOptions (ver ApiClient.cs).
        private static readonly JsonSerializerOptions ApiClientJsonOptions = new JsonSerializerOptions
        {
            PropertyNamingPolicy = JsonNamingPolicy.CamelCase,
            PropertyNameCaseInsensitive = true
        };

        [Fact]
        public void Serializa_claves_del_diccionario_intactas_incluido_el_punto()
        {
            var controls = new Dictionary<string, SecurityControlState>
            {
                ["chrome.DownloadRestrictions"] = new SecurityControlState { Present = true, Value = 3 }
            };

            string json = JsonSerializer.Serialize(controls, ApiClientJsonOptions);

            // sanitizeControls() en PHP itera $controls como array asociativo: la clave debe
            // llegar literal, sin que CamelCase (que solo afecta nombres de PROPIEDAD, no
            // claves de Dictionary) la toque ni le quite el punto.
            using var doc = JsonDocument.Parse(json);
            Assert.True(doc.RootElement.TryGetProperty("chrome.DownloadRestrictions", out _),
                $"la clave 'chrome.DownloadRestrictions' no viajó intacta. JSON: {json}");
        }

        [Fact]
        public void Cada_valor_tiene_la_forma_exacta_present_value_en_minuscula()
        {
            var controls = new Dictionary<string, SecurityControlState>
            {
                ["chrome.DownloadRestrictions"] = new SecurityControlState { Present = true, Value = 3 }
            };

            string json = JsonSerializer.Serialize(controls, ApiClientJsonOptions);

            // sanitizeControls() lee $entry['present'] y $entry['value'] en minúscula
            // (ver SecurityReport.php). PascalCase del lado C# (Present/Value) rompería
            // el parseo en PHP en silencio (quedarían en null vía ?? default).
            Assert.Contains("\"chrome.DownloadRestrictions\":{\"present\":true,\"value\":3}", json);

            using var doc = JsonDocument.Parse(json);
            var entry = doc.RootElement.GetProperty("chrome.DownloadRestrictions");
            Assert.True(entry.TryGetProperty("present", out var presentEl));
            Assert.Equal(JsonValueKind.True, presentEl.ValueKind);
            Assert.True(entry.TryGetProperty("value", out var valueEl));
            Assert.Equal(3, valueEl.GetInt32());
        }

        [Fact]
        public void Un_value_nulo_se_serializa_como_null()
        {
            var controls = new Dictionary<string, SecurityControlState>
            {
                ["defender.RealTimeProtection"] = new SecurityControlState { Present = false, Value = null }
            };

            string json = JsonSerializer.Serialize(controls, ApiClientJsonOptions);

            // sanitizeControls(): $value = $entry['value'] ?? null; un valor ausente o null
            // deben ser indistinguibles y quedar en null, nunca omitir la clave "value".
            Assert.Contains("\"defender.RealTimeProtection\":{\"present\":false,\"value\":null}", json);

            using var doc = JsonDocument.Parse(json);
            var entry = doc.RootElement.GetProperty("defender.RealTimeProtection");
            Assert.True(entry.TryGetProperty("value", out var valueEl));
            Assert.Equal(JsonValueKind.Null, valueEl.ValueKind);
        }

        [Fact]
        public void Serializacion_DeUnaListaProduceArrayDeStrings()
        {
            var controls = new Dictionary<string, SecurityControlState>
            {
                ["chrome.URLBlocklist"] = new SecurityControlState
                {
                    Present = true,
                    Value = new[] { "facebook.com", "x.com" }
                }
            };

            string json = JsonSerializer.Serialize(controls, ApiClientJsonOptions);

            Assert.Contains("\"chrome.URLBlocklist\"", json);
            Assert.Contains("\"value\":[\"facebook.com\",\"x.com\"]", json);
        }
    }
}
