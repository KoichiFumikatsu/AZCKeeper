using System.Text.Json;
using Keeper.Agent.Modules.Diagnostics;
using Keeper.Agent.Modules.Enforcement;
using Keeper.Shared.Protocol;

namespace Keeper.Agent.Tests;

public sealed class InstallEnforcerTests
{
    [Theory]
    [InlineData(null, false, "audit", "audit_not_blocking", SecurityControlState.Unknown)]
    [InlineData("Audit", false, "audit", "audit_not_blocking", SecurityControlState.Unknown)]
    [InlineData("Enforce", false, "applied", null, SecurityControlState.Applied)]
    [InlineData("Audit", true, "dry_run", "dry_run", SecurityControlState.Failed)]
    [InlineData("Enforce", true, "dry_run", "dry_run", SecurityControlState.Failed)]
    public async Task ModeReachesSecurityReportWithoutClaimingAuditBlocks(string? mode, bool dryRun,
        string state, string? error, SecurityControlState controlState)
    {
        var context = Samples.Context(new TestClock());
        var module = new InstallEnforcer(new MemorySystemPolicyStore { IsDryRun = dryRun },
            loadOptions: () => AppLockerOptions.FromEnvironment(name => name == "KEEPER_APPLOCKER_MODE" ? mode : null));
        await module.InitAsync(context);
        await module.ApplyPolicyAsync(Samples.Policy(Samples.Rule(RuleKind.Installation, RuleEffect.Deny, "*")));
        Assert.Equal(state, module.Snapshot().State);
        Assert.Equal(error, module.Snapshot().ErrorCode);
        Assert.Equal(state == "applied" ? "opaque-v1" : null, module.Snapshot().AppliedVersion);
        Assert.Equal(state, Assert.Single(((MemoryEvents)context.Outbox).Logs).Code);

        var diagnostics = new AgentDiagnostics(() => [module.Snapshot()]);
        await diagnostics.InitAsync(context);
        await diagnostics.TickAsync(default);
        var report = Assert.Single(((MemoryEvents)context.Outbox).SecurityReports);
        var wire = JsonSerializer.Serialize(report, ProtocolJson.Options);
        var received = JsonSerializer.Deserialize<SecurityReport>(wire, ProtocolJson.Options)!;
        var control = Assert.Single(received.Controls);
        Assert.Equal(controlState, control.State);
        Assert.Equal(error, control.ErrorCode);
        Assert.Equal(state == "applied" ? "ready" : "degraded", diagnostics.Snapshot().State);
    }

    [Theory]
    [InlineData(AppLockerMode.Audit, "io")]
    [InlineData(AppLockerMode.Enforce, "io")]
    [InlineData(AppLockerMode.Audit, "unsupported_adapter")]
    [InlineData(AppLockerMode.Enforce, "unsupported_adapter")]
    [InlineData(AppLockerMode.Audit, "argument")]
    [InlineData(AppLockerMode.Enforce, "argument")]
    public async Task AppLockerFailurePreservesLegacyMsiUntilSuccessfulRetry(AppLockerMode mode, string failure)
    {
        var store = new RecordingStore
        {
            ApplyFailure = failure switch
            {
                "unsupported_adapter" => new NotSupportedException("adapter_unsupported"),
                "argument" => new ArgumentException("adapter_argument"),
                _ => new IOException("policy_failed")
            }
        };
        store.Inner.SetDword(InstallEnforcer.InstallerPath, "DisableMSI", 1);
        store.Inner.SetDword(InstallEnforcer.InstallerPath, "AlwaysInstallElevated", 1);
        var clock = new TestClock();
        var module = new InstallEnforcer(store, new AppLockerOptions { Mode = mode });
        await module.InitAsync(Samples.Context(clock));
        await module.ApplyPolicyAsync(Samples.Policy(Samples.Rule(RuleKind.Installation, RuleEffect.Deny, "*")));

        Assert.Equal("failed", module.Snapshot().State);
        Assert.Null(module.Snapshot().AppliedVersion);
        Assert.Equal(1, store.Inner.Values[(InstallEnforcer.InstallerPath, "DisableMSI")]);
        Assert.Equal(0, store.Inner.Values[(InstallEnforcer.InstallerPath, "AlwaysInstallElevated")]);
        Assert.Equal(new[] { "AlwaysInstallElevated=0", "apply" }, store.Operations);

        store.ApplyFailure = null;
        store.Operations.Clear();
        clock.Advance(TimeSpan.FromMinutes(1));
        await module.TickAsync(default);
        Assert.Equal(new[] { "AlwaysInstallElevated=0", "apply", "DisableMSI=delete" }, store.Operations);
        Assert.False(store.Inner.Values.ContainsKey((InstallEnforcer.InstallerPath, "DisableMSI")));
        Assert.Equal(mode == AppLockerMode.Audit ? "audit" : "applied", module.Snapshot().State);
    }

    [Theory]
    [InlineData("mode")]
    [InlineData("publisher")]
    [InlineData("path")]
    [InlineData("null_publishers")]
    [InlineData("null_paths")]
    [InlineData("null_options")]
    [InlineData("environment_mode")]
    [InlineData("environment_json")]
    [InlineData("environment_null")]
    public async Task InvalidConfigurationWithdrawsPolicyAndReportsUnsupportedWithoutRetry(string scenario)
    {
        var store = new RecordingStore();
        var original = store.Inner.AppLocker.PolicyXml;
        var clock = new TestClock();
        var context = Samples.Context(clock);
        var invalid = false;
        var loads = 0;
        var module = new InstallEnforcer(store, loadOptions: () =>
        {
            loads++;
            return !invalid ? new AppLockerOptions { Mode = AppLockerMode.Enforce } : InvalidOptions(scenario);
        });
        await module.InitAsync(context);
        var policy = Samples.Policy(Samples.Rule(RuleKind.Installation, RuleEffect.Deny, "*"));
        await module.ApplyPolicyAsync(policy);
        Assert.NotNull(store.Inner.AppLocker.Backup);
        invalid = true;
        store.Operations.Clear();
        store.Inner.SetDword(InstallEnforcer.InstallerPath, "AlwaysInstallElevated", 1);
        await module.ApplyPolicyAsync(policy);

        Assert.Equal("unsupported", module.Snapshot().State);
        Assert.Equal("invalid_configuration", module.Snapshot().ErrorCode);
        Assert.Null(module.Snapshot().AppliedVersion);
        Assert.Equal(original, store.Inner.AppLocker.PolicyXml);
        Assert.Null(store.Inner.AppLocker.Backup);
        Assert.DoesNotContain("apply", store.Operations);
        Assert.Contains("clear", store.Operations);
        Assert.Equal(0, store.Inner.Values[(InstallEnforcer.InstallerPath, "AlwaysInstallElevated")]);
        clock.Advance(TimeSpan.FromHours(1));
        await module.TickAsync(default);
        Assert.Equal(2, loads);

        var diagnostics = new AgentDiagnostics(() => [module.Snapshot()]);
        await diagnostics.InitAsync(context);
        await diagnostics.TickAsync(default);
        var control = Assert.Single(Assert.Single(((MemoryEvents)context.Outbox).SecurityReports).Controls);
        Assert.Equal(SecurityControlState.Unsupported, control.State);
        Assert.Equal("invalid_configuration", control.ErrorCode);

        invalid = false;
        await module.ApplyPolicyAsync(policy);
        Assert.Equal("applied", module.Snapshot().State);
        Assert.Null(module.Snapshot().ErrorCode);
    }

    [Theory]
    [InlineData("allow", "applied")]
    [InlineData("withdraw", "applied")]
    [InlineData("unsupported", "unsupported")]
    public async Task MsiElevationIsDisabledEvenWithoutAnApplicableDeny(string scenario, string state)
    {
        var store = new MemorySystemPolicyStore();
        store.SetDword(InstallEnforcer.InstallerPath, "AlwaysInstallElevated", 1);
        var module = new InstallEnforcer(store);
        await module.InitAsync(Samples.Context());
        var policy = scenario switch
        {
            "allow" => Samples.Policy(Samples.Rule(RuleKind.Installation, RuleEffect.Allow, "*")),
            "unsupported" => Samples.Policy(Samples.Rule(RuleKind.Installation, RuleEffect.Deny, "exe")),
            _ => Samples.Policy()
        };
        await module.ApplyPolicyAsync(policy);
        Assert.Equal(state, module.Snapshot().State);
        Assert.Equal(0, store.Values[(InstallEnforcer.InstallerPath, "AlwaysInstallElevated")]);
        Assert.DoesNotContain(store.Writes, w => w.Name == "AlwaysInstallElevated" && w.Value is null);
    }

    [Fact]
    public async Task InvalidConfigurationDoesNotHideCleanupFailure()
    {
        var store = new RecordingStore { ClearFailure = new IOException("restore_failed") };
        var module = new InstallEnforcer(store, new AppLockerOptions { TrustedPublishers = ["*"] });
        await module.InitAsync(Samples.Context());
        await module.ApplyPolicyAsync(Samples.Policy(Samples.Rule(RuleKind.Installation, RuleEffect.Deny, "*")));
        Assert.Equal("failed", module.Snapshot().State);
        Assert.Equal(nameof(IOException), module.Snapshot().ErrorCode);
        Assert.Equal(0, store.Inner.Values[(InstallEnforcer.InstallerPath, "AlwaysInstallElevated")]);
    }

    private static AppLockerOptions InvalidOptions(string scenario) => scenario switch
    {
        "mode" => new() { Mode = (AppLockerMode)42 },
        "publisher" => new() { TrustedPublishers = ["*"] },
        "path" => new() { AdditionalWritablePaths = [@"%TEMP%\*"] },
        "null_publishers" => new() { TrustedPublishers = null! },
        "null_paths" => new() { AdditionalWritablePaths = null! },
        "null_options" => null!,
        "environment_mode" => AppLockerOptions.FromEnvironment(name => name == "KEEPER_APPLOCKER_MODE" ? "invalid" : null),
        "environment_json" => AppLockerOptions.FromEnvironment(name => name == "KEEPER_APPLOCKER_WRITABLE_PATHS" ? "[invalid" : null),
        "environment_null" => AppLockerOptions.FromEnvironment(name => name == "KEEPER_APPLOCKER_TRUSTED_PUBLISHERS" ? "null" : null),
        _ => throw new ArgumentOutOfRangeException(nameof(scenario))
    };

    private sealed class RecordingStore : ISystemPolicyStore
    {
        public MemorySystemPolicyStore Inner { get; } = new();
        public List<string> Operations { get; } = [];
        public Exception? ApplyFailure { get; set; }
        public Exception? ClearFailure { get; init; }
        public bool IsDryRun => false;
        public void ApplyAppLocker(string policyXml)
        {
            Operations.Add("apply");
            if (ApplyFailure is not null) throw ApplyFailure;
            Inner.ApplyAppLocker(policyXml);
        }
        public void ClearAppLocker()
        {
            Operations.Add("clear");
            if (ClearFailure is not null) throw ClearFailure;
            Inner.ClearAppLocker();
        }
        public void SetDword(string path, string name, int? value)
        {
            Operations.Add($"{name}={value?.ToString() ?? "delete"}");
            Inner.SetDword(path, name, value);
        }
        public void ReplaceStringList(string path, IReadOnlyList<string> values) => Inner.ReplaceStringList(path, values);
    }
}
