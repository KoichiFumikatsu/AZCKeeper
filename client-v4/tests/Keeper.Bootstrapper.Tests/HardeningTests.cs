using System.Reflection;
using System.Reflection.Emit;
using System.Runtime.InteropServices;
using Keeper.Bootstrapper.Hardening;
using Keeper.Shared.Contracts;

namespace Keeper.Bootstrapper.Tests;

public sealed class HardeningTests
{
    private readonly HardeningFake fake = new();
    private readonly List<string> output = [];
    private HardeningCoordinator Coordinator => new(fake, fake, fake, fake, output.Add);
    private int Harden(bool auto = false) => Coordinator.Run(new() { HardeningMode = auto ? HardeningMode.Auto : HardeningMode.Panel }, false, !auto);
    private bool WorkerIsAdmin => fake.Accounts.Single(a => a.Sid == HardeningFake.Worker).Administrator;

    [Fact]
    public void HappyPathPreservesOrderAndNeverReachesZeroUsableAdmins()
    {
        Assert.Equal(0, Harden());
        string[] ordered = ["create_api", $"add:{HardeningFake.Admin}:{AccountSids.Administrators}", "logon_interactive",
            "deny:True", "sessions", $"add:{HardeningFake.Worker}:{AccountSids.Users}",
            $"remove:{HardeningFake.Worker}:{AccountSids.Administrators}", "visibility:0", "state:hardened"];
        var previous = -1;
        foreach (var operation in ordered)
        {
            var current = fake.Operations.IndexOf(operation);
            Assert.True(current > previous, operation);
            previous = current;
        }
        Assert.False(WorkerIsAdmin);
        Assert.True(fake.Accounts.Single(a => a.Sid == HardeningFake.Worker).User);
        Assert.All(fake.OperationalAdmins, count => Assert.True(count >= 1));
        Assert.Equal("hardened", fake.State!.Status);
        Assert.True(fake.State.LogoffRequired);
        Assert.False(fake.LeaseHeld);
        Assert.Equal(fake.Writes.Count, fake.Writes.Select(state => state.Revision).Distinct().Count());
        Assert.DoesNotContain(HardeningFake.TestPassword, string.Join('\n', output));
    }

    [Fact]
    public void FailedCredentialVerificationNeverDemotesOrAppliesPolicy()
    {
        fake.CredentialValid = false;
        Assert.Equal(1, Harden());
        Assert.True(WorkerIsAdmin);
        Assert.DoesNotContain(fake.Operations, op => op.StartsWith("remove:") || op.StartsWith("deny:"));
        Assert.Equal(2, fake.State!.Step);
        Assert.All(fake.OperationalAdmins, count => Assert.True(count >= 1));
    }

    [Theory]
    [InlineData(2, 4)]
    [InlineData(3, 5)]
    public void RechecksRecoveryCredentialBeforeAndAfterDemotion(int verification, int step)
    {
        fake.FailVerificationNumber = verification;
        Assert.Equal(1, Harden(true));
        Assert.True(WorkerIsAdmin);
        Assert.Equal(step, fake.State!.Step);
        Assert.Equal(HardeningMode.Panel, fake.State.Mode);
        Assert.False(fake.State.RecoveryRequired);
        Assert.All(fake.OperationalAdmins, count => Assert.True(count >= 1));
        if (verification == 2) Assert.DoesNotContain($"remove:{HardeningFake.Worker}:{AccountSids.Administrators}", fake.Operations);
    }

    [Theory]
    [InlineData("create_api", 1)]
    [InlineData("deny:True", 3)]
    [InlineData("sessions", 4)]
    [InlineData("visibility:0", 6)]
    [InlineData("state:hardened", 7)]
    public void AutoFailureRestoresPrivilegesFallsBackToPanelAndDoesNotRetry(string operation, int step)
    {
        fake.FailOnce = operation;
        Assert.Equal(1, Harden(true));
        Assert.True(WorkerIsAdmin);
        Assert.Equal(HardeningMode.Panel, fake.State!.Mode);
        Assert.Equal(step, fake.State.Step);
        Assert.False(fake.State.RecoveryRequired);
        Assert.False(fake.Deny);
        Assert.Null(fake.Visibility);
        Assert.DoesNotContain(HardeningFake.TestPassword, string.Join('\n', output));
        fake.Operations.Clear();
        Assert.Equal(0, Harden(true));
        Assert.Equal(new[] { "lease", "read_state", "state:failed" }, fake.Operations);
    }

    [Theory]
    [InlineData(false)]
    [InlineData(true)]
    public void DryRunDoesNotEvenReadOrAcquireDependencies(bool undo)
    {
        fake.ForbidAccess = true;
        Assert.Equal(0, Coordinator.Run(new(), true, true, undo));
        Assert.Empty(fake.Operations);
        Assert.Contains("DRY-RUN finalizado: 0 mutaciones.", output);
    }

    [Fact]
    public void PanelWaitsUntilExplicitCommand()
    {
        Assert.Equal(0, Coordinator.Run(new(), false, false));
        Assert.True(WorkerIsAdmin);
        Assert.Equal("waiting_panel", fake.State!.Status);
        Assert.DoesNotContain("secret", fake.Operations);
        Assert.Equal(0, Harden());
        Assert.False(WorkerIsAdmin);
    }

    [Fact]
    public void UnhardenRestoresSnapshotAndKeepsManagedAndBuiltinAdministrators()
    {
        Assert.Equal(0, Harden());
        fake.Operations.Clear();
        Assert.Equal(0, Coordinator.Run(new(), false, true, true));
        Assert.True(WorkerIsAdmin);
        Assert.False(fake.Accounts.Single(a => a.Sid == HardeningFake.Worker).User);
        Assert.True(fake.Accounts.Single(a => a.Sid == HardeningFake.Admin).Administrator);
        Assert.False(fake.Accounts.Single(a => a.Sid == HardeningFake.Builtin).Enabled);
        Assert.False(fake.Deny);
        Assert.Null(fake.Visibility);
        Assert.Equal("unhardened", fake.State!.Status);
        Assert.Equal(HardeningMode.Panel, fake.State.Mode);
        Assert.True(fake.Operations.IndexOf($"add:{HardeningFake.Worker}:{AccountSids.Administrators}") < fake.Operations.IndexOf("deny:False"));
        Assert.DoesNotContain("secret", fake.Operations);
        Assert.Equal(0, Coordinator.Run(new(), false, true, true));
    }

    [Fact]
    public void RollbackPreservesPreexistingPolicyAndVisibility()
    {
        fake.Deny = true;
        fake.Visibility = 1;
        Assert.Equal(0, Harden());
        Assert.Equal(0, Coordinator.Run(new(), false, true, true));
        Assert.True(fake.Deny);
        Assert.Equal(1, fake.Visibility);
    }

    [Theory]
    [InlineData(false)]
    [InlineData(true)]
    public void UnhardenSkipsDeletedTargetsAndRestoresRemainingAccounts(bool wasAdministrator)
    {
        const string second = "S-1-5-21-1-2-3-1003";
        fake.Accounts[0] = fake.Accounts[0] with { Administrator = wasAdministrator };
        fake.Accounts.Add(new("second", second, true, true, false));
        fake.Active = [HardeningFake.Worker, second];
        Assert.Equal(0, Harden());
        fake.Accounts.RemoveAll(a => a.Sid == HardeningFake.Worker);
        fake.Operations.Clear();

        Assert.Equal(0, Coordinator.Run(new(), false, false, true));

        Assert.DoesNotContain(fake.Operations, op => op.Contains(HardeningFake.Worker));
        Assert.True(fake.Accounts.Single(a => a.Sid == second).Administrator);
        Assert.False(fake.Accounts.Single(a => a.Sid == second).User);
        Assert.False(fake.Deny);
        Assert.Null(fake.Visibility);
        Assert.Equal("unhardened", fake.State!.Status);
        Assert.False(fake.State.RecoveryRequired);
        Assert.False(fake.LeaseHeld);
    }

    [Theory]
    [InlineData("promotion")]
    [InlineData("silent_promotion")]
    [InlineData("users")]
    [InlineData("deny")]
    [InlineData("inventory")]
    public void UnhardenContinuesCompensationAfterFailureAndCanRetry(string failure)
    {
        const string second = "S-1-5-21-1-2-3-1003";
        fake.Accounts.Add(new("second", second, true, true, false));
        fake.Active = [HardeningFake.Worker, second];
        fake.Visibility = 1;
        Assert.Equal(0, Harden());
        fake.Operations.Clear();
        fake.FailPromotion = failure == "promotion";
        fake.IgnoreOperation = failure == "silent_promotion" ? $"add:{HardeningFake.Worker}:{AccountSids.Administrators}" : null;
        fake.FailOnce = failure switch
        {
            "users" => $"remove:{HardeningFake.Worker}:{AccountSids.Users}",
            "deny" => "deny:False",
            "inventory" => "list",
            _ => null
        };

        Assert.Equal(1, Coordinator.Run(new(), false, true, true));

        Assert.Equal(1, fake.Visibility);
        Assert.False(fake.Accounts.Single(a => a.Sid == second).User);
        Assert.Equal(failure == "users", fake.Accounts.Single(a => a.Sid == HardeningFake.Worker).User);
        Assert.Equal(failure != "users", fake.Deny);
        if (failure != "inventory") Assert.True(fake.Accounts.Single(a => a.Sid == second).Administrator);
        Assert.True(fake.State!.RecoveryRequired);
        Assert.Equal("rollback_failed", fake.State.Status);
        Assert.Equal(2, fake.State.RestoreAdminSids.Length);
        fake.FailPromotion = false;
        fake.IgnoreOperation = null;
        Assert.Equal(0, Coordinator.Run(new(), false, true, true));
        Assert.False(fake.State!.RecoveryRequired);
        Assert.True(WorkerIsAdmin);
        Assert.False(fake.Deny);
    }

    [Fact]
    public void ExplicitRehardenAfterAutoFailureRestoresConfiguredAutoMode()
    {
        fake.FailOnce = "visibility:0";
        Assert.Equal(1, Harden(true));
        Assert.Equal(HardeningMode.Panel, fake.State!.Mode);
        Assert.Equal(0, Coordinator.Run(new() { HardeningMode = HardeningMode.Auto }, false, true));
        Assert.Equal(HardeningMode.Auto, fake.State!.Mode);
        Assert.Equal("hardened", fake.State.Status);
        Assert.False(fake.State.RecoveryRequired);
    }

    [Theory]
    [InlineData(false)]
    [InlineData(true)]
    public void CustomAdminNameProtectsManagedAccountButNotOldDefaultName(bool targetManagedAdmin)
    {
        fake.Accounts[0] = fake.Accounts[0] with { Name = "azcadmin" };
        fake.Active = [targetManagedAdmin ? HardeningFake.Admin : HardeningFake.Worker];
        Assert.Equal(targetManagedAdmin ? 1 : 0, Coordinator.Run(new() { AdminName = "recovery-admin" }, false, true));
        Assert.Equal(targetManagedAdmin, WorkerIsAdmin);
        Assert.True(fake.Accounts.Single(a => a.Sid == HardeningFake.Admin).Administrator);
        if (targetManagedAdmin) Assert.DoesNotContain(fake.Operations, op => op.StartsWith("remove:"));
    }

    [Theory]
    [InlineData(false, true, false)]
    [InlineData(false, false, true)]
    [InlineData(true, true, true)]
    public void LogoffIsRequiredOnlyForMembershipChanges(bool administrator, bool user, bool logoff)
    {
        fake.Accounts[0] = fake.Accounts[0] with { Administrator = administrator, User = user };
        Assert.Equal(0, Harden());
        Assert.Equal(logoff, fake.State!.LogoffRequired);
    }

    [Fact]
    public void PanelDryRunUnhardenWithoutExplicitCommandShowsUndoPlanWithoutAccess()
    {
        fake.ForbidAccess = true;
        Assert.Equal(0, Coordinator.Run(new() { AdminName = "recovery-admin" }, true, false, true));
        Assert.Contains(output, line => line.Contains("Restaurar Administradores"));
        Assert.Contains(output, line => line.Contains("recovery-admin permanece"));
        Assert.DoesNotContain(output, line => line.Contains("esperar comando"));
        Assert.Empty(fake.Operations);
    }

    [Fact]
    public void VisibilityUsesJournalNameForCaptureApplyAndRestoreIncludingCasing()
    {
        fake.Accounts.Add(new("Recovery-Admin", HardeningFake.Admin, true, true, false));
        fake.State = new() { AdminSid = HardeningFake.Admin, AdminName = "Recovery-Admin" };
        fake.Visibility = 1;
        var config = new HardeningConfig { AdminName = "recovery-admin" };
        Assert.Equal(0, Coordinator.Run(config, false, true));
        Assert.Equal(0, Coordinator.Run(config, false, true, true));
        Assert.Equal(1, fake.Visibility);
        Assert.NotEmpty(fake.VisibilityNames);
        Assert.All(fake.VisibilityNames, name => Assert.Equal(fake.State!.AdminName, name));
    }

    [Fact]
    public void BatchVerificationDetectsUnappliedDemotionAndRollsBack()
    {
        fake.IgnoreOperation = $"remove:{HardeningFake.Worker}:{AccountSids.Administrators}";
        Assert.Equal(1, Harden());
        Assert.True(WorkerIsAdmin);
        Assert.False(fake.State!.RecoveryRequired);
        Assert.Equal(4, fake.State.Step);
        Assert.False(fake.Deny);
    }

    [Fact]
    public void AccountSnapshotsDoNotScaleWithTargetCount()
    {
        Assert.Equal(0, Harden());
        var hardenLists = fake.Operations.Count(op => op == "list");
        fake.Operations.Clear();
        Assert.Equal(0, Coordinator.Run(new(), false, true, true));
        var undoLists = fake.Operations.Count(op => op == "list");
        fake.Accounts.Add(new("second", "S-1-5-21-1-2-3-1003", true, true, false));
        fake.Active = [HardeningFake.Worker, "S-1-5-21-1-2-3-1003"];
        fake.Operations.Clear();
        Assert.Equal(0, Harden());
        Assert.Equal(hardenLists, fake.Operations.Count(op => op == "list"));
        fake.Operations.Clear();
        Assert.Equal(0, Coordinator.Run(new(), false, true, true));
        Assert.Equal(undoLists, fake.Operations.Count(op => op == "list"));
    }

    [Theory]
    [InlineData(HardeningFake.Admin)]
    [InlineData(HardeningFake.Builtin)]
    public void ProtectedAccountsAreNeverDemoted(string sid)
    {
        fake.Active = [sid];
        Assert.Equal(1, Harden());
        Assert.DoesNotContain(fake.Operations, op => op.StartsWith("remove:"));
        Assert.True(WorkerIsAdmin);
    }

    [Fact]
    public void ExistingUnownedAdminNameIsNeverOverwritten()
    {
        fake.Accounts.Add(new("azcadmin", HardeningFake.Admin, true, true, false));
        Assert.Equal(1, Harden());
        Assert.DoesNotContain("set_password_api", fake.Operations);
        Assert.DoesNotContain("secret", fake.Operations);
        Assert.True(WorkerIsAdmin);
    }

    [Fact]
    public void EnabledBuiltinAdministratorAbortsWithoutChangingIt()
    {
        fake.Accounts[1] = fake.Accounts[1] with { Enabled = true };
        Assert.Equal(1, Harden());
        Assert.DoesNotContain("create_api", fake.Operations);
        Assert.True(fake.Accounts[1].Enabled);
    }

    [Theory]
    [InlineData(NoSessionTarget.EnrolledAccounts)]
    [InlineData(NoSessionTarget.LastConsoleUser)]
    public void NoSessionUsesConfiguredSidFallback(NoSessionTarget fallback)
    {
        fake.Active = [];
        Assert.Equal(0, Coordinator.Run(new() { NoSessionTarget = fallback,
            EnrolledAccountSids = [HardeningFake.Worker], LastConsoleUserSid = HardeningFake.Worker }, false, true));
        Assert.False(WorkerIsAdmin);
    }

    [Fact]
    public void MissingSessionAndFallbackAbortWithoutDemotionWhenOnlySessionsAreTargeted()
    {
        fake.Active = [];
        Assert.Equal(1, Coordinator.Run(new() { DemoteAllLocalAdmins = false }, false, true));
        Assert.True(WorkerIsAdmin);
        Assert.Equal(4, fake.State!.Step);
    }

    [Fact]
    public void LocalAdministratorsWithoutSessionAreDemotedByDefault()
    {
        fake.Active = [];
        Assert.Equal(0, Harden());
        Assert.False(WorkerIsAdmin);
        Assert.Equal(new[] { HardeningFake.Worker }, fake.State!.RestoreAdminSids);
        Assert.True(fake.State.LogoffRequired);
    }

    [Fact]
    public void OtherLocalAdministratorIsDemotedAlongsideTheSessionUser()
    {
        const string other = "S-1-5-21-1-2-3-1005";
        fake.Accounts.Add(new("otro-admin", other, true, true, true));
        Assert.Equal(0, Harden());
        Assert.False(WorkerIsAdmin);
        Assert.False(fake.Accounts.Single(a => a.Sid == other).Administrator);
        Assert.Equal(0, Coordinator.Run(new(), false, true, undo: true));
        Assert.True(WorkerIsAdmin);
        Assert.True(fake.Accounts.Single(a => a.Sid == other).Administrator);
    }

    [Fact]
    public void InterruptedOperationRequiresRollbackAndPreservesJournal()
    {
        Assert.Equal(0, Harden());
        fake.State = fake.State! with { Status = "running", RecoveryRequired = true };
        fake.Operations.Clear();
        Assert.Equal(1, Harden());
        Assert.DoesNotContain("secret", fake.Operations);
        Assert.Equal(0, Coordinator.Run(new(), false, true, true));
        Assert.True(WorkerIsAdmin);
    }

    [Fact]
    public void FailedCompensationReportsRecoveryRequiredAndCanRetryUnharden()
    {
        fake.FailOnce = "visibility:0";
        fake.FailPromotion = true;
        Assert.Equal(1, Harden(true));
        Assert.True(fake.State!.RecoveryRequired);
        Assert.True(fake.Accounts.Single(a => a.Sid == HardeningFake.Admin).Administrator);
        Assert.True(fake.Deny);
        fake.FailPromotion = false;
        Assert.Equal(0, Coordinator.Run(new(), false, true, true));
        Assert.True(WorkerIsAdmin);
        Assert.False(fake.State!.RecoveryRequired);
    }

    [Fact]
    public void PartialDemotionOfMultipleSessionsRestoresAllAndPreservesExistingUsersMembership()
    {
        const string second = "S-1-5-21-1-2-3-1003";
        fake.Accounts.Add(new("second", second, true, true, true));
        fake.Active = [HardeningFake.Worker, second, HardeningFake.Worker];
        fake.FailOnce = $"remove:{second}:{AccountSids.Administrators}";
        Assert.Equal(1, Harden(true));
        Assert.True(WorkerIsAdmin);
        Assert.True(fake.Accounts.Single(a => a.Sid == second).Administrator);
        Assert.True(fake.Accounts.Single(a => a.Sid == second).User);
        Assert.False(fake.Accounts.Single(a => a.Sid == HardeningFake.Worker).User);
        Assert.All(fake.OperationalAdmins, count => Assert.True(count >= 1));
        Assert.False(fake.State!.RecoveryRequired);
    }

    [Fact]
    public void DomainOrUnknownActiveSidDoesNotFallBackToEnrolledUser()
    {
        fake.Active = ["S-1-5-21-9-9-9-1001"];
        Assert.Equal(1, Coordinator.Run(new() { EnrolledAccountSids = [HardeningFake.Worker] }, false, true));
        Assert.True(WorkerIsAdmin);
        Assert.DoesNotContain(fake.Operations, op => op.StartsWith("remove:"));
    }

    [Fact]
    public void PasswordUsesPInvokeAndAccountAdapterHasNoProcessCalls()
    {
        var type = typeof(WindowsLocalAccounts);
        foreach (var entry in new[] { ("Create", "NetUserAdd"), ("SetPassword", "NetUserSetInfo") })
        {
            var native = type.GetMethod(entry.Item2, BindingFlags.NonPublic | BindingFlags.Static)!;
            Assert.Equal("netapi32.dll", native.GetCustomAttribute<DllImportAttribute>()!.Value);
            Assert.Contains(Calls(type.GetMethod(entry.Item1)!), called => called == native);
        }
        var calls = type.GetMethods(BindingFlags.Public | BindingFlags.NonPublic | BindingFlags.Instance | BindingFlags.Static | BindingFlags.DeclaredOnly)
            .SelectMany(Calls).ToArray();
        Assert.DoesNotContain(calls, call => call.DeclaringType?.Namespace == "System.Diagnostics");
        var logon = type.GetMethod("LogonUser", BindingFlags.NonPublic | BindingFlags.Static)!;
        Assert.Equal(typeof(IntPtr), logon.GetParameters()[2].ParameterType);
        Assert.Contains(calls, call => call.Name == "ZeroFreeGlobalAllocUnicode");
    }

    private static IEnumerable<MethodBase> Calls(MethodInfo method)
    {
        var code = method.GetMethodBody()?.GetILAsByteArray();
        if (code is null) yield break;
        var opcodes = typeof(OpCodes).GetFields(BindingFlags.Public | BindingFlags.Static)
            .Select(f => (OpCode)f.GetValue(null)!).ToDictionary(op => unchecked((ushort)op.Value));
        for (var offset = 0; offset < code.Length;)
        {
            ushort value = code[offset++];
            if (value == 0xfe) value = (ushort)(0xfe00 | code[offset++]);
            var op = opcodes[value];
            if (op.OperandType == OperandType.InlineMethod)
                yield return method.Module.ResolveMethod(BitConverter.ToInt32(code, offset))!;
            offset += op.OperandType switch
            {
                OperandType.InlineNone => 0,
                OperandType.ShortInlineBrTarget or OperandType.ShortInlineI or OperandType.ShortInlineVar => 1,
                OperandType.InlineVar => 2,
                OperandType.InlineI8 or OperandType.InlineR => 8,
                OperandType.InlineSwitch => 4 + 4 * BitConverter.ToInt32(code, offset),
                _ => 4
            };
        }
    }
}
