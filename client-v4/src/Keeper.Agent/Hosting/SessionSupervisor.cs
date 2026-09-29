using System.Diagnostics;
using System.IO.Pipes;
using System.Runtime.InteropServices;
using System.Runtime.Versioning;
using System.Security.AccessControl;
using System.Security.Principal;
using Keeper.Agent.Modules.Security;
using Keeper.Agent.Policy;
using Keeper.Shared.Contracts;

namespace Keeper.Agent.Hosting;

[SupportedOSPlatform("windows")]
public sealed class SessionSupervisor(WindowsSessionLauncher launcher, string sessionExe, DeviceLock deviceLock,
    Func<PolicyCoordinator?> policy) : ModuleBase
{
    public override string Name => "SessionSupervisor";
    private readonly Dictionary<int, Peer> _peers = [];
    private readonly Dictionary<int, DateTimeOffset> _retry = [];
    private DateTimeOffset _scan;
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
            var sessions = launcher.Enumerate();
            foreach (var id in _peers.Keys.Where(id => !sessions.Any(s => s.Id == id)).ToArray()) Remove(id);
            foreach (var session in sessions)
            {
                if (_peers.ContainsKey(session.Id) || _retry.GetValueOrDefault(session.Id) > now) continue;
                var name = $"AZCKeeper.v4.{session.Id}.{Guid.NewGuid():N}";
                var pipe = NamedPipeServerStreamAcl.Create(name, PipeDirection.InOut, 1, PipeTransmissionMode.Byte,
                    PipeOptions.Asynchronous | PipeOptions.FirstPipeInstance, 128 * 1024, 128 * 1024, CreateSecurity(session.Sid));
                try
                {
                    var connecting = pipe.WaitForConnectionAsync(ct);
                    var process = launcher.Launch(session, sessionExe, name);
                    _peers.Add(session.Id, new(pipe, process, session, connecting, now));
                }
                catch { pipe.Dispose(); _retry[session.Id] = now.AddSeconds(30); throw; }
            }
        }
        foreach (var (id, peer) in _peers.ToArray())
        {
            try
            {
                if (peer.Process.HasExited) throw new IOException($"session_exited (exit code {peer.Process.ExitCode})");
                if (!peer.Connecting.IsCompleted)
                {
                    if (now - peer.Started > TimeSpan.FromSeconds(20)) throw new IOException("session_connect_timeout");
                    continue;
                }
                await peer.Connecting;
                if (!peer.Verified)
                {
                    if (!GetNamedPipeClientProcessId(peer.Pipe.SafePipeHandle, out var pid) || pid != peer.Process.Id || peer.Process.SessionId != id)
                        throw new UnauthorizedAccessException("unexpected_session_process");
                    string? sid = null;
                    peer.Pipe.RunAsClient(() => { using var user = WindowsIdentity.GetCurrent(); sid = user.User?.Value; });
                    if (sid != peer.Session.Sid) throw new UnauthorizedAccessException("unexpected_session_sid");
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
        State = _peers.Count > 0 ? "ready" : "no_interactive_session";
    }
    private async Task ExchangeAsync(Peer peer, CancellationToken ct, bool shutdown = false)
    {
        using var timeout = CancellationTokenSource.CreateLinkedTokenSource(ct);
        timeout.CancelAfter(TimeSpan.FromSeconds(5));
        var coordinator = policy();
        var current = coordinator?.Current;
        var version = coordinator?.CurrentVersion;
        var projection = current is null || peer.Version == version ? null : current with { Rules = [], ManagementHosts = [], Composition = [] };
        var request = new SessionRequest(1, Guid.NewGuid(), version, projection, deviceLock.Locked, deviceLock.PinAllowed, peer.Acknowledged, shutdown);
        await SessionProtocol.WriteAsync(peer.Pipe, request, timeout.Token);
        var response = await SessionProtocol.ReadAsync<SessionResponse>(peer.Pipe, timeout.Token);
        SessionProtocol.Validate(response, request.CorrelationId);
        var ack = new List<Guid>();
        foreach (var episode in response.Episodes) { await Context.Outbox.EnqueueAsync(episode, ct); ack.Add(episode.EventId); }
        foreach (var log in response.Logs) { await Context.Outbox.EnqueueAsync(log, ct); ack.Add(log.EventId); }
        foreach (var activity in response.Activity ?? []) { await Context.Outbox.EnqueueAsync(activity, ct); ack.Add(activity.SnapshotId); }
        if (response.Pin is not null) await deviceLock.ValidatePinAsync(response.Pin, ct);
        peer.Acknowledged = ack;
        peer.Version = version;
        if (shutdown) await SessionProtocol.WriteAsync(peer.Pipe, request with { Policy = null, Acknowledged = ack }, timeout.Token);
    }
    private void Remove(int id)
    {
        var peer = _peers[id];
        peer.Pipe.Dispose();
        if (peer.Exchange is not null) _ = peer.Exchange.ContinueWith(t => _ = t.Exception, TaskContinuationOptions.OnlyOnFaulted);
        if (!peer.Process.HasExited) peer.Process.Kill();
        peer.Process.Dispose(); _peers.Remove(id);
    }
    public override async Task ShutdownAsync()
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
    private sealed class Peer(NamedPipeServerStream pipe, Process process, InteractiveSession session, Task connecting, DateTimeOffset started)
    {
        public NamedPipeServerStream Pipe { get; } = pipe;
        public Process Process { get; } = process;
        public InteractiveSession Session { get; } = session;
        public Task Connecting { get; } = connecting;
        public DateTimeOffset Started { get; } = started;
        public bool Verified { get; set; }
        public long? Version { get; set; }
        public IReadOnlyList<Guid> Acknowledged { get; set; } = [];
        public Task? Exchange { get; set; }
    }
    [DllImport("kernel32.dll", SetLastError = true)] [return: MarshalAs(UnmanagedType.Bool)]
    private static extern bool GetNamedPipeClientProcessId(Microsoft.Win32.SafeHandles.SafePipeHandle pipe, out uint pid);
}
