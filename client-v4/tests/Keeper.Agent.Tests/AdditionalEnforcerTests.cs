using Keeper.Agent.Modules.Enforcement;
using Keeper.Agent.Transport;
using Keeper.Agent.Hosting;

namespace Keeper.Agent.Tests;

public sealed class AdditionalEnforcerTests
{
    [Fact]
    public async Task DownloadWritesAllSupportedBrowsersAndWithdrawsRule()
    {
        var store = new MemorySystemPolicyStore(); var enforcer = new DownloadEnforcer(store);
        await enforcer.InitAsync(Samples.Context());
        await enforcer.ApplyPolicyAsync(Samples.Policy(Samples.Rule(RuleKind.Download, RuleEffect.Deny, "*")));
        Assert.All(WebEnforcer.BrowserPaths, path => Assert.Equal(3, store.Values[(path, "DownloadRestrictions")]));
        await enforcer.ApplyPolicyAsync(Samples.Policy()); Assert.Empty(store.Values);
    }
    [Fact]
    public async Task InstallDefaultsToExplicitAuditStatusWithoutMsiOrDownloadRestrictions()
    {
        var store = new MemorySystemPolicyStore(); var enforcer = new InstallEnforcer(store);
        await enforcer.InitAsync(Samples.Context());
        await enforcer.ApplyPolicyAsync(Samples.Policy(Samples.Rule(RuleKind.Installation, RuleEffect.Deny, "*")));
        Assert.Equal(0, Assert.Single(store.Values).Value);
        Assert.Equal("audit", enforcer.Snapshot().State);
        Assert.Equal("audit_not_blocking", enforcer.Snapshot().ErrorCode);
        Assert.Null(enforcer.Snapshot().AppliedVersion);
        Assert.Contains("AuditOnly", store.AppLocker.PolicyXml, StringComparison.Ordinal);
        Assert.DoesNotContain(store.Writes, w => w.Name == "DownloadRestrictions");
    }
    [Fact]
    public async Task InvalidDownloadTargetHasNoPartialWrites()
    {
        var store = new MemorySystemPolicyStore(); var enforcer = new DownloadEnforcer(store);
        await enforcer.InitAsync(Samples.Context());
        await enforcer.ApplyPolicyAsync(Samples.Policy(Samples.Rule(RuleKind.Download, RuleEffect.Deny, "exe")));
        Assert.All(store.Writes, w => Assert.Null(w.Value)); Assert.Equal("unsupported", enforcer.Snapshot().State);
    }
    [Fact]
    public void DnsAndThrottlingHaveIndependentBackoffAndCannotBypassBudget()
    {
        Assert.Equal(NetworkFailureKind.Dns, NetworkBackoffPolicy.Classify(new HttpRequestException("dns", new System.Net.Sockets.SocketException(11001))));
        Assert.Equal(NetworkFailureKind.Authorization, NetworkBackoffPolicy.Classify(new TransportException(System.Net.HttpStatusCode.Forbidden, null)));
        var schedule = new SyncSchedule(Samples.Device);
        for (var i = 0; i < 15; i++) Assert.InRange(schedule.AfterAttempt(false, 1, failureKind: NetworkFailureKind.Dns).TotalSeconds, 120, 132);
        for (var i = 0; i < 10; i++) schedule.AfterAttempt(false, 1, failureKind: NetworkFailureKind.Throttled);
        Assert.InRange(schedule.AfterAttempt(false, 1, failureKind: NetworkFailureKind.Throttled).TotalSeconds, 1800, 1812);
        Assert.InRange(schedule.AfterAttempt(false, 1, failureKind: NetworkFailureKind.Dns).TotalSeconds, 120, 132);
    }
}
