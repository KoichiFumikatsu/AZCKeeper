using Keeper.Agent.Modules.Maintenance;

namespace Keeper.Agent.Tests;

public sealed class RestorePointTests
{
    private sealed class FakeService : IRestorePointService
    {
        public int Calls;
        public TaskCompletionSource? Pending;
        public Exception? Fail;
        public Task CreateAsync(string description, CancellationToken ct)
        {
            Calls++;
            if (Fail is not null) return Task.FromException(Fail);
            return Pending?.Task ?? Task.CompletedTask;
        }
    }

    private static RestorePointModule Module(FakeService service, string path) =>
        new(service, path, TimeSpan.FromHours(24), TimeSpan.FromHours(6));

    [Fact]
    public async Task PrimerTickCreaPuntoYLoPersiste()
    {
        using var directory = new TestDirectory();
        var clock = new TestClock();
        var context = Samples.Context(clock);
        var service = new FakeService();
        var module = Module(service, directory.File("rp.json"));
        await module.InitAsync(context);
        await module.TickAsync(default);   // dispara
        await module.TickAsync(default);   // recoge el resultado
        Assert.Equal(1, service.Calls);
        Assert.Equal("applied", module.Snapshot().State);
        Assert.Contains(((MemoryEvents)context.Outbox).Logs, l => l.Code == "restore_point_created");
        Assert.True(File.Exists(directory.File("rp.json")));
    }

    [Fact]
    public async Task RespetaElIntervaloYNoSeRepiteAlReiniciar()
    {
        using var directory = new TestDirectory();
        var clock = new TestClock();
        var service = new FakeService();
        var module = Module(service, directory.File("rp.json"));
        await module.InitAsync(Samples.Context(clock));
        await module.TickAsync(default); await module.TickAsync(default);
        clock.Advance(TimeSpan.FromHours(23));
        await module.TickAsync(default);
        Assert.Equal(1, service.Calls);

        // Reinicio del servicio: el estado persistido evita un punto nuevo en cada arranque.
        var restarted = Module(service, directory.File("rp.json"));
        await restarted.InitAsync(Samples.Context(clock));
        await restarted.TickAsync(default);
        Assert.Equal(1, service.Calls);

        clock.Advance(TimeSpan.FromHours(2));   // 25 h desde el primero
        await restarted.TickAsync(default); await restarted.TickAsync(default);
        Assert.Equal(2, service.Calls);
    }

    [Fact]
    public async Task NoBloqueaElTickMientrasSeCrea()
    {
        using var directory = new TestDirectory();
        var service = new FakeService { Pending = new TaskCompletionSource() };
        var module = Module(service, directory.File("rp.json"));
        await module.InitAsync(Samples.Context(new TestClock()));
        // El tick guarda el estado (I/O breve) pero NO espera la creación, que sigue pendiente.
        var tick = module.TickAsync(default);
        Assert.Same(tick, await Task.WhenAny(tick, Task.Delay(TimeSpan.FromSeconds(5))));
        await module.TickAsync(default);
        Assert.Equal("creating", module.Snapshot().State);
        service.Pending.SetResult();
        await module.TickAsync(default);
        Assert.Equal("applied", module.Snapshot().State);
    }

    [Fact]
    public async Task DryRunNoReintentaAntesDelBackoff()
    {
        using var directory = new TestDirectory();
        var clock = new TestClock();
        var service = new FakeService { Fail = new NotSupportedException("restore_points_dry_run") };
        var module = Module(service, directory.File("rp.json"));
        await module.InitAsync(Samples.Context(clock));
        await module.TickAsync(default); await module.TickAsync(default);
        Assert.Equal("dry_run", module.Snapshot().State);
        clock.Advance(TimeSpan.FromHours(5));
        await module.TickAsync(default);
        Assert.Equal(1, service.Calls);
        clock.Advance(TimeSpan.FromHours(2));
        await module.TickAsync(default);
        Assert.Equal(2, service.Calls);
    }
}
