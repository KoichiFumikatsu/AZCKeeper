using System.IO.Pipes;
using System.Runtime.InteropServices;
using System.Runtime.Versioning;
using System.Security.Principal;
using Keeper.Shared.Contracts;

namespace Keeper.Agent.Hosting;

// Pide la cedula al usuario de la primera sesion interactiva abriendo Keeper.Session en modo identificacion.
// Mismas garantias que SessionSupervisor: binario verificado contra el trust, canal con ACL solo SYSTEM + ese
// usuario, y se comprueba que el otro extremo es el proceso lanzado en esa sesion con ese SID.
[SupportedOSPlatform("windows")]
public sealed class WindowsDocumentPrompt(WindowsSessionLauncher launcher, string sessionExe, Action<string> log)
{
    public static readonly TimeSpan AnswerTimeout = TimeSpan.FromMinutes(15);

    public async Task<string?> AskAsync(CancellationToken ct)
    {
        var session = launcher.Enumerate().FirstOrDefault();
        if (session is null) return null;
        var name = $"{IdentifyProtocol.PipePrefix}{session.Id}.{Guid.NewGuid():N}";
        await using var pipe = NamedPipeServerStreamAcl.Create(name, PipeDirection.InOut, 1, PipeTransmissionMode.Byte,
            PipeOptions.Asynchronous | PipeOptions.FirstPipeInstance, 4096, 4096, SessionSupervisor.CreateSecurity(session.Sid));
        using var timeout = CancellationTokenSource.CreateLinkedTokenSource(ct);
        timeout.CancelAfter(AnswerTimeout);
        var connecting = pipe.WaitForConnectionAsync(timeout.Token);
        using var process = launcher.Launch(session, sessionExe, name);
        try
        {
            await connecting;
            if (!GetNamedPipeClientProcessId(pipe.SafePipeHandle, out var pid) || pid != process.Id || process.SessionId != session.Id)
                throw new UnauthorizedAccessException("unexpected_identify_process");
            string? sid = null;
            pipe.RunAsClient(() => { using var user = WindowsIdentity.GetCurrent(); sid = user.User?.Value; });
            if (sid != session.Sid) throw new UnauthorizedAccessException("unexpected_identify_sid");
            var document = IdentifyProtocol.Validate(await SessionProtocol.ReadAsync<IdentifyAnswer>(pipe, timeout.Token));
            log(document is null ? "alta: la persona cerro la ventana de cedula" : "alta: cedula recibida desde la sesion del usuario");
            return document;
        }
        catch (OperationCanceledException) when (!ct.IsCancellationRequested)
        {
            log("alta: sin respuesta a la ventana de cedula");
            return null;
        }
        finally
        {
            try { if (!process.HasExited) process.Kill(); } catch (InvalidOperationException) { }
        }
    }

    [DllImport("kernel32.dll", SetLastError = true)] [return: MarshalAs(UnmanagedType.Bool)]
    private static extern bool GetNamedPipeClientProcessId(Microsoft.Win32.SafeHandles.SafePipeHandle pipe, out uint pid);
}
