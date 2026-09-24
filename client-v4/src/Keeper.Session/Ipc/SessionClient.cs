using System.IO.Pipes;
using System.Runtime.InteropServices;
using Keeper.Agent.Hosting;
using Keeper.Session.Modules.UI;
using Keeper.Shared.Contracts;

namespace Keeper.Session.Ipc;

public sealed class SessionClient(string pipeName, int agentPid, ModuleHost host, SessionBuffer buffer, LockScreen screen)
{
    public async Task RunAsync(CancellationToken ct)
    {
        using var pipe = new NamedPipeClientStream(".", pipeName, PipeDirection.InOut, PipeOptions.Asynchronous,
            System.Security.Principal.TokenImpersonationLevel.Identification);
        await pipe.ConnectAsync(15000, ct);
        if (!GetNamedPipeServerProcessId(pipe.SafePipeHandle, out var pid) || pid != agentPid)
            throw new UnauthorizedAccessException("unexpected_agent_process");
        long? version = null;
        while (!ct.IsCancellationRequested)
        {
            var request = await SessionProtocol.ReadAsync<SessionRequest>(pipe, ct);
            if (request.ProtocolVersion != 1 || request.CorrelationId == Guid.Empty || request.Acknowledged.Count > 120)
                throw new InvalidDataException("invalid_agent_request");
            buffer.Acknowledge(request.Acknowledged);
            if (request.Policy is not null && request.PolicyVersion > (version ?? 0))
            {
                await host.ApplyPolicyAsync(request.Policy);
                version = request.PolicyVersion;
            }
            await screen.SetStateAsync(request.Locked, request.PinAllowed);
            await host.TickAsync(ct);
            if (request.Shutdown) await host.ShutdownAsync();
            await SessionProtocol.WriteAsync(pipe, buffer.Response(request.CorrelationId, screen.TakePin()), ct);
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
