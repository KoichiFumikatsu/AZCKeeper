using System.Net;
using System.Net.Http.Headers;
using System.Text.Json;
using Keeper.Agent.Storage;
using Keeper.Shared.Protocol;

namespace Keeper.Agent.Transport;

// Alta a escala (docs/architecture/v4-alta-equipos.md): el paquete trae la clave de alta de la empresa, no un ticket
// por equipo. El agente pide alta con su serie y reintenta la MISMA solicitud (idempotente por su clave publica)
// hasta que el backend la aprueba (cruce con un equipo esperado o decision de IT). Aprobada: login por ticket, se
// guarda el device_id asignado por el servidor y el arranque normal continua con el.
public sealed class IntakeEnrollment(HttpClient http, HttpMessageSigner signer, string enrollmentKey, TimeProvider clock,
    Action<string> log, Func<string?> serial, Func<TimeSpan, CancellationToken, Task>? delay = null)
{
    public static readonly TimeSpan NetworkRetry = TimeSpan.FromMinutes(2);
    public static readonly TimeSpan MaxWait = TimeSpan.FromHours(6);
    private readonly Func<TimeSpan, CancellationToken, Task> _delay = delay ?? ((wait, ct) => Task.Delay(wait, ct));
    // Autoidentificacion: el backend avisa (ask_document) y se pregunta a la persona; si cierra la ventana se
    // vuelve a preguntar a los 30 minutos. La cedula enviada queda guardada en el backend.
    public Func<CancellationToken, Task<string?>>? AskDocument { get; init; }
    public static readonly TimeSpan AskAgain = TimeSpan.FromMinutes(30);
    private string? _claimedDocument;
    private DateTimeOffset _nextAsk;

    public static Guid ReadDeviceId(string path)
    {
        try { return File.Exists(path) && Guid.TryParse(File.ReadAllText(path).Trim(), out var id) ? id : Guid.Empty; }
        catch (IOException) { return Guid.Empty; }
    }

    // Devuelve el device_id asignado. Si hubo login por ticket, el token queda guardado para el primer sync.
    public async Task<Guid> RunAsync(IDeviceTokenStore tokens, string deviceIdPath, CancellationToken ct)
    {
        EnrollmentRequestStatusStatus? lastStatus = null;
        while (true)
        {
            TimeSpan wait;
            try
            {
                var status = await RequestAsync(ct);
                if (status.Status != lastStatus)
                {
                    log(status.Status switch
                    {
                        EnrollmentRequestStatusStatus.Pending => $"alta solicitada ({status.RequestId}): esperando que IT la apruebe en el panel o que cargue este equipo como esperado",
                        EnrollmentRequestStatusStatus.Rejected => $"alta warn: rechazada por IT ({status.RequestId}); el equipo no queda gestionado",
                        _ => $"alta {status.Status.ToString().ToLowerInvariant()} ({status.RequestId})"
                    });
                    lastStatus = status.Status;
                }
                if (status.Status == EnrollmentRequestStatusStatus.Approved && status.EnrollmentTicket is { Length: > 0 } ticket)
                {
                    var issuedAt = clock.GetUtcNow();
                    var token = await LoginAsync(ticket, ct);
                    await tokens.SaveAsync(new StoredDeviceToken(token, issuedAt), ct);
                    Persist(deviceIdPath, token.DeviceId);
                    log($"alta completada: device_id {token.DeviceId}");
                    return token.DeviceId;
                }
                if (status.Status == EnrollmentRequestStatusStatus.Enrolled && status.DeviceId is { } device && device != Guid.Empty)
                {
                    // El login por ticket ocurrio pero se perdio el device_id local: se recupera y sigue el login normal.
                    Persist(deviceIdPath, device);
                    return device;
                }
                if (status.Status == EnrollmentRequestStatusStatus.Pending && status.AskDocument && _claimedDocument is null &&
                    AskDocument is not null && clock.GetUtcNow() >= _nextAsk)
                {
                    _nextAsk = clock.GetUtcNow() + AskAgain;
                    if ((_claimedDocument = await AskDocument(ct)) is not null) continue;
                }
                wait = TimeSpan.FromSeconds(Math.Clamp(status.RetryAfterSeconds, 60, (int)MaxWait.TotalSeconds));
            }
            catch (TransportException ex)
            {
                // 401 = clave de alta desconocida o revocada: el paquete necesita una clave nueva; no insistir seguido.
                wait = ex.Status == HttpStatusCode.Unauthorized ? TimeSpan.FromHours(1)
                    : ex.RetryAfter is { } retry && retry > TimeSpan.Zero ? (retry < MaxWait ? retry : MaxWait) : NetworkRetry;
                log($"alta warn: HTTP {(int)ex.Status}{(ex.Status == HttpStatusCode.Unauthorized ? " (clave de alta invalida o revocada)" : "")}; reintento en {(int)wait.TotalMinutes} min");
            }
            catch (Exception ex) when (ex is HttpRequestException or TaskCanceledException or InvalidDataException or JsonException && !ct.IsCancellationRequested)
            {
                wait = NetworkRetry;
                log($"alta warn: {ex.GetType().Name}; reintento en {(int)wait.TotalMinutes} min");
            }
            await _delay(wait, ct);
        }
    }

    private static void Persist(string path, Guid device)
    {
        var temp = path + ".tmp";
        File.WriteAllText(temp, device.ToString("D"));
        File.Move(temp, path, true);
    }

    private async Task<EnrollmentRequestStatus> RequestAsync(CancellationToken ct)
    {
        var body = new EnrollmentRequestInput
        {
            EnrollmentKey = enrollmentKey, PublicKey = signer.PublicKey, SerialNumber = serial(),
            Hostname = Environment.MachineName, AgentVersion = AgentIdentity.Version.ToString(3), ClaimedDocument = _claimedDocument
        };
        var status = await PostAsync<EnrollmentRequestStatus>("client/enrollment-requests", body, HttpMessageSigner.CreateNonce(), ct);
        if (status.RequestId == Guid.Empty) throw new InvalidDataException("invalid_enrollment_status");
        return status;
    }

    private async Task<DeviceToken> LoginAsync(string ticket, CancellationToken ct)
    {
        var challenge = await PostAsync<Challenge>("client/auth/challenges", new ChallengeRequest { EnrollmentTicket = ticket }, null, ct);
        var token = await PostAsync<DeviceToken>("client/login", new DeviceLogin
        {
            EnrollmentTicket = ticket, PublicKey = signer.PublicKey, AgentVersion = AgentIdentity.Version.ToString(3), Hostname = Environment.MachineName
        }, challenge.Nonce, ct);
        if (token.DeviceId == Guid.Empty || token.TenantId == Guid.Empty || token.TokenType != "Bearer" || token.ExpiresIn <= 0 ||
            string.IsNullOrWhiteSpace(token.AccessToken)) throw new InvalidDataException("invalid_device_token");
        return token;
    }

    private async Task<T> PostAsync<T>(string path, object value, string? nonce, CancellationToken ct) where T : class
    {
        if (http.BaseAddress is not { Scheme: "https" } root || !root.AbsolutePath.EndsWith("/v1/", StringComparison.Ordinal))
            throw new InvalidOperationException("HTTPS API base must end in /v1/");
        var bytes = JsonSerializer.SerializeToUtf8Bytes(value, value.GetType(), ProtocolJson.Options);
        using var request = new HttpRequestMessage(HttpMethod.Post, new Uri(root, path)) { Content = new ByteArrayContent(bytes) };
        request.Content.Headers.ContentType = new MediaTypeHeaderValue("application/json");
        if (nonce is not null) signer.Sign(request, bytes, clock.GetUtcNow(), nonce);
        using var response = await http.SendAsync(request, HttpCompletionOption.ResponseHeadersRead, ct);
        if (!response.IsSuccessStatusCode)
        {
            var retry = response.Headers.RetryAfter;
            throw new TransportException(response.StatusCode, retry?.Delta ?? (retry?.Date - clock.GetUtcNow()));
        }
        if (response.Content.Headers.ContentLength > 64 * 1024) throw new InvalidDataException("response_too_large");
        await response.Content.LoadIntoBufferAsync(64 * 1024);
        return JsonSerializer.Deserialize<T>(await response.Content.ReadAsByteArrayAsync(ct), ProtocolJson.Options)
            ?? throw new InvalidDataException("empty_response");
    }
}
