using System.Globalization;
using System.Security.Principal;
using Microsoft.Win32;

namespace Keeper.Agent.Modules.Enforcement;

public interface ISystemPolicyStore : IAppLockerPolicyStore
{
    bool IsDryRun { get; }
    void ReplaceStringList(string path, IReadOnlyList<string> values);
    void SetDword(string path, string name, int? value);
}

public sealed record RegistryWrite(string Path, string Name, object? Value);

public sealed class MemorySystemPolicyStore : ISystemPolicyStore
{
    public bool IsDryRun { get; init; }
    public Dictionary<(string Path, string Name), object> Values { get; } = new();
    public List<RegistryWrite> Writes { get; } = [];
    public MemoryAppLockerMachine AppLocker { get; } = new();
    public void ApplyAppLocker(string policyXml) => new AppLockerPolicyStore(AppLocker).ApplyAppLocker(policyXml);
    public void ClearAppLocker() => new AppLockerPolicyStore(AppLocker).ClearAppLocker();

    public void ReplaceStringList(string path, IReadOnlyList<string> values)
    {
        foreach (var key in Values.Keys.Where(k => k.Path == path && int.TryParse(k.Name, out _)).ToArray())
        {
            Values.Remove(key);
            Writes.Add(new RegistryWrite(path, key.Name, null));
        }
        for (var i = 0; i < values.Count; i++)
        {
            var name = (i + 1).ToString(CultureInfo.InvariantCulture);
            Values[(path, name)] = values[i];
            Writes.Add(new RegistryWrite(path, name, values[i]));
        }
    }

    public void SetDword(string path, string name, int? value)
    {
        if (value is null) Values.Remove((path, name));
        else Values[(path, name)] = value.Value;
        Writes.Add(new RegistryWrite(path, name, value));
    }
}

public sealed class WindowsSystemPolicyStore(bool enableWrites, Action<string> log, IAppLockerPolicyStore? appLocker = null) : ISystemPolicyStore
{
    public bool IsDryRun => !enableWrites || !IsElevated();

    public void ApplyAppLocker(string policyXml)
    {
        if (IsDryRun || !OperatingSystem.IsWindows()) { log("dry-run AppLocker apply + AppIDSvc automatic/start"); return; }
        (appLocker ?? new AppLockerPolicyStore(new WindowsAppLockerMachine(log))).ApplyAppLocker(policyXml);
    }

    public void ClearAppLocker()
    {
        if (IsDryRun || !OperatingSystem.IsWindows()) { log("dry-run AppLocker restore + AppIDSvc startup restore"); return; }
        (appLocker ?? new AppLockerPolicyStore(new WindowsAppLockerMachine(log))).ClearAppLocker();
    }

    private static bool IsElevated()
    {
        if (!OperatingSystem.IsWindows()) return false;
        using var identity = WindowsIdentity.GetCurrent();
        return new WindowsPrincipal(identity).IsInRole(WindowsBuiltInRole.Administrator);
    }

    public void ReplaceStringList(string path, IReadOnlyList<string> values)
    {
        if (IsDryRun || !OperatingSystem.IsWindows())
        {
            log($"dry-run HKLM\\{path}: {values.Count} entries");
            return;
        }
        using var hklm = RegistryKey.OpenBaseKey(RegistryHive.LocalMachine, RegistryView.Registry64);
        using var key = hklm.CreateSubKey(path, writable: true);
        var desired = values.Select((v, i) => (Name: (i + 1).ToString(CultureInfo.InvariantCulture), Value: v)).ToArray();
        foreach (var item in desired) key.SetValue(item.Name, item.Value, RegistryValueKind.String);
        foreach (var name in key.GetValueNames().Where(n => int.TryParse(n, out _) && !desired.Any(d => d.Name == n)))
            key.DeleteValue(name, throwOnMissingValue: false);
    }

    public void SetDword(string path, string name, int? value)
    {
        if (IsDryRun || !OperatingSystem.IsWindows())
        {
            log($"dry-run HKLM\\{path}\\{name} = {value?.ToString(CultureInfo.InvariantCulture) ?? "delete"}");
            return;
        }
        using var hklm = RegistryKey.OpenBaseKey(RegistryHive.LocalMachine, RegistryView.Registry64);
        using var key = hklm.CreateSubKey(path, writable: true);
        if (value is null) key.DeleteValue(name, throwOnMissingValue: false);
        else key.SetValue(name, value.Value, RegistryValueKind.DWord);
    }
}
