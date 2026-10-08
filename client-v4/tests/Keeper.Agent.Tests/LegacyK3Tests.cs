using System.Text.Json;
using Keeper.Agent.Hosting;
using Keeper.Agent.Modules.Devices;
using Keeper.Agent.Storage;
using Keeper.Shared.Contracts;
using Keeper.Shared.Protocol;

namespace Keeper.Agent.Tests;

// K3 residual en equipos v4: deteccion (proceso, autoarranque, instalacion), logs una vez por cambio y republicar el inventario.
public sealed class LegacyK3Tests
{
    private sealed class FakeProbe : ILegacyK3Probe
    {
        public bool? RunningValue = false, AutostartValue = false, InstalledValue = false;
        public Exception? Failure;
        public int Reads;
        public bool? Running() { Reads++; return Failure is null ? RunningValue : throw Failure; }
        public bool? Autostart() => AutostartValue;
        public bool? Installed() => InstalledValue;
    }

    private sealed class Rig
    {
        public readonly FakeProbe Probe = new();
        public readonly TestClock Clock = new();
        public readonly List<LegacyK3Status> Published = [];
        public LegacyK3 Module = null!;
        public ModuleContext Context = null!;
        public List<LogEntry> Logs => ((MemoryEvents)Context.Outbox).Logs;
        public IEnumerable<string> Codes => Logs.Select(l => l.Code);
        public async Task StartAsync()
        {
            Context = Samples.Context(Clock);
            Module = new LegacyK3(Probe, (s, _) => { Published.Add(s); return Task.CompletedTask; });
            await Module.InitAsync(Context);
        }
        public async Task NextCheckAsync() { Clock.Advance(LegacyK3.DefaultInterval); await Module.TickAsync(default); }
    }

    private static LegacyK3Status Status(bool? running, bool? autostart, bool? installed) => new() { Running = running, Autostart = autostart, Installed = installed };

    [Fact]
    public async Task DetectaAlArrancarYLuegoCada15Minutos()
    {
        var rig = new Rig();
        await rig.StartAsync();
        Assert.Equal(1, rig.Probe.Reads);
        Assert.Equal(Status(false, false, false), rig.Module.Current);
        rig.Clock.Advance(TimeSpan.FromMinutes(14)); await rig.Module.TickAsync(default);
        Assert.Equal(1, rig.Probe.Reads);
        rig.Clock.Advance(TimeSpan.FromMinutes(1)); await rig.Module.TickAsync(default);
        Assert.Equal(2, rig.Probe.Reads);
        Assert.Equal("ready", rig.Module.Snapshot().State);
    }

    [Fact]
    public async Task EquipoLimpioNoGeneraLogsYPublicaUnaVez()
    {
        var rig = new Rig();
        await rig.StartAsync(); await rig.NextCheckAsync(); await rig.NextCheckAsync();
        Assert.Empty(rig.Logs);
        Assert.Equal([Status(false, false, false)], rig.Published);
    }

    [Fact]
    public async Task K3AbiertoAvisaUnaVezPorCambioYRepublicaSoloAlCambiar()
    {
        var rig = new Rig();
        rig.Probe.RunningValue = true; rig.Probe.AutostartValue = true; rig.Probe.InstalledValue = true;
        await rig.StartAsync(); await rig.NextCheckAsync(); await rig.NextCheckAsync();
        Assert.Equal(["legacy_k3_running", "legacy_k3_autostart"], rig.Codes);
        var running = rig.Logs[0];
        Assert.Equal(("LegacyK3", LogEntryLevel.Warn), (running.Component, running.Level));
        Assert.Equal(LogEntryLevel.Info, rig.Logs[1].Level);
        Assert.Single(rig.Published);

        // El usuario lo cierra (como hizo la release puente): se republica, pero el autoarranque no ha cambiado.
        rig.Probe.RunningValue = false; await rig.NextCheckAsync();
        Assert.Equal(2, rig.Logs.Count);
        Assert.Equal(Status(false, true, true), rig.Published[^1]);

        // Vuelve a abrirse: otro aviso (nuevo cambio).
        rig.Probe.RunningValue = true; await rig.NextCheckAsync();
        Assert.Equal(["legacy_k3_running", "legacy_k3_autostart", "legacy_k3_running"], rig.Codes);

        // Se cierra y se quita el autoarranque: legacy_k3_gone una sola vez, aunque siga instalado.
        rig.Probe.RunningValue = false; rig.Probe.AutostartValue = false; await rig.NextCheckAsync(); await rig.NextCheckAsync();
        Assert.Equal("legacy_k3_gone", rig.Codes.Last());
        Assert.Single(rig.Codes, c => c == "legacy_k3_gone");
        Assert.Equal(Status(false, false, true), rig.Module.Current);
        Assert.Equal(4, rig.Published.Count);
    }

    [Fact]
    public async Task SoloAutoarranqueAvisaInfoSinWarn()
    {
        var rig = new Rig();
        rig.Probe.AutostartValue = true; rig.Probe.InstalledValue = true;
        await rig.StartAsync();
        Assert.Equal(["legacy_k3_autostart"], rig.Codes);
    }

    [Fact]
    public async Task DatoDesconocidoNoDisparaGone()
    {
        var rig = new Rig();
        rig.Probe.RunningValue = true;
        await rig.StartAsync();
        rig.Probe.RunningValue = false; rig.Probe.AutostartValue = null; await rig.NextCheckAsync();
        Assert.DoesNotContain("legacy_k3_gone", rig.Codes);
        Assert.Equal(Status(false, null, false), rig.Module.Current);
    }

    [Fact]
    public async Task LectorQueFallaDejaElDatoNullSinRomperElModulo()
    {
        var rig = new Rig();
        rig.Probe.Failure = new System.ComponentModel.Win32Exception(5);
        await rig.StartAsync();
        Assert.Equal(Status(null, false, false), rig.Module.Current);
        Assert.Equal("ready", rig.Module.Snapshot().State);
        await rig.NextCheckAsync();
        Assert.Single(rig.Published);
    }

    [Fact]
    public async Task SinSondaTodoDesconocido()
    {
        var published = new List<LegacyK3Status>();
        var module = new LegacyK3(null, (s, _) => { published.Add(s); return Task.CompletedTask; });
        await module.InitAsync(Samples.Context(new TestClock()));
        Assert.Equal(Status(null, null, null), module.Current);
        Assert.Single(published);
    }

    [Theory]
    [InlineData("S-1-5-21-111-222-333-1001", true)]
    [InlineData("S-1-12-1-111-222-333-444", true)]
    [InlineData("S-1-5-21-111-222-333-1001_Classes", false)]
    [InlineData(".DEFAULT", false)]
    [InlineData("S-1-5-18", true)]
    public void SoloColmenasDeUsuario(string name, bool expected) => Assert.Equal(expected, LegacyK3Layout.IsUserHive(name));

    [Fact]
    public void RutasDeInstalacionLocalYRoaming()
    {
        var candidates = LegacyK3Layout.ExecutableCandidates(@"C:\Users\AZC").ToArray();
        Assert.Equal([@"C:\Users\AZC\AppData\Local\AZCKeeper\app\AZCKeeper_Client.exe", @"C:\Users\AZC\AppData\Roaming\AZCKeeper\app\AZCKeeper_Client.exe"], candidates);
    }

    [Fact]
    public void CualquierPositivoGanaYUnFalloSinPositivosEsDesconocido()
    {
        Assert.True(LegacyK3Layout.Any([() => throw new UnauthorizedAccessException(), () => true]));
        Assert.Null(LegacyK3Layout.Any([() => false, () => throw new System.Security.SecurityException()]));
        Assert.False(LegacyK3Layout.Any([() => false, () => false]));
        Assert.False(LegacyK3Layout.Any([]));
    }

    [Fact]
    public void InstalacionSeBuscaEnLosPerfilesSinSeguirUniones()
    {
        if (!OperatingSystem.IsWindows()) return;
        using var users = new TestDirectory();
        Directory.CreateDirectory(Path.Combine(users.Root, "Ana", "AppData", "Local", "AZCKeeper"));
        Directory.CreateDirectory(Path.Combine(users.Root, "Public"));
        Assert.False(new WindowsLegacyK3Probe(users.Root).Installed());

        var roaming = Path.Combine(users.Root, "AZC", "AppData", "Roaming", "AZCKeeper", "app");
        Directory.CreateDirectory(roaming);
        File.WriteAllText(Path.Combine(roaming, "AZCKeeper_Client.exe"), "MZ");
        Assert.True(new WindowsLegacyK3Probe(users.Root).Installed());

        Assert.Null(new WindowsLegacyK3Probe(Path.Combine(users.Root, "no-existe")).Installed());
    }

    [Fact]
    public void InstalacionDentroDeUnaUnionNoCuenta()
    {
        if (!OperatingSystem.IsWindows()) return;
        using var users = new TestDirectory();
        var outside = Path.Combine(users.Root, "..", "k3-fuera-" + Guid.NewGuid().ToString("N"));
        var app = Path.Combine(outside, "AppData", "Local", "AZCKeeper", "app");
        Directory.CreateDirectory(app);
        File.WriteAllText(Path.Combine(app, "AZCKeeper_Client.exe"), "MZ");
        var profiles = Path.Combine(users.Root, "Users");
        Directory.CreateDirectory(profiles);
        try
        {
            Directory.CreateSymbolicLink(Path.Combine(profiles, "All Users"), Path.GetFullPath(outside));
        }
        catch (Exception ex) when (ex is IOException or UnauthorizedAccessException)
        {
            Directory.Delete(outside, true);
            return; // sin privilegio para crear enlaces en este equipo de pruebas
        }
        try { Assert.False(new WindowsLegacyK3Probe(profiles).Installed()); }
        finally { Directory.Delete(Path.Combine(profiles, "All Users")); Directory.Delete(outside, true); }
    }

    [Fact]
    public async Task InventarioIncluyeK3YSeRepublicaAlCambiar()
    {
        if (!OperatingSystem.IsWindows()) return;
        var published = new List<DeviceInventory>();
        var inventory = new Inventory((i, _) => { published.Add(i); return Task.CompletedTask; });
        // Antes de InitAsync: se guarda y entra en el primer inventario (un solo envio al arrancar).
        await inventory.UpdateLegacyK3Async(Status(true, true, true), default);
        Assert.Empty(published);
        await inventory.InitAsync(Samples.Context(new TestClock()));
        Assert.Equal(Status(true, true, true), Assert.Single(published).LegacyK3);
        await inventory.UpdateLegacyK3Async(Status(false, true, true), default);
        Assert.Equal(2, published.Count);
        Assert.Equal(published[0] with { LegacyK3 = Status(false, true, true) }, published[1]);
        Assert.Contains("\"legacy_k3\":{\"running\":false,\"autostart\":true,\"installed\":true}", JsonSerializer.Serialize(published[1], ProtocolJson.Options));
        Assert.Contains("\"running\":null", JsonSerializer.Serialize(Status(null, false, true), ProtocolJson.Options));
    }

    [Fact]
    public async Task ElOutboxMandaSoloElInventarioMasReciente()
    {
        using var directory = new TestDirectory();
        using var outbox = new DurableOutbox(directory.File("outbox.json"));
        DeviceInventory Sample(LegacyK3Status k3) => new()
        {
            OsEdition = "Windows 11 Pro", Cpu = "cpu (4 hilos)", RamBytes = 8L << 30, Architecture = DeviceInventoryArchitecture.X64, LegacyK3 = k3
        };
        await outbox.EnqueueInventoryAsync(Sample(Status(true, true, true)), default);
        await outbox.EnqueueInventoryAsync(Sample(Status(false, false, true)), default);
        var request = JsonSerializer.Deserialize<SyncRequest>((await outbox.PrepareAsync(1, default)).Body, ProtocolJson.Options)!;
        Assert.Equal(Status(false, false, true), request.Inventory!.LegacyK3);
    }

    [Fact]
    public async Task ElHostLoArrancaYLoTickeaSinRomper()
    {
        var probe = new FakeProbe { RunningValue = true };
        var clock = new TestClock();
        var context = Samples.Context(clock);
        var module = new LegacyK3(probe);
        await using var host = new ModuleHost([module], context);
        await host.InitAsync();
        clock.Advance(LegacyK3.DefaultInterval); await host.TickAsync(default);
        Assert.Equal(2, probe.Reads);
        Assert.Equal("ready", host.Snapshot().Single().State);
    }
}
