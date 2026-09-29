using System.Text.Json;
using Keeper.Agent.Modules.Devices;
using Keeper.Agent.Storage;
using Keeper.Shared.Protocol;

namespace Keeper.Agent.Tests;

public sealed class InventoryTests
{
    [Theory]
    [InlineData("Professional", "26200", "Windows 11 Pro")]
    [InlineData("Core", "26100", "Windows 11 Home")]
    [InlineData("Core", "19045", "Windows 10 Home")]
    [InlineData("Enterprise", "22631", "Windows 11 Enterprise")]
    [InlineData(null, "26200", "Windows 11")]
    [InlineData("IoTEnterprise", "19044", "Windows 10 IoTEnterprise")]
    public void EdicionLegibleDesdeElRegistro(string? editionId, string build, string expected) =>
        Assert.Equal(expected, InventoryFormat.Edition(new WindowsVersionInfo(editionId, build, null, null)));

    [Fact]
    public void BuildYCpuFormateados()
    {
        Assert.Equal("26200.6584", InventoryFormat.Build(new WindowsVersionInfo("Professional", "26200", 6584, "25H2")));
        Assert.Null(InventoryFormat.Build(new WindowsVersionInfo(null, null, null, null)));
        Assert.Equal("Intel(R) Core(TM) i5-10400 CPU @ 2.90GHz (12 hilos)", InventoryFormat.Cpu("  Intel(R) Core(TM) i5-10400 CPU @ 2.90GHz ", 12));
        Assert.Equal(160, InventoryFormat.Cpu(new string('x', 400), 8).Length);
    }

    [Fact]
    public async Task RecopilaElInventarioRealDeEsteEquipo()
    {
        DeviceInventory? published = null;
        var inventory = new Inventory((i, _) => { published = i; return Task.CompletedTask; });
        await inventory.InitAsync(Samples.Context(new TestClock()));
        Assert.NotNull(published);
        Assert.Matches("^Windows 1[01]( .+)?$", published!.OsEdition);
        Assert.Matches(@"^\d{5}(\.\d+)?$", published.OsBuild ?? "");
        Assert.EndsWith("hilos)", published.Cpu);
        Assert.True(published.RamBytes > 1L << 30 && published.DiskBytes > 1L << 30);
        Console.WriteLine($"INVENTARIO: {published.OsEdition} | {published.OsBuild} | {published.Cpu} | {published.RamBytes >> 20} MB | {published.DiskBytes >> 30} GB | {published.Architecture} | serie {published.SerialNumber ?? "(sin serie)"}");
    }

    // Tabla SMBIOS sintetica: tipo 0 (BIOS) y tipo 1 (System) con la serie en su cadena 4, y fin (127).
    private static byte[] SmbiosTable(string serial)
    {
        var body = new List<byte>();
        body.AddRange(new byte[] { 0, 4, 0, 0 }); body.AddRange(System.Text.Encoding.ASCII.GetBytes("Vendor\0\0"));
        body.AddRange(new byte[] { 1, 8, 1, 0, 1, 2, 3, 4 });
        body.AddRange(System.Text.Encoding.ASCII.GetBytes("LENOVO\0ThinkPad\0v1\0" + serial + "\0\0"));
        body.AddRange(new byte[] { 127, 4, 2, 0, 0, 0 });
        var raw = new List<byte> { 0, 3, 4, 0 }; raw.AddRange(BitConverter.GetBytes(body.Count)); raw.AddRange(body);
        return raw.ToArray();
    }

    [Theory]
    [InlineData("PF3ABC12", "PF3ABC12")]
    [InlineData("  5CD1234XYZ ", "5CD1234XYZ")]
    [InlineData("To be filled by O.E.M.", null)]
    [InlineData("Default string", null)]
    [InlineData("0000000", null)]
    public void SerieDelFabricanteDesdeSmbios(string raw, string? expected) =>
        Assert.Equal(expected, Smbios.SystemSerial(SmbiosTable(raw)));

    [Fact]
    public void TablaSmbiosCortaOCorruptaNoRevienta()
    {
        Assert.Null(Smbios.SystemSerial([]));
        Assert.Null(Smbios.SystemSerial(new byte[] { 0, 3, 4, 0, 50, 0, 0, 0, 1, 2 }));
    }

    private static DeviceInventory Sample(string edition) => new()
    {
        OsEdition = edition, OsBuild = "26200.1", Cpu = "cpu (4 hilos)", RamBytes = 8L << 30, DiskBytes = 256L << 30,
        Architecture = DeviceInventoryArchitecture.X64
    };

    [Fact]
    public async Task ElOutboxEnviaSoloElUltimoInventarioYLoRetiraAlCompletarElSync()
    {
        using var directory = new TestDirectory();
        using var outbox = new DurableOutbox(directory.File("outbox.json"));
        await outbox.EnqueueInventoryAsync(Sample("Windows 11 Home"), default);
        await outbox.EnqueueInventoryAsync(Sample("Windows 11 Pro"), default);
        var batch = await outbox.PrepareAsync(1, default);
        var request = JsonSerializer.Deserialize<SyncRequest>(batch.Body, ProtocolJson.Options)!;
        Assert.Equal("Windows 11 Pro", request.Inventory!.OsEdition);
        Assert.Single((await outbox.InspectAsync()).Events, e => e.Inventory is not null);

        // Llega otro inventario con el lote en vuelo: el del lote se respeta (reintento con el mismo cuerpo).
        await outbox.EnqueueInventoryAsync(Sample("Windows 11 Enterprise"), default);
        Assert.Equal(batch.Body, (await outbox.PrepareAsync(1, default)).Body);

        await outbox.CompleteAsync([], default);   // el sync respondio; el inventario no tiene ACK propio
        var events = (await outbox.InspectAsync()).Events;
        Assert.Equal("Windows 11 Enterprise", Assert.Single(events, e => e.Inventory is not null).Inventory!.OsEdition);
        var next = JsonSerializer.Deserialize<SyncRequest>((await outbox.PrepareAsync(1, default)).Body, ProtocolJson.Options)!;
        Assert.Equal("Windows 11 Enterprise", next.Inventory!.OsEdition);
        await outbox.CompleteAsync([], default);
        Assert.DoesNotContain((await outbox.InspectAsync()).Events, e => e.Inventory is not null);
        Assert.Null(JsonSerializer.Deserialize<SyncRequest>((await outbox.PrepareAsync(1, default)).Body, ProtocolJson.Options)!.Inventory);
    }
}
