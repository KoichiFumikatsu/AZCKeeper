using System.IO.Pipes;
using System.Runtime.InteropServices;
using Keeper.Agent.Hosting;
using Keeper.Session.Modules.Activity;
using Keeper.Session.Modules.UI;
using Keeper.Shared.Contracts;

namespace Keeper.Session.Ipc;

public sealed class SessionClient(string pipeName, int agentPid, ModuleHost host, SessionBuffer buffer, LockScreen screen,
    IInputIdleSource idle, Func<bool> locked, PowerNoticeWindow? notice = null)
{
    public async Task RunAsync(CancellationToken ct)
    {
        using var pipe = new NamedPipeClientStream(".", pipeName, PipeDirection.InOut, PipeOptions.Asynchronous,
            System.Security.Principal.TokenImpersonationLevel.Identification);
        await pipe.ConnectAsync(15000, ct);
        if (!GetNamedPipeServerProcessId(pipe.SafePipeHandle, out var pid) || pid != agentPid)
            throw new UnauthorizedAccessException("unexpected_agent_process");
        long? version = null;
        using var location = new Keeper.Session.Modules.Location.LocationTracker();
        var locationActive = false;
        while (!ct.IsCancellationRequested)
        {
            var request = await SessionProtocol.ReadAsync<SessionRequest>(pipe, ct);
            if (request.ProtocolVersion != 1 || request.CorrelationId == Guid.Empty || request.Acknowledged.Count > 120)
                throw new InvalidDataException("invalid_agent_request");
            buffer.Acknowledge(request.Acknowledged);
            if (request.Policy is not null && request.PolicyVersion > (version ?? 0))
            {
                locationActive = request.Policy.LocationTracking == true;
                await host.ApplyPolicyAsync(request.Policy);
                version = request.PolicyVersion;
            }
            await screen.SetStateAsync(request.Locked, request.PinAllowed, request.PinStatus);
            if (request.PowerNotice is { } incoming) SessionProtocol.Validate(incoming);
            notice?.Set(request.Shutdown ? null : request.PowerNotice);
            await host.TickAsync(ct);
            await location.PollAsync(request.Location, buffer, ct, locationActive && !request.Shutdown);
            if (request.Shutdown) await host.ShutdownAsync();
            await SessionProtocol.WriteAsync(pipe, buffer.Response(request.CorrelationId, screen.TakePin(), locationActive ? location.NetworkId : null,
                IdleSeconds(), notice?.TakeChoice()), ct);
            if (request.Shutdown)
            {
                var final = await SessionProtocol.ReadAsync<SessionRequest>(pipe, ct);
                if (final.ProtocolVersion != 1 || final.CorrelationId != request.CorrelationId) throw new InvalidDataException("invalid_shutdown_ack");
                buffer.Acknowledge(final.Acknowledged);
                return;
            }
        }
    }
    // Bloqueado = sin uso (spec 3): un ano. Si Windows no responde, null (desconocido): el planificador lo trata como «en uso».
    private long? IdleSeconds()
    {
        if (locked()) return SessionProtocol.MaxIdleSeconds;
        try { return Math.Clamp((long)idle.GetIdleTime().TotalSeconds, 0, SessionProtocol.MaxIdleSeconds); }
        catch (System.ComponentModel.Win32Exception) { return null; }
    }
    [DllImport("kernel32.dll", SetLastError = true)] [return: MarshalAs(UnmanagedType.Bool)]
    private static extern bool GetNamedPipeServerProcessId(Microsoft.Win32.SafeHandles.SafePipeHandle pipe, out uint pid);
}
