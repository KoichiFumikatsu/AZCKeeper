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

public enum DeviceAction { Shutdown, Restart, Logoff }
public interface IDeviceActions { Task ExecuteAsync(DeviceAction action, CancellationToken ct); }
public sealed class WindowsDeviceActions(bool enabled) : IDeviceActions
{
    public async Task ExecuteAsync(DeviceAction action, CancellationToken ct)
    {
        if (!enabled || !OperatingSystem.IsWindows()) throw new NotSupportedException("device_actions_dry_run");
        if (action == DeviceAction.Logoff) throw new NotSupportedException("logoff_requires_target_session");
        var start = new ProcessStartInfo(Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.System), "shutdown.exe")) { UseShellExecute = false, CreateNoWindow = true };
        start.ArgumentList.Add(action == DeviceAction.Restart ? "/r" : "/s");
        start.ArgumentList.Add("/f");
        start.ArgumentList.Add("/t"); start.ArgumentList.Add("0");
        using var process = Process.Start(start) ?? throw new IOException("shutdown_not_started");
        await process.WaitForExitAsync(ct);
        if (process.ExitCode != 0) throw new IOException("shutdown_failed");
    }
    public void LogoffSession(int sessionId)
    {
        if (!enabled || !OperatingSystem.IsWindows()) throw new NotSupportedException("device_actions_dry_run");
        if (!WTSLogoffSession(IntPtr.Zero, sessionId, false)) throw new System.ComponentModel.Win32Exception(Marshal.GetLastWin32Error());
    }
    [DllImport("wtsapi32.dll", SetLastError = true)] [return: MarshalAs(UnmanagedType.Bool)]
    private static extern bool WTSLogoffSession(IntPtr server, int sessionId, bool wait);
}

public sealed record InboxEntry(Command Command, CommandResult? Result, bool Started, bool Published = false);
public sealed class CommandExecutor(string path, Guid deviceId, DeviceLock deviceLock, IDeviceActions actions) : ModuleBase
{
    public override string Name => "CommandExecutor";
    private readonly SemaphoreSlim _gate = new(1, 1);
    private List<InboxEntry> _entries = [];
    public override async Task InitAsync(ModuleContext ctx)
    {
        await base.InitAsync(ctx);
        if (File.Exists(path)) _entries = JsonSerializer.Deserialize<List<InboxEntry>>(await File.ReadAllBytesAsync(path, ctx.StoppingToken), ProtocolJson.Options) ?? [];
    }
    public async Task AcceptAsync(IReadOnlyList<Command> commands, Guid tenant, CancellationToken ct)
    {
        await _gate.WaitAsync(ct);
        try
        {
            PurgeCompleted();
            var rejected = new List<SyncRequestCommandResultsItem>();
            foreach (var command in commands)
            {
                if (command.Id == Guid.Empty || command.DeviceId != deviceId || command.TenantId != tenant || !Enum.IsDefined(command.Type))
                {
                    rejected.Add(Reject(command, "invalid_command_destination"));
                    continue;
                }
                if (command.Status is not (CommandStatus.Pending or CommandStatus.Delivered)) continue;
                if (_entries.Any(e => e.Command.Id == command.Id)) continue;
                if (command.ExpiresAt <= Context.Clock.GetUtcNow())
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
        }
        finally { _gate.Release(); }
    }
    public override async Task TickAsync(CancellationToken ct)
    {
        await _gate.WaitAsync(ct);
        try
        {
            if (!_entries.Any(e => e.Result is null || !e.Published || e.Command.ExpiresAt <= Context.Clock.GetUtcNow())) return;
            foreach (var original in _entries.Where(e => e.Result is null).ToArray())
            {
                var index = _entries.IndexOf(original);
                var command = original.Command;
                var code = "completed";
                var status = CommandResultStatus.Succeeded;
                try
                {
                    if (command.ExpiresAt <= Context.Clock.GetUtcNow()) throw new InvalidOperationException("expired");
                    if (original.Started) throw new InvalidOperationException("interrupted");
                    _entries[index] = original with { Started = true };
                    await SaveAsync(ct);
                    switch (command.Type)
                    {
                        case CommandType.Lock: await deviceLock.SetLockedAsync(true, ct); break;
                        case CommandType.Unlock: await deviceLock.SetLockedAsync(false, ct); break;
                        case CommandType.Restart: await actions.ExecuteAsync(DeviceAction.Restart, ct); break;
                        case CommandType.Shutdown: await actions.ExecuteAsync(DeviceAction.Shutdown, ct); break;
                        default: throw new NotSupportedException("unsupported_command");
                    }
                }
                catch (OperationCanceledException) when (ct.IsCancellationRequested) { throw; }
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
        finally { _gate.Release(); }
    }
    private void PurgeCompleted() => _entries.RemoveAll(e => e.Published && e.Result is not null && e.Command.ExpiresAt <= Context.Clock.GetUtcNow());
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
