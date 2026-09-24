using Keeper.Agent.Modules.Enforcement;
using Keeper.Shared.Contracts;
using Keeper.Shared.Protocol;

namespace Keeper.Agent.Tests;

public sealed class RegistryRegressionTests
{
    [Fact]
    public async Task FailureBacksOffAtOneFiveAndFifteenMinutesAndReportsOnlyTransitions()
    {
        var clock = new TestClock();
        var context = Samples.Context(clock);
        var events = (MemoryEvents)context.Outbox;
        var module = new FailingEnforcer { Fail = true };
        await module.InitAsync(context);
        var policy = Samples.Policy();
        await module.ApplyPolicyAsync(policy);
        Assert.Equal(1, module.Attempts);
        foreach (var minutes in new[] { 1, 5, 15, 15 })
        {
            var before = module.Attempts;
            for (var second = 1; second < minutes * 60; second++)
            {
                clock.Advance(TimeSpan.FromSeconds(1));
                await module.TickAsync(default);
                await module.ApplyPolicyAsync(policy);
            }
            Assert.Equal(before, module.Attempts);
            clock.Advance(TimeSpan.FromSeconds(1));
            await module.TickAsync(default);
            Assert.Equal(before + 1, module.Attempts);
        }
        Assert.Single(events.Logs);
        Assert.Equal("failed", events.Logs[0].Code);
        module.Fail = false;
        clock.Advance(TimeSpan.FromMinutes(15));
        await module.TickAsync(default);
        Assert.Equal("applied", module.Snapshot().State);
        Assert.Equal(policy.Version, module.Snapshot().AppliedVersion);
        Assert.Equal(new[] { "failed", "applied" }, events.Logs.Select(l => l.Code));
        await module.ApplyPolicyAsync(policy);
        Assert.Equal(2, events.Logs.Count);
        module.Fail = true;
        await module.ApplyPolicyAsync(policy with { Version = "v2" });
        var attempts = module.Attempts;
        clock.Advance(TimeSpan.FromMinutes(1));
        await module.TickAsync(default);
        Assert.Equal(attempts + 1, module.Attempts);
        Assert.Equal(3, events.Logs.Count);
    }

    [Theory]
    [InlineData("")]
    [InlineData(".")]
    [InlineData("..")]
    [InlineData(".example.com")]
    [InlineData("example..com")]
    [InlineData("example.com..")]
    [InlineData("\u0001.example.com")]
    [InlineData("*.")]
    public async Task MalformedDomainsAreUnsupportedAndWithdrawPreviousPolicy(string domain)
    {
        var store = new MemorySystemPolicyStore();
        var clock = new TestClock();
        var context = Samples.Context(clock);
        var module = new WebEnforcer(store);
        await module.InitAsync(context);
        await module.ApplyPolicyAsync(Samples.Policy(Samples.Rule(RuleKind.Web, RuleEffect.Deny, "blocked.test")));
        await module.ApplyPolicyAsync(Samples.Policy(Samples.Rule(RuleKind.Web, RuleEffect.Deny, domain)) with { Version = "v2" });
        Assert.Equal("unsupported", module.Snapshot().State);
        Assert.Null(module.Snapshot().AppliedVersion);
        Assert.Empty(store.Values);
        var writes = store.Writes.Count;
        clock.Advance(TimeSpan.FromHours(1));
        await module.TickAsync(default);
        Assert.Equal(writes, store.Writes.Count);
        Assert.Equal(2, ((MemoryEvents)context.Outbox).Logs.Count);
    }

    [Theory]
    [InlineData(RuleEffect.Allow, "*")]
    [InlineData(RuleEffect.Deny, "write")]
    public async Task ThirdUsbRuleAtEqualPriorityCannotBeSilentlyDiscarded(RuleEffect effect, string target)
    {
        var store = new MemorySystemPolicyStore();
        var module = new UsbEnforcer(store);
        await module.InitAsync(Samples.Context());
        var deny = Samples.Rule(RuleKind.Usb, RuleEffect.Deny, "*");
        await module.ApplyPolicyAsync(Samples.Policy(deny));
        await module.ApplyPolicyAsync(Samples.Policy(deny, deny with { Id = Guid.NewGuid() }, Samples.Rule(RuleKind.Usb, effect, target)));
        Assert.Equal("unsupported", module.Snapshot().State);
        Assert.Empty(store.Values);
    }

    [Fact]
    public async Task CleanupFailureIsReportedAndRetriedWithBackoff()
    {
        var clock = new TestClock();
        var module = new FailingEnforcer();
        await module.InitAsync(Samples.Context(clock));
        await module.ApplyPolicyAsync(Samples.Policy());
        module.Unsupported = true;
        module.FailCleanup = true;
        await module.ApplyPolicyAsync(Samples.Policy() with { Version = "v2" });
        Assert.Equal("failed", module.Snapshot().State);
        Assert.Null(module.Snapshot().AppliedVersion);
        await module.TickAsync(default);
        Assert.Equal(1, module.Cleanups);
        module.FailCleanup = false;
        clock.Advance(TimeSpan.FromMinutes(1));
        await module.TickAsync(default);
        Assert.Equal("unsupported", module.Snapshot().State);
        Assert.Equal(2, module.Cleanups);
    }

    private sealed class FailingEnforcer() : RegistryEnforcer(new MemorySystemPolicyStore())
    {
        public override string Name => "TestEnforcer";
        public bool Fail { get; set; }
        public bool Unsupported { get; set; }
        public bool FailCleanup { get; set; }
        public int Attempts { get; private set; }
        public int Cleanups { get; private set; }
        protected override void Apply(EffectivePolicy policy)
        {
            Attempts++;
            if (Fail) throw new IOException("write_failed");
            if (Unsupported) throw new NotSupportedException();
        }
        protected override void Clear()
        {
            Cleanups++;
            if (FailCleanup) throw new IOException("cleanup_failed");
        }
    }
}
