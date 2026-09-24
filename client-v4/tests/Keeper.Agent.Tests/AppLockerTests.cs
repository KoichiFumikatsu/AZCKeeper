using System.Xml.Linq;
using Keeper.Agent.Modules.Enforcement;

namespace Keeper.Agent.Tests;

public sealed class AppLockerTests
{
    [Theory]
    [InlineData(AppLockerMode.Enforce, "Enabled")]
    [InlineData(AppLockerMode.Audit, "AuditOnly")]
    public async Task InstallAppliesExpectedCollectionsPathsPublishersAndService(AppLockerMode mode, string enforcement)
    {
        var store = new MemorySystemPolicyStore();
        var options = new AppLockerOptions { Mode = mode, TrustedPublishers = ["O=MICROSOFT CORPORATION, L=REDMOND, S=WASHINGTON, C=US", "O=Tenant & Co, C=CO"] };
        var module = new InstallEnforcer(store, options);
        await module.InitAsync(Samples.Context());
        await module.ApplyPolicyAsync(Samples.Policy(Samples.Rule(RuleKind.Installation, RuleEffect.Deny, "*")));
        Assert.Equal(mode == AppLockerMode.Audit ? "audit" : "applied", module.Snapshot().State);
        Assert.Equal(mode == AppLockerMode.Audit ? "audit_not_blocking" : null, module.Snapshot().ErrorCode);
        Assert.Equal(mode == AppLockerMode.Audit ? null : "opaque-v1", module.Snapshot().AppliedVersion);
        Assert.Equal(2, store.AppLocker.Startup.Start);
        Assert.True(store.AppLocker.IsRunning);
        Assert.Equal(new[] { "backup", "auto_start", "policy" }, store.AppLocker.Operations);

        var xml = XElement.Parse(store.AppLocker.PolicyXml);
        Assert.Equal("AppLockerPolicy", xml.Name.LocalName);
        Assert.Equal("1", (string?)xml.Attribute("Version"));
        Assert.Equal(new[] { "Exe", "Msi", "Script", "Appx" }, xml.Elements().Select(c => (string?)c.Attribute("Type")));
        foreach (var collection in xml.Elements())
        {
            Assert.Equal(enforcement, (string?)collection.Attribute("EnforcementMode"));
            foreach (var rule in collection.Elements())
            {
                Assert.True(Guid.TryParse((string?)rule.Attribute("Id"), out _));
                Assert.Equal("S-1-1-0", (string?)rule.Attribute("UserOrGroupSid"));
            }
            if ((string?)collection.Attribute("Type") == "Appx")
            {
                Assert.Equal("*", (string?)Assert.Single(collection.Descendants("FilePublisherCondition")).Attribute("PublisherName"));
                continue;
            }
            Assert.Equal(new[] { @"%PROGRAMFILES%\*", @"%WINDIR%\*" }, Paths(collection, "Allow"));
            Assert.Equal(new[] { @"%OSDRIVE%\Users\*\Downloads\*", @"%OSDRIVE%\Users\*\AppData\*", @"%WINDIR%\Temp\*", @"%OSDRIVE%\Temp\*" }, Paths(collection, "Deny"));
            Assert.Equal(options.TrustedPublishers, collection.Descendants("FilePublisherCondition").Select(p => (string?)p.Attribute("PublisherName")));
            foreach (var publisher in collection.Descendants("FilePublisherCondition"))
            {
                Assert.Equal("*", (string?)publisher.Attribute("BinaryName"));
                Assert.Equal("*", (string?)publisher.Attribute("ProductName"));
                Assert.Equal("0.0.0.0", (string?)publisher.Element("BinaryVersionRange")?.Attribute("LowSection"));
                Assert.Equal("*", (string?)publisher.Element("BinaryVersionRange")?.Attribute("HighSection"));
            }
            Assert.All(collection.Elements("FilePublisherRule"), r => Assert.Equal("Allow", (string?)r.Attribute("Action")));
        }
        Assert.Equal(0, Assert.Single(store.Values).Value);
        Assert.Equal(0, store.Values[(InstallEnforcer.InstallerPath, "AlwaysInstallElevated")]);
        Assert.DoesNotContain(store.Writes, w => w.Name == "DownloadRestrictions");
    }

    [Theory]
    [InlineData("Exe", "Downloads", "program.exe")]
    [InlineData("Exe", "Downloads", "screen.scr")]
    [InlineData("Msi", "Downloads", "setup.msi")]
    [InlineData("Script", @"AppData\Roaming", "run.bat")]
    [InlineData("Script", @"AppData\Local", "run.cmd")]
    [InlineData("Script", @"AppData\Local\Temp", "run.ps1")]
    [InlineData("Script", @"AppData\Local\Temp", "run.vbs")]
    public void DirectoryDeniesCoverRequestedFilesAndNestedDirectories(string type, string directory, string file)
    {
        var xml = XElement.Parse(AppLockerPolicy.Create(new()));
        var collection = xml.Elements().Single(c => (string?)c.Attribute("Type") == type);
        var path = $@"%OSDRIVE%\Users\Test User\{directory}\nested\{file}";
        Assert.Contains(Paths(collection, "Deny"), pattern =>
            System.Text.RegularExpressions.Regex.IsMatch(path,
                "^" + System.Text.RegularExpressions.Regex.Escape(pattern!).Replace(@"\*", ".*", StringComparison.Ordinal) + "$"));
        Assert.DoesNotContain(collection.Elements(), r => r.Element("Exceptions") is not null);
    }

    [Theory]
    [InlineData(false)]
    [InlineData(true)]
    public async Task WithdrawalRestoresOriginalPolicyAndStartupAcrossRecreation(bool explicitAllow)
    {
        var store = new MemorySystemPolicyStore();
        const string original = "<AppLockerPolicy Version=\"1\"><RuleCollection Type=\"Dll\" EnforcementMode=\"AuditOnly\" /></AppLockerPolicy>";
        store.AppLocker.PolicyXml = original;
        store.AppLocker.Startup = new(3, null);
        var module = new InstallEnforcer(store);
        await module.InitAsync(Samples.Context());
        await module.ApplyPolicyAsync(Samples.Policy(Samples.Rule(RuleKind.Installation, RuleEffect.Deny, "*")));
        module = new InstallEnforcer(store, new() { Mode = AppLockerMode.Enforce });
        await module.InitAsync(Samples.Context());
        await module.ApplyPolicyAsync(Samples.Policy(Samples.Rule(RuleKind.Installation, RuleEffect.Deny, "*")));
        Assert.Equal(original, store.AppLocker.Backup!.PolicyXml);
        await module.ApplyPolicyAsync(explicitAllow
            ? Samples.Policy(Samples.Rule(RuleKind.Installation, RuleEffect.Allow, "*")) : Samples.Policy());
        Assert.Equal(original, store.AppLocker.PolicyXml);
        Assert.Equal(new ApplicationIdentityStartup(3, null), store.AppLocker.Startup);
        Assert.Null(store.AppLocker.Backup);
        Assert.Equal("applied", module.Snapshot().State);
        Assert.Equal(0, Assert.Single(store.Values).Value);
        Assert.Equal(0, store.Values[(InstallEnforcer.InstallerPath, "AlwaysInstallElevated")]);
        var operations = store.AppLocker.Operations.Count;
        await module.ApplyPolicyAsync(Samples.Policy());
        Assert.Equal(operations, store.AppLocker.Operations.Count);
    }

    [Fact]
    public async Task UnsupportedRuleWithdrawsPreviouslyAppliedAppLocker()
    {
        var store = new MemorySystemPolicyStore();
        var original = store.AppLocker.PolicyXml;
        var module = new InstallEnforcer(store);
        await module.InitAsync(Samples.Context());
        await module.ApplyPolicyAsync(Samples.Policy(Samples.Rule(RuleKind.Installation, RuleEffect.Deny, "*")));
        await module.ApplyPolicyAsync(Samples.Policy(Samples.Rule(RuleKind.Installation, RuleEffect.Deny, "exe")));
        Assert.Equal("unsupported", module.Snapshot().State);
        Assert.Equal(original, store.AppLocker.PolicyXml);
        Assert.Equal(3, store.AppLocker.Startup.Start);
        Assert.Null(store.AppLocker.Backup);
    }

    [Fact]
    public void RepeatedApplyHasStableUniqueRuleIdsAndModeChangesDoNotDuplicateRules()
    {
        var audit = XElement.Parse(AppLockerPolicy.Create(new()));
        var enforce = XElement.Parse(AppLockerPolicy.Create(new() { Mode = AppLockerMode.Enforce }));
        var ids = audit.Descendants().Attributes("Id").Select(a => a.Value).ToArray();
        Assert.Equal(ids.Length, ids.Distinct().Count());
        Assert.Equal(ids, enforce.Descendants().Attributes("Id").Select(a => a.Value));
        Assert.Equal(audit.ToString(), XElement.Parse(AppLockerPolicy.Create(new())).ToString());
    }

    [Fact]
    public void TenantConfigurationUsesAuditDefaultAndSupportsExplicitEnforceAndRedirectedFolders()
    {
        Assert.Equal(AppLockerMode.Audit, AppLockerOptions.FromEnvironment(_ => null).Mode);
        var config = new Dictionary<string, string>
        {
            ["KEEPER_APPLOCKER_MODE"] = "Enforce",
            ["KEEPER_APPLOCKER_TRUSTED_PUBLISHERS"] = "[\"O=TENANT, C=CO\"]"
        };
        // Serialize Windows paths as JSON rather than relying on shell/environment expansion.
        config["KEEPER_APPLOCKER_WRITABLE_PATHS"] = System.Text.Json.JsonSerializer.Serialize(new[] { @"D:\Profiles\*\Downloads\*", @"D:\Temp\*" });
        var options = AppLockerOptions.FromEnvironment(name => config.GetValueOrDefault(name));
        Assert.Equal(AppLockerMode.Enforce, options.Mode);
        var xml = XElement.Parse(AppLockerPolicy.Create(options));
        foreach (var collection in xml.Elements().Where(c => (string?)c.Attribute("Type") != "Appx"))
        {
            Assert.Contains(@"D:\Profiles\*\Downloads\*", Paths(collection, "Deny"));
            Assert.Contains(@"D:\Temp\*", Paths(collection, "Deny"));
            Assert.Equal("O=TENANT, C=CO", (string?)Assert.Single(collection.Descendants("FilePublisherCondition")).Attribute("PublisherName"));
        }
        Assert.Throws<ArgumentException>(() => AppLockerOptions.FromEnvironment(_ => "invalid"));
    }

    [Fact]
    public void WildcardPublishersAndUnsupportedEnvironmentVariablesAreRejected()
    {
        Assert.Throws<ArgumentException>(() => AppLockerPolicy.Create(new() { TrustedPublishers = ["*"] }));
        Assert.Throws<ArgumentException>(() => AppLockerPolicy.Create(new() { AdditionalWritablePaths = [@"%TEMP%\*"] }));
        Assert.Throws<ArgumentException>(() => AppLockerPolicy.Create(new() { AdditionalWritablePaths = [@"relative\*"] }));
    }

    [Fact]
    public void DryRunNeverInvokesAppLockerOrServiceAdapter()
    {
        var machine = new MemoryAppLockerMachine();
        var logs = new List<string>();
        var store = new WindowsSystemPolicyStore(false, logs.Add, new AppLockerPolicyStore(machine));
        store.ApplyAppLocker(AppLockerPolicy.Create(new()));
        store.ClearAppLocker();
        Assert.Empty(machine.Operations);
        Assert.Equal(3, machine.Startup.Start);
        Assert.False(machine.IsRunning);
        Assert.Equal(2, logs.Count);
    }

    [Fact]
    public void ClearWithoutOwnershipLeavesExistingPolicyAndServiceAlone()
    {
        var machine = new MemoryAppLockerMachine { Startup = new(2, 1), IsRunning = true };
        new AppLockerPolicyStore(machine).ClearAppLocker();
        Assert.Empty(machine.Operations);
        Assert.Equal(new ApplicationIdentityStartup(2, 1), machine.Startup);
        Assert.True(machine.IsRunning);
    }

    [Fact]
    public void FailureRetainsBackupForRetryAndRestoresOriginallyAutomaticService()
    {
        var machine = new FailingMachine();
        machine.Inner.Startup = new(2, 1);
        var original = machine.Inner.PolicyXml;
        var store = new AppLockerPolicyStore(machine);
        Assert.Throws<IOException>(() => store.ApplyAppLocker(AppLockerPolicy.Create(new())));
        Assert.Equal(original, machine.Inner.Backup!.PolicyXml);
        machine.FailPolicy = false;
        store.ApplyAppLocker(AppLockerPolicy.Create(new()));
        machine.FailRestore = true;
        Assert.Throws<IOException>(store.ClearAppLocker);
        Assert.NotNull(machine.Inner.Backup);
        machine.FailRestore = false;
        new AppLockerPolicyStore(machine).ClearAppLocker();
        Assert.Null(machine.Inner.Backup);
        Assert.Equal(original, machine.Inner.PolicyXml);
        Assert.Equal(new ApplicationIdentityStartup(2, 1), machine.Inner.Startup);
    }

    private static IEnumerable<string?> Paths(XElement collection, string action) => collection.Elements("FilePathRule")
        .Where(r => (string?)r.Attribute("Action") == action).Select(r => (string?)r.Element("Conditions")?.Element("FilePathCondition")?.Attribute("Path"));

    private sealed class FailingMachine : IAppLockerMachine
    {
        public MemoryAppLockerMachine Inner { get; } = new();
        public bool FailPolicy { get; set; } = true;
        public bool FailRestore { get; set; }
        public AppLockerBackup? ReadBackup() => Inner.ReadBackup();
        public void SaveBackup(AppLockerBackup backup) => Inner.SaveBackup(backup);
        public void DeleteBackup() => Inner.DeleteBackup();
        public string ReadLocalPolicy() => Inner.ReadLocalPolicy();
        public ApplicationIdentityStartup ReadStartup() => Inner.ReadStartup();
        public void EnsureAutomaticAndRunning() => Inner.EnsureAutomaticAndRunning();
        public void SetLocalPolicy(string policyXml)
        {
            if (FailPolicy) throw new IOException("policy_failed");
            Inner.SetLocalPolicy(policyXml);
        }
        public void RestoreStartup(ApplicationIdentityStartup startup)
        {
            if (FailRestore) throw new IOException("restore_failed");
            Inner.RestoreStartup(startup);
        }
    }
}
