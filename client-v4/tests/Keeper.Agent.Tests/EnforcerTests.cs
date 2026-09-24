using Keeper.Agent.Modules.Enforcement;
using Keeper.Shared.Protocol;

namespace Keeper.Agent.Tests;

public sealed class EnforcerTests
{
    [Fact]
    public async Task WebWritesMachineBrowserListsAndRemovesStaleEntries()
    {
        var store = new MemorySystemPolicyStore();
        var module = new WebEnforcer(store);
        await module.InitAsync(Samples.Context());
        await module.ApplyPolicyAsync(Samples.Policy(Samples.Rule(RuleKind.Web, RuleEffect.Deny, "*.Example.COM", "example.com", "blocked.test")));
        foreach (var browser in WebEnforcer.BrowserPaths)
        {
            Assert.Equal("blocked.test", store.Values[(browser + @"\URLBlocklist", "1")]);
            Assert.Equal("example.com", store.Values[(browser + @"\URLBlocklist", "2")]);
            Assert.Equal("keep.azclegal.com", store.Values[(browser + @"\URLAllowlist", "1")]);
        }
        await module.ApplyPolicyAsync(Samples.Policy());
        Assert.DoesNotContain(store.Values.Keys, k => k.Path.EndsWith("URLBlocklist", StringComparison.Ordinal));
        Assert.Equal("applied", module.Snapshot().State);
    }

    [Theory]
    [InlineData("*", true, false)]
    [InlineData("write", false, true)]
    public async Task UsbWritesCorrectDwordsAndClearsPreviousMode(string target, bool all, bool write)
    {
        var store = new MemorySystemPolicyStore();
        var module = new UsbEnforcer(store);
        await module.InitAsync(Samples.Context());
        await module.ApplyPolicyAsync(Samples.Policy(Samples.Rule(RuleKind.Usb, RuleEffect.Deny, "*")));
        await module.ApplyPolicyAsync(Samples.Policy(Samples.Rule(RuleKind.Usb, RuleEffect.Deny, target)));
        Assert.Equal(all, store.Values.ContainsKey((UsbEnforcer.Root, "Deny_All")));
        Assert.Equal(write, store.Values.ContainsKey((UsbEnforcer.Disks, "Deny_Write")));
        Assert.All(store.Values.Values, value => Assert.Equal(1, value));
        await module.ApplyPolicyAsync(Samples.Policy(Samples.Rule(RuleKind.Usb, RuleEffect.Allow, "*")));
        Assert.Empty(store.Values);
    }

    [Fact]
    public async Task UsbSelectsHighestPriorityAndWithdrawsConflictingPolicy()
    {
        var store = new MemorySystemPolicyStore();
        var module = new UsbEnforcer(store);
        await module.InitAsync(Samples.Context());
        var deny = Samples.Rule(RuleKind.Usb, RuleEffect.Deny, "*");
        var allow = Samples.Rule(RuleKind.Usb, RuleEffect.Allow, "*");
        await module.ApplyPolicyAsync(Samples.Policy(deny, allow with { Priority = 1 }));
        Assert.Empty(store.Values);
        store.Writes.Clear();
        await module.ApplyPolicyAsync(Samples.Policy(deny, allow));
        Assert.Equal("unsupported", module.Snapshot().State);
        Assert.All(store.Writes, w => Assert.Null(w.Value));
    }

    [Fact]
    public async Task UnsupportedWebScheduleDoesNotPartiallyWrite()
    {
        var store = new MemorySystemPolicyStore();
        var module = new WebEnforcer(store);
        await module.InitAsync(Samples.Context());
        await module.ApplyPolicyAsync(Samples.Policy(Samples.Rule(RuleKind.Web, RuleEffect.Deny, "example.com") with { ScheduleId = Guid.NewGuid() }));
        Assert.Equal("unsupported", module.Snapshot().State);
        Assert.Empty(store.Writes);
    }

    [Fact]
    public async Task DryRunReportsSimulationAndWindowsAdapterNeverOpensRegistryWhenDisabled()
    {
        var fake = new MemorySystemPolicyStore { IsDryRun = true };
        var module = new UsbEnforcer(fake);
        await module.InitAsync(Samples.Context());
        await module.ApplyPolicyAsync(Samples.Policy(Samples.Rule(RuleKind.Usb, RuleEffect.Deny, "*")));
        Assert.Equal("dry_run", module.Snapshot().State);
        Assert.Null(module.Snapshot().AppliedVersion);
        var logs = new List<string>();
        var windows = new WindowsSystemPolicyStore(false, logs.Add);
        windows.ReplaceStringList("unused-test-path", ["example.com"]);
        windows.SetDword("unused-test-path", "unused", 1);
        Assert.True(windows.IsDryRun);
        Assert.Equal(2, logs.Count);
    }
}
