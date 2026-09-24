using Keeper.Shared.Policy;

namespace Keeper.Agent.Modules.Enforcement;

public sealed class DownloadEnforcer(ISystemPolicyStore store) : RegistryEnforcer(store)
{
    public override string Name => "DownloadEnforcer";
    protected override void Apply(EffectivePolicy policy)
    {
        var deny = BlanketRule.Select(policy, RuleKind.Download);
        foreach (var path in WebEnforcer.BrowserPaths) Store.SetDword(path, "DownloadRestrictions", deny ? 3 : null);
    }
    protected override void Clear()
    {
        foreach (var path in WebEnforcer.BrowserPaths) Store.SetDword(path, "DownloadRestrictions", null);
    }
}

internal static class BlanketRule
{
    public static bool Select(EffectivePolicy policy, RuleKind kind)
    {
        var rules = policy.Rules.Where(r => r.Kind == kind).OrderByDescending(r => r.Priority).ToArray();
        if (rules.Any(r => r.ScheduleId is not null || r.Targets.Count != 1 || r.Targets[0] != "*" ||
            r.Effect is not (RuleEffect.Deny or RuleEffect.Allow))) throw new NotSupportedException("unsupported_rule");
        if (rules.Length > 0 && rules.Any(r => r.Priority == rules[0].Priority && r.Effect != rules[0].Effect))
            throw new NotSupportedException("conflicting_rules");
        return rules.FirstOrDefault()?.Effect == RuleEffect.Deny;
    }
}
