using System.IO.Pipes;
using System.Runtime.InteropServices;
using Keeper.Agent.Hosting;
using Keeper.Session.Modules.Activity;
using Keeper.Session.Modules.UI;
using Keeper.Shared.Contracts;

namespace Keeper.Session.Ipc;

public sealed class SessionClient(string pipeName, int agentPid, ModuleHost host, SessionBuffer buffer, LockScreen screen,
    IInputIdleSource idle, Func<bool> windowsLocked, Func<bool> inCall, PowerNoticeWindow? notice = null)
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
        var gate = new PowerNoticeGate();
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
            var accepted = gate.Filter(request.PowerNotice, request.Shutdown, out var invalidWarn);
            notice?.Set(accepted);
            if (invalidWarn is not null) await buffer.EnqueueAsync(invalidWarn, ct);
            await host.TickAsync(ct);
            await location.PollAsync(request.Location, buffer, ct, locationActive && !request.Shutdown);
            if (request.Shutdown) await host.ShutdownAsync();
            // Peek/commit: la eleccion de la persona solo se descarta cuando la respuesta se escribio; si falla, sigue pendiente.
            var choice = notice?.PeekChoice();
            await SessionProtocol.WriteAsync(pipe, buffer.Response(request.CorrelationId, screen.TakePin(), locationActive ? location.NetworkId : null,
                SessionClientRules.IdleSeconds(windowsLocked(), screen.LockRequested, inCall(), idle), choice), ct);
            if (choice is not null) notice?.CommitChoice(choice);
            if (request.Shutdown)
            {
                var final = await SessionProtocol.ReadAsync<SessionRequest>(pipe, ct);
                if (final.ProtocolVersion != 1 || final.CorrelationId != request.CorrelationId) throw new InvalidDataException("invalid_shutdown_ack");
                buffer.Acknowledge(final.Acknowledged);
                return;
            }
        }
    }
    [DllImport("kernel32.dll", SetLastError = true)] [return: MarshalAs(UnmanagedType.Bool)]
    private static extern bool GetNamedPipeServerProcessId(Microsoft.Win32.SafeHandles.SafePipeHandle pipe, out uint pid);
}
