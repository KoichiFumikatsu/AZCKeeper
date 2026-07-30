using System.Net.Http.Headers;
using System.Text;
using System.Text.Json;
using AZCKeeper.K4.Contracts;

namespace AZCKeeper.K4.Core;

/// <summary>
/// Canal HTTP con la API de Keeper 4. Implementa IApiClient para los módulos y añade
/// login/handshake para el core. Auth por X-Auth-Token (el hosting no propaga
/// Authorization). Ningún método lanza hacia los módulos: devuelven éxito/fracaso.
/// </summary>
public sealed class K4ApiClient : IApiClient
{
    private readonly HttpClient _http;
    private readonly JsonSerializerOptions _json = new() { PropertyNamingPolicy = JsonNamingPolicy.CamelCase };
    private string _deviceGuid;
    private string? _token;

    public K4ApiClient(string baseUrl, string deviceGuid, HttpClient? http = null)
    {
        _http = http ?? new HttpClient();
        _http.BaseAddress = new Uri(baseUrl.TrimEnd('/') + "/");
        _deviceGuid = deviceGuid;
    }

    public bool HasToken => _token is not null;

    public async Task<LoginResult> LoginAsync(string cc, string deviceName, string version)
    {
        var (status, body) = await PostAsync("client/login",
            new { cc, deviceId = _deviceGuid, deviceName, version }, withToken: false);
        if (status == 200 && body.TryGetProperty("token", out var t))
        {
            _token = t.GetString();
            return new LoginResult(true, "ok", null);
        }
        var st = body.TryGetProperty("status", out var s) ? s.GetString() : "error";
        return new LoginResult(false, st ?? "error", status == 202 ? "pending" : null);
    }

    public async Task<HandshakeResult?> HandshakeAsync(string version, string? deviceName = null)
    {
        var (status, body) = await PostAsync("client/handshake",
            new { deviceId = _deviceGuid, version, deviceName }, withToken: true);
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
        try
        {
            using var req = new HttpRequestMessage(HttpMethod.Post, path)
            {
                Content = new StringContent(JsonSerializer.Serialize(payload, _json), Encoding.UTF8, "application/json")
            };
            if (withToken && _token is not null) req.Headers.Add("X-Auth-Token", _token);
            using var res = await _http.SendAsync(req);
            return (( int)res.StatusCode, await ParseAsync(res));
        }
        catch { return (0, default); }
    }

    private async Task<(int, JsonElement)> GetAsync(string path)
    {
        try
        {
            using var req = new HttpRequestMessage(HttpMethod.Get, path);
            if (_token is not null) req.Headers.Add("X-Auth-Token", _token);
            using var res = await _http.SendAsync(req);
            return ((int)res.StatusCode, await ParseAsync(res));
        }
        catch { return (0, default); }
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
