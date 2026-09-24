using Keeper.Shared.Protocol;

namespace Keeper.Agent.Modules.Enforcement;

public sealed class UsbEnforcer(ISystemPolicyStore store) : RegistryEnforcer(store)
{
    public override string Name => "UsbEnforcer";
    public const string Root = @"SOFTWARE\Policies\Microsoft\Windows\RemovableStorageDevices";
    public const string AllStorage = Root + @"\{53f5630d-b6bf-11d0-94f2-00a0c91efb8b}";
    public const string Disks = Root + @"\{53f56307-b6bf-11d0-94f2-00a0c91efb8b}";

    protected override void Apply(EffectivePolicy policy)
    {
        var rules = policy.Rules.Where(r => r.Kind == RuleKind.Usb).OrderByDescending(r => r.Priority).ToArray();
        foreach (var rule in rules)
            if (rule.ScheduleId is not null || rule.Targets.Count != 1 ||
                !(rule.Effect == RuleEffect.Deny && rule.Targets[0] is "*" or "write" || rule.Effect == RuleEffect.Allow && rule.Targets[0] == "*"))
                throw new NotSupportedException("Unsupported prototype USB target");
        if (rules.Length > 0 && rules.Any(r => r.Priority == rules[0].Priority &&
            (r.Effect != rules[0].Effect || r.Targets[0] != rules[0].Targets[0])))
            throw new NotSupportedException("Conflicting USB priorities");
        var selected = rules.FirstOrDefault();
        var denyAll = selected?.Effect == RuleEffect.Deny && selected.Targets[0] == "*";
        var readOnly = selected?.Effect == RuleEffect.Deny && selected.Targets[0] == "write";
        RemoveLegacyValues();
        Store.SetDword(Disks, "Deny_Write", readOnly ? 1 : null);
        Store.SetDword(AllStorage, "Deny_All", denyAll ? 1 : null);
    }
    protected override void Clear()
    {
        Store.SetDword(Disks, "Deny_Write", null);
        Store.SetDword(AllStorage, "Deny_All", null);
        RemoveLegacyValues();
    }

    private void RemoveLegacyValues()
    {
        // Withdraw values written at the wrong locations by earlier agents.
        Store.SetDword(AllStorage, "Deny_Write", null);
        Store.SetDword(Root, "Deny_All", null);
    }
}
