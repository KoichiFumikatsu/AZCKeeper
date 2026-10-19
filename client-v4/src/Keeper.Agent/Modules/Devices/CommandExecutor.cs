using System.Diagnostics;
using System.Runtime.InteropServices;
using System.Text.Json;
using System.Security.Cryptography;
using System.Text;
using Keeper.Agent.Modules.Security;
using Keeper.Agent.Storage;
using Keeper.Shared.Contracts;
using Keeper.Shared.Protocol;

namespace Keeper.Agent.Modules.Devices;

public enum DeviceAction { Shutdown, Restart, Logoff, Wipe }

// Cambio del nombre de Windows (comando rename_computer). Nombre NetBIOS/DNS: 1-15, letras, numeros y guion, no solo
// numeros. El guion bajo de las placas (ACT_0015) no es valido en DNS: el panel lo convierte a ACT-0015.
public interface IComputerNamer { void Rename(string name); }
public static class ComputerName
{
    private static readonly System.Text.RegularExpressions.Regex Valid = new("^(?![0-9]+$)[A-Za-z0-9-]{1,15}$");
    public static bool IsValid(string? name) => name is not null && Valid.IsMatch(name);
}
[System.Runtime.Versioning.SupportedOSPlatform("windows")]
public sealed class WindowsComputerNamer(bool enabled) : IComputerNamer
{
    // Se aplica en el proximo arranque (Windows guarda el nombre pendiente). Requiere SYSTEM/administrador.
    public void Rename(string name)
    {
        if (!enabled) throw new NotSupportedException("device_actions_dry_run");
        if (!SetComputerNameExW(5 /* ComputerNamePhysicalDnsHostname */, name)) throw new System.ComponentModel.Win32Exception(Marshal.GetLastWin32Error());
    }
    [DllImport("kernel32.dll", CharSet = CharSet.Unicode, SetLastError = true)] [return: MarshalAs(UnmanagedType.Bool)]
    private static extern bool SetComputerNameExW(int nameType, string lpBuffer);
}

public sealed class CommandRejectedException(string code) : Exception(code) { public string Code { get; } = code; }
public interface IDeviceActions { Task ExecuteAsync(DeviceAction action, CancellationToken ct); }
public sealed class WindowsDeviceActions(bool enabled) : IDeviceActions
{
    // Perfiles que NUNCA se borran en un wipe: cuentas del sistema y las de administración de IT
    // (Administrator break-glass y azcadmin del Modo B), para no perder el acceso al equipo.
    private static readonly HashSet<string> PreservedProfiles = new(StringComparer.OrdinalIgnoreCase)
    { "default", "public", "default user", "all users", "administrator", "azcadmin" };

    public async Task ExecuteAsync(DeviceAction action, CancellationToken ct)
    {
        if (!enabled || !OperatingSystem.IsWindows()) throw new NotSupportedException("device_actions_dry_run");
        if (action == DeviceAction.Logoff) throw new NotSupportedException("logoff_requires_target_session");
        if (action == DeviceAction.Wipe) { WipeUserData(ct); return; }
        var start = new ProcessStartInfo(Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.System), "shutdown.exe")) { UseShellExecute = false, CreateNoWindow = true };
        start.ArgumentList.Add(action == DeviceAction.Restart ? "/r" : "/s");
        start.ArgumentList.Add("/f");
        start.ArgumentList.Add("/t"); start.ArgumentList.Add("0");
        using var process = Process.Start(start) ?? throw new IOException("shutdown_not_started");
        await process.WaitForExitAsync(ct);
        if (process.ExitCode != 0) throw new IOException("shutdown_failed");
    }

    // Borra los datos de los perfiles de usuario (no cifra: decisión de negocio con flota Home/Pro
    // mixta) y luego sobrescribe el espacio libre para que lo borrado no se recupere. Best-effort por
    // archivo: los bloqueados por una sesión activa se saltan y quedan cubiertos por cipher /w.
    private void WipeUserData(CancellationToken ct)
    {
        var systemDrive = Environment.GetEnvironmentVariable("SystemDrive") ?? "C:";
        WipeProfiles(Path.Combine(systemDrive + Path.DirectorySeparatorChar, "Users"), ct);
        // Sobrescribe el espacio libre (irrecuperable). Es lento: se dispara en segundo plano para no
        // bloquear el reporte del resultado del comando (el equipo robado puede no reconectar).
        try
        {
            var cipher = new ProcessStartInfo(Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.System), "cipher.exe")) { UseShellExecute = false, CreateNoWindow = true };
            cipher.ArgumentList.Add("/w:" + systemDrive + Path.DirectorySeparatorChar);
            Process.Start(cipher);
        }
        catch { /* best-effort: el borrado de datos ya ocurrió */ }
    }

    // Borra todo perfil de usuario bajo usersRoot salvo los preservados. Separado para poder probarlo
    // sobre un directorio temporal sin tocar C:\Users real.
    public static void WipeProfiles(string usersRoot, CancellationToken ct)
    {
        if (!Directory.Exists(usersRoot)) throw new DirectoryNotFoundException("users_root_missing");
        foreach (var directory in Directory.EnumerateDirectories(usersRoot))
        {
            ct.ThrowIfCancellationRequested();
            if (PreservedProfiles.Contains(Path.GetFileName(directory))) continue;
            DeleteTreeBestEffort(directory);
        }
    }

    private static void DeleteTreeBestEffort(string root)
    {
        try
        {
            foreach (var file in Directory.EnumerateFiles(root, "*", SearchOption.AllDirectories))
            {
                try { File.SetAttributes(file, FileAttributes.Normal); File.Delete(file); } catch { }
            }
        }
        catch { /* enumeración parcial: seguimos con el borrado del árbol */ }
        try { Directory.Delete(root, recursive: true); } catch { }
    }
    public void LogoffSession(int sessionId)
    {
        if (!enabled || !OperatingSystem.IsWindows()) throw new NotSupportedException("device_actions_dry_run");
        if (!WTSLogoffSession(IntPtr.Zero, sessionId, false)) throw new System.ComponentModel.Win32Exception(Marshal.GetLastWin32Error());
    }
    [DllImport("wtsapi32.dll", SetLastError = true)] [return: MarshalAs(UnmanagedType.Bool)]
    private static extern bool WTSLogoffSession(IntPtr server, int sessionId, bool wait);
}

// DueAt: reinicio/apagado con aviso (4.0.17). Se fija al empezar la cuenta regresiva y se guarda en disco, asi que
// sobrevive a un reinicio del agente.
public sealed record InboxEntry(Command Command, CommandResult? Result, bool Started, bool Published = false, DateTimeOffset? DueAt = null);
public sealed class CommandExecutor(string path, Guid deviceId, DeviceLock deviceLock, IDeviceActions actions,
    IComputerNamer? namer = null, IHardeningLauncher? hardening = null, ISessionLogoff? logoff = null) : ModuleBase
{
    public override string Name => "CommandExecutor";
    // Reinicio/apagado con aviso: la orden espera en la bandeja (sin bloquear el tick) hasta DueAt y solo entonces se
    // ejecuta y se reporta. Si el agente se reinicia a mitad de la cuenta, la retoma con el mismo plazo. Si al volver ya
    // paso el plazo por mas de NoticeGrace (el equipo estuvo apagado o suspendido, o el agente detenido), NO se ejecuta:
    // se reporta notice_deadline_missed para no apagar por sorpresa a quien acaba de encender el equipo.
    public static readonly TimeSpan NoticeGrace = TimeSpan.FromMinutes(2);
    private static readonly long[] NoticeChoices = [0, 60, 300, 600];
    // Cancelacion por IT (4.0.27): el servidor reenvia la orden con cancel_requested_at. Si la cuenta regresiva no llego a
    // shutdown.exe, se detiene y se responde failed/cancelled_by_admin; la persona ve «IT cancelo...» durante CancelledNoticeFor.
    // Si ya se ejecuto (o se interrumpio a mitad), se informa en el registro que llego tarde y el resultado normal sigue su curso.
    public const string CancelledByAdmin = "cancelled_by_admin";
    public static readonly TimeSpan CancelledNoticeFor = TimeSpan.FromSeconds(60);
    private readonly SemaphoreSlim _gate = new(1, 1);
    private List<InboxEntry> _entries = [];
    private volatile PowerNotice? _notice;
    private (PowerNotice Notice, DateTimeOffset Until)? _cancelledNotice;
    private readonly HashSet<Guid> _lateReported = [];
    /// <summary>Aviso vigente para las sesiones (el de plazo mas cercano), o null si no hay cuenta regresiva. Tras una
    /// cancelacion de IT, durante CancelledNoticeFor, el aviso con Cancelled = true.</summary>
    public PowerNotice? PendingNotice => _notice;
    // Desfase confirmado con el servidor (ServerClock): expires_at es hora del servidor y se compara con hora_local +
    // desfase; un reloj adelantado descartaba ordenes vigentes como expired. Los avisos (DueAt) siguen en hora local.
    public Func<TimeSpan>? ServerOffset { get; init; }
    private DateTimeOffset ServerNow => Context.Clock.GetUtcNow() + (ServerOffset?.Invoke() ?? TimeSpan.Zero);
    public override async Task InitAsync(ModuleContext ctx)
    {
        await base.InitAsync(ctx);
        if (File.Exists(path)) _entries = JsonSerializer.Deserialize<List<InboxEntry>>(await File.ReadAllBytesAsync(path, ctx.StoppingToken), ProtocolJson.Options) ?? [];
        RefreshNotice();
    }
    public async Task AcceptAsync(IReadOnlyList<Command> commands, Guid tenant, CancellationToken ct)
    {
        await _gate.WaitAsync(ct);
        try
        {
            PurgeCompleted();
            var rejected = new List<SyncRequestCommandResultsItem>();
            var logs = new List<LogEntry>();
            foreach (var command in commands)
            {
                if (command.Id == Guid.Empty || command.DeviceId != deviceId || command.TenantId != tenant || !Enum.IsDefined(command.Type))
                {
                    rejected.Add(Reject(command, "invalid_command_destination"));
                    continue;
                }
                if (command.CancelRequestedAt is not null) { Cancel(command, logs); continue; }
                if (command.Status is not (CommandStatus.Pending or CommandStatus.Delivered)) continue;
                if (_entries.Any(e => e.Command.Id == command.Id)) continue;
                if (command.ExpiresAt <= ServerNow)
                {
                    rejected.Add(Reject(command, "expired"));
                    continue;
                }
                // Published receipts remain for deduplication until expiry, outside the work quota.
                if (_entries.Count(e => !e.Published) >= 1000)
                {
                    rejected.Add(Reject(command, "command_inbox_full"));
                    continue;
                }
                _entries.Add(new(command, null, false));
            }
            await SaveAsync(ct);
            foreach (var result in rejected) await Context.Outbox.EnqueueAsync(result, ct);
            foreach (var log in logs) await Context.Outbox.EnqueueAsync(log, ct);
        }
        finally { RefreshNotice(); _gate.Release(); }
    }
    // Bajo _gate: TickAsync tambien lo toma mientras llama a shutdown.exe, asi que una cancelacion que entra aqui ve la orden
    // o todavia esperando (y la detiene antes de shutdown.exe) o ya iniciada (Started: llego tarde). Nunca a medias.
    private void Cancel(Command command, List<LogEntry> logs)
    {
        var index = _entries.FindIndex(e => e.Command.Id == command.Id);
        var now = Context.Clock.GetUtcNow();
        if (index < 0)
        {
            // Nunca la recibio (o se perdio la respuesta que la traia): se guarda ya cancelada para no ejecutarla si llega.
            _entries.Add(new(command, Cancelled(now), false));
            logs.Add(CancelLog(command, "command_cancelled", LogEntryLevel.Info, "not_started", now));
            Context.Log($"orden {command.Type} {command.Id} cancelada por IT antes de empezar");
            return;
        }
        var entry = _entries[index];
        if (entry.Result is { Code: CancelledByAdmin }) return;
        if (entry.Result is null && !entry.Started)
        {
            _entries[index] = entry with { Result = Cancelled(now) };
            if (entry.DueAt is not null && entry.Command.Type is CommandType.Restart or CommandType.Shutdown)
                _cancelledNotice = (new PowerNotice(entry.Command.Type == CommandType.Restart ? PowerNoticeAction.Restart : PowerNoticeAction.Shutdown, entry.DueAt.Value, Cancelled: true), now + CancelledNoticeFor);
            logs.Add(CancelLog(command, "command_cancelled", LogEntryLevel.Info, entry.DueAt is null ? "not_started" : "countdown_stopped", now));
            Context.Log($"orden {command.Type} {command.Id} cancelada por IT: cuenta regresiva detenida antes de ejecutarla");
            return;
        }
        // Ya se llamo a shutdown.exe (Started) o ya tiene resultado: no se puede deshacer. Se informa una vez.
        if (!_lateReported.Add(command.Id)) return;
        logs.Add(CancelLog(command, "command_cancel_too_late", LogEntryLevel.Warn, entry.Result?.Code ?? "already_started", now));
        Context.Log($"orden {command.Type} {command.Id}: la cancelacion de IT llego tarde ({entry.Result?.Code ?? "ya iniciada"})");
    }
    private static CommandResult Cancelled(DateTimeOffset at) => new() { EventId = Guid.NewGuid(), At = at, Status = CommandResultStatus.Failed, Code = CancelledByAdmin };
    private LogEntry CancelLog(Command command, string code, LogEntryLevel level, string detail, DateTimeOffset at) => new()
    {
        EventId = Guid.NewGuid(), At = at, Level = level, Component = Name, Code = code,
        Fields = new LogEntryFields { CommandId = command.Id, ErrorCode = detail }
    };
    public override async Task TickAsync(CancellationToken ct)
    {
        await _gate.WaitAsync(ct);
        try
        {
            var now = Context.Clock.GetUtcNow();
            bool Waiting(InboxEntry e) => e.Result is null && e.DueAt > now;
            if (!_entries.Any(e => (e.Result is null && !Waiting(e)) || (e.Result is not null && (!e.Published || e.Command.ExpiresAt <= ServerNow)))) return;
            foreach (var original in _entries.Where(e => e.Result is null && !Waiting(e)).ToArray())
            {
                var index = _entries.IndexOf(original);
                var command = original.Command;
                var code = "completed";
                var status = CommandResultStatus.Succeeded;
                try
                {
                    if (original.DueAt is { } due)
                    {
                        // Ya avisada: el vencimiento de la orden no la anula (la persona ya vio la cuenta regresiva).
                        if (now - due > NoticeGrace) throw new CommandRejectedException("notice_deadline_missed");
                    }
                    else
                    {
                        if (command.ExpiresAt <= ServerNow) throw new InvalidOperationException("expired");
                        var notice = NoticeSeconds(command);
                        if (notice > 0 && !original.Started)
                        {
                            var deadline = now.AddSeconds(notice);
                            _entries[index] = original with { DueAt = deadline };
                            await SaveAsync(ct);
                            Context.Log($"orden {command.Type} con aviso de {notice} s: se ejecuta a las {deadline:O}");
                            continue;
                        }
                    }
                    if (original.Started) throw new InvalidOperationException("interrupted");
                    _entries[index] = original with { Started = true };
                    await SaveAsync(ct);
                    switch (command.Type)
                    {
                        case CommandType.Lock: await deviceLock.SetLockedAsync(true, ct); break;
                        case CommandType.Unlock: await deviceLock.SetLockedAsync(false, ct); break;
                        case CommandType.Restart: await actions.ExecuteAsync(DeviceAction.Restart, ct); break;
                        case CommandType.Shutdown: await actions.ExecuteAsync(DeviceAction.Shutdown, ct); break;
                        case CommandType.Wipe: await actions.ExecuteAsync(DeviceAction.Wipe, ct); break;
                        case CommandType.RenameComputer: code = await RenameAsync(command, ct); break;
                        case CommandType.Harden: case CommandType.Unharden:
                            (status, code) = await HardeningAsync(command, command.Type == CommandType.Unharden, ct); break;
                        default: throw new NotSupportedException("unsupported_command");
                    }
                }
                catch (OperationCanceledException) when (ct.IsCancellationRequested) { throw; }
                catch (CommandRejectedException ex) { status = CommandResultStatus.Failed; code = ex.Code; }
                catch (Exception ex) { status = CommandResultStatus.Failed; code = ex.GetType().Name; }
                var result = new CommandResult { EventId = Guid.NewGuid(), At = Context.Clock.GetUtcNow(), Status = status, Code = code };
                _entries[index] = _entries[index] with { Result = result };
                await SaveAsync(ct);
            }
            foreach (var entry in _entries.Where(e => e.Result is not null && !e.Published).ToArray())
            {
                await Context.Outbox.EnqueueAsync(new SyncRequestCommandResultsItem { CommandId = entry.Command.Id, Result = entry.Result! }, ct);
                _entries[_entries.IndexOf(entry)] = entry with { Published = true };
            }
            PurgeCompleted();
            await SaveAsync(ct);
        }
        finally { RefreshNotice(); _gate.Release(); }
    }
    // Solo reinicio/apagado admiten aviso, y solo 1, 5 o 10 minutos (el servidor ya valida; se revalida aqui).
    private static long NoticeSeconds(Command command)
    {
        if (command.Type is not (CommandType.Restart or CommandType.Shutdown)) return 0;
        var seconds = command.Parameters?.NoticeSeconds ?? 0;
        return NoticeChoices.Contains(seconds) ? seconds : throw new CommandRejectedException("invalid_notice");
    }
    private void RefreshNotice()
    {
        var running = _entries
            .Where(e => e.Result is null && e.DueAt is not null)
            .OrderBy(e => e.DueAt)
            .Select(e => new PowerNotice(e.Command.Type == CommandType.Restart ? PowerNoticeAction.Restart : PowerNoticeAction.Shutdown, e.DueAt!.Value))
            .FirstOrDefault();
        // Otra cuenta regresiva vigente gana al aviso de cancelacion.
        if (_cancelledNotice is { } c && (running is not null || Context.Clock.GetUtcNow() >= c.Until)) _cancelledNotice = null;
        _notice = running ?? _cancelledNotice?.Notice;
    }
    private async Task<(CommandResultStatus, string)> HardeningAsync(Command command, bool undo, CancellationToken ct)
    {
        if (hardening is null) throw new CommandRejectedException("device_actions_dry_run");
        var adminName = HardeningCommand.ValidateAdminName(command.Parameters?.AdminName ?? "azcadmin");
        var outcome = await hardening.RunAsync(undo, adminName, ct);
        // Solo se cierra la sesion de las cuentas degradadas en ESTE endurecimiento, y solo si termino bien.
        var loggedOff = !undo && outcome.ExitCode == 0 && outcome.State is { Status: "hardened", LogoffRequired: true } state && logoff is not null
            ? logoff.Schedule(state.RestoreAdminSids.Concat(state.AddedUsersSids).ToArray(), HardeningCommand.LogoffDelay) : 0;
        var (ok, code) = HardeningCommand.Result(outcome, undo, loggedOff);
        Context.Log(ok ? $"endurecimiento: {code}" : $"endurecimiento warn: {code}");
        return (ok ? CommandResultStatus.Succeeded : CommandResultStatus.Failed, code);
    }
    private async Task<string> RenameAsync(Command command, CancellationToken ct)
    {
        var name = command.Parameters?.ComputerName;
        // El servidor ya valida; se revalida aqui porque el comando termina en una llamada al SO como SYSTEM.
        if (!ComputerName.IsValid(name)) throw new CommandRejectedException("invalid_computer_name");
        if (namer is null) throw new CommandRejectedException("device_actions_dry_run");
        try { namer.Rename(name!); }
        catch (NotSupportedException ex) { throw new CommandRejectedException(ex.Message); }
        if (command.Parameters?.RestartNow != true) return "pending_restart";
        await actions.ExecuteAsync(DeviceAction.Restart, ct);
        return "completed";
    }
    private void PurgeCompleted() => _entries.RemoveAll(e => e.Published && e.Result is not null && e.Command.ExpiresAt <= ServerNow);
    private SyncRequestCommandResultsItem Reject(Command command, string code) => new()
    {
        CommandId = command.Id,
        Result = new CommandResult
        {
            EventId = new Guid(SHA256.HashData(Encoding.UTF8.GetBytes($"{command.Id}:{command.DeviceId}:{command.TenantId}:{command.Type}:{code}"))[..16]),
            At = Context.Clock.GetUtcNow(), Status = CommandResultStatus.Failed, Code = code
        }
    };
    private Task SaveAsync(CancellationToken ct) => AtomicFile.WriteAsync(path, JsonSerializer.SerializeToUtf8Bytes(_entries, ProtocolJson.Options), ct);
    public override Task ShutdownAsync() { _gate.Dispose(); return Task.CompletedTask; }
}
