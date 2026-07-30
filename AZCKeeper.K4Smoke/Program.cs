using System.Net.Http.Headers;
using System.Text;
using System.Text.Json;

// Smoke test del contrato cliente<->API Keeper 4, desde C# compilado (no curl).
// Prueba el flujo real de un cliente contra devkeep: login -> handshake ->
// enviar episodios -> reportar estado de modulos -> recoger comandos.
//
// Uso: k4smoke <cc> <deviceGuid>   (por defecto usa el usuario de prueba en DEV)
// El backend usa X-Auth-Token (el hosting no propaga Authorization).

var baseUrl = "http://devkeep.azclegal.com/public/index.php/api";
var cc = args.Length > 0 ? args[0] : "K4TEST";
var deviceGuid = args.Length > 1 ? args[1] : "a1b2c3d4-e5f6-4789-8abc-de0123456789";

var http = new HttpClient { BaseAddress = new Uri(baseUrl + "/") };
var json = new JsonSerializerOptions { PropertyNamingPolicy = JsonNamingPolicy.CamelCase };
int pass = 0, fail = 0;

void Check(string name, bool ok, string? detail = null)
{
    Console.WriteLine($"  [{(ok ? "OK " : "FAIL")}] {name}{(detail is null ? "" : "  " + detail)}");
    if (ok) pass++; else fail++;
}

async Task<(int status, JsonElement body)> Call(HttpMethod method, string path, object? payload, string? token)
{
    using var req = new HttpRequestMessage(method, path);
    if (payload is not null)
        req.Content = new StringContent(JsonSerializer.Serialize(payload, json), Encoding.UTF8, "application/json");
    if (token is not null) req.Headers.Add("X-Auth-Token", token);
    using var res = await http.SendAsync(req);
    var text = await res.Content.ReadAsStringAsync();
    JsonElement el;
    try { el = JsonDocument.Parse(text).RootElement.Clone(); }
    catch { el = default; }
    return ((int)res.StatusCode, el);
}

Console.WriteLine($"K4 smoke test contra {baseUrl}\n");

// 1. Login por CC -> token.
var (loginStatus, loginBody) = await Call(HttpMethod.Post, "client/login",
    new { cc, deviceId = deviceGuid, deviceName = "K4-Smoke", version = "4.0.0.0" }, null);
var token = loginStatus == 200 && loginBody.TryGetProperty("token", out var t) ? t.GetString() : null;
Check("login devuelve token", token is not null, $"HTTP {loginStatus}");
if (token is null)
{
    Console.WriteLine($"\nSin token no se puede seguir (respuesta: {loginBody}). " +
        "El usuario debe existir activo en DEV.");
    return fail == 0 ? 0 : 1;
}

// 2. Handshake -> effectiveConfig recortado por tier.
var (hsStatus, hsBody) = await Call(HttpMethod.Post, "client/handshake",
    new { deviceId = deviceGuid, version = "4.0.0.0" }, token);
Check("handshake ok", hsStatus == 200 && hsBody.GetProperty("ok").GetBoolean());
if (hsStatus == 200 && hsBody.TryGetProperty("effectiveConfig", out var cfg)
    && cfg.TryGetProperty("modules", out var mods))
{
    var wt = mods.TryGetProperty("enableWindowTracking", out var w) && w.GetBoolean();
    Check("effectiveConfig trae modulos", true, $"windowTracking={wt}");
}

// 3. Enviar un batch de episodios.
var now = DateTime.Now;
var start = now.ToString("yyyy-MM-dd HH:mm:ss");
var end = now.AddMinutes(3).ToString("yyyy-MM-dd HH:mm:ss");
var (epStatus, epBody) = await Call(HttpMethod.Post, "client/episodes/batch",
    new { deviceId = deviceGuid, episodes = new[] {
        new { startLocalTime = start, endLocalTime = end, durationSeconds = 180,
              processName = "k4smoke.exe", windowTitle = "prueba", isCallApp = false } } }, token);
Check("ingesta de episodios", epStatus == 200 && epBody.GetProperty("ok").GetBoolean(),
    epStatus == 200 ? $"inserted={epBody.GetProperty("inserted").GetInt32()}" : $"HTTP {epStatus}");

// 4. Reportar estado de modulos.
var (msStatus, msBody) = await Call(HttpMethod.Post, "client/module-state",
    new { deviceId = deviceGuid, modules = new[] {
        new { code = "activityTracking", running = true },
        new { code = "windowTracking", running = true } } }, token);
Check("reporte de estado de modulos", msStatus == 200 && msBody.GetProperty("ok").GetBoolean(),
    msStatus == 200 ? $"reported={msBody.GetProperty("reported").GetInt32()}" : $"HTTP {msStatus}");

// 5. Recoger comandos pendientes.
var (cmdStatus, cmdBody) = await Call(HttpMethod.Get, $"client/commands?deviceId={deviceGuid}", null, token);
var cmdCount = cmdStatus == 200 && cmdBody.TryGetProperty("commands", out var cmds) ? cmds.GetArrayLength() : -1;
Check("recoger comandos", cmdStatus == 200 && cmdBody.GetProperty("ok").GetBoolean(),
    $"pendientes={cmdCount}");

// 6. Reporte de seguridad con el estado del AGENTE ELEVADO (courier).
//    Caso ROJO: el agente corre pero NO esta elevado -> canEnforce=false.
//    El panel debe poder distinguir esto de "todo bien" con un WHERE.
var (secRedStatus, secRedBody) = await Call(HttpMethod.Post, "client/security/report",
    new {
        deviceId = deviceGuid,
        agentPresent = true,
        controls = new { antivirus = "on", firewall = "on" },
        agentEnforcement = new {
            elevated = false,
            canEnforce = false,
            selfTestError = "access denied writing HKLM",
            agentVersion = "4.0.0.0",
            reportedAt = DateTime.UtcNow.ToString("yyyy-MM-ddTHH:mm:ssZ"),
            applied = Array.Empty<string>(),
            failed = Array.Empty<object>()
        }
    }, token);
Check("security/report (agente NO elevado = ROJO)",
    secRedStatus == 200 && secRedBody.GetProperty("ok").GetBoolean(),
    secRedStatus == 200 ? $"changed={secRedBody.GetProperty("changed").GetBoolean()}" : $"HTTP {secRedStatus} {secRedBody}");

// Caso VERDE: el agente esta elevado y aplico controles.
var (secGrnStatus, secGrnBody) = await Call(HttpMethod.Post, "client/security/report",
    new {
        deviceId = deviceGuid,
        agentPresent = true,
        controls = new { antivirus = "on", firewall = "on" },
        agentEnforcement = new {
            elevated = true,
            canEnforce = true,
            selfTestError = (string?)null,
            agentVersion = "4.0.0.0",
            reportedAt = DateTime.UtcNow.ToString("yyyy-MM-ddTHH:mm:ssZ"),
            applied = new[] { "chrome.DownloadRestrictions", "chrome.URLBlocklist" },
            failed = new[] { new { code = "usb.block", reason = "clave inexistente en esta build de Windows" } }
        }
    }, token);
Check("security/report (agente elevado = VERDE)",
    secGrnStatus == 200 && secGrnBody.GetProperty("ok").GetBoolean(),
    secGrnStatus == 200 ? $"changed={secGrnBody.GetProperty("changed").GetBoolean()}" : $"HTTP {secGrnStatus} {secGrnBody}");

Console.WriteLine($"\n---\nPASS: {pass}  FAIL: {fail}");
return fail == 0 ? 0 : 1;
