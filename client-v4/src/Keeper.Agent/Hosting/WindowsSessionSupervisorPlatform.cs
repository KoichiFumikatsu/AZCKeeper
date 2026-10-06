using System.ComponentModel;
using System.Diagnostics;
using System.IO.Pipes;
using System.Runtime.InteropServices;
using System.Runtime.Versioning;
using System.Security.Principal;
using Keeper.Shared.Contracts;

namespace Keeper.Agent.Hosting;

public interface ISessionSupervisorPlatform
{
    IReadOnlyList<InteractiveSession> Enumerate();
    ISupervisedSession Connect(InteractiveSession session, CancellationToken ct);
    void CloseExistingTaskManagers(IReadOnlyList<InteractiveSession> sessions);
}

public interface ISupervisedSession : IDisposable
{
    bool HasExited { get; }
    Task Connecting { get; }
    void Verify();
    Task<SessionResponse> ExchangeAsync(SessionRequest request, CancellationToken ct);
    Task SendAsync(SessionRequest request, CancellationToken ct);
}

[SupportedOSPlatform("windows")]
public sealed class WindowsSessionSupervisorPlatform(WindowsSessionLauncher launcher, string sessionExe) : ISessionSupervisorPlatform
{
    public IReadOnlyList<InteractiveSession> Enumerate() => launcher.Enumerate();

    public ISupervisedSession Connect(InteractiveSession session, CancellationToken ct)
    {
        var name = $"AZCKeeper.v4.{session.Id}.{Guid.NewGuid():N}";
        var pipe = NamedPipeServerStreamAcl.Create(name, PipeDirection.InOut, 1, PipeTransmissionMode.Byte,
            PipeOptions.Asynchronous | PipeOptions.FirstPipeInstance, 128 * 1024, 128 * 1024, SessionSupervisor.CreateSecurity(session.Sid));
        try
        {
            var connecting = pipe.WaitForConnectionAsync(ct);
            var process = launcher.Launch(session, sessionExe, name);
            return new Connection(pipe, process, session, connecting);
        }
        catch { pipe.Dispose(); throw; }
    }

    public void CloseExistingTaskManagers(IReadOnlyList<InteractiveSession> sessions)
    {
        var failures = new List<Exception>();
        foreach (var process in Process.GetProcessesByName("Taskmgr"))
        {
            using (process)
            {
                try
                {
                    if (!process.HasExited && sessions.Any(s => s.Id == process.SessionId)) process.Kill();
                }
                catch (InvalidOperationException) { } // Puede terminar entre la enumeracion y Kill.
                catch (Win32Exception ex) { failures.Add(ex); }
            }
        }
        if (failures.Count != 0) throw new AggregateException("session_taskmgr_close_failed", failures);
    }

    private sealed class Connection(NamedPipeServerStream pipe, Process process, InteractiveSession session, Task connecting) : ISupervisedSession
    {
        public bool HasExited => process.HasExited;
        public Task Connecting => connecting;
        public void Verify()
        {
            if (!GetNamedPipeClientProcessId(pipe.SafePipeHandle, out var pid) || pid != process.Id || process.SessionId != session.Id)
                throw new UnauthorizedAccessException("unexpected_session_process");
            string? sid = null;
            pipe.RunAsClient(() => { using var user = WindowsIdentity.GetCurrent(); sid = user.User?.Value; });
            if (sid != session.Sid) throw new UnauthorizedAccessException("unexpected_session_sid");
        }
        public async Task<SessionResponse> ExchangeAsync(SessionRequest request, CancellationToken ct)
        {
            await SessionProtocol.WriteAsync(pipe, request, ct);
            return await SessionProtocol.ReadAsync<SessionResponse>(pipe, ct);
        }
        public Task SendAsync(SessionRequest request, CancellationToken ct) => SessionProtocol.WriteAsync(pipe, request, ct);
        public void Dispose()
        {
            pipe.Dispose();
            if (!process.HasExited) process.Kill();
            process.Dispose();
        }
    }

    [DllImport("kernel32.dll", SetLastError = true)] [return: MarshalAs(UnmanagedType.Bool)]
    private static extern bool GetNamedPipeClientProcessId(Microsoft.Win32.SafeHandles.SafePipeHandle pipe, out uint pid);
}
