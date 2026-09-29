using Keeper.Agent.Modules.Diagnostics;
using Keeper.Agent.Modules.Enforcement;
using Keeper.Shared.Contracts;
using Keeper.Shared.Protocol;

namespace Keeper.Agent.Tests;

public sealed class AgentDiagnosticsTests
{
    [Theory]
    [InlineData("applied", SecurityControlState.Applied)]
    [InlineData("ready", SecurityControlState.Applied)]
    [InlineData("locked", SecurityControlState.Applied)]
    [InlineData("unlocked", SecurityControlState.Applied)]
    [InlineData("no_interactive_session", SecurityControlState.Applied)]
    [InlineData("current", SecurityControlState.Applied)]   // UpdateManager al dia: no debe degradar la salud
    [InlineData("dry_run", SecurityControlState.Failed)]
    [InlineData("pending_restart", SecurityControlState.Failed)]
    [InlineData("failed", SecurityControlState.Failed)]
    [InlineData("degraded", SecurityControlState.Failed)]
    [InlineData("unsupported", SecurityControlState.Unsupported)]
    [InlineData("audit", SecurityControlState.Unknown)]
    [InlineData("awaiting_package", SecurityControlState.Unknown)]
    [InlineData("verified_pending_install", SecurityControlState.Unknown)]
    [InlineData("unknown", SecurityControlState.Unknown)]
    [InlineData("future_state", SecurityControlState.Unknown)]
    public async Task MapsModuleStatesAndOnlyReportsReadyForAppliedControls(string state, SecurityControlState expected)
    {
        var context = Samples.Context(new TestClock());
        var module = new AgentDiagnostics(() => [new("control", null, null, state)]);
        await module.InitAsync(context);
        await module.TickAsync(default);
        var report = Assert.Single(((MemoryEvents)context.Outbox).SecurityReports);
        var control = Assert.Single(report.Controls);
        Assert.Equal(expected, control.State);
        Assert.Equal(expected == SecurityControlState.Applied ? "ready" : "degraded", module.Snapshot().State);
        Assert.Equal(expected == SecurityControlState.Applied ? null : state, control.ErrorCode);
    }

    [Theory]
    [InlineData(false)]
    [InlineData(true)]
    public async Task RegistryWriteAndDryRunReachServerWithAccurateHealth(bool dryRun)
    {
        var context = Samples.Context(new TestClock());
        var enforcer = new UsbEnforcer(new MemorySystemPolicyStore { IsDryRun = dryRun });
        await enforcer.InitAsync(context);
        await enforcer.ApplyPolicyAsync(Samples.Policy(Samples.Rule(RuleKind.Usb, RuleEffect.Deny, "*")));
        var diagnostics = new AgentDiagnostics(() => [enforcer.Snapshot()]);
        await diagnostics.InitAsync(context);
        await diagnostics.TickAsync(default);
        var control = Assert.Single(Assert.Single(((MemoryEvents)context.Outbox).SecurityReports).Controls);
        Assert.Equal(dryRun ? SecurityControlState.Failed : SecurityControlState.Applied, control.State);
        Assert.Equal(dryRun ? "degraded" : "ready", diagnostics.Snapshot().State);
        Assert.Equal(dryRun ? null : "opaque-v1", enforcer.Snapshot().AppliedVersion);
    }

    [Fact]
    public async Task SinCambiosNoRepiteLogYElReporteSaleSoloComoSenalDeVidaCadaHora()
    {
        // Antes: log "degraded" y SecurityReport cada 5 min aunque nada cambiara (288 filas/dia por equipo).
        var clock = new TestClock();
        var context = Samples.Context(clock);
        var snapshot = new ModuleSnapshot("control", null, null, "unknown", "unhardened");
        var diagnostics = new AgentDiagnostics(() => [snapshot]);
        await diagnostics.InitAsync(context);
        var events = (MemoryEvents)context.Outbox;
        await diagnostics.TickAsync(default);
        Assert.Single(events.SecurityReports);
        Assert.Single(events.Logs);
        for (var i = 0; i < 11; i++) { clock.Advance(TimeSpan.FromMinutes(5)); await diagnostics.TickAsync(default); }   // 55 min
        Assert.Single(events.SecurityReports);
        Assert.Single(events.Logs);
        clock.Advance(TimeSpan.FromMinutes(5)); await diagnostics.TickAsync(default);   // 60 min: senal de vida
        Assert.Equal(2, events.SecurityReports.Count);
        Assert.Single(events.Logs);
        snapshot = snapshot with { State = "applied", ErrorCode = null };
        clock.Advance(TimeSpan.FromMinutes(5)); await diagnostics.TickAsync(default);   // cambio: reporte y log
        Assert.Equal(3, events.SecurityReports.Count);
        Assert.Equal(2, events.Logs.Count);
        Assert.Equal("ready", events.Logs[^1].Code);
    }

    [Fact]
    public async Task ThrottlePreservesErrorsAndRecoveryDoesNotLatchOwnDegradedState()
    {
        var clock = new TestClock();
        var context = Samples.Context(clock);
        var snapshot = new ModuleSnapshot("control", null, null, "failed", "write_denied");
        AgentDiagnostics? diagnostics = null;
        diagnostics = new AgentDiagnostics(() => [snapshot, diagnostics!.Snapshot()]);
        await diagnostics.InitAsync(context);
        await diagnostics.TickAsync(default);
        var events = (MemoryEvents)context.Outbox;
        Assert.Equal("write_denied", Assert.Single(Assert.Single(events.SecurityReports).Controls).ErrorCode);
        snapshot = snapshot with { State = "applied", ErrorCode = null };
        await diagnostics.TickAsync(default);
        Assert.Single(events.SecurityReports);
        clock.Advance(TimeSpan.FromMinutes(5));
        await diagnostics.TickAsync(default);
        Assert.Equal("ready", diagnostics.Snapshot().State);
        Assert.Equal(2, events.SecurityReports.Count);
    }
}
