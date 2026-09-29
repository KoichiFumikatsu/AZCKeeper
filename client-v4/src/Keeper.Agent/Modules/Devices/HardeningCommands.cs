using System.Diagnostics;
using System.Runtime.InteropServices;
using System.Runtime.Versioning;
using System.Text.Json;
using System.Text.Json.Serialization;
using Keeper.Agent.Hosting;
using Keeper.Shared.Contracts;

namespace Keeper.Agent.Modules.Devices;

// Endurecer / revertir desde el panel (comandos harden / unharden). El agente no reimplementa el Modo B: lanza el
// bootstrapper instalado (verificado contra el trust) con --harden / --unharden y lee el journal que este deja.
public sealed record HardeningOutcome(int ExitCode, HardeningState? State);
public interface IHardeningLauncher { Task<HardeningOutcome> RunAsync(bool undo, string adminName, CancellationToken ct); }
// Aviso en la sesion y cierre diferido de las sesiones de las cuentas degradadas: sin nuevo inicio de sesion el token
// actual conserva el grupo Administradores.
public interface ISessionLogoff { int Schedule(IReadOnlyCollection<string> sids, TimeSpan delay); }

public static class HardeningCommand
{
    public static readonly TimeSpan LogoffDelay = TimeSpan.FromMinutes(2);
    private static readonly System.Text.RegularExpressions.Regex AdminName = new(@"^[^\x00-\x1f""/\\\[\]:;|=,+*?<>@]{1,20}$");

    public static string ValidateAdminName(string? name) =>
        name is not null && AdminName.IsMatch(name) && !name.EndsWith('.') ? name : throw new CommandRejectedException("invalid_admin_name");

    // Codigo de resultado para el panel. Fallo = el journal dice por que (failed_step_N, rollback_incomplete...).
    public static (bool Ok, string Code) Result(HardeningOutcome outcome, bool undo, int loggedOffSessions)
    {
        var state = outcome.State;
        if (outcome.ExitCode == 0 && state is not null && state.Status == (undo ? "unhardened" : "hardened"))
            return (true, undo ? "unhardened" : loggedOffSessions > 0 ? $"hardened_logoff_{loggedOffSessions}" : "hardened");
        var code = state?.ErrorCode ?? (outcome.ExitCode == 3 ? "elevation_required" : $"exit_{outcome.ExitCode}");
        return (false, code.Length <= 100 ? code : code[..100]);
    }
}

[SupportedOSPlatform("windows")]
public sealed class WindowsHardeningLauncher(string binDirectory, string dataDirectory, IReadOnlyDictionary<string, string> binaryHashes) : IHardeningLauncher
{
    public static readonly TimeSpan Timeout = TimeSpan.FromMinutes(5);
    private static readonly JsonSerializerOptions Json = CreateJson();
    private static JsonSerializerOptions CreateJson()
    {
        var options = new JsonSerializerOptions { PropertyNamingPolicy = JsonNamingPolicy.SnakeCaseLower };
        options.Converters.Add(new JsonStringEnumConverter());
        return options;
    }

    public async Task<HardeningOutcome> RunAsync(bool undo, string adminName, CancellationToken ct)
    {
        var exe = Path.Combine(binDirectory, "Keeper.Bootstrapper.exe");
        WindowsSessionLauncher.VerifyTrustedBinaries(exe, binaryHashes);
        var directory = Path.Combine(dataDirectory, "hardening");
        Directory.CreateDirectory(directory);
        // Solo el nombre de la cuenta gestionada; la clave ya esta en password.dpapi (la bajo HardeningPasswordModule).
        var config = Path.Combine(directory, "panel-command.json");
        await File.WriteAllTextAsync(config, JsonSerializer.Serialize(new { admin_name = adminName }), ct);
        var start = new ProcessStartInfo(exe) { UseShellExecute = false, CreateNoWindow = true, WorkingDirectory = binDirectory };
        start.ArgumentList.Add(undo ? "--unharden" : "--harden");
        start.ArgumentList.Add("--hardening-config");
        start.ArgumentList.Add(config);
        using var process = Process.Start(start) ?? throw new IOException("bootstrapper_not_started");
        using var timeout = CancellationTokenSource.CreateLinkedTokenSource(ct);
        timeout.CancelAfter(Timeout);
        try { await process.WaitForExitAsync(timeout.Token); }
        catch (OperationCanceledException) when (!ct.IsCancellationRequested)
        {
            try { process.Kill(); } catch (InvalidOperationException) { }
            throw new CommandRejectedException("hardening_timeout");
        }
        HardeningState? state = null;
        var journal = Path.Combine(directory, "state.json");
        if (File.Exists(journal)) state = JsonSerializer.Deserialize<HardeningState>(await File.ReadAllBytesAsync(journal, ct), Json);
        return new HardeningOutcome(process.ExitCode, state);
    }
}

[SupportedOSPlatform("windows")]
public sealed class WindowsSessionLogoff(WindowsSessionLauncher launcher, Action<string> log) : ISessionLogoff
{
    public int Schedule(IReadOnlyCollection<string> sids, TimeSpan delay)
    {
        var sessions = launcher.Enumerate().Where(s => sids.Contains(s.Sid, StringComparer.OrdinalIgnoreCase)).ToArray();
        foreach (var session in sessions)
        {
            const string title = "AZCKeeper";
            var message = $"IT aplicó cambios de seguridad en este equipo. Tu sesión se cerrará en {(int)delay.TotalMinutes} minutos para aplicarlos: guarda tu trabajo ahora.";
            // MB_OK | MB_ICONWARNING | MB_TOPMOST | MB_SETFOREGROUND; no espera respuesta.
            WTSSendMessage(IntPtr.Zero, session.Id, title, title.Length * 2, message, message.Length * 2, 0x00040030 | 0x00010000, (int)delay.TotalSeconds, out _, false);
            var id = session.Id;
            _ = Task.Run(async () =>
            {
                await Task.Delay(delay);
                var ok = WTSLogoffSession(IntPtr.Zero, id, false);
                log(ok ? $"endurecimiento: sesion {id} cerrada para aplicar la degradacion" : $"endurecimiento warn: no se pudo cerrar la sesion {id} ({Marshal.GetLastWin32Error()})");
            });
        }
        return sessions.Length;
    }

    [DllImport("wtsapi32.dll", CharSet = CharSet.Unicode, SetLastError = true)] [return: MarshalAs(UnmanagedType.Bool)]
    private static extern bool WTSSendMessage(IntPtr server, int sessionId, string title, int titleLength, string message, int messageLength,
        int style, int timeout, out int response, [MarshalAs(UnmanagedType.Bool)] bool wait);
    [DllImport("wtsapi32.dll", SetLastError = true)] [return: MarshalAs(UnmanagedType.Bool)]
    private static extern bool WTSLogoffSession(IntPtr server, int sessionId, [MarshalAs(UnmanagedType.Bool)] bool wait);
}
