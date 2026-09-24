namespace Keeper.Agent.Modules.Enforcement;

public sealed class InstallEnforcer(ISystemPolicyStore store) : RegistryEnforcer(store)
{
    public override string Name => "InstallEnforcer";
    public const string InstallerPath = @"SOFTWARE\Policies\Microsoft\Windows\Installer";
    protected override void Apply(EffectivePolicy policy)
    {
        var deny = BlanketRule.Select(policy, RuleKind.Installation);
        Store.SetDword(InstallerPath, "AlwaysInstallElevated", 0);
        Store.SetDword(InstallerPath, "DisableMSI", deny ? 1 : null);
        // MSI is only one installation path; do not claim the blanket rule is enforced.
        if (deny) throw new NotSupportedException("applocker_not_provisioned");
    }
    protected override void Clear()
    {
        Store.SetDword(InstallerPath, "DisableMSI", null);
        Store.SetDword(InstallerPath, "AlwaysInstallElevated", null);
    }
}
