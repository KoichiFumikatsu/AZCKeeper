using System.Net.Sockets;
using System.Security.Authentication;
using System.Security.Cryptography;
using System.Text;
using Keeper.Shared.Contracts;
using Keeper.Shared.Protocol;

namespace Keeper.Agent.Transport;

// El servidor de origen en modo mudado responde 409 device_relocated con la orden firmada en el cuerpo.
public sealed class DeviceRelocatedException(string relocation) : Exception("device_relocated")
{
    public string Relocation { get; } = relocation;
}

// Conexion a UN servidor (HttpClient con su BaseAddress + SyncClient). Mudarse = crear otra y soltar esta.
public interface IRelocatableSync : ISyncCycle, IDisposable
{
    Task ProbeLoginAsync(CancellationToken ct);
}

public sealed class SyncConnection(HttpClient http, SyncClient client) : IRelocatableSync
{
    public long RequestCount => client.RequestCount;
    public Task<int> ExecuteAsync(CancellationToken ct) => client.ExecuteAsync(ct);
    public Task ProbeLoginAsync(CancellationToken ct) => client.ProbeLoginAsync(ct);
    public void Dispose() => http.Dispose();
}

// Mudanza de servidor (4.0.24). La orden llega en /client/sync (relocation) o en un 409 device_relocated; se verifica
// con la clave de releases (RelocationVerifier) y se PRUEBA el destino (challenge + login firmado con la misma clave de
// equipo: la BD copiada conoce al equipo). Solo si el login funciona se guarda {data}\api-base.json (prioridad sobre
// KEEPER_API_BASE), se cambia de conexion (la firma HTTP usa la URI absoluta, asi que la autoridad nueva va sola; el token
// se guarda con clave base+keyId, asi que es otro) y la cola se reenvia al destino con el mismo lote pendiente.
// Si el destino falla (DNS, TLS, 401 porque aun no se copio la BD): se sigue en la base actual y se reintenta cada 15 min
// hasta expires_at. La cola nunca se toca aqui.
public sealed class ServerRelocation : ISyncCycle, IDisposable
{
    public static readonly TimeSpan RetryInterval = TimeSpan.FromMinutes(15);
    public const string Component = "ServerRelocation";
    private readonly Func<Uri, IRelocatableSync> _connect;
    private readonly IReadOnlyDictionary<string, ECDsa> _trustedKeys;
    private readonly Guid _deviceId;
    private readonly Func<Guid?> _tenant;
    private readonly string _dataDirectory;
    private readonly string _installedBase;
    private readonly IEventSink _events;
    private readonly TimeProvider _clock;
    private readonly Action<string> _log;
    private IRelocatableSync _current;
    private long _retiredRequests;
    private long _lastSequence;
    private (RelocationManifest Manifest, string Jws)? _pending;
    private DateTimeOffset _nextAttempt;
    private string? _lastFailure;
    private readonly HashSet<string> _rejected = new(StringComparer.Ordinal);
    private readonly HashSet<Guid> _notified = [];

    public ServerRelocation(Uri api, long lastSequence, string installedBase, Func<Uri, IRelocatableSync> connect,
        IReadOnlyDictionary<string, ECDsa> trustedKeys, Guid deviceId, Func<Guid?> tenant, string dataDirectory, IEventSink events,
        TimeProvider clock, Action<string> log)
    {
        CurrentApi = api; _lastSequence = lastSequence; _installedBase = installedBase; _connect = connect; _trustedKeys = trustedKeys;
        _deviceId = deviceId; _tenant = tenant; _dataDirectory = dataDirectory; _events = events; _clock = clock; _log = log;
        _current = connect(api);
    }

    public Uri CurrentApi { get; private set; }
    public long RequestCount => _retiredRequests + _current.RequestCount;
    public bool HasPending => _pending is not null;

    // Base al arrancar: {data}\api-base.json si aplica a KEEPER_API_BASE (ApiBaseOverride.Resolve), con su sequence.
    public static (Uri? Api, long Sequence) StartupBase(string dataDirectory, string? installedBase)
    {
        if (installedBase is null) return (null, 0);
        var moved = ApiBaseOverride.Read(dataDirectory);
        var applies = moved is not null && ApiBaseOverride.SameBase(moved.InstalledBase, installedBase);
        var value = applies ? moved!.ApiBase : installedBase;
        if (!Uri.TryCreate(value, UriKind.Absolute, out var api) || !ApiBaseOverride.ValidApi(api))
            throw new InvalidOperationException("KEEPER_API_BASE must be an HTTPS /v1/ URL");
        return (api, applies ? moved!.Sequence : 0);
    }

    public async Task<int> ExecuteAsync(CancellationToken ct)
    {
        if (await TryRelocateAsync(ct)) return await _current.ExecuteAsync(ct);
        int next;
        try { next = await _current.ExecuteAsync(ct); }
        catch (DeviceRelocatedException ex)
        {
            await OfferAsync(ex.Relocation, _tenant(), ct, fromConflict: true);
            if (await TryRelocateAsync(ct)) return await _current.ExecuteAsync(ct);
            throw;
        }
        // Orden recibida en este mismo sync: se prueba ya (la cola ya quedo confirmada por el origen).
        return await TryRelocateAsync(ct) ? await _current.ExecuteAsync(ct) : next;
    }

    // Llamado con la respuesta de cada sync (campo relocation) y con el cuerpo de un 409 device_relocated.
    public async Task OfferAsync(string? jws, Guid? tenantId, CancellationToken ct, bool fromConflict = false)
    {
        if (string.IsNullOrEmpty(jws) || _pending?.Jws == jws) return;
        RelocationManifest manifest;
        try { manifest = RelocationVerifier.Verify(jws, _trustedKeys, CurrentApi, tenantId, _deviceId, _lastSequence, _clock.GetUtcNow()); }
        catch (RelocationRejectedException rejected)
        {
            var key = rejected.RelocationId?.ToString() ?? Convert.ToHexString(SHA256.HashData(Encoding.UTF8.GetBytes(jws)));
            if (_rejected.Add(key)) await ReportAsync("relocation_rejected", LogEntryLevel.Warn, rejected.Reason, ct);
            return;
        }
        if (fromConflict && _notified.Add(manifest.Id)) await ReportAsync("device_relocated", LogEntryLevel.Info, manifest.ToBase.Host, ct);
        _pending = (manifest, jws);
        _nextAttempt = DateTimeOffset.MinValue;
        _lastFailure = null;
        _log($"{Component}: orden {manifest.Id} (sequence {manifest.Sequence}) verificada hacia {manifest.ToBase.Host}; se prueba el destino");
    }

    private async Task<bool> TryRelocateAsync(CancellationToken ct)
    {
        if (_pending is not { } pending) return false;
        var now = _clock.GetUtcNow();
        if (now >= pending.Manifest.ExpiresAt)
        {
            _pending = null;
            _log($"{Component}: la orden {pending.Manifest.Id} vencio sin poder mudarse; se sigue en {CurrentApi.Host}");
            return false;
        }
        if (now < _nextAttempt) return false;
        _nextAttempt = now + RetryInterval;
        var destination = pending.Manifest.ToApi;
        var target = _connect(destination);
        try { await target.ProbeLoginAsync(ct); }
        catch (Exception ex) when (ex is not OperationCanceledException || !ct.IsCancellationRequested)
        {
            _retiredRequests += target.RequestCount;
            target.Dispose();
            await FailedAsync(FailureReason(ex), ct);
            return false;
        }
        try { new ApiBaseOverride(destination.AbsoluteUri, _installedBase, pending.Manifest.Sequence, pending.Manifest.Id, now).Write(_dataDirectory); }
        catch (Exception ex) when (ex is IOException or UnauthorizedAccessException)
        {
            _retiredRequests += target.RequestCount;
            target.Dispose();
            await FailedAsync("persist_failed", ct);
            return false;
        }
        var previous = _current;
        _retiredRequests += previous.RequestCount;
        _current = target;
        previous.Dispose();
        var from = CurrentApi.Host;
        CurrentApi = destination;
        _lastSequence = pending.Manifest.Sequence;
        _pending = null;
        _lastFailure = null;
        _log($"{Component}: mudado de {from} a {destination.Host} (orden {pending.Manifest.Id}); la cola se reenvia al destino");
        await ReportAsync("relocated", LogEntryLevel.Info, destination.Host, ct);
        return true;
    }

    // Un fallo por intento al log local; al servidor solo cuando cambia el motivo (cada 15 min durante dias seria ruido).
    private async Task FailedAsync(string reason, CancellationToken ct)
    {
        if (reason == _lastFailure) { _log($"{Component} warn: relocation_failed ({reason}); reintento en {RetryInterval.TotalMinutes:0} min"); return; }
        _lastFailure = reason;
        await ReportAsync("relocation_failed", LogEntryLevel.Warn, reason, ct);
    }

    // Motivo corto (va en fields.error_code; el servidor rechaza codigos con palabras como token o password).
    public static string FailureReason(Exception ex)
    {
        if (ex is DeviceRelocatedException) return "destination_relocated";
        if (ex is TransportException transport) return $"http_{(int)transport.Status}";
        if (ex is TimeoutException or TaskCanceledException) return "timeout";
        for (Exception? current = ex; current is not null; current = current.InnerException)
        {
            if (current is AuthenticationException) return "tls";
            if (current is SocketException { SocketErrorCode: SocketError.HostNotFound or SocketError.NoData or SocketError.TryAgain }) return "dns";
        }
        if (ex is HttpRequestException) return "network";
        if (ex is InvalidDataException or System.Text.Json.JsonException) return "invalid_response";
        return ex.GetType().Name;
    }

    private Task ReportAsync(string code, LogEntryLevel level, string? errorCode, CancellationToken ct)
    {
        _log($"{Component} {level.ToString().ToLowerInvariant()}: {code}{(errorCode is null ? "" : $" ({errorCode})")}");
        return _events.EnqueueAsync(new LogEntry
        {
            EventId = Guid.NewGuid(), At = _clock.GetUtcNow(), Level = level, Component = Component, Code = code,
            Fields = errorCode is null ? null : new LogEntryFields { ErrorCode = errorCode }
        }, ct);
    }

    public void Dispose() => _current.Dispose();
}
