using Keeper.Agent.Hosting;
using Keeper.Agent.Storage;
using Keeper.Shared.Contracts;
using Keeper.Shared.Protocol;

namespace Keeper.Agent.Tests;

// Regresion 2026-09-30 (DESKTOP-949SGVE): una foto de actividad por ciclo de sesion llenaba la cola (16 MB), el
// agente no podia ni guardar el lote a enviar y dejo de sincronizar ~17 h; un modulo fallando cada segundo lleno el log.
public sealed class OutboxPressureTests
{
    private static ActivitySnapshot Activity(DateOnly day, long sequence, long active = 60) => new()
    {
        SnapshotId = Guid.NewGuid(), Sequence = sequence, Day = day, ActiveSeconds = active * sequence, IdleSeconds = 0
    };

    [Fact]
    public async Task SoloSeConservaLaUltimaFotoDeCadaDia()
    {
        using var directory = new TestDirectory();
        using var outbox = new DurableOutbox(directory.File("outbox.json"));
        var today = new DateOnly(2026, 9, 30); var yesterday = today.AddDays(-1);
        for (var i = 1; i <= 300; i++) await outbox.EnqueueAsync(Activity(today, i), default);
        await outbox.EnqueueAsync(Activity(yesterday, 1000), default);
        var events = (await outbox.InspectAsync()).Events.Where(e => e.Activity is not null).ToList();
        Assert.Equal(2, events.Count);
        Assert.Equal(300, events.Single(e => e.Activity!.Day == today).Activity!.Sequence);
    }

    [Fact]
    public async Task LaFotoIncluidaEnElLotePendienteNoSeReemplazaHastaConfirmarse()
    {
        using var directory = new TestDirectory();
        using var outbox = new DurableOutbox(directory.File("outbox.json"));
        var day = new DateOnly(2026, 9, 30);
        await outbox.EnqueueAsync(Activity(day, 1), default);
        var batch = await outbox.PrepareAsync(1, default);
        await outbox.EnqueueAsync(Activity(day, 2), default);
        await outbox.EnqueueAsync(Activity(day, 3), default);
        var state = await outbox.InspectAsync();
        Assert.Equal(2, state.Events.Count);
        Assert.Contains(state.Events, e => batch.EventIds.Contains(e.Id));
        Assert.Contains(state.Events, e => e.Activity!.Sequence == 3);
    }

    [Fact]
    public async Task ColaLlenaDescartaLogsViejosYNoBloqueaElSync()
    {
        using var directory = new TestDirectory();
        using var outbox = new DurableOutbox(directory.File("outbox.json"), maxBytes: 60_000);
        var command = new SyncRequestCommandResultsItem
        {
            CommandId = Guid.NewGuid(),
            Result = new CommandResult { EventId = Guid.NewGuid(), At = DateTimeOffset.UtcNow, Status = CommandResultStatus.Succeeded, Code = "completed" }
        };
        await outbox.EnqueueAsync(command, default);
        for (var i = 0; i < 400; i++) await outbox.EnqueueAsync(Samples.Log(), default);
        var batch = await outbox.PrepareAsync(1, default);
        Assert.NotEmpty(batch.Body);
        var state = await outbox.InspectAsync();
        Assert.Contains(state.Events, e => e.Command is not null);
        Assert.True(state.Events.Count(e => e.Log is not null) < 400);
    }

    [Fact]
    public async Task UnaColaHeredadaConFotosRepetidasSeCompactaAlPrepararElEnvio()
    {
        using var directory = new TestDirectory();
        var path = directory.File("outbox.json");
        var day = new DateOnly(2026, 9, 29);
        // Cola escrita por una version anterior: muchas fotos del mismo dia sin compactar.
        var events = Enumerable.Range(1, 500).Select(i => new OutboxEvent(Guid.NewGuid(), Activity: Activity(day, i))).ToList();
        await File.WriteAllBytesAsync(path, System.Text.Json.JsonSerializer.SerializeToUtf8Bytes(new OutboxState(10, events, [], null), ProtocolJson.Options));
        using var outbox = new DurableOutbox(path);
        var batch = await outbox.PrepareAsync(1, default);
        var state = await outbox.InspectAsync();
        var kept = Assert.Single(state.Events);
        Assert.Equal(500, kept.Activity!.Sequence);
        Assert.Equal([kept.Id], batch.EventIds);
    }

    private sealed class FailingModule : ModuleBase
    {
        public int Ticks { get; private set; }
        public override string Name => "Failing";
        public override Task TickAsync(CancellationToken ct) { Ticks++; throw new IOException("outbox_quota_exceeded"); }
    }

    [Fact]
    public async Task UnModuloQueFallaEsperaAntesDeReintentarYNoRepiteElLog()
    {
        var clock = new TestClock();
        var logs = new List<string>();
        var module = new FailingModule();
        await using var host = new ModuleHost([module], new ModuleContext(new MemoryEvents(), clock, logs.Add, CancellationToken.None));
        await host.InitAsync();
        for (var i = 0; i < 10; i++) await host.TickAsync(default);
        Assert.Equal(1, module.Ticks);
        clock.Advance(TimeSpan.FromSeconds(3));
        await host.TickAsync(default);
        Assert.Equal(2, module.Ticks);
        Assert.Single(logs, l => l.Contains("IOException", StringComparison.Ordinal));
        for (var i = 0; i < 20; i++) { clock.Advance(TimeSpan.FromSeconds(301)); await host.TickAsync(default); }
        Assert.Equal(22, module.Ticks);
    }
}
