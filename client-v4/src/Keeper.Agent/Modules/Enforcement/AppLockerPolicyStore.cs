namespace Keeper.Agent.Modules.Enforcement;

public interface IAppLockerPolicyStore
{
    void ApplyAppLocker(string policyXml);
    void ClearAppLocker();
}

public sealed record ApplicationIdentityStartup(int Start, int? DelayedAutoStart);
public sealed record AppLockerBackup(string PolicyXml, ApplicationIdentityStartup Startup);

public interface IAppLockerMachine
{
    AppLockerBackup? ReadBackup();
    void SaveBackup(AppLockerBackup backup);
    void DeleteBackup();
    string ReadLocalPolicy();
    ApplicationIdentityStartup ReadStartup();
    void EnsureAutomaticAndRunning();
    void SetLocalPolicy(string policyXml);
    void RestoreStartup(ApplicationIdentityStartup startup);
}

public sealed class AppLockerPolicyStore(IAppLockerMachine machine) : IAppLockerPolicyStore
{
    public void ApplyAppLocker(string policyXml)
    {
        if (machine.ReadBackup() is null)
            machine.SaveBackup(new AppLockerBackup(machine.ReadLocalPolicy(), machine.ReadStartup()));
        machine.EnsureAutomaticAndRunning();
        machine.SetLocalPolicy(policyXml);
    }

    public void ClearAppLocker()
    {
        if (machine.ReadBackup() is not { } backup) return;
        machine.SetLocalPolicy(backup.PolicyXml);
        machine.RestoreStartup(backup.Startup);
        // Keep the durable backup until both restore operations succeed, including across agent restarts.
        machine.DeleteBackup();
    }
}

public sealed class MemoryAppLockerMachine : IAppLockerMachine
{
    public string PolicyXml { get; set; } = "<AppLockerPolicy Version=\"1\" />";
    public ApplicationIdentityStartup Startup { get; set; } = new(3, null);
    public bool IsRunning { get; set; }
    public AppLockerBackup? Backup { get; private set; }
    public List<string> Operations { get; } = [];

    public AppLockerBackup? ReadBackup() => Backup;
    public string ReadLocalPolicy() => PolicyXml;
    public ApplicationIdentityStartup ReadStartup() => Startup;
    public void SaveBackup(AppLockerBackup backup) { Backup = backup; Operations.Add("backup"); }
    public void DeleteBackup() { Backup = null; Operations.Add("delete_backup"); }
    public void EnsureAutomaticAndRunning()
    {
        Startup = new(2, 0);
        IsRunning = true;
        Operations.Add("auto_start");
    }
    public void SetLocalPolicy(string policyXml) { PolicyXml = policyXml; Operations.Add("policy"); }
    public void RestoreStartup(ApplicationIdentityStartup startup) { Startup = startup; Operations.Add("restore_startup"); }
}
