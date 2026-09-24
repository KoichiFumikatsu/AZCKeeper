using System.Security;
using Keeper.Shared.Contracts;

namespace Keeper.Bootstrapper.Hardening;

public static class AccountSids
{
    public const string Administrators = "S-1-5-32-544";
    public const string Users = "S-1-5-32-545";
    public const string LocalAdministrators = "S-1-5-114";
}

public sealed record LocalAccount(string Name, string Sid, bool Enabled, bool Administrator, bool User)
{
    public bool BuiltInAdministrator => Sid.EndsWith("-500", StringComparison.Ordinal);
}

public interface ILocalAccounts
{
    IReadOnlyList<LocalAccount> List();
    IReadOnlyList<string> ActiveUserSids();
    LocalAccount Create(string name, SecureString password);
    void SetPassword(string sid, SecureString password);
    void AddToGroup(string sid, string groupSid);
    void RemoveFromGroup(string sid, string groupSid);
    bool ValidateInteractiveLogon(string sid, SecureString password);
}

public interface ISecurityPolicy
{
    bool HasNetworkDeny();
    void SetNetworkDeny(bool enabled);
    int? ReadVisibility(string name);
    void SetVisibility(string name, int? value);
}

public interface IHardeningSecret { SecureString Read(); }
public interface IHardeningStateStore
{
    IDisposable Acquire();
    HardeningState? Read();
    void Write(HardeningState state);
}

public enum NoSessionTarget { EnrolledAccounts, LastConsoleUser }
public sealed record HardeningConfig
{
    public HardeningMode HardeningMode { get; init; } = HardeningMode.Panel;
    public string AdminName { get; init; } = "azcadmin";
    public string? PasswordFile { get; init; }
    public NoSessionTarget NoSessionTarget { get; init; } = NoSessionTarget.EnrolledAccounts;
    public string[] EnrolledAccountSids { get; init; } = [];
    public string? LastConsoleUserSid { get; init; }

    public void Validate()
    {
        if (string.IsNullOrWhiteSpace(AdminName) || AdminName.Length > 20 ||
            AdminName.Any(c => char.IsControl(c) || "\"/\\[]:;|=,+*?<>@".Contains(c)) || AdminName.EndsWith('.') ||
            !Enum.IsDefined(HardeningMode) || !Enum.IsDefined(NoSessionTarget))
            throw new ArgumentException("invalid_hardening_config");
    }
}

public interface IHardeningRunner
{
    int Run(HardeningConfig config, bool dryRun, bool explicitCommand, bool undo = false);
}
