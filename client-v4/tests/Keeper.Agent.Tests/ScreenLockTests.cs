extern alias session;
using ScreenLockController = session::Keeper.Session.Modules.UI.ScreenLockController;
using ILockKeyboard = session::Keeper.Session.Modules.UI.ILockKeyboard;
using ILockWindows = session::Keeper.Session.Modules.UI.ILockWindows;
using LockKeyFilter = session::Keeper.Session.Modules.UI.LockKeyFilter;
using Keeper.Agent.Modules.Security;
using Keeper.Agent.Modules.Diagnostics;
using Keeper.Shared.Contracts;
using Keeper.Shared.Protocol;
using Microsoft.Win32;

namespace Keeper.Agent.Tests;

public sealed class ScreenLockTests
{
    [Fact]
    public void LockUnlockAndPinChangesKeepHookUntilUnlockAndAreIdempotent()
    {
        var calls = new List<string>();
        var keyboard = new FakeKeyboard(calls);
        var windows = new FakeWindows(calls);
        using var screen = new ScreenLockController(keyboard, windows);
        screen.SetState(false, false);
        Assert.Empty(calls);
        screen.SetState(true, false);
        screen.SetState(true, false);
        screen.SetState(true, true);
        Assert.True(screen.Locked);
        Assert.Equal(new[] { "hook.install", "windows.show:False", "windows.show:True" }, calls);
        screen.SetState(false, false);
        screen.SetState(false, false);
        screen.Dispose();
        Assert.False(screen.Locked);
        Assert.False(keyboard.Installed);
        Assert.Equal(new[] { "hook.install", "windows.show:False", "windows.show:True", "hook.remove", "windows.close" }, calls);
        screen.SetState(true, false);
        Assert.True(keyboard.Installed);
    }

    [Theory]
    [InlineData("hook")]
    [InlineData("window")]
    [InlineData("pin")]
    public void ExceptionDuringLockAlwaysRemovesHookAndClosesPartialWindows(string stage)
    {
        var calls = new List<string>();
        var keyboard = new FakeKeyboard(calls);
        var windows = new FakeWindows(calls);
        using var screen = new ScreenLockController(keyboard, windows);
        if (stage == "pin") screen.SetState(true, false);
        keyboard.FailInstall = stage == "hook";
        windows.FailShow = stage != "hook";
        Assert.Throws<IOException>(() => screen.SetState(true, true));
        Assert.False(screen.Locked);
        Assert.False(keyboard.Installed);
        Assert.Equal(new[] { "hook.remove", "windows.close" }, calls.TakeLast(2));
    }

    [Fact]
    public void WindowCloseExceptionCannotLeaveHookInstalled()
    {
        var calls = new List<string>();
        var keyboard = new FakeKeyboard(calls);
        var windows = new FakeWindows(calls) { FailClose = true };
        var screen = new ScreenLockController(keyboard, windows);
        screen.SetState(true, false);
        Assert.Throws<IOException>(() => screen.Dispose());
        Assert.False(keyboard.Installed);
        windows.FailClose = false;
        screen.Dispose();
    }

    [Theory]
    [InlineData(0x5B, false, false, true)]
    [InlineData(0x5C, false, false, true)]
    [InlineData(0x09, true, false, true)]
    [InlineData(0x1B, false, true, true)]
    [InlineData(0x73, true, false, true)]
    [InlineData(0x1B, true, false, true)]
    [InlineData(0x2E, true, true, false)] // Ctrl+Alt+Supr pertenece al escritorio seguro.
    [InlineData(0x09, false, false, false)]
    [InlineData(0x73, false, false, false)]
    [InlineData(0x31, false, false, false)]
    [InlineData(0x0D, false, false, false)]
    public void FilterBlocksEscapeShortcutsButAllowsPinAndTab(int key, bool alt, bool control, bool expected)
    {
        var filter = new LockKeyFilter();
        Assert.Equal(expected, filter.Suppress(key, false, alt, control));
        Assert.Equal(expected, filter.Suppress(key, true, alt, control));
    }

    [Fact]
    public void SuppressedKeyRemainsSuppressedThroughRepeatAndReleaseAfterModifierReleased()
    {
        var filter = new LockKeyFilter();
        Assert.True(filter.Suppress(0x09, false, true, false));
        Assert.True(filter.Suppress(0x09, false, false, false));
        Assert.True(filter.Suppress(0x09, true, false, false));
        Assert.False(filter.Suppress(0x09, false, false, false));
    }

    public static IEnumerable<object?[]> RegistryValues()
    {
        yield return [null];
        yield return [new LockRegistryValue(RegistryValueKind.DWord, 0)];
        yield return [new LockRegistryValue(RegistryValueKind.DWord, 1)];
        yield return [new LockRegistryValue(RegistryValueKind.DWord, 7)];
        yield return [new LockRegistryValue(RegistryValueKind.QWord, long.MaxValue)];
        yield return [new LockRegistryValue(RegistryValueKind.String, "original")];
        yield return [new LockRegistryValue(RegistryValueKind.ExpandString, "%TEMP%\\original")];
        yield return [new LockRegistryValue(RegistryValueKind.MultiString, new[] { "uno", "dos" })];
        yield return [new LockRegistryValue(RegistryValueKind.Binary, new byte[] { 0, 255, 8 })];
        yield return [new LockRegistryValue(RegistryValueKind.None, new byte[] { 4, 0 })];
    }

    [Theory]
    [MemberData(nameof(RegistryValues))]
    public void RegistryRestoresExactKindAndValueOrOriginalAbsenceAcrossRestart(LockRegistryValue? original)
    {
        var registry = new FakeRegistry();
        if (original is not null) registry.Write(TaskManagerPolicy.PolicyPath, TaskManagerPolicy.ValueName, original);
        registry.Write(TaskManagerPolicy.PolicyPath, "OtherPolicy", new(RegistryValueKind.DWord, 42));
        var policy = new TaskManagerPolicy(registry);
        policy.Disable();
        var writes = registry.Mutations.Count;
        policy.Disable();
        Assert.Equal(writes, registry.Mutations.Count);
        Assert.Equal(new(RegistryValueKind.DWord, 1), registry.Read(TaskManagerPolicy.PolicyPath, TaskManagerPolicy.ValueName));
        var restarted = new TaskManagerPolicy(registry);
        restarted.Disable();
        restarted.Restore();
        Assert.Equal(original, registry.Read(TaskManagerPolicy.PolicyPath, TaskManagerPolicy.ValueName));
        Assert.Equal(42, registry.Read(TaskManagerPolicy.PolicyPath, "OtherPolicy")!.Data);
        writes = registry.Mutations.Count;
        restarted.Restore();
        Assert.Equal(writes, registry.Mutations.Count);
        restarted.Disable();
        restarted.Restore();
        Assert.Equal(original, registry.Read(TaskManagerPolicy.PolicyPath, TaskManagerPolicy.ValueName));
    }

    [Fact]
    public void BackupIsFlushedBeforeDisableAndPreservedWhenRestoreFails()
    {
        var registry = new FakeRegistry();
        var policy = new TaskManagerPolicy(registry);
        policy.Disable();
        Assert.True(registry.Mutations.IndexOf("flush:" + TaskManagerPolicy.BackupPath) <
            registry.Mutations.IndexOf("write:" + TaskManagerPolicy.PolicyPath + ":DisableTaskMgr"));
        registry.FailPolicyWrites = true;
        Assert.Throws<IOException>(() => policy.Restore());
        Assert.NotNull(registry.Read(TaskManagerPolicy.BackupPath, "Saved"));
        registry.FailPolicyWrites = false;
        policy.Restore();
        Assert.Null(registry.Read(TaskManagerPolicy.PolicyPath, TaskManagerPolicy.ValueName));
        Assert.Null(registry.Read(TaskManagerPolicy.BackupPath, "Saved"));
    }

    [Fact]
    public void FailedBackupNeverChangesPolicyAndFailedDisableCanBeRestored()
    {
        var registry = new FakeRegistry { FailBackupFlush = true };
        var policy = new TaskManagerPolicy(registry);
        Assert.Throws<IOException>(() => policy.Disable());
        Assert.Null(registry.Read(TaskManagerPolicy.PolicyPath, TaskManagerPolicy.ValueName));
        registry.FailBackupFlush = false;
        registry.FailPolicyWrites = true;
        Assert.Throws<IOException>(() => policy.Disable());
        registry.FailPolicyWrites = false;
        policy.Restore();
        Assert.Null(registry.Read(TaskManagerPolicy.PolicyPath, TaskManagerPolicy.ValueName));
    }

    [Fact]
    public void LoadedUserReconciliationHandlesLogoffNewSessionDuplicateSidAndUnlockWithoutPeer()
    {
        var first = new FakeRegistry();
        var second = new FakeRegistry();
        second.Write(TaskManagerPolicy.PolicyPath, TaskManagerPolicy.ValueName, new(RegistryValueKind.DWord, 7));
        var users = new Dictionary<string, ILockRegistry> { ["user1"] = first, ["user2"] = second };
        IReadOnlyList<string> loaded = ["user1", "user2"];
        var policies = new SessionTaskManagerPolicies(() => loaded, sid => users[sid]);
        policies.Reconcile(["user1", "user1"], true);
        Assert.Equal(7, second.Read(TaskManagerPolicy.PolicyPath, TaskManagerPolicy.ValueName)!.Data);
        loaded = [];
        policies.Reconcile([], true);
        loaded = ["user1", "user2"];
        policies.Reconcile(["user1", "user2"], true);
        policies.Reconcile([], false);
        Assert.Null(first.Read(TaskManagerPolicy.PolicyPath, TaskManagerPolicy.ValueName));
        Assert.Equal(7, second.Read(TaskManagerPolicy.PolicyPath, TaskManagerPolicy.ValueName)!.Data);
    }

    [Fact]
    public async Task PersistedDeviceLockReportsBloqueadoAndUnlockSurvivesServiceRestart()
    {
        using var directory = new TestDirectory();
        var clock = new TestClock();
        var context = Samples.Context(clock);
        var device = new DeviceLock(directory.File("lock"));
        await device.InitAsync(context);
        await device.SetLockedAsync(true, default);
        await device.ShutdownAsync();
        var restarted = new DeviceLock(directory.File("lock"));
        await restarted.InitAsync(context);
        Assert.True(restarted.Locked);
        var diagnostics = new AgentDiagnostics(() => [restarted.Snapshot()]);
        await diagnostics.InitAsync(context);
        await diagnostics.TickAsync(default);
        var events = (MemoryEvents)context.Outbox;
        var report = Assert.Single(Assert.Single(events.SecurityReports).Controls);
        Assert.Equal(SecurityControlState.Applied, report.State);
        Assert.Equal("Bloqueado", report.ErrorCode);
        await restarted.SetLockedAsync(false, default);
        clock.Advance(TimeSpan.FromMinutes(5));
        await diagnostics.TickAsync(default);
        Assert.Equal("Desbloqueado", Assert.Single(events.SecurityReports[^1].Controls).ErrorCode);
        await restarted.ShutdownAsync();
        var unlocked = new DeviceLock(directory.File("lock"));
        await unlocked.InitAsync(context);
        Assert.False(unlocked.Locked);
        await unlocked.ShutdownAsync();
    }

    [Fact]
    public void RestoreFailureIsReportedEvenWithoutPeerAndOtherUsersStillRestore()
    {
        var first = new FakeRegistry();
        var second = new FakeRegistry();
        var policies = new SessionTaskManagerPolicies(() => ["first", "second"], sid => sid == "first" ? first : second);
        policies.Reconcile(["first", "second"], true);
        first.FailPolicyWrites = true;
        Assert.Throws<AggregateException>(() => policies.Reconcile([], false));
        Assert.NotNull(first.Read(TaskManagerPolicy.BackupPath, "Saved"));
        Assert.Null(second.Read(TaskManagerPolicy.PolicyPath, TaskManagerPolicy.ValueName));
        first.FailPolicyWrites = false;
        policies.Reconcile([], false);
        Assert.Null(first.Read(TaskManagerPolicy.PolicyPath, TaskManagerPolicy.ValueName));
    }
    private sealed class FakeKeyboard(List<string> calls) : ILockKeyboard
    {
        public bool Installed { get; private set; }
        public bool FailInstall { get; set; }
        public void Install()
        {
            calls.Add("hook.install"); Installed = true;
            if (FailInstall) throw new IOException("hook_failed");
        }
        public void Remove() { calls.Add("hook.remove"); Installed = false; }
    }

    private sealed class FakeWindows(List<string> calls) : ILockWindows
    {
        public bool FailShow { get; set; }
        public bool FailClose { get; set; }
        public void Show(bool pinAllowed)
        {
            calls.Add("windows.show:" + pinAllowed);
            if (FailShow) throw new IOException("window_failed");
        }
        public void Close()
        {
            calls.Add("windows.close");
            if (FailClose) throw new IOException("close_failed");
        }
    }

    private sealed class FakeRegistry : ILockRegistry
    {
        private readonly Dictionary<(string Path, string Name), LockRegistryValue> _values = [];
        public List<string> Mutations { get; } = [];
        public bool FailPolicyWrites { get; set; }
        public bool FailBackupFlush { get; set; }
        public LockRegistryValue? Read(string path, string name) => _values.GetValueOrDefault((path, name));
        public void Write(string path, string name, LockRegistryValue value)
        {
            if (FailPolicyWrites && path == TaskManagerPolicy.PolicyPath) throw new IOException("write_denied");
            Mutations.Add("write:" + path + ":" + name); _values[(path, name)] = value;
        }
        public void Delete(string path, string name)
        {
            if (FailPolicyWrites && path == TaskManagerPolicy.PolicyPath) throw new IOException("delete_denied");
            Mutations.Add("delete:" + path + ":" + name); _values.Remove((path, name));
        }
        public void Flush(string path)
        {
            if (FailBackupFlush && path == TaskManagerPolicy.BackupPath) throw new IOException("flush_failed");
            Mutations.Add("flush:" + path);
        }
    }
}
