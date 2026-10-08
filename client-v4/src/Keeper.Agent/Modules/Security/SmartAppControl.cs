using Keeper.Shared.Contracts;
using Keeper.Shared.Protocol;

namespace Keeper.Agent.Modules.Security;

// Smart App Control (Windows 11): HKLM\SYSTEM\CurrentControlSet\Control\CI\Policy, DWORD VerifiedAndReputablePolicyState
// 0 = apagado, 1 = activo, 2 = evaluacion. Activo bloquea binarios sin firma, incluido Keeper.Bootstrapper (Win32 4551).
// Detras de una interfaz para probar sin tocar el registro.
public interface ISmartAppControlRegistry
{
    int? Read();       // null = ausente o ilegible
    void Disable();    // escribe 0 (REG_DWORD); aplica al reiniciar
}

public static class SmartAppControlState
{
    public static DeviceInventorySmartAppControl From(int? value) => value switch
    {
        0 => DeviceInventorySmartAppControl.Off,
        1 => DeviceInventorySmartAppControl.On,
        2 => DeviceInventorySmartAppControl.Evaluation,
        _ => DeviceInventorySmartAppControl.Unknown
    };

    // Para el inventario: un lector que falla no debe impedir reportar el resto del equipo.
    public static DeviceInventorySmartAppControl Read(ISmartAppControlRegistry registry)
    {
        try { return From(registry.Read()); }
        catch (Exception) { return DeviceInventorySmartAppControl.Unknown; }
    }
}

[System.Runtime.Versioning.SupportedOSPlatform("windows")]
public sealed class WindowsSmartAppControlRegistry : ISmartAppControlRegistry
{
    private const string KeyPath = @"SYSTEM\CurrentControlSet\Control\CI\Policy";
    private const string ValueName = "VerifiedAndReputablePolicyState";

    public int? Read()
    {
        try
        {
            using var key = Microsoft.Win32.Registry.LocalMachine.OpenSubKey(KeyPath);
            return key?.GetValue(ValueName) is int value ? value : null;
        }
        catch (Exception ex) when (ex is System.Security.SecurityException or UnauthorizedAccessException or IOException) { return null; }
    }

    public void Disable()
    {
        using var key = Microsoft.Win32.Registry.LocalMachine.OpenSubKey(KeyPath, writable: true) ?? throw new IOException("smart_app_control_key_missing");
        key.SetValue(ValueName, 0, Microsoft.Win32.RegistryValueKind.DWord);
    }
}

// Interruptor por cliente (sync windows_security, 4.0.19): con disable_smart_app_control=true y SAC activo o en
// evaluacion, lo apaga (efectivo al reiniciar; no se fuerza el reinicio). Con false no toca nada: apagarlo es
// irreversible en Windows y el agente nunca lo reactiva. registry null = dry-run (no escribe).
public sealed class SmartAppControl(ISmartAppControlRegistry? registry) : ModuleBase
{
    public override string Name => "SmartAppControl";
    private string? _reportedFailure;
    // Estados que AgentDiagnostics entiende: ready (nada que hacer), applied (apagado, falta reiniciar), failed + error.
    public override ModuleSnapshot Snapshot() => base.Snapshot() with { ErrorCode = State == "failed" ? _reportedFailure : null };

    // Como DeviceLock.TryApplyVerifierAsync: un fallo aqui no rompe el sync ni las ordenes.
    public async Task TryApplyAsync(WindowsSecurityDirective? settings, CancellationToken ct)
    {
        try { await ApplyAsync(settings, ct); }
        catch (OperationCanceledException) when (ct.IsCancellationRequested) { throw; }
        catch (Exception ex) { Context.Log($"smart_app_control_apply_failed: {ex.GetType().Name}"); }
    }

    public async Task ApplyAsync(WindowsSecurityDirective? settings, CancellationToken ct)
    {
        if (settings is not { DisableSmartAppControl: true } || registry is null) return;
        var current = registry.Read();
        if (current is not (1 or 2)) { if (State != "applied") State = "ready"; return; }
        string? failure;
        try
        {
            registry.Disable();
            // Si la escritura no queda (otra politica la revierte), se reporta como fallo y no se repite cada sync.
            failure = registry.Read() == 0 ? null : "value_not_persisted";
        }
        catch (Exception ex) when (ex is not OperationCanceledException) { failure = ex.GetType().Name; }
        if (failure is not null)
        {
            State = "failed";
            if (_reportedFailure != failure)
            {
                _reportedFailure = failure;
                await ReportAsync("smart_app_control_disable_failed", ct, LogEntryLevel.Warn, failure);
            }
            return;
        }
        _reportedFailure = null;
        State = "applied";
        await ReportAsync("smart_app_control_disabled", ct);
    }
}
