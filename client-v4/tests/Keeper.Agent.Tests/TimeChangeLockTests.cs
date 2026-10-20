using System.Text.Json;
using Keeper.Agent.Modules.Security;
using Keeper.Agent.Policy;
using Keeper.Shared.Contracts;
using Keeper.Shared.Protocol;
using Keeper.Shared.Time;

namespace Keeper.Agent.Tests;

// Bloqueo del cambio manual de la hora (agente 4.0.28, time_change_lock de la politica). La LSA es simulada: nunca se
// tocan los derechos del equipo que corre las pruebas.
public sealed class TimeChangeLockTests : IDisposable
{
    private const string Right = TimeChangeLock.Right;
    private readonly TestDirectory _dir = new();
    private string StatePath => _dir.File(AgentDataFiles.TimeChangeLock);

    public void Dispose() => _dir.Dispose();

    internal sealed class FakeRights : IAccountRights
    {
        // Lo que trae Windows 10/11 de fabrica: Administradores y LOCAL SERVICE; SYSTEM no aparece asignado.
        public HashSet<(string Sid, string Right)> Assigned { get; } =
            [(TimeChangeLock.Administrators, Right), (TimeChangeLock.LocalService, Right), (TimeChangeLock.Administrators, "SeTimeZonePrivilege"),
             ("S-1-5-32-545", "SeTimeZonePrivilege")];
        public List<string> Writes { get; } = [];
        public Exception? RemoveFailure { get; set; }
        public bool Has(string sid, string right) => Assigned.Contains((sid, right));
        public void Add(string sid, string right) { Writes.Add($"+{sid}"); Assigned.Add((sid, right)); }
        public void Remove(string sid, string right)
        {
            if (RemoveFailure is not null) throw RemoveFailure;
            Writes.Add($"-{sid}");
            Assigned.Remove((sid, right));
        }
    }

    private sealed class Harness
    {
        public TestClock Clock { get; } = new();
        public MemoryEvents Events { get; } = new();
        public FakeRights Rights { get; }
        public bool Domain { get; set; }
        public TimeChangeLockModule Module { get; }
        public Harness(string statePath, FakeRights? rights = null, bool dryRun = false)
        {
            Rights = rights ?? new FakeRights();
            Module = new TimeChangeLockModule(dryRun ? null : Rights, () => Domain, statePath);
        }
        public Task InitAsync() => Module.InitAsync(new ModuleContext(Events, Clock, _ => { }, CancellationToken.None));
        public Task ApplyAsync(bool? locked) => Module.ApplyPolicyAsync(Samples.Policy() with { TimeChangeLock = locked });
        public IEnumerable<(string Code, string? Detail, LogEntryLevel Level)> Logs =>
            Events.Logs.Select(l => (l.Code, l.Fields?.ErrorCode, l.Level));
        public string State => Module.Snapshot().State;
    }

    [Fact]
    public async Task LockRemovesTheRightFromAdministratorsKeepsItForSystemAndLocalServiceAndUnlockRestoresExactly()
    {
        var h = new Harness(StatePath);
        await h.InitAsync();
        await h.ApplyAsync(true);
        // SYSTEM no lo tenia asignado: se le da ANTES de quitarselo a Administradores.
        Assert.Equal(["+" + TimeChangeLock.LocalSystem, "-" + TimeChangeLock.Administrators], h.Rights.Writes);
        Assert.False(h.Rights.Has(TimeChangeLock.Administrators, Right));
        Assert.True(h.Rights.Has(TimeChangeLock.LocalSystem, Right));
        Assert.True(h.Rights.Has(TimeChangeLock.LocalService, Right));
        // La zona horaria sigue libre (SeTimeZonePrivilege no se toca).
        Assert.True(h.Rights.Has(TimeChangeLock.Administrators, "SeTimeZonePrivilege"));
        Assert.True(h.Rights.Has("S-1-5-32-545", "SeTimeZonePrivilege"));
        Assert.Equal([("time_change_locked", null, LogEntryLevel.Info)], h.Logs);
        Assert.Equal("applied", h.State);
        var state = JsonSerializer.Deserialize<TimeChangeLockState>(File.ReadAllBytes(StatePath))!;
        Assert.True(state.AdminsRemoved);
        Assert.Equal([TimeChangeLock.LocalSystem], state.Granted);

        // Apagar la opcion (la politica ya no trae el campo): se devuelve exactamente lo que se cambio.
        h.Rights.Writes.Clear();
        h.Events.Logs.Clear();
        await h.ApplyAsync(null);
        Assert.Equal(["+" + TimeChangeLock.Administrators, "-" + TimeChangeLock.LocalSystem], h.Rights.Writes);
        Assert.Equal(new FakeRights().Assigned, h.Rights.Assigned);
        Assert.False(File.Exists(StatePath));
        Assert.Equal([("time_change_unlocked", "politica", LogEntryLevel.Info)], h.Logs);
        Assert.Equal("ready", h.State);
    }

    [Fact]
    public async Task IdempotentRepeatedPoliciesAndTicksDoNotTouchTheLsaAgain()
    {
        var h = new Harness(StatePath);
        await h.InitAsync();
        await h.ApplyAsync(true);
        h.Rights.Writes.Clear();
        h.Events.Logs.Clear();
        await h.ApplyAsync(true);
        h.Clock.Advance(TimeChangeLockModule.CheckInterval);
        await h.Module.TickAsync(default);
        Assert.Empty(h.Rights.Writes);
        Assert.Empty(h.Logs);
        // Apagado y sin nada que devolver: tampoco hace nada.
        await h.ApplyAsync(false);
        h.Rights.Writes.Clear(); h.Events.Logs.Clear();
        await h.ApplyAsync(false);
        await h.ApplyAsync(null);
        Assert.Empty(h.Rights.Writes);
        Assert.Empty(h.Logs);
    }

    [Fact]
    public async Task OffByDefaultAndNothingHappensBeforeAPolicyArrives()
    {
        var h = new Harness(StatePath);
        await h.InitAsync();
        h.Clock.Advance(TimeChangeLockModule.CheckInterval * 3);
        await h.Module.TickAsync(default);
        await h.ApplyAsync(null);
        Assert.Empty(h.Rights.Writes);
        Assert.Empty(h.Logs);
        Assert.False(File.Exists(StatePath));
        Assert.Equal("ready", h.State);
    }

    [Fact]
    public async Task NeverInADomainAndJoiningADomainGivesTheRightBack()
    {
        var domain = new Harness(StatePath) { Domain = true };
        await domain.InitAsync();
        await domain.ApplyAsync(true);
        Assert.Empty(domain.Rights.Writes);
        Assert.Empty(domain.Logs);
        Assert.Equal("domain", domain.State);
        Assert.False(File.Exists(StatePath));

        var h = new Harness(StatePath);
        await h.InitAsync();
        await h.ApplyAsync(true);
        h.Rights.Writes.Clear(); h.Events.Logs.Clear();
        h.Domain = true;
        h.Clock.Advance(TimeChangeLockModule.CheckInterval);
        await h.Module.TickAsync(default);
        Assert.True(h.Rights.Has(TimeChangeLock.Administrators, Right));
        Assert.Equal([("time_change_unlocked", "dominio", LogEntryLevel.Info)], h.Logs);
        Assert.Equal("domain", h.State);
    }

    [Fact]
    public async Task AdministratorsRegainingTheRightIsRemovedAgainOnTheNextCheck()
    {
        var h = new Harness(StatePath);
        await h.InitAsync();
        await h.ApplyAsync(true);
        h.Rights.Writes.Clear(); h.Events.Logs.Clear();
        h.Rights.Add(TimeChangeLock.Administrators, Right); // alguien lo devolvio con secpol.msc
        h.Rights.Writes.Clear();
        h.Clock.Advance(TimeSpan.FromMinutes(5));
        await h.Module.TickAsync(default);
        Assert.Empty(h.Rights.Writes); // aun no toca revisar
        h.Clock.Advance(TimeSpan.FromMinutes(5));
        await h.Module.TickAsync(default);
        Assert.Equal(["-" + TimeChangeLock.Administrators], h.Rights.Writes);
        Assert.Equal([("time_change_locked", "reaplicado", LogEntryLevel.Info)], h.Logs);
    }

    [Fact]
    public async Task StatePersistsAcrossServiceRestartsAndUnlockAfterRestartRestoresExactly()
    {
        var rights = new FakeRights();
        var first = new Harness(StatePath, rights);
        await first.InitAsync();
        await first.ApplyAsync(true);

        var second = new Harness(StatePath, rights);
        await second.InitAsync();
        Assert.Equal("applied", second.State);
        rights.Writes.Clear();
        await second.ApplyAsync(false);
        Assert.Equal(new FakeRights().Assigned, rights.Assigned);
        Assert.False(File.Exists(StatePath));
    }

    [Fact]
    public async Task AdministratorsWithoutTheRightBeforeTheLockDoNotGetItOnUnlockAndKeepersAlreadyAssignedAreNotTouched()
    {
        var rights = new FakeRights();
        rights.Assigned.Remove((TimeChangeLock.Administrators, Right));
        rights.Assigned.Add((TimeChangeLock.LocalSystem, Right));
        var h = new Harness(StatePath, rights);
        await h.InitAsync();
        await h.ApplyAsync(true);
        Assert.Empty(rights.Writes);
        Assert.True(File.Exists(StatePath)); // queda constancia de que esta encendido, sin nada que devolver
        await h.ApplyAsync(null);
        Assert.Empty(rights.Writes);
        Assert.False(rights.Has(TimeChangeLock.Administrators, Right));
        Assert.True(rights.Has(TimeChangeLock.LocalSystem, Right));
    }

    [Fact]
    public async Task FailureMidwayIsReportedOnceAndTheSavedIntentStillAllowsAFullRevert()
    {
        var h = new Harness(StatePath);
        h.Rights.RemoveFailure = new System.ComponentModel.Win32Exception(5);
        await h.InitAsync();
        await h.ApplyAsync(true);
        h.Clock.Advance(TimeChangeLockModule.CheckInterval);
        await h.Module.TickAsync(default);
        Assert.Equal([("time_change_lock_failed", "Win32Exception_5", LogEntryLevel.Warn)], h.Logs);
        Assert.Equal("failed", h.State);
        Assert.Equal("Win32Exception_5", h.Module.Snapshot().ErrorCode);
        // El registro se guardo antes de cada cambio: apagar devuelve lo que pudo haberse cambiado.
        h.Rights.RemoveFailure = null;
        await h.ApplyAsync(null);
        Assert.Equal(new FakeRights().Assigned, h.Rights.Assigned);
        Assert.False(File.Exists(StatePath));
    }

    [Fact]
    public async Task DryRunNeverTouchesTheLsa()
    {
        var h = new Harness(StatePath, dryRun: true);
        await h.InitAsync();
        await h.ApplyAsync(true);
        Assert.Empty(h.Rights.Writes);
        Assert.Equal("dry_run", h.State);
    }

    [Fact]
    public async Task CoordinatorDeliversTheFieldAndPoliciesWithoutItStillApply()
    {
        // Politica de un servidor que no manda el campo (o agente con politica en cache de antes): TimeChangeLock null.
        var json = JsonSerializer.Serialize(Samples.Policy(), ProtocolJson.Options);
        Assert.DoesNotContain("time_change_lock", json);
        Assert.Null(JsonSerializer.Deserialize<EffectivePolicy>(json, ProtocolJson.Options)!.TimeChangeLock);
        var locked = JsonSerializer.Deserialize<EffectivePolicy>(json.TrimEnd('}') + ",\"time_change_lock\":true}", ProtocolJson.Options)!;
        Assert.True(locked.TimeChangeLock);

        var h = new Harness(StatePath);
        await using var host = new Keeper.Agent.Hosting.ModuleHost([h.Module], new ModuleContext(h.Events, h.Clock, _ => { }, CancellationToken.None));
        await host.InitAsync();
        var coordinator = new PolicyCoordinator(new MemoryPolicyStore(), host, Samples.Device);
        Assert.True(await coordinator.ApplyAsync(1, locked, CancellationToken.None));
        Assert.False(h.Rights.Has(TimeChangeLock.Administrators, Right));
        Assert.True(await coordinator.ApplyAsync(2, locked with { TimeChangeLock = null }, CancellationToken.None));
        Assert.True(h.Rights.Has(TimeChangeLock.Administrators, Right));
    }
}
