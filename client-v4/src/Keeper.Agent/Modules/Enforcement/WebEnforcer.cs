using System.Globalization;
using Keeper.Shared.Protocol;

namespace Keeper.Agent.Modules.Enforcement;

public sealed class WebEnforcer(ISystemPolicyStore store) : RegistryEnforcer(store)
{
    public override string Name => "WebEnforcer";
    public static IReadOnlyList<string> BrowserPaths { get; } =
    [@"SOFTWARE\Policies\Google\Chrome", @"SOFTWARE\Policies\Microsoft\Edge", @"SOFTWARE\Policies\BraveSoftware\Brave"];

    protected override void Apply(EffectivePolicy policy)
    {
        var rules = policy.Rules.Where(r => r.Kind == RuleKind.Web).ToArray();
        if (rules.Any(r => r.ScheduleId is not null || r.Effect != RuleEffect.Deny))
            throw new NotSupportedException("Only unconditional domain deny rules supported");
        var domains = rules.SelectMany(r => r.Targets).Select(NormalizeDomain).Distinct().Order(StringComparer.Ordinal).ToArray();
        var management = policy.ManagementHosts.Select(NormalizeDomain).Distinct().ToArray();
        if (domains.Length > 1000 || management.Contains("*")) throw new NotSupportedException("Invalid domain list");
        foreach (var browser in BrowserPaths)
        {
            // Chromium allowlists take precedence, preserving management even under a parent-domain block.
            Store.ReplaceStringList(browser + @"\URLAllowlist", management);
            Store.ReplaceStringList(browser + @"\URLBlocklist", domains);
        }
    }

    private static string NormalizeDomain(string domain)
    {
        if (domain == "*") return domain;
        var input = domain.StartsWith("*.", StringComparison.Ordinal) ? domain[2..] : domain;
        input = input.EndsWith('.') ? input[..^1] : input;
        if (input.Split('.').Any(string.IsNullOrEmpty)) throw new NotSupportedException("Malformed domain");
        string ascii;
        try { ascii = new IdnMapping().GetAscii(input).ToLowerInvariant(); }
        catch (ArgumentException ex) { throw new NotSupportedException("Malformed domain", ex); }
        if (Uri.CheckHostName(ascii) is not (UriHostNameType.Dns or UriHostNameType.IPv4) || ascii.Contains(':') || ascii.Length > 253)
            throw new NotSupportedException("Expected domain, not URL or pattern");
        return ascii;
    }

    protected override void Clear()
    {
        foreach (var browser in BrowserPaths)
        {
            Store.ReplaceStringList(browser + @"\URLBlocklist", []);
            Store.ReplaceStringList(browser + @"\URLAllowlist", []);
        }
    }
}
