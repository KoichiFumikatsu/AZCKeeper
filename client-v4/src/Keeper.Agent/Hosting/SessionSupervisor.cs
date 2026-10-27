using System.IO.Pipes;
using System.Runtime.Versioning;
using System.Security.AccessControl;
using System.Security.Principal;
using Keeper.Agent.Modules.Security;
using Keeper.Agent.Policy;
using Keeper.Shared.Contracts;
using Keeper.Shared.Protocol;

namespace Keeper.Agent.Hosting;

[SupportedOSPlatform("windows")]
public sealed class SessionSupervisor(ISessionSupervisorPlatform platform, DeviceLock deviceLock,
    Func<PolicyCoordinator?> policy, SessionTaskManagerPolicies taskManagers, Func<PowerNotice?>? powerNotice = null) : ModuleBase
{
    public SessionSupervisor(WindowsSessionLauncher launcher, string sessionExe, DeviceLock deviceLock,
        Func<PolicyCoordinator?> policy, Func<PowerNotice?>? powerNotice = null) : this(new WindowsSessionSupervisorPlatform(launcher, sessionExe), deviceLock,
            policy, SessionTaskManagerPolicies.Windows(), powerNotice) { }
    public Keeper.Agent.Modules.Location.LocationModule? Location { get; init; }
    /// <summary>La persona eligio «Posponer» o «Apagar/Reiniciar ahora» en el aviso; el planificador (Task 7) decide.</summary>
    public Action<PowerChoice>? OnPowerChoice { get; init; }
    /// <summary>Inactividad (segundos) por sesion verificada; null = sin dato (el planificador lo trata como «en uso»).</summary>
    public IReadOnlyList<long?> IdleSeconds() => _peers.Values.Where(p => p.Verified).Select(p => p.Idle).ToArray();
    public override string Name => "SessionSupervisor";
    private readonly Dictionary<int, Peer> _peers = [];
    private readonly Dictionary<int, DateTimeOffset> _retry = [];
    private DateTimeOffset _scan;
    private IReadOnlyList<InteractiveSession> _sessions = [];
    private readonly Dictionary<string, string> _protectionErrors = [];
    public override ModuleSnapshot Snapshot() => base.Snapshot() with
    {
        ErrorCode = _protectionErrors.Count == 0 ? null : string.Join(";", _protectionErrors.Keys)
    };
    private void Protect(string code, Action operation)
    {
        try { operation(); _protectionErrors.Remove(code); }
        catch (Exception ex)
        {
            var detail = $"{ex.GetType().Name}: {ex.Message}";
            if (!_protectionErrors.TryGetValue(code, out var previous) || previous != detail)
                Context.Log($"SessionSupervisor {code}: {detail}");
            _protectionErrors[code] = detail;
        }
    }
    public static PipeSecurity CreateSecurity(string sid)
    {
        var security = new PipeSecurity();
        security.SetAccessRuleProtection(true, false);
        security.AddAccessRule(new PipeAccessRule(new SecurityIdentifier(WellKnownSidType.NetworkSid, null), PipeAccessRights.FullControl, AccessControlType.Deny));
        security.AddAccessRule(new PipeAccessRule(new SecurityIdentifier(WellKnownSidType.LocalSystemSid, null), PipeAccessRights.FullControl, AccessControlType.Allow));
        security.AddAccessRule(new PipeAccessRule(new SecurityIdentifier(sid), PipeAccessRights.ReadWrite, AccessControlType.Allow));
        return security;
    }
    public override async Task TickAsync(CancellationToken ct)
    {
        var now = Context.Clock.GetUtcNow();
        if (now >= _scan)
        {
            _scan = now.AddSeconds(10);
            var sessions = platform.Enumerate();
            _sessions = sessions;
            Protect("session_taskmgr_policy_failed", () => taskManagers.Reconcile(sessions.Select(s => s.Sid).ToArray(), deviceLock.Locked));
            foreach (var id in _peers.Keys.Where(id => !sessions.Any(s => s.Id == id)).ToArray()) Remove(id);
            foreach (var session in sessions)
            {
                if (_peers.ContainsKey(session.Id) || _retry.GetValueOrDefault(session.Id) > now) continue;
                try { _peers.Add(session.Id, new(platform.Connect(session, ct), session, now)); }
                catch { _retry[session.Id] = now.AddSeconds(30); throw; }
            }
        }
        else Protect("session_taskmgr_policy_failed", () => taskManagers.Reconcile(_sessions.Select(s => s.Sid).ToArray(), deviceLock.Locked));
        if (deviceLock.Locked) Protect("session_taskmgr_close_failed", () => platform.CloseExistingTaskManagers(_sessions));
        else _protectionErrors.Remove("session_taskmgr_close_failed");
        foreach (var (id, peer) in _peers.ToArray())
        {
            try
            {
                if (peer.Connection.HasExited) throw new IOException("session_exited");
                if (!peer.Connection.Connecting.IsCompleted)
                {
                    if (now - peer.Started > TimeSpan.FromSeconds(20)) throw new IOException("session_connect_timeout");
                    continue;
                }
                await peer.Connection.Connecting;
                if (!peer.Verified)
                {
                    peer.Connection.Verify();
                    peer.Verified = true;
                }
                if (peer.Exchange is not null)
                {
                    if (!peer.Exchange.IsCompleted) continue;
                    await peer.Exchange;
                }
                peer.Exchange = ExchangeAsync(peer, ct);
            }
            catch (OperationCanceledException) when (ct.IsCancellationRequested) { throw; }
            catch (Exception ex) when (ex is IOException or UnauthorizedAccessException or OperationCanceledException or InvalidDataException or System.ComponentModel.Win32Exception or System.Text.Json.JsonException)
            {
                Remove(id); _retry[id] = now.AddSeconds(30);
                // El codigo al servidor no cambia; el motivo queda en el log local para diagnosticar en el equipo.
                Context.Log($"SessionSupervisor: sesion {id}: {ex.GetType().Name}: {ex.Message}");
                await ReportAsync("session_capture_lost", ct);
            }
        }
        State = _protectionErrors.Count > 0 ? "degraded" : _peers.Count > 0 ? "ready" : "no_interactive_session";
    }
    private async Task ExchangeAsync(Peer peer, CancellationToken ct, bool shutdown = false)
    {
        using var timeout = CancellationTokenSource.CreateLinkedTokenSource(ct);
        timeout.CancelAfter(TimeSpan.FromSeconds(5));
        var coordinator = policy();
        var current = coordinator?.Current;
        var version = coordinator?.CurrentVersion;
        var projection = current is null || peer.Version == version ? null : current with { Rules = [], ManagementHosts = [], Composition = [] };
        var request = new SessionRequest(1, Guid.NewGuid(), version, projection, deviceLock.Locked, deviceLock.PinAllowed, peer.Acknowledged, shutdown,
            shutdown ? null : powerNotice?.Invoke(), deviceLock.PinStatus, !shutdown && peer == _peers.OrderBy(p => p.Key).FirstOrDefault().Value ? Location?.Pending : null);
        var response = await peer.Connection.ExchangeAsync(request, timeout.Token);
        SessionProtocol.Validate(response, request.CorrelationId);
        var ack = new List<Guid>();
        if (response.LocationNetworkId is { } network)
        {
            if (peer.LocationNetworkId is { } old && old != network) LocationNetwork.Changed();
            peer.LocationNetworkId = network;
        }
        foreach (var point in response.Locations ?? [])
        {
            if (Location is not null) await Location.AcceptAsync(point, ct);
            ack.Add(point.EventId);
        }
        foreach (var episode in response.Episodes) { await Context.Outbox.EnqueueAsync(episode, ct); ack.Add(episode.EventId); }
        foreach (var log in response.Logs)
        {
            // Se confirma igual para que la sesion lo saque de su buffer; un aviso fuera de lista no debe cortar la captura.
            if (SessionProtocol.IsAccepted(log)) await Context.Outbox.EnqueueAsync(log, ct);
            else await ReportAsync("session_log_rejected", ct, LogEntryLevel.Warn, RejectedCode(log));
            ack.Add(log.EventId);
        }
        foreach (var activity in response.Activity ?? []) { await Context.Outbox.EnqueueAsync(activity, ct); ack.Add(activity.SnapshotId); }
        peer.Idle = response.IdleSeconds;
        if (response.PowerChoice is { } choice) OnPowerChoice?.Invoke(choice);
        if (response.Pin is not null) await deviceLock.ValidatePinAsync(response.Pin, ct);
        peer.Acknowledged = ack;
        peer.Version = version;
        if (shutdown) await peer.Connection.SendAsync(request with { Policy = null, Acknowledged = ack }, timeout.Token);
    }
    private static string RejectedCode(LogEntry log)
    {
        var code = new string($"{log.Component}_{log.Code}".Select(c => char.IsAsciiLetterOrDigit(c) || c == '_' ? c : '_').ToArray());
        return code.Length > 80 ? code[..80] : code;
    }
    private void Remove(int id)
    {
        var peer = _peers[id];
        if (peer.Exchange is not null) _ = peer.Exchange.ContinueWith(t => _ = t.Exception, TaskContinuationOptions.OnlyOnFaulted);
        peer.Connection.Dispose(); _peers.Remove(id);
    }
    public override async Task ShutdownAsync()
    {
        try
        {
            foreach (var (id, peer) in _peers.ToArray())
            {
                try
                {
                    if (peer.Exchange is not null) await peer.Exchange;
                    if (peer.Verified) await ExchangeAsync(peer, CancellationToken.None, shutdown: true);
                }
                catch (Exception ex) when (ex is IOException or OperationCanceledException or ObjectDisposedException or InvalidDataException) { }
                finally { Remove(id); }
            }
        }
        finally { taskManagers.Reconcile([], locked: false); }
    }
    private sealed class Peer(ISupervisedSession connection, InteractiveSession session, DateTimeOffset started)
    {
        public ISupervisedSession Connection { get; } = connection;
        public InteractiveSession Session { get; } = session;
        public DateTimeOffset Started { get; } = started;
        public bool Verified { get; set; }
        public long? Version { get; set; }
        public IReadOnlyList<Guid> Acknowledged { get; set; } = [];
        public Guid? LocationNetworkId { get; set; }
        public long? Idle { get; set; }
        public Task? Exchange { get; set; }
    }
}
