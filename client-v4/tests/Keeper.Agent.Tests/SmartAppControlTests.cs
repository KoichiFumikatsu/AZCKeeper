using System.ComponentModel;
using System.Security.Cryptography;
using System.Text;
using System.Text.Json;
using Keeper.Agent.Modules.Devices;
using Keeper.Agent.Modules.Security;
using Keeper.Agent.Modules.Update;
using Keeper.Shared.Protocol;

namespace Keeper.Agent.Tests;

// 4.0.19: Smart App Control (inventario e interruptor por cliente) y fallos al lanzar el instalador de la actualizacion.
public sealed class SmartAppControlTests
{
    private sealed class FakeSacRegistry(int? value) : ISmartAppControlRegistry
    {
        public int? Value = value;
        public int Writes;
        public Exception? ReadFailure, WriteFailure;
        public bool IgnoreWrite;
        public int? Read() => ReadFailure is null ? Value : throw ReadFailure;
        public void Disable()
        {
            Writes++;
            if (WriteFailure is not null) throw WriteFailure;
            if (!IgnoreWrite) Value = 0;
        }
    }

    private static WindowsSecurityDirective Directive(bool disable) => new() { DisableSmartAppControl = disable };

    [Theory]
    [InlineData(0, DeviceInventorySmartAppControl.Off, "off")]
    [InlineData(1, DeviceInventorySmartAppControl.On, "on")]
    [InlineData(2, DeviceInventorySmartAppControl.Evaluation, "evaluation")]
    [InlineData(7, DeviceInventorySmartAppControl.Unknown, "unknown")]
    [InlineData(null, DeviceInventorySmartAppControl.Unknown, "unknown")]
    public async Task InventarioReportaSmartAppControl(int? value, DeviceInventorySmartAppControl expected, string json)
    {
        DeviceInventory? published = null;
        var inventory = new Inventory((i, _) => { published = i; return Task.CompletedTask; }, new FakeSacRegistry(value));
        await inventory.InitAsync(Samples.Context(new TestClock()));
        Assert.Equal(expected, published!.SmartAppControl);
        Assert.Contains($"\"smart_app_control\":\"{json}\"", JsonSerializer.Serialize(published, ProtocolJson.Options));
    }

    [Fact]
    public async Task InventarioConLectorQueFallaReportaUnknownSinRomper()
    {
        DeviceInventory? published = null;
        var inventory = new Inventory((i, _) => { published = i; return Task.CompletedTask; },
            new FakeSacRegistry(1) { ReadFailure = new UnauthorizedAccessException() });
        await inventory.InitAsync(Samples.Context(new TestClock()));
        Assert.Equal(DeviceInventorySmartAppControl.Unknown, published!.SmartAppControl);
    }

    [Theory]
    [InlineData(1)]
    [InlineData(2)]
    public async Task ConInterruptorActivoLoApagaYReportaUnaSolaVez(int initial)
    {
        var registry = new FakeSacRegistry(initial);
        var context = Samples.Context(new TestClock());
        var sac = new SmartAppControl(registry);
        await sac.InitAsync(context);
        for (var i = 0; i < 3; i++) await sac.TryApplyAsync(Directive(true), default);
        Assert.Equal(0, registry.Value);
        Assert.Equal(1, registry.Writes);
        Assert.Equal("applied", sac.Snapshot().State);
        var log = Assert.Single(((MemoryEvents)context.Outbox).Logs);
        Assert.Equal(("SmartAppControl", "smart_app_control_disabled", LogEntryLevel.Info), (log.Component, log.Code, log.Level));
        Assert.Null(log.Fields);
    }

    [Theory]
    [InlineData(false, 1)]
    [InlineData(false, 2)]
    [InlineData(false, 0)]   // nunca reactivar: con false no se toca un equipo ya apagado
    [InlineData(true, 0)]
    [InlineData(true, null)]
    public async Task SinInterruptorOYaApagadoNoTocaNada(bool disable, int? initial)
    {
        var registry = new FakeSacRegistry(initial);
        var context = Samples.Context(new TestClock());
        var sac = new SmartAppControl(registry);
        await sac.InitAsync(context);
        await sac.TryApplyAsync(Directive(disable), default);
        await sac.TryApplyAsync(null, default);   // servidor anterior o sin campo
        Assert.Equal(0, registry.Writes);
        Assert.Equal(initial, registry.Value);
        Assert.Empty(((MemoryEvents)context.Outbox).Logs);
    }

    [Fact]
    public async Task DryRunNoEscribe()
    {
        var context = Samples.Context(new TestClock());
        var sac = new SmartAppControl(null);
        await sac.InitAsync(context);
        await sac.TryApplyAsync(Directive(true), default);
        Assert.Empty(((MemoryEvents)context.Outbox).Logs);
    }

    [Fact]
    public async Task FalloAlEscribirSeReportaUnaVezConElTipoDeError()
    {
        var registry = new FakeSacRegistry(1) { WriteFailure = new UnauthorizedAccessException() };
        var context = Samples.Context(new TestClock());
        var sac = new SmartAppControl(registry);
        await sac.InitAsync(context);
        for (var i = 0; i < 3; i++) await sac.TryApplyAsync(Directive(true), default);
        Assert.Equal(3, registry.Writes);
        var log = Assert.Single(((MemoryEvents)context.Outbox).Logs);
        Assert.Equal(("smart_app_control_disable_failed", LogEntryLevel.Warn, "UnauthorizedAccessException"), (log.Code, log.Level, log.Fields?.ErrorCode));
        Assert.Equal(("failed", "UnauthorizedAccessException"), (sac.Snapshot().State, sac.Snapshot().ErrorCode));
        registry.WriteFailure = null;
        await sac.TryApplyAsync(Directive(true), default);
        Assert.Equal("smart_app_control_disabled", ((MemoryEvents)context.Outbox).Logs[^1].Code);
        Assert.Equal(2, ((MemoryEvents)context.Outbox).Logs.Count);
        Assert.Equal(("applied", (string?)null), (sac.Snapshot().State, sac.Snapshot().ErrorCode));
    }

    [Fact]
    public async Task EscrituraQueNoQuedaSeReportaComoFalloSinRepetir()
    {
        var registry = new FakeSacRegistry(1) { IgnoreWrite = true };
        var context = Samples.Context(new TestClock());
        var sac = new SmartAppControl(registry);
        await sac.InitAsync(context);
        await sac.TryApplyAsync(Directive(true), default);
        await sac.TryApplyAsync(Directive(true), default);
        var log = Assert.Single(((MemoryEvents)context.Outbox).Logs);
        Assert.Equal(("smart_app_control_disable_failed", "value_not_persisted"), (log.Code, log.Fields?.ErrorCode));
    }

    [Fact]
    public async Task FalloInesperadoQuedaAisladoDelSync()
    {
        var messages = new List<string>();
        var context = new Keeper.Shared.Contracts.ModuleContext(new MemoryEvents(), new TestClock(), messages.Add, CancellationToken.None);
        var sac = new SmartAppControl(new FakeSacRegistry(1) { ReadFailure = new InvalidOperationException() });
        await sac.InitAsync(context);
        await sac.TryApplyAsync(Directive(true), default);   // no lanza
        Assert.Contains("smart_app_control_apply_failed: InvalidOperationException", messages);
        Assert.Empty(((MemoryEvents)context.Outbox).Logs);
    }

    // ---- UpdateManager: lanzamiento del bootstrapper -------------------------------------------------------------
    private sealed class ScriptedInstaller : IReleaseInstaller
    {
        public int Calls;
        public Exception? Failure;
        public void Install(string packagePath) { Calls++; if (Failure is not null) throw Failure; }
    }

    private static async Task<(UpdateManager Updater, MemoryEvents Events, TestClock Clock, ScriptedInstaller Installer, TestDirectory Directory)> VerifiedUpdate(Exception? failure)
    {
        var directory = new TestDirectory();
        using var key = ECDsa.Create(ECCurve.NamedCurves.nistP256);
        var bytes = Encoding.UTF8.GetBytes("signed test package");
        var release = SecurityModuleTests.SignedRelease(key, bytes);
        await File.WriteAllBytesAsync(directory.File(release.Id.ToString("N") + ".zip"), bytes);
        var clock = new TestClock();
        var context = Samples.Context(clock);
        var installer = new ScriptedInstaller { Failure = failure };
        var publicKey = ECDsa.Create(key.ExportParameters(false));
        var updater = new UpdateManager(directory.Root, new Dictionary<string, ECDsa> { [release.KeyId] = publicKey }, 0, "stable", null, installer, new Version(4, 0, 0));
        await updater.InitAsync(context);
        updater.Offer(release);
        await updater.TickAsync(default);
        Assert.Equal("verified_pending_install", updater.Snapshot().State);
        ((MemoryEvents)context.Outbox).Logs.Clear();
        return (updater, (MemoryEvents)context.Outbox, clock, installer, directory);
    }

    [Fact]
    public async Task UpdateLaunchFailureSmartAppControlSeReportaYReintentaEn30Minutos()
    {
        var (updater, events, clock, installer, directory) = await VerifiedUpdate(new Win32Exception(4551));
        using var cleanup = directory;
        clock.Advance(TimeSpan.FromMinutes(6));
        await updater.TickAsync(default);
        Assert.Equal(1, installer.Calls);
        Assert.Equal(("failed_launch", "win32_4551"), (updater.Snapshot().State, updater.Snapshot().ErrorCode));
        var failure = Assert.Single(events.Logs, l => l.Code == "update_launch_failed");
        Assert.Equal((LogEntryLevel.Warn, "UpdateManager", "win32_4551"), (failure.Level, failure.Component, failure.Fields?.ErrorCode));
        for (var i = 0; i < 5; i++) { clock.Advance(TimeSpan.FromMinutes(5)); await updater.TickAsync(default); }   // 25 min: espera
        Assert.Equal(1, installer.Calls);
        clock.Advance(TimeSpan.FromMinutes(6));
        await updater.TickAsync(default);                                                                             // 31 min: reintenta
        Assert.Equal(2, installer.Calls);
        Assert.Single(events.Logs, l => l.Code == "update_launch_failed");   // mismo release + codigo: no se repite
        Assert.Single(events.Logs, l => l.Code == "update_applying");
        installer.Failure = new IOException("zip");
        clock.Advance(TimeSpan.FromMinutes(31));
        await updater.TickAsync(default);
        Assert.Equal(2, events.Logs.Count(l => l.Code == "update_launch_failed"));   // codigo distinto: se reporta
        Assert.Equal("IOException", events.Logs[^1].Fields?.ErrorCode);
        installer.Failure = null;
        clock.Advance(TimeSpan.FromMinutes(31));
        await updater.TickAsync(default);
        Assert.Equal(("applying", null), (updater.Snapshot().State, updater.Snapshot().ErrorCode));
    }

    [Fact]
    public async Task UpdateLaunchStalledSeReportaUnaVezYReintenta()
    {
        var (updater, events, clock, installer, directory) = await VerifiedUpdate(null);
        using var cleanup = directory;
        clock.Advance(TimeSpan.FromMinutes(6));
        await updater.TickAsync(default);
        Assert.Equal(("applying", 1), (updater.Snapshot().State, installer.Calls));
        for (var i = 0; i < 5; i++) { clock.Advance(TimeSpan.FromMinutes(5)); await updater.TickAsync(default); }   // 25 min aplicando
        Assert.Equal(("applying", 1), (updater.Snapshot().State, installer.Calls));
        Assert.DoesNotContain(events.Logs, l => l.Code == "update_apply_stalled");
        clock.Advance(TimeSpan.FromMinutes(6));
        await updater.TickAsync(default);                                                                             // 31 min: atascado
        Assert.Equal(("apply_stalled", "update_apply_stalled", 1), (updater.Snapshot().State, updater.Snapshot().ErrorCode, installer.Calls));
        var stalled = Assert.Single(events.Logs, l => l.Code == "update_apply_stalled");
        Assert.Equal(LogEntryLevel.Warn, stalled.Level);
        clock.Advance(TimeSpan.FromMinutes(5));
        await updater.TickAsync(default);                                                                             // siguiente tick: relanza
        Assert.Equal(("applying", 2), (updater.Snapshot().State, installer.Calls));
        clock.Advance(TimeSpan.FromMinutes(31));
        await updater.TickAsync(default);
        clock.Advance(TimeSpan.FromMinutes(5));
        await updater.TickAsync(default);
        Assert.Equal(3, installer.Calls);
        Assert.Single(events.Logs, l => l.Code == "update_apply_stalled");   // una vez por release
    }
}
