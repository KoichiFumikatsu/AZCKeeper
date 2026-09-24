using System.Text.Json;

namespace Keeper.Agent.Modules.Enforcement;

public sealed class InstallEnforcer(ISystemPolicyStore store, AppLockerOptions? options = null,
    Func<AppLockerOptions>? loadOptions = null) : RegistryEnforcer(store)
{
    private readonly Func<AppLockerOptions> _loadOptions = loadOptions ?? (() => options ?? new());
    private bool _audit;
    public override string Name => "InstallEnforcer";
    protected override (string State, string? ErrorCode) AppliedStatus =>
        _audit ? ("audit", "audit_not_blocking") : base.AppliedStatus;
    public const string InstallerPath = @"SOFTWARE\Policies\Microsoft\Windows\Installer";
    protected override void Apply(EffectivePolicy policy)
    {
        Store.SetDword(InstallerPath, "AlwaysInstallElevated", 0);
        _audit = false;
        var deny = BlanketRule.Select(policy, RuleKind.Installation);
        if (!deny)
        {
            Clear();
            return;
        }
        AppLockerOptions configured;
        string xml;
        try
        {
            configured = _loadOptions();
            xml = AppLockerPolicy.Create(configured);
        }
        catch (Exception ex) when (ex is ArgumentException or JsonException or FormatException)
        {
            throw new InvalidEnforcementConfigurationException(ex);
        }
        try
        {
            Store.ApplyAppLocker(xml);
        }
        catch (NotSupportedException ex)
        {
            // Adapter failures must not trigger unsupported-rule cleanup of the previous MSI protection.
            throw new InvalidOperationException("applocker_apply_failed", ex);
        }
        // Preserve legacy protection until the replacement policy has been written successfully.
        Store.SetDword(InstallerPath, "DisableMSI", null);
        _audit = configured.Mode == AppLockerMode.Audit;
    }
    protected override void Clear()
    {
        Store.SetDword(InstallerPath, "AlwaysInstallElevated", 0);
        Store.ClearAppLocker();
        Store.SetDword(InstallerPath, "DisableMSI", null);
    }
}
