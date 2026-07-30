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
    private string _deviceGuid;
    private string? _token;

    public K4ApiClient(string baseUrl, string deviceGuid, HttpClient? http = null, NetworkBackoffPolicy? backoff = null)
    {
        _http = http ?? new HttpClient();
        _http.BaseAddress = new Uri(baseUrl.TrimEnd('/') + "/");
        _deviceGuid = deviceGuid;
        _backoff = backoff ?? new NetworkBackoffPolicy();
    }

    /// <summary>El cliente está en backoff de red: el shell debe saltarse el ciclo.</summary>
    public bool IsBackingOff => _backoff.IsBackingOff;
    public DateTime BackoffUntilUtc => _backoff.BackoffUntilUtc;

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

    public async Task<LoginResult> LoginAsync(string cc, string deviceName, string version)
    {
        var (status, body) = await PostAsync("client/login",
            new { cc, deviceId = _deviceGuid, deviceName, version }, withToken: false);
        if (status == 200 && body.TryGetProperty("token", out var t))
        {
            _token = t.GetString();
            TokenChanged?.Invoke(_token);
            return new LoginResult(true, "ok", null);
        }
        var st = body.TryGetProperty("status", out var s) ? s.GetString() : "error";
        return new LoginResult(false, st ?? "error", status == 202 ? "pending" : null);
    }

    public async Task<HandshakeResult?> HandshakeAsync(string version, string? deviceName = null)
    {
        var (status, body) = await PostAsync("client/handshake",
            new { deviceId = _deviceGuid, version, deviceName }, withToken: true);
        LastHandshakeStatus = status;
        if (status != 200 || !body.TryGetProperty("effectiveConfig", out var cfg)) return null;
        return new HandshakeResult(cfg);
    }

    public async Task<bool> SendEpisodesAsync(IReadOnlyList<EpisodeDto> episodes)
    {
        if (episodes.Count == 0) return true;
        var (status, _) = await PostAsync("client/episodes/batch",
            new { deviceId = _deviceGuid, episodes }, withToken: true);
        return status == 200;
    }

    public async Task<bool> SendActivityDayAsync(ActivityDayDto day)
    {
        var payload = new
        {
            deviceId = _deviceGuid, day.DayDate, day.TzOffsetMinutes, day.IsWorkday,
            day.ActivityTracked, day.WindowTracked, day.CallTracked,
            day.ActiveSeconds, day.IdleSeconds, day.CallSeconds,
            day.WorkActiveSeconds, day.WorkIdleSeconds, day.FirstEventAt, day.LastEventAt
        };
        var (status, _) = await PostAsync("client/activity-day", payload, withToken: true);
        return status == 200;
    }

    public async Task<bool> ReportModuleStateAsync(IReadOnlyList<ModuleStateDto> modules)
    {
        var mods = modules.Select(m => new { code = m.Code, running = m.Running, detail = m.Detail });
        var (status, _) = await PostAsync("client/module-state",
            new { deviceId = _deviceGuid, modules = mods }, withToken: true);
        return status == 200;
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

    private async Task<(int, JsonElement)> PostAsync(string path, object payload, bool withToken)
    {
        if (_backoff.IsBackingOff) return (0, default); // no abrir socket durante backoff
        try
        {
            using var req = new HttpRequestMessage(HttpMethod.Post, path)
            {
                Content = new StringContent(JsonSerializer.Serialize(payload, _json), Encoding.UTF8, "application/json")
            };
            if (withToken && _token is not null) req.Headers.Add("X-Auth-Token", _token);
            using var res = await _http.SendAsync(req);
            var code = (int)res.StatusCode;
            _backoff.Register(code, null); // 2xx/4xx resetea; 5xx/429/403 escala
            return (code, await ParseAsync(res));
        }
        catch (Exception ex) { _backoff.Register(0, ex); return (0, default); }
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

public sealed class HandshakeResult
{
    private readonly JsonElement _effectiveConfig;
    public HandshakeResult(JsonElement effectiveConfig) => _effectiveConfig = effectiveConfig;

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
