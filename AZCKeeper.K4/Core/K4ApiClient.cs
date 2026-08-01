using System.Net.Http.Headers;
using System.Text;
using System.Text.Json;
using AZCKeeper.K4.Contracts;
using AZCKeeper.K4.Shell;

namespace AZCKeeper.K4.Core;

/// <summary>
/// Canal HTTP con la API de Keeper 4. Implementa IApiClient para los módulos y añade
/// login/handshake para el core. Auth por X-Auth-Token (el hosting no propaga
/// Authorization). Ningún método lanza hacia los módulos: devuelven éxito/fracaso.
///
/// Posee el backoff de red (como el ApiClient de K3): cada envío real registra su
/// resultado y, mientras haya backoff activo, NO abre socket — devuelve fallo inmediato
/// para no sostener bans del hosting compartido (100+ equipos tras un NAT). El shell lee
/// IsBackingOff para saltarse ciclos. La cola offline (Etapa 2) se enganchará aquí mismo.
/// </summary>
public sealed class K4ApiClient : IApiClient
{
    private readonly HttpClient _http;
    private readonly JsonSerializerOptions _json = new() { PropertyNamingPolicy = JsonNamingPolicy.CamelCase };
    private readonly NetworkBackoffPolicy _backoff;
    private readonly OfflineQueue? _queue;
    private string _deviceGuid;
    private string? _token;

    public K4ApiClient(string baseUrl, string deviceGuid, HttpClient? http = null,
        NetworkBackoffPolicy? backoff = null, OfflineQueue? queue = null)
    {
        _http = http ?? new HttpClient();
        _http.BaseAddress = new Uri(baseUrl.TrimEnd('/') + "/");
        _deviceGuid = deviceGuid;
        _backoff = backoff ?? new NetworkBackoffPolicy();
        _queue = queue;
    }

    /// <summary>El cliente está en backoff de red: el shell debe saltarse el ciclo.</summary>
    public bool IsBackingOff => _backoff.IsBackingOff;
    public DateTime BackoffUntilUtc => _backoff.BackoffUntilUtc;
    public int PendingQueueCount => _queue?.PendingCount() ?? 0;

    public bool HasToken => _token is not null;

    /// <summary>Token de sesión vigente, o null. Para persistirlo (DPAPI) al cambiar.</summary>
    public string? CurrentToken => _token;

    /// <summary>Estado HTTP del último handshake. Lo usa el re-login silencioso (401).</summary>
    public int LastHandshakeStatus { get; private set; }

    /// <summary>Se dispara cuando el token CAMBIA por un login (no por restaurarlo del disco).</summary>
    public event Action<string?>? TokenChanged;

    /// <summary>Restaura un token guardado (arranque). No dispara TokenChanged: no es un login nuevo.</summary>
    public void RestoreToken(string token) => _token = token;

    /// <summary>Descarta el token (p.ej. 401). Fuerza re-login en el siguiente ciclo.</summary>
    public void ClearToken() => _token = null;

    public async Task<LoginResult> LoginAsync(string cc, string password, string deviceName, string version)
    {
        var (status, body) = await PostAsync("client/login",
            new { cc, password, deviceId = _deviceGuid, deviceName, version }, withToken: false);
        if (status == 200 && body.TryGetProperty("token", out var t))
        {
            _token = t.GetString();
            TokenChanged?.Invoke(_token);
            return new LoginResult(true, "ok", null);
        }
        var st = body.TryGetProperty("status", out var s) ? s.GetString() : "error";
        return new LoginResult(false, st ?? "error", status == 202 ? "pending" : null);
    }

    public async Task<HandshakeResult?> HandshakeAsync(string version, string? deviceName = null, int idleSeconds = 0, object? specs = null)
    {
        var (status, body) = await PostAsync("client/handshake",
            new { deviceId = _deviceGuid, version, deviceName, idleSeconds, specs }, withToken: true);
        LastHandshakeStatus = status;
        if (status != 200 || !body.TryGetProperty("effectiveConfig", out var cfg)) return null;
        return new HandshakeResult(cfg, body);
    }

    /// <summary>
    /// Drena logs Warn/Error al panel (POST /client/logs). NO se encola: es telemetria de
    /// soporte; si no entra, se reintenta con los nuevos del proximo ciclo. entries =
    /// [{level,source,message,ts}, ...].
    /// </summary>
    public async Task<bool> SendClientLogsAsync(IEnumerable<object> entries)
    {
        var body = new { deviceId = _deviceGuid, entries };
        var (status, _) = await PostJsonAsync("client/logs", JsonSerializer.Serialize(body, _json), withToken: true);
        return status == 200;
    }

    /// <summary>
    /// Sube un snapshot de diagnostico. NO se encola: es telemetria efimera (si no entra, se
    /// pierde ese snapshot y ya; el proximo tick manda uno fresco). Respeta el backoff igual
    /// que el resto (PostJsonAsync no abre socket en backoff). clientTs = ahora en UTC.
    /// </summary>
    public async Task<bool> SendDiagnosticsAsync(object payload)
    {
        var body = new { deviceId = _deviceGuid, clientTs = DateTime.UtcNow.ToString("yyyy-MM-ddTHH:mm:ssZ"), payload };
        var (status, _) = await PostJsonAsync("client/diagnostics", JsonSerializer.Serialize(body, _json), withToken: true);
        return status == 200;
    }

    public async Task<bool> SendEpisodesAsync(IReadOnlyList<EpisodeDto> episodes)
    {
        if (episodes.Count == 0) return true;
        return await PostDataAsync("client/episodes/batch", new { deviceId = _deviceGuid, episodes });
    }

    public Task<bool> SendActivityDayAsync(ActivityDayDto day)
    {
        var payload = new
        {
            deviceId = _deviceGuid, day.DayDate, day.TzOffsetMinutes, day.IsWorkday,
            day.ActivityTracked, day.WindowTracked, day.CallTracked,
            day.ActiveSeconds, day.IdleSeconds, day.CallSeconds,
            day.WorkActiveSeconds, day.WorkIdleSeconds, day.FirstEventAt, day.LastEventAt
        };
        return PostDataAsync("client/activity-day", payload);
    }

    public Task<bool> ReportModuleStateAsync(IReadOnlyList<ModuleStateDto> modules)
    {
        var mods = modules.Select(m => new { code = m.Code, running = m.Running, detail = m.Detail });
        return PostDataAsync("client/module-state", new { deviceId = _deviceGuid, modules = mods });
    }

    public async Task<IReadOnlyList<CommandDto>> PollCommandsAsync()
    {
        var (status, body) = await GetAsync($"client/commands?deviceId={_deviceGuid}");
        if (status != 200 || !body.TryGetProperty("commands", out var arr) || arr.ValueKind != JsonValueKind.Array)
            return Array.Empty<CommandDto>();
        var list = new List<CommandDto>();
        foreach (var c in arr.EnumerateArray())
        {
            list.Add(new CommandDto(
                c.GetProperty("id").GetInt64(),
                c.GetProperty("command_type").GetString() ?? "",
                c.TryGetProperty("params_json", out var p) && p.ValueKind == JsonValueKind.String ? p.GetString() : null));
        }
        return list;
    }

    public async Task<bool> ReportCommandResultAsync(long commandId, string status, object? result)
    {
        var (code, _) = await PostAsync("client/commands/result",
            new { deviceId = _deviceGuid, commandId, status, result }, withToken: true);
        return code == 200;
    }

    /// <summary>
    /// Reporta el estado de seguridad, incluido el bloque del agente elevado que el cliente
    /// transporta (courier). NO se encola: el estado es last-write-wins, así que reenviar
    /// uno viejo desde la cola podría pisar uno más nuevo. Se reintenta solo en el próximo
    /// ciclo con datos frescos. agentEnforcement null => el endpoint lo trata como ausente.
    /// </summary>
    public async Task<bool> ReportSecurityAsync(bool agentPresent, object controls, object? agentEnforcement)
    {
        var payload = new { deviceId = _deviceGuid, agentPresent, controls, agentEnforcement };
        var (status, _) = await PostJsonAsync("client/security/report",
            JsonSerializer.Serialize(payload, _json), withToken: true);
        return status == 200;
    }

    public async Task<bool> SendScreenshotMetaAsync(ScreenshotMetaDto meta)
    {
        var payload = new
        {
            deviceId = _deviceGuid, meta.CapturedAt, meta.ObjectKey, meta.Sha256,
            meta.SizeBytes, triggerType = meta.TriggerType, commandId = meta.CommandId
        };
        var (status, _) = await PostAsync("client/screenshots", payload, withToken: true);
        return status == 200;
    }

    private Task<(int, JsonElement)> PostAsync(string path, object payload, bool withToken)
        => PostJsonAsync(path, JsonSerializer.Serialize(payload, _json), withToken);

    private async Task<(int, JsonElement)> PostJsonAsync(string path, string json, bool withToken)
    {
        if (_backoff.IsBackingOff) return (0, default); // no abrir socket durante backoff
        try
        {
            using var req = new HttpRequestMessage(HttpMethod.Post, path)
            {
                Content = new StringContent(json, Encoding.UTF8, "application/json")
            };
            if (withToken && _token is not null) req.Headers.Add("X-Auth-Token", _token);
            using var res = await _http.SendAsync(req);
            var code = (int)res.StatusCode;
            _backoff.Register(code, null); // 2xx/4xx resetea; 5xx/429/403 escala
            return (code, await ParseAsync(res));
        }
        catch (Exception ex) { _backoff.Register(0, ex); return (0, default); }
    }

    /// <summary>
    /// Envío de DATOS idempotentes (episodios, resumen, estado): si no entra (backoff, red,
    /// 5xx), se ENCOLA el JSON exacto para reintentar. Los endpoints son idempotentes
    /// (INSERT IGNORE / VALUES replace / upsert), así que reenviar no duplica. Sin cola
    /// configurada, un fallo simplemente devuelve false (comportamiento previo).
    /// </summary>
    private async Task<bool> PostDataAsync(string path, object payload)
    {
        var json = JsonSerializer.Serialize(payload, _json);
        var (status, _) = await PostJsonAsync(path, json, withToken: true);
        if (status == 200) return true;
        // Solo encolar lo que vale la pena reintentar: sin-red/backoff (0), 5xx, 429. Un
        // 4xx es error permanente del cliente (payload inválido, token muerto): reintentarlo
        // solo llenaría la cola hasta el dead-letter sin cambiar el resultado.
        if (status == 0 || status >= 500 || status == 429)
            _queue?.Enqueue(path, json);
        return false;
    }

    /// <summary>
    /// Reintenta lo encolado. Un 200 lo borra; cualquier otro resultado incrementa su
    /// contador (y a las MaxRetries se descarta como dead letter). Se corta si entra en
    /// backoff para no inflar contadores con intentos que ni abren socket.
    /// </summary>
    public async Task DrainAsync(int batch = 10)
    {
        if (_queue is null) return;
        foreach (var item in _queue.Peek(batch))
        {
            if (_backoff.IsBackingOff) break;
            var (status, _) = await PostJsonAsync(item.Endpoint, item.PayloadJson, withToken: true);
            if (status == 200) _queue.MarkSent(item.Id);
            else _queue.MarkRetried(item.Id, $"HTTP {status}");
        }
    }

    private async Task<(int, JsonElement)> GetAsync(string path)
    {
        if (_backoff.IsBackingOff) return (0, default);
        try
        {
            using var req = new HttpRequestMessage(HttpMethod.Get, path);
            if (_token is not null) req.Headers.Add("X-Auth-Token", _token);
            using var res = await _http.SendAsync(req);
            var code = (int)res.StatusCode;
            _backoff.Register(code, null);
            return (code, await ParseAsync(res));
        }
        catch (Exception ex) { _backoff.Register(0, ex); return (0, default); }
    }

    private static async Task<JsonElement> ParseAsync(HttpResponseMessage res)
    {
        var text = await res.Content.ReadAsStringAsync();
        try { return JsonDocument.Parse(text).RootElement.Clone(); }
        catch { return default; }
    }
}

public sealed record LoginResult(bool Ok, string Status, string? Note);

/// <summary>El flag de diagnostico que el handshake anuncia. Enabled=false por defecto.</summary>
public sealed record DiagnosticsFlag(bool Enabled, int IntervalSeconds, DateTime? UntilUtc)
{
    public static readonly DiagnosticsFlag Off = new(false, 4, null);
}

public sealed class HandshakeResult
{
    private readonly JsonElement _effectiveConfig;

    /// <summary>Modo diagnostico anunciado por el servidor (bloque 'diagnostics' del handshake).</summary>
    public DiagnosticsFlag Diagnostics { get; }

    public HandshakeResult(JsonElement effectiveConfig, JsonElement root)
    {
        _effectiveConfig = effectiveConfig;
        Diagnostics = ParseDiagnostics(root);
    }

    private static DiagnosticsFlag ParseDiagnostics(JsonElement root)
    {
        if (root.ValueKind != JsonValueKind.Object ||
            !root.TryGetProperty("diagnostics", out var d) || d.ValueKind != JsonValueKind.Object)
            return DiagnosticsFlag.Off;

        bool enabled = d.TryGetProperty("enabled", out var e) && e.ValueKind == JsonValueKind.True;
        int interval = d.TryGetProperty("intervalSeconds", out var iv) && iv.TryGetInt32(out var i) ? Math.Max(1, i) : 4;
        DateTime? until = null;
        if (d.TryGetProperty("untilUtc", out var u) && u.ValueKind == JsonValueKind.String &&
            DateTime.TryParse(u.GetString(), null, System.Globalization.DateTimeStyles.AdjustToUniversal | System.Globalization.DateTimeStyles.AssumeUniversal, out var parsed))
            until = parsed;
        return new DiagnosticsFlag(enabled, interval, until);
    }

    /// <summary>Traduce effectiveConfig.modules a ModuleSettings por código de catálogo.</summary>
    public IReadOnlyDictionary<string, ModuleSettings> ToModuleConfig()
    {
        var map = new Dictionary<string, ModuleSettings>(StringComparer.Ordinal);
        if (_effectiveConfig.TryGetProperty("modules", out var mods) && mods.ValueKind == JsonValueKind.Object)
        {
            foreach (var flag in mods.EnumerateObject())
            {
                // enableWindowTracking -> windowTracking
                if (!flag.Name.StartsWith("enable", StringComparison.Ordinal)) continue;
                var code = char.ToLowerInvariant(flag.Name[6]) + flag.Name[7..];
                map[code] = new ModuleSettings { Enabled = flag.Value.ValueKind == JsonValueKind.True };
            }
        }
        return map;
    }
}
