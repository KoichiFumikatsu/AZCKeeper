using Microsoft.Win32;
using System.Runtime.Versioning;

namespace Keeper.Agent.Modules.Security;

public sealed class SessionHiveUnavailableException() : IOException("session_hive_unloaded");

public sealed record LockRegistryValue(RegistryValueKind Kind, object Data);

public interface ILockRegistry
{
    LockRegistryValue? Read(string path, string name);
    void Write(string path, string name, LockRegistryValue value);
    void Delete(string path, string name);
    void Flush(string path);
}

[SupportedOSPlatform("windows")]
public sealed class TaskManagerPolicy(ILockRegistry registry)
{
    public const string PolicyPath = @"Software\Microsoft\Windows\CurrentVersion\Policies\System";
    public const string BackupPath = @"Software\Microsoft\Windows\CurrentVersion\Policies\AZCKeeperScreenLock";
    public const string ValueName = "DisableTaskMgr";
    private const string Saved = "Saved";

    public void Disable()
    {
        if (registry.Read(BackupPath, Saved) is null)
        {
            var original = registry.Read(PolicyPath, ValueName);
            if (original is not null) registry.Write(BackupPath, ValueName, original);
            else registry.Delete(BackupPath, ValueName);
            registry.Write(BackupPath, Saved, new(RegistryValueKind.DWord, original is null ? 0 : 1));
            registry.Flush(BackupPath);
        }
        if (registry.Read(PolicyPath, ValueName) is { Kind: RegistryValueKind.DWord, Data: 1 }) return;
        registry.Write(PolicyPath, ValueName, new(RegistryValueKind.DWord, 1));
        registry.Flush(PolicyPath);
    }

    public void Restore()
    {
        var saved = registry.Read(BackupPath, Saved);
        if (saved is null) return;
        if (saved.Kind != RegistryValueKind.DWord || saved.Data is not int exists || exists is not (0 or 1))
            throw new InvalidDataException("invalid_taskmgr_backup");
        if (exists == 1)
            registry.Write(PolicyPath, ValueName, registry.Read(BackupPath, ValueName)
                ?? throw new InvalidDataException("missing_taskmgr_backup"));
        else registry.Delete(PolicyPath, ValueName);
        registry.Flush(PolicyPath);
        // El marcador se borra solo despues de restaurar: un cierre abrupto permite reintentar.
        registry.Delete(BackupPath, Saved);
        registry.Flush(BackupPath);
    }
}

[SupportedOSPlatform("windows")]
public sealed class SessionUserLockRegistry(string sid) : ILockRegistry
{
    public LockRegistryValue? Read(string path, string name)
    {
        using var user = Registry.Users.OpenSubKey(sid, writable: true) ?? throw new SessionHiveUnavailableException();
        using var key = user.OpenSubKey(path);
        var value = key?.GetValue(name, null, RegistryValueOptions.DoNotExpandEnvironmentNames);
        return value is null ? null : new(key!.GetValueKind(name), value);
    }

    public void Write(string path, string name, LockRegistryValue value)
    {
        using var user = Registry.Users.OpenSubKey(sid, writable: true) ?? throw new SessionHiveUnavailableException();
        using var key = user.CreateSubKey(path, writable: true);
        key.SetValue(name, value.Data, value.Kind);
    }

    public void Delete(string path, string name)
    {
        using var user = Registry.Users.OpenSubKey(sid, writable: true) ?? throw new SessionHiveUnavailableException();
        using var key = user.OpenSubKey(path, writable: true);
        key?.DeleteValue(name, throwOnMissingValue: false);
    }

    public void Flush(string path)
    {
        using var user = Registry.Users.OpenSubKey(sid, writable: true) ?? throw new SessionHiveUnavailableException();
        using var key = user.OpenSubKey(path);
        key?.Flush();
    }
}

[SupportedOSPlatform("windows")]
public sealed class SessionTaskManagerPolicies(Func<IReadOnlyList<string>> loadedUsers, Func<string, ILockRegistry> registry)
{
    public void Reconcile(IReadOnlyCollection<string> activeUsers, bool locked)
    {
        var failures = new List<Exception>();
        foreach (var sid in loadedUsers().Concat(activeUsers).Distinct(StringComparer.Ordinal))
        {
            try
            {
                var policy = new TaskManagerPolicy(registry(sid));
                if (locked && activeUsers.Contains(sid)) policy.Disable();
                else if (!locked) policy.Restore();
            }
            catch (SessionHiveUnavailableException) { }
            catch (Exception ex) { failures.Add(ex); }
        }
        if (failures.Count != 0) throw new AggregateException("session_taskmgr_policy_failed", failures);
    }

    public static SessionTaskManagerPolicies Windows() => new(
        () => Registry.Users.GetSubKeyNames().Where(s => s.StartsWith("S-1-", StringComparison.Ordinal) && !s.EndsWith("_Classes", StringComparison.Ordinal)).ToArray(),
        sid => new SessionUserLockRegistry(sid));
}
