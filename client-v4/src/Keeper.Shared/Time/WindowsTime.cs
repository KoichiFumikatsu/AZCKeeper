using System.Diagnostics;
using System.Runtime.InteropServices;
using System.Runtime.Versioning;
using System.Text.RegularExpressions;
using Microsoft.Win32;

namespace Keeper.Shared.Time;

// Hora de Windows: servicio W32Time en automatico y en marcha, NTP time.windows.com (o el del dominio, que no se toca),
// zona horaria automatica (servicio tzautoupdate) y w32tm /resync. Lo usan el agente (modulo TimeSync, SYSTEM) y el
// Bootstrapper antes de arrancar el servicio que pide el alta. Un equipo con la hora mal no puede firmar peticiones
// que el servidor acepte (ventana de +-60 s); el agente tambien corrige sus firmas con la hora del servidor.
public sealed record TimeSettings(int? W32TimeStart, bool W32TimeRunning, string? NtpType, string? NtpServer, bool DomainJoined,
    int? TzAutoUpdateStart, string? TimeZoneId);

// Item: nombre corto para el log (va en error_code: solo [a-zA-Z0-9_.:-]).
public sealed record TimeCommand(string Item, string Program, IReadOnlyList<string> Arguments)
{
    public override string ToString() => $"{Program} {string.Join(' ', Arguments)}";
}

public sealed record TimeCommandResult(int ExitCode, string Output);

public interface ITimeSystem
{
    Task<TimeSettings> ReadAsync(CancellationToken ct);
    Task<TimeCommandResult> RunAsync(TimeCommand command, CancellationToken ct);
    // Pone la hora del sistema (UTC). Solo la usa TimeSync del agente, con la hora del servidor de Keeper.
    void SetSystemTime(DateTimeOffset utc);
}

public static class TimeSyncPlan
{
    public const string WindowsNtp = "time.windows.com,0x9";
    public const int ServiceAuto = 2;
    public const int ServiceDemand = 3;
    public const int ServiceDisabled = 4;

    public static TimeCommand Resync { get; } = new("resincronizar", "w32tm.exe", ["/resync"]);

    // Lo que hay que corregir para dejar la hora automatica, en orden de ejecucion (sin la resincronizacion).
    public static IReadOnlyList<TimeCommand> Restore(TimeSettings s)
    {
        var commands = new List<TimeCommand>();
        if (s.W32TimeStart != ServiceAuto) commands.Add(new("servicio_hora", "sc.exe", ["config", "w32time", "start=", "auto"]));
        if (!s.W32TimeRunning) commands.Add(new("servicio_detenido", "sc.exe", ["start", "w32time"]));
        if (s.DomainJoined)
        {
            // En dominio la fuente la decide el dominio (NT5DS o la GPO): solo se reactiva si alguien la apago.
            if (s.NtpType is null || s.NtpType.Equals("NoSync", StringComparison.OrdinalIgnoreCase))
                commands.Add(new("hora_automatica", "w32tm.exe", ["/config", "/syncfromflags:domhier", "/update"]));
        }
        else if (!string.Equals(s.NtpType, "NTP", StringComparison.OrdinalIgnoreCase) || !UsesWindowsNtp(s.NtpServer))
            commands.Add(new("hora_automatica", "w32tm.exe", ["/config", "/manualpeerlist:" + WindowsNtp, "/syncfromflags:manual", "/update"]));
        if (s.TzAutoUpdateStart is not (ServiceAuto or ServiceDemand))
            commands.Add(new("zona_automatica", "sc.exe", ["config", "tzautoupdate", "start=", "demand"]));
        return commands;
    }

    // La zona que el usuario cambio a mano (para eso tuvo que apagar la zona automatica) se devuelve a la anterior.
    public static TimeCommand RestoreZone(string zoneId) => new("zona_horaria", "tzutil.exe", ["/s", zoneId]);

    public static bool UsesWindowsNtp(string? server) =>
        server is not null && server.Split(' ', StringSplitOptions.RemoveEmptyEntries)
            .Any(p => p.Split(',')[0].Equals("time.windows.com", StringComparison.OrdinalIgnoreCase));

    // Salida de "sc.exe query": "STATE : 4 RUNNING" (la etiqueta se traduce, el codigo y RUNNING no).
    public static bool IsRunning(string scQueryOutput) => Regex.IsMatch(scQueryOutput, @"\b4\s+RUNNING\b");
}

[SupportedOSPlatform("windows")]
public sealed class WindowsTimeSystem(TimeSpan? timeout = null) : ITimeSystem
{
    private readonly TimeSpan _timeout = timeout ?? TimeSpan.FromSeconds(90);

    public async Task<TimeSettings> ReadAsync(CancellationToken ct)
    {
        using var w32 = Registry.LocalMachine.OpenSubKey(@"SYSTEM\CurrentControlSet\Services\W32Time");
        using var parameters = Registry.LocalMachine.OpenSubKey(@"SYSTEM\CurrentControlSet\Services\W32Time\Parameters");
        using var tz = Registry.LocalMachine.OpenSubKey(@"SYSTEM\CurrentControlSet\Services\tzautoupdate");
        using var zone = Registry.LocalMachine.OpenSubKey(@"SYSTEM\CurrentControlSet\Control\TimeZoneInformation");
        var query = await RunAsync(new("consulta", "sc.exe", ["query", "w32time"]), ct);
        return new(w32?.GetValue("Start") as int?, TimeSyncPlan.IsRunning(query.Output), parameters?.GetValue("Type") as string,
            parameters?.GetValue("NtpServer") as string, DomainJoined(), tz?.GetValue("Start") as int?, zone?.GetValue("TimeZoneKeyName") as string);
    }

    public async Task<TimeCommandResult> RunAsync(TimeCommand command, CancellationToken ct)
    {
        if (command.Program is not ("sc.exe" or "w32tm.exe" or "tzutil.exe")) throw new ArgumentException("programa no permitido", nameof(command));
        var start = new ProcessStartInfo(Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.System), command.Program))
        { UseShellExecute = false, CreateNoWindow = true, RedirectStandardOutput = true, RedirectStandardError = true };
        foreach (var argument in command.Arguments) start.ArgumentList.Add(argument);
        using var process = Process.Start(start) ?? throw new IOException("time_command_not_started");
        using var limit = CancellationTokenSource.CreateLinkedTokenSource(ct);
        limit.CancelAfter(_timeout);
        var output = process.StandardOutput.ReadToEndAsync(limit.Token);
        var error = process.StandardError.ReadToEndAsync(limit.Token);
        try { await process.WaitForExitAsync(limit.Token); }
        catch (OperationCanceledException)
        {
            try { process.Kill(); } catch (InvalidOperationException) { }
            if (ct.IsCancellationRequested) throw;
            return new(-1, "timeout");
        }
        return new(process.ExitCode, await output + await error);
    }

    public void SetSystemTime(DateTimeOffset utc)
    {
        // SYSTEM tiene SeSystemtimePrivilege pero deshabilitado: se habilita en el token del proceso antes de llamar.
        using var self = Process.GetCurrentProcess();
        if (!OpenProcessToken(self.Handle, 0x0020 | 0x0008, out var token)) // ADJUST_PRIVILEGES | QUERY
            throw new System.ComponentModel.Win32Exception();
        try
        {
            if (!LookupPrivilegeValue(null, "SeSystemtimePrivilege", out var luid)) throw new System.ComponentModel.Win32Exception();
            var privileges = new TokenPrivileges { Count = 1, Luid = luid, Attributes = 0x00000002 }; // SE_PRIVILEGE_ENABLED
            if (!AdjustTokenPrivileges(token, false, ref privileges, 0, IntPtr.Zero, IntPtr.Zero) || Marshal.GetLastWin32Error() != 0)
                throw new System.ComponentModel.Win32Exception();
            var value = utc.UtcDateTime;
            var time = new SystemTime
            {
                Year = (ushort)value.Year, Month = (ushort)value.Month, DayOfWeek = (ushort)value.DayOfWeek, Day = (ushort)value.Day,
                Hour = (ushort)value.Hour, Minute = (ushort)value.Minute, Second = (ushort)value.Second, Milliseconds = (ushort)value.Millisecond
            };
            if (!SetSystemTimeNative(ref time)) throw new System.ComponentModel.Win32Exception();
        }
        finally { CloseHandle(token); }
    }

    [StructLayout(LayoutKind.Sequential)]
    private struct SystemTime
    {
        public ushort Year, Month, DayOfWeek, Day, Hour, Minute, Second, Milliseconds;
    }

    [StructLayout(LayoutKind.Sequential, Pack = 4)]
    private struct TokenPrivileges
    {
        public int Count;
        public long Luid;
        public int Attributes;
    }

    [DllImport("kernel32.dll", EntryPoint = "SetSystemTime", SetLastError = true)]
    [return: MarshalAs(UnmanagedType.Bool)]
    private static extern bool SetSystemTimeNative(ref SystemTime time);
    [DllImport("advapi32.dll", SetLastError = true)]
    [return: MarshalAs(UnmanagedType.Bool)]
    private static extern bool OpenProcessToken(IntPtr process, int access, out IntPtr token);
    [DllImport("advapi32.dll", SetLastError = true, CharSet = CharSet.Unicode)]
    [return: MarshalAs(UnmanagedType.Bool)]
    private static extern bool LookupPrivilegeValue(string? system, string name, out long luid);
    [DllImport("advapi32.dll", SetLastError = true)]
    [return: MarshalAs(UnmanagedType.Bool)]
    private static extern bool AdjustTokenPrivileges(IntPtr token, [MarshalAs(UnmanagedType.Bool)] bool disableAll, ref TokenPrivileges state,
        int length, IntPtr previous, IntPtr returnLength);
    [DllImport("kernel32.dll", SetLastError = true)]
    [return: MarshalAs(UnmanagedType.Bool)]
    private static extern bool CloseHandle(IntPtr handle);

    private static bool DomainJoined()
    {
        if (NetGetJoinInformation(null, out var name, out var status) != 0) return false;
        NetApiBufferFree(name);
        return status == 3; // NetSetupDomainName
    }

    [DllImport("netapi32.dll", CharSet = CharSet.Unicode)]
    private static extern int NetGetJoinInformation(string? server, out IntPtr name, out int status);
    [DllImport("netapi32.dll")]
    private static extern int NetApiBufferFree(IntPtr buffer);
}

// Paso unico (Bootstrapper): deja la hora automatica y resincroniza. Nunca lanza: la instalacion sigue aunque falle.
public static class TimeSyncOnce
{
    public static async Task<IReadOnlyList<string>> RunAsync(ITimeSystem system, CancellationToken ct)
    {
        var lines = new List<string>();
        try
        {
            var settings = await system.ReadAsync(ct);
            foreach (var command in TimeSyncPlan.Restore(settings).Append(TimeSyncPlan.Resync))
            {
                var result = await system.RunAsync(command, ct);
                lines.Add($"HORA: {command} -> {result.ExitCode}");
            }
        }
        catch (Exception ex) when (ex is IOException or UnauthorizedAccessException or System.ComponentModel.Win32Exception or System.Security.SecurityException or InvalidOperationException)
        {
            lines.Add($"HORA: no se pudo ajustar ({ex.GetType().Name}); la instalacion continua");
        }
        return lines;
    }
}
