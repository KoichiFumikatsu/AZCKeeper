using System.Net;
using System.Net.Http.Headers;
using System.Security.Cryptography;
using System.Text.Json;
using Keeper.Agent.Migration;
using Keeper.Agent.Modules.Security;
using Keeper.Agent.Policy;
using Keeper.Agent.Storage;
using Keeper.Shared.Protocol;

namespace Keeper.Agent.Transport;

public sealed class TransportException(HttpStatusCode status, TimeSpan? retryAfter, ProblemDetail? problem = null)
    : Exception($"HTTP {(int)status}{problem?.Describe()}")
{
    public HttpStatusCode Status { get; } = status;
    public TimeSpan? RetryAfter { get; } = retryAfter;
    /// <summary>code del Problem y, en un 422 de /v1/client/* (servidor con errors), el pointer y la regla del primer error.</summary>
    public ProblemDetail? Problem { get; } = problem;
}

/// <summary>Lo util de un Problem para decidir y registrar (nunca trae el valor rechazado). Textos acotados y sin control.</summary>
public sealed record ProblemDetail(string? Code, string? Pointer, string? Rule)
{
    public string Describe() => $"{(Code is null ? "" : $" {Code}")}{(Pointer is null ? "" : $" pointer={Pointer}")}{(Rule is null ? "" : $" rule={Rule}")}";

    public static ProblemDetail? Parse(byte[] body)
    {
        if (body.Length == 0) return null;
        try
        {
            using var document = JsonDocument.Parse(body, new JsonDocumentOptions { MaxDepth = 8 });
            var root = document.RootElement;
            if (root.ValueKind != JsonValueKind.Object) return null;
            string? pointer = null, rule = null;
            if (root.TryGetProperty("errors", out var errors) && errors.ValueKind == JsonValueKind.Array && errors.GetArrayLength() > 0 &&
                errors[0] is { ValueKind: JsonValueKind.Object } first)
            {
                pointer = Text(first, "pointer");
                rule = Text(first, "code");
            }
            var code = Text(root, "code");
            return code is null && pointer is null ? null : new ProblemDetail(code, pointer, rule);
        }
        catch (JsonException) { return null; }
    }

    private static string? Text(JsonElement element, string name)
    {
        if (!element.TryGetProperty(name, out var value) || value.ValueKind != JsonValueKind.String) return null;
        var text = new string(value.GetString()!.Where(c => !char.IsControl(c) && !char.IsWhiteSpace(c)).Take(160).ToArray());
        return text.Length == 0 ? null : text;
    }
}

public interface ISyncCycle
{
    long RequestCount { get; }
    Task<int> ExecuteAsync(CancellationToken ct);
}

public sealed class EnrollmentException(HttpStatusCode status) : Exception(
    $"enrollment_rejected (HTTP {(int)status}): ticket invalid, expired, already used, or identity rejected. Configure a new KEEPER_ENROLLMENT_TICKET and restart the service. Enrollment retries are suspended.");

public sealed class SyncClient(HttpClient http, HttpMessageSigner signer, Guid deviceId,
    DurableOutbox outbox, PolicyCoordinator policy, TimeProvider clock,
    string? enrollmentTicket = null, IDeviceTokenStore? tokenStore = null) : ISyncCycle
{
    // 422 del sync: rechazos que se resuelven en el mismo ciclo (apartar el elemento o partir el paquete) antes de
    // desistir hasta el siguiente con backoff. Cada envio cuenta como peticion (el Scheduler espacia el siguiente ciclo).
    public const int MaxRejectionsPerCycle = 8;
    private StoredDeviceToken? _token;
    private DateTimeOffset _expiresAt;
    private string? _enrollmentTicket = string.IsNullOrWhiteSpace(enrollmentTicket) ? null : enrollmentTicket;
    private EnrollmentException? _enrollmentError;
    private bool _tokenLoaded;
    public Func<SyncResponse, Guid, CancellationToken, Task>? OnResponse { get; init; }
    public Action<string>? Log { get; init; }
    public HardeningPasswordModule? HardeningPassword { get; init; }
    // La clave de azcadmin cambia rara vez: se pide en el primer sync del proceso y luego cada 6 h (una rotacion
    // llega en ese plazo o al reiniciar el servicio). Sin clave cargada (409) se reintenta cada 15 min para que
    // IT pueda cargarla y correr --harden sin esperar. Un error reintenta en el siguiente sync.
    public TimeSpan HardeningRefresh { get; init; } = TimeSpan.FromHours(6);
    public TimeSpan HardeningUnconfiguredRetry { get; init; } = TimeSpan.FromMinutes(15);
    private DateTimeOffset? _nextHardeningAt;
    public long RequestCount { get; private set; }

    public async Task<int> ExecuteAsync(CancellationToken ct)
    {
        if (!_tokenLoaded)
        {
            try
            {
                if (tokenStore is not null && await tokenStore.LoadAsync(ct) is { } stored)
                    SetToken(stored.Token, stored.IssuedAt);
            }
            catch (Exception ex) when (ex is CryptographicException or InvalidDataException or IOException or JsonException or
                ArgumentException or FormatException or UnauthorizedAccessException)
            {
                _token = null;
                if (tokenStore is not null) await tokenStore.DeleteAsync(ct);
            }
            finally { _tokenLoaded = true; }
        }
        if (_enrollmentError is not null) throw _enrollmentError;
        if (_token is null || _expiresAt <= clock.GetUtcNow().AddSeconds(60)) await LoginAsync(ct);
        SyncResponse response;
        DateTimeOffset issuedAt;
        for (var rejections = 1; ; rejections++)
        {
            var batch = await outbox.PrepareAsync(policy.CurrentVersion, ct);
            issuedAt = clock.GetUtcNow();
            try
            {
                response = await PostAsync<SyncResponse>("client/sync", batch.Body, true, batch.IdempotencyKey, null, ct);
                break;
            }
            catch (TransportException ex) when (ex.Status == HttpStatusCode.Unauthorized)
            {
                _token = null;
                if (tokenStore is not null) await tokenStore.DeleteAsync(ct);
                throw;
            }
            // Un 422 no se arregla reenviando el mismo paquete (ACT-0033: dos dias reenviandolo cada 15 min con la cola
            // frenada). Se aparta solo el elemento malo (pointer del servidor) o se parte el paquete hasta dar con el.
            catch (TransportException ex) when (ex.Status == HttpStatusCode.UnprocessableEntity)
            {
                var outcome = await outbox.RejectPendingAsync(ex.Problem?.Pointer, ex.Problem?.Rule, ct);
                Log?.Invoke($"sync_rejected: {ex.Message}; {outcome.Describe()}");
                if (outcome.Kind == RejectionKind.Systemic || rejections >= MaxRejectionsPerCycle) throw;
            }
        }
        if (response.ProtocolVersion != 1 || response.PolicyVersion < 1 || response.NextSyncAfterSeconds is < 120 or > 3600)
            throw new InvalidDataException("invalid_sync_response");
        // server_time llega en un 2xx a una peticion firmada: confirma el desfase de las firmas.
        signer.Clock?.Observe(response.ServerTime, confirmed: true);
        await outbox.CompleteAsync(response.EpisodeAcks.Concat(response.LogAcks).Concat(response.CommandAcks)
            .Concat(response.SecurityAck is null ? [] : new[] { response.SecurityAck })
            // Sin el ack de actividad la foto enviada nunca salia de la cola: se reenviaba la misma en cada sync y las
            // nuevas se acumulaban hasta llenarla (incidente 2026-09-30).
            .Concat(response.ActivityAck is null ? [] : new[] { response.ActivityAck }), ct);
        if (response.Token is not null) SetToken(response.Token, issuedAt);
        // Only an accepted sync makes the active token durable, including server rotation.
        if (tokenStore is not null) await tokenStore.SaveAsync(_token!, ct);
        var effective = response.Policy;
        var tenantChanged = policy.Current is not null && policy.Current.TenantId != _token!.Token.TenantId;
        if (effective is null && (tenantChanged || policy.CurrentVersion is null || response.PolicyVersion > policy.CurrentVersion))
            effective = await GetPolicyAsync(ct);
        if (effective is not null && effective.TenantId != _token!.Token.TenantId)
            throw new InvalidDataException("policy_tenant_mismatch");
        if (effective is not null && effective.DeviceId != deviceId)
            throw new InvalidDataException("policy_device_mismatch");
        await policy.ApplyAsync(response.PolicyVersion, effective, ct, allowTenantChange: tenantChanged);
        if (OnResponse is not null) await OnResponse(response, _token!.Token.TenantId, ct);
        if (HardeningPassword is not null && (_nextHardeningAt is null || clock.GetUtcNow() >= _nextHardeningAt))
        {
            var configured = await GetHardeningAsync(ct);
            _nextHardeningAt = clock.GetUtcNow() + (configured ? HardeningRefresh : HardeningUnconfiguredRetry);
        }
        return checked((int)response.NextSyncAfterSeconds);
    }

    // Mudanza (ServerRelocation): login contra el destino antes de cambiar. El token guardado es del servidor anterior
    // (otra clave base+keyId), asi que no se lee; el de este login se guarda con el primer sync aceptado del destino.
    public async Task ProbeLoginAsync(CancellationToken ct)
    {
        _tokenLoaded = true;
        _token = null;
        await LoginAsync(ct);
    }

    private async Task LoginAsync(CancellationToken ct)
    {
        var ticket = _enrollmentTicket;
        try
        {
            var challenge = await PostAsync<Challenge>("client/auth/challenges",
                Serialize(MigrationEnrollment.CreateChallenge(deviceId, ticket)), false, null, null, ct);
            // expires_at es hora del servidor: se compara con la hora corregida (un reloj adelantado lo daba por vencido).
            if (challenge.ExpiresAt <= ServerNow()) throw new InvalidDataException("challenge_expired");
            var login = MigrationEnrollment.CreateLogin(signer, deviceId, ticket);
            var issuedAt = clock.GetUtcNow();
            var token = await PostAsync<DeviceToken>("client/login", Serialize(login), false, null, challenge.Nonce, ct);
            SetToken(token, issuedAt);
        }
        catch (TransportException ex) when (ticket is not null &&
            ex.Status is HttpStatusCode.BadRequest or HttpStatusCode.Unauthorized or HttpStatusCode.Forbidden or
                HttpStatusCode.NotFound or HttpStatusCode.Conflict or HttpStatusCode.Gone or HttpStatusCode.UnprocessableEntity)
        {
            _enrollmentTicket = null;
            throw _enrollmentError = new EnrollmentException(ex.Status);
        }
    }

    private DateTimeOffset ServerNow() => signer.Clock?.ToServer(clock.GetUtcNow()) ?? clock.GetUtcNow();

    private void SetToken(DeviceToken? token, DateTimeOffset issuedAt)
    {
        if (token is null || token.DeviceId != deviceId || token.TenantId == Guid.Empty || token.TokenType != "Bearer" ||
            token.ExpiresIn is <= 0 or > 604800 || string.IsNullOrWhiteSpace(token.AccessToken) || token.AccessToken.Length > 16384)
            throw new InvalidDataException("invalid_device_token");
        _expiresAt = issuedAt.AddSeconds(token.ExpiresIn);
        _token = new StoredDeviceToken(token, issuedAt);
        _enrollmentTicket = null;
    }

    private async Task<T> PostAsync<T>(string path, byte[] body, bool authenticated, Guid? idempotencyKey, string? nonce, CancellationToken ct) where T : class
    {
        if (http.BaseAddress is not { Scheme: "https" } root || !root.AbsolutePath.EndsWith('/'))
            throw new InvalidOperationException("HTTPS API base must end in /v1/");
        using var request = new HttpRequestMessage(HttpMethod.Post, new Uri(root, path));
        request.Content = new ByteArrayContent(body);
        request.Content.Headers.ContentType = new MediaTypeHeaderValue("application/json");
        if (authenticated) request.Headers.Authorization = new AuthenticationHeaderValue("Bearer", _token!.Token.AccessToken);
        if (idempotencyKey is not null) request.Headers.Add("Idempotency-Key", idempotencyKey.Value.ToString());
        if (authenticated || nonce is not null) signer.Sign(request, body, clock.GetUtcNow(), nonce ?? HttpMessageSigner.CreateNonce());
        return await SendAsync<T>(request, 256 * 1024, ct) ?? throw new InvalidDataException("empty_response");
    }

    private async Task<EffectivePolicy?> GetPolicyAsync(CancellationToken ct)
    {
        using var request = new HttpRequestMessage(HttpMethod.Get, new Uri(http.BaseAddress!, "client/policy"));
        request.Headers.Authorization = new AuthenticationHeaderValue("Bearer", _token!.Token.AccessToken);
        if (policy.Current is not null && policy.Current.TenantId == _token.Token.TenantId)
            request.Headers.TryAddWithoutValidation("If-None-Match", policy.Current.Etag);
        signer.Sign(request, [], clock.GetUtcNow(), HttpMessageSigner.CreateNonce());
        return await SendAsync<EffectivePolicy>(request, 1024 * 1024, ct, allowNotModified: true);
    }

    // true = clave recibida y aplicada; false = el tenant aun no tiene clave (409).
    private async Task<bool> GetHardeningAsync(CancellationToken ct)
    {
        using var request = new HttpRequestMessage(HttpMethod.Get, new Uri(http.BaseAddress!, "client/hardening"));
        request.Headers.Authorization = new AuthenticationHeaderValue("Bearer", _token!.Token.AccessToken);
        signer.Sign(request, [], clock.GetUtcNow(), HttpMessageSigner.CreateNonce());
        RequestCount++;
        using var response = await http.SendAsync(request, HttpCompletionOption.ResponseHeadersRead, ct);
        if (response.StatusCode == HttpStatusCode.Conflict)
        {
            if (Relocation(await ErrorBodyAsync(response, ct)) is { } relocation) throw new DeviceRelocatedException(relocation);
            return false;
        }
        if (!response.IsSuccessStatusCode)
        {
            if (response.StatusCode == HttpStatusCode.Unauthorized)
            {
                _token = null;
                if (tokenStore is not null) await tokenStore.DeleteAsync(ct);
            }
            var retry = response.Headers.RetryAfter;
            throw new TransportException(response.StatusCode, retry?.Delta ?? (retry?.Date - clock.GetUtcNow()),
                ProblemDetail.Parse(await ErrorBodyAsync(response, ct)));
        }
        const int limit = 16384;
        if (response.Content.Headers.ContentLength > limit) throw new InvalidDataException("response_too_large");
        // A fixed buffer avoids retained plaintext copies from deserialization or MemoryStream growth.
        var buffer = new byte[limit + 1];
        try
        {
            await using var stream = await response.Content.ReadAsStreamAsync(ct);
            var length = 0;
            int count;
            while ((count = await stream.ReadAsync(buffer.AsMemory(length), ct)) > 0)
            {
                length += count;
                if (length > limit) throw new InvalidDataException("response_too_large");
            }
            HardeningPassword!.ApplyResponse(buffer.AsSpan(0, length), ct);
        }
        finally { CryptographicOperations.ZeroMemory(buffer); }
        return true;
    }

    private async Task<T?> SendAsync<T>(HttpRequestMessage request, int limit, CancellationToken ct, bool allowNotModified = false) where T : class
    {
        RequestCount++;
        using var response = await http.SendAsync(request, HttpCompletionOption.ResponseHeadersRead, ct);
        // Null preserves both the cached policy and its version without reapplying it.
        if (allowNotModified && response.StatusCode == HttpStatusCode.NotModified) return null;
        if (!response.IsSuccessStatusCode)
        {
            var problem = await ErrorBodyAsync(response, ct);
            if (response.StatusCode == HttpStatusCode.Conflict && Relocation(problem) is { } relocation)
                throw new DeviceRelocatedException(relocation);
            var retry = response.Headers.RetryAfter;
            throw new TransportException(response.StatusCode, retry?.Delta ?? (retry?.Date - clock.GetUtcNow()), ProblemDetail.Parse(problem));
        }
        if (response.Content.Headers.ContentLength > limit) throw new InvalidDataException("response_too_large");
        await using var stream = await response.Content.ReadAsStreamAsync(ct);
        using var buffer = new MemoryStream();
        var chunk = new byte[8192];
        int length;
        while ((length = await stream.ReadAsync(chunk, ct)) > 0)
        {
            if (buffer.Length + length > limit) throw new InvalidDataException("response_too_large");
            buffer.Write(chunk, 0, length);
        }
        return JsonSerializer.Deserialize<T>(buffer.GetBuffer().AsSpan(0, (int)buffer.Length), ProtocolJson.Options)
            ?? throw new InvalidDataException("empty_response");
    }

    // Cuerpo de una respuesta de error (Problem), acotado: vacio si no cabe o no se pudo leer.
    private static async Task<byte[]> ErrorBodyAsync(HttpResponseMessage response, CancellationToken ct)
    {
        const int limit = 32 * 1024;
        if (response.Content.Headers.ContentLength > limit) return [];
        try
        {
            await using var stream = await response.Content.ReadAsStreamAsync(ct);
            var buffer = new byte[limit + 1];
            var length = 0;
            int count;
            while (length <= limit && (count = await stream.ReadAsync(buffer.AsMemory(length), ct)) > 0) length += count;
            return length > limit ? [] : buffer[..length];
        }
        catch (Exception ex) when (ex is IOException or HttpRequestException) { return []; }
    }

    // 409 device_relocated (servidor de origen en modo mudado): la orden firmada viene en Problem.relocation.
    private static string? Relocation(byte[] body)
    {
        if (body.Length == 0) return null;
        try
        {
            using var document = JsonDocument.Parse(body);
            var root = document.RootElement;
            return root.ValueKind == JsonValueKind.Object && root.TryGetProperty("code", out var code) && code.ValueKind == JsonValueKind.String &&
                code.GetString() == "device_relocated" && root.TryGetProperty("relocation", out var relocation) && relocation.ValueKind == JsonValueKind.String
                ? relocation.GetString() : null;
        }
        catch (JsonException) { return null; }
    }

    private static byte[] Serialize<T>(T value) => JsonSerializer.SerializeToUtf8Bytes(value, ProtocolJson.Options);
}
