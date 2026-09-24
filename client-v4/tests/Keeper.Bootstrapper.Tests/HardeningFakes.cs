using System.Security;
using Keeper.Bootstrapper.Hardening;
using Keeper.Shared.Contracts;

namespace Keeper.Bootstrapper.Tests;

internal sealed class HardeningFake : ILocalAccounts, ISecurityPolicy, IHardeningSecret, IHardeningStateStore
{
    public const string Worker = "S-1-5-21-1-2-3-1001";
    public const string Admin = "S-1-5-21-1-2-3-1002";
    public const string Builtin = "S-1-5-21-1-2-3-500";
    public const string TestPassword = "FAKE-ONLY-secret-never-in-output";
    public List<LocalAccount> Accounts { get; } = [new("worker", Worker, true, true, false), new("RenamedBuiltin", Builtin, false, true, false)];
    public List<string> Operations { get; } = [];
    public List<int> OperationalAdmins { get; } = [];
    public List<HardeningState> Writes { get; } = [];
    public List<string> VisibilityNames { get; } = [];
    public string[] Active { get; set; } = [Worker];
    public bool CredentialValid { get; set; } = true;
    public int FailVerificationNumber { get; set; }
    public int VerificationCount { get; private set; }
    public bool Deny { get; set; }
    public int? Visibility { get; set; }
    public string? FailOnce { get; set; }
    public string? IgnoreOperation { get; set; }
    public bool ForbidAccess { get; set; }
    public bool FailPromotion { get; set; }
    public HardeningState? State { get; set; }
    public bool LeaseHeld { get; private set; }

    private void Record(string operation)
    {
        if (ForbidAccess) throw new InvalidOperationException("dry_run_touched_dependency");
        Operations.Add(operation);
        if (FailOnce == operation) { FailOnce = null; throw new IOException(TestPassword); }
    }
    public IReadOnlyList<LocalAccount> List() { Record("list"); return Accounts.ToArray(); }
    public IReadOnlyList<string> ActiveUserSids() { Record("sessions"); return Active; }
    public LocalAccount Create(string name, SecureString password)
    {
        Record("create_api");
        Assert.Equal(TestPassword.Length, password.Length);
        var account = new LocalAccount(name, Admin, true, false, false);
        Accounts.Add(account);
        Count();
        return account;
    }
    public void SetPassword(string sid, SecureString password) { Record("set_password_api"); Assert.Equal(Admin, sid); }
    public void AddToGroup(string sid, string groupSid)
    {
        Record($"add:{sid}:{groupSid}");
        if (IgnoreOperation == $"add:{sid}:{groupSid}") return;
        if (FailPromotion && sid == Worker && groupSid == AccountSids.Administrators) throw new IOException("promotion_failed");
        Change(sid, a => groupSid == AccountSids.Administrators ? a with { Administrator = true } : a with { User = true });
    }
    public void RemoveFromGroup(string sid, string groupSid)
    {
        Record($"remove:{sid}:{groupSid}");
        if (IgnoreOperation == $"remove:{sid}:{groupSid}") return;
        Assert.NotEqual(Admin, sid);
        Assert.NotEqual(Builtin, sid);
        if (groupSid == AccountSids.Administrators)
        {
            Assert.True(Deny);
            Assert.True(VerificationCount >= 2);
            Assert.Contains(sid, State!.RestoreAdminSids);
        }
        Change(sid, a => groupSid == AccountSids.Administrators ? a with { Administrator = false } : a with { User = false });
    }
    public bool ValidateInteractiveLogon(string sid, SecureString password)
    {
        Record("logon_interactive");
        VerificationCount++;
        return CredentialValid && VerificationCount != FailVerificationNumber;
    }
    public bool HasNetworkDeny() { Record("read_deny"); return Deny; }
    public void SetNetworkDeny(bool enabled) { Record("deny:" + enabled); Deny = enabled; }
    public int? ReadVisibility(string name) { Record("read_visibility"); VisibilityNames.Add(name); return Visibility; }
    public void SetVisibility(string name, int? value) { VisibilityNames.Add(name); Visibility = value; Record("visibility:" + (value?.ToString() ?? "absent")); }
    SecureString IHardeningSecret.Read()
    {
        Record("secret");
        var result = new SecureString();
        foreach (var c in TestPassword) result.AppendChar(c);
        result.MakeReadOnly();
        return result;
    }
    public IDisposable Acquire()
    {
        Record("lease"); Assert.False(LeaseHeld); LeaseHeld = true;
        return new Callback(() => LeaseHeld = false);
    }
    HardeningState? IHardeningStateStore.Read() { Record("read_state"); return State; }
    public void Write(HardeningState state)
    {
        Assert.True(LeaseHeld);
        Record("state:" + state.Status);
        State = state;
        Writes.Add(state);
    }
    private void Change(string sid, Func<LocalAccount, LocalAccount> update)
    {
        var index = Accounts.FindIndex(a => a.Sid == sid);
        Accounts[index] = update(Accounts[index]);
        Count();
    }
    private void Count() => OperationalAdmins.Add(Accounts.Count(a => a.Enabled && a.Administrator && (a.Sid != Admin || CredentialValid)));
    private sealed class Callback(Action action) : IDisposable { public void Dispose() => action(); }
}
