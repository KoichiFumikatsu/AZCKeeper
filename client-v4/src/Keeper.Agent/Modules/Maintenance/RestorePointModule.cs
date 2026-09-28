using System.Diagnostics;
using System.Text.Json;
using Keeper.Agent.Storage;
using Keeper.Shared.Contracts;
using Keeper.Shared.Protocol;

namespace Keeper.Agent.Modules.Maintenance;

public interface IRestorePointService { Task CreateAsync(string description, CancellationToken ct); }

// Crea puntos de restauración del sistema (existe en Home y Pro, a diferencia de BitLocker).
public sealed class WindowsRestorePointService(bool enabled) : IRestorePointService
{
    // Habilita la protección en la unidad del sistema y levanta el límite nativo de un punto cada
    // 24 h: la cadencia la controla el módulo, no Windows. La descripción viaja por variable de
    // entorno para no interpolarla en el script.
    private const string Script =
        "$ErrorActionPreference='Stop';" +
        "Enable-ComputerRestore -Drive ($env:SystemDrive + '\\');" +
        "New-ItemProperty -Path 'HKLM:\\SOFTWARE\\Microsoft\\Windows NT\\CurrentVersion\\SystemRestore' " +
        "-Name SystemRestorePointCreationFrequency -Value 0 -PropertyType DWord -Force | Out-Null;" +
        "Checkpoint-Computer -Description $env:KEEPER_RESTORE_POINT_DESCRIPTION -RestorePointType MODIFY_SETTINGS";

    public async Task CreateAsync(string description, CancellationToken ct)
    {
        if (!enabled || !OperatingSystem.IsWindows()) throw new NotSupportedException("restore_points_dry_run");
        var start = new ProcessStartInfo(Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.System),
            "WindowsPowerShell", "v1.0", "powershell.exe")) { UseShellExecute = false, CreateNoWindow = true };
        foreach (var arg in new[] { "-NoProfile", "-NonInteractive", "-ExecutionPolicy", "Bypass", "-Command", Script })
            start.ArgumentList.Add(arg);
        start.Environment["KEEPER_RESTORE_POINT_DESCRIPTION"] = description;
        using var process = Process.Start(start) ?? throw new IOException("restore_point_not_started");
        await process.WaitForExitAsync(ct);
        if (process.ExitCode != 0) throw new IOException("restore_point_failed");
    }
}

public sealed record RestorePointState(DateTimeOffset? LastCreatedAt, DateTimeOffset? LastAttemptAt, string? LastError);

// Punto de restauración periódico para soporte IT. La creación tarda minutos, así que corre en
// segundo plano y se sondea en los ticks siguientes: ModuleHost ejecuta los ticks en serie y
// esperar aquí bloquearía a los enforcers. El estado persiste para que reiniciar el servicio o
// reinstalar no dispare un punto nuevo en cada arranque.
public sealed class RestorePointModule(IRestorePointService service, string statePath, TimeSpan interval, TimeSpan retryAfterFailure)
    : ModuleBase
{
    public override string Name => "RestorePoint";
    private RestorePointState _state = new(null, null, null);
    private Task? _running;

    public override async Task InitAsync(ModuleContext ctx)
    {
        await base.InitAsync(ctx);
        try
        {
            if (File.Exists(statePath))
                _state = JsonSerializer.Deserialize<RestorePointState>(await File.ReadAllBytesAsync(statePath, ctx.StoppingToken)) ?? _state;
        }
        catch (Exception ex) when (ex is JsonException or IOException) { _state = new(null, null, null); }
        State = _state.LastError is { } error ? error : _state.LastCreatedAt is null ? "pending" : "applied";
    }

    public override async Task TickAsync(CancellationToken ct)
    {
        var now = Context.Clock.GetUtcNow();
        if (_running is not null)
        {
            if (!_running.IsCompleted) return;
            var finished = _running;
            _running = null;
            if (finished.IsCompletedSuccessfully)
            {
                _state = _state with { LastCreatedAt = now, LastError = null };
                State = "applied";
                await SaveAsync(ct);
                await ReportAsync("restore_point_created", ct);
            }
            else
            {
                var code = finished.Exception?.InnerException is NotSupportedException ? "dry_run" : "restore_point_failed";
                _state = _state with { LastError = code };
                State = code;
                await SaveAsync(ct);
                await ReportAsync(code, ct, code == "dry_run" ? LogEntryLevel.Warn : LogEntryLevel.Error);
            }
            return;
        }
        if (!IsDue(now)) return;
        _state = _state with { LastAttemptAt = now };
        await SaveAsync(ct);
        State = "creating";
        _running = service.CreateAsync($"Keeper AZC {now:yyyy-MM-dd HH:mm}Z", Context.StoppingToken);
    }

    private bool IsDue(DateTimeOffset now)
    {
        if (_state.LastError is not null && _state.LastAttemptAt is { } attempt && now < attempt + retryAfterFailure) return false;
        return _state.LastCreatedAt is not { } last || now >= last + interval;
    }

    private Task SaveAsync(CancellationToken ct) => AtomicFile.WriteAsync(statePath, JsonSerializer.SerializeToUtf8Bytes(_state), ct);
}
