using Keeper.Agent.Modules.Diagnostics;
using Keeper.Agent.Modules.Security;
using Keeper.Shared.Contracts;
using Keeper.Shared.Protocol;

namespace Keeper.Agent.Tests;

public sealed class HardeningStatusTests
{
    [Theory]
    [InlineData("hardened", false, true, "pending_logoff", SecurityControlState.Unknown)]
    [InlineData("failed", false, false, "failed_step_3_panel", SecurityControlState.Failed)]
    [InlineData("failed", true, false, "recovery_required", SecurityControlState.Failed)]
    [InlineData("waiting_panel", false, false, "waiting_panel", SecurityControlState.Unknown)]
    [InlineData("unhardened", false, false, "unhardened", SecurityControlState.Unknown)]
    public async Task ProjectsJournalThroughExistingOutboxAndSecurityReport(string status, bool recovery, bool logoff,
        string expected, SecurityControlState controlState)
    {
        var state = new HardeningState { Status = status, Step = 3, RecoveryRequired = recovery, LogoffRequired = logoff };
        var module = new HardeningStatusModule(_ => Task.FromResult<HardeningState?>(state));
        var context = Samples.Context();
        await module.InitAsync(context);
        await module.TickAsync(default);
        await module.TickAsync(default);
        Assert.Equal(expected, module.Snapshot().State);
        var events = (MemoryEvents)context.Outbox;
        Assert.Equal($"hardening_{status}_step_3_mode_panel", Assert.Single(events.Logs).Code);
        var diagnostics = new AgentDiagnostics(() => [module.Snapshot()]);
        await diagnostics.InitAsync(context);
        await diagnostics.TickAsync(default);
        var control = Assert.Single(Assert.Single(events.SecurityReports).Controls);
        Assert.Equal("LocalAccountHardening", control.ControlId);
        Assert.Equal(controlState, control.State);
        Assert.Equal(expected, control.ErrorCode);
    }
}
