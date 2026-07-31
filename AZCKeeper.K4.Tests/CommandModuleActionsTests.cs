using System.Collections.Generic;
using System.Threading.Tasks;
using AZCKeeper.K4.Contracts;
using AZCKeeper.K4.Modules;
using Xunit;

namespace AZCKeeper.K4.Tests;

/// <summary>
/// Las acciones de control remoto (lock/shutdown/restart/logoff) llaman al ejecutor del
/// sistema con los argumentos correctos, SIN ejecutar nada real (ejecutor falso) y reportan
/// el resultado. shutdown/restart leen la ventana de gracia de params.
/// </summary>
public class CommandModuleActionsTests
{
    private sealed class FakeSys : ISystemActions
    {
        public string? ShutdownFlag; public int ShutdownGrace = -1;
        public bool LoggedOff; public bool Locked;
        public void Shutdown(string flag, int graceSeconds) { ShutdownFlag = flag; ShutdownGrace = graceSeconds; }
        public void Logoff() => LoggedOff = true;
        public void LockWorkStation() => Locked = true;
    }

    /// <summary>ApiClient falso que entrega un comando y captura el resultado reportado.</summary>
    private sealed class FakeApi : IApiClient
    {
        private readonly CommandDto _cmd;
        public string? ReportedStatus; public long ReportedId = -1;
        public FakeApi(CommandDto cmd) => _cmd = cmd;
        public Task<IReadOnlyList<CommandDto>> PollCommandsAsync() => Task.FromResult<IReadOnlyList<CommandDto>>(new[] { _cmd });
        public Task<bool> ReportCommandResultAsync(long commandId, string status, object? result)
        { ReportedId = commandId; ReportedStatus = status; return Task.FromResult(true); }
        // No usados por este modulo:
        public Task<bool> SendEpisodesAsync(IReadOnlyList<EpisodeDto> e) => Task.FromResult(true);
        public Task<bool> SendActivityDayAsync(ActivityDayDto d) => Task.FromResult(true);
        public Task<bool> ReportModuleStateAsync(IReadOnlyList<ModuleStateDto> m) => Task.FromResult(true);
        public Task<bool> SendScreenshotMetaAsync(ScreenshotMetaDto m) => Task.FromResult(true);
    }

    private sealed class FixedClock : IClock { public System.DateTime Now => new(2026,7,31); public System.DateTime UtcNow => new(2026,7,31); }

    private static async Task<(FakeSys sys, FakeApi api)> RunAsync(string type, string? paramsJson)
    {
        var api = new FakeApi(new CommandDto(42, type, paramsJson));
        var sys = new FakeSys();
        var mod = new CommandModule(api, new FixedClock(), null, sys);
        // Ejercer un ciclo de poll directamente via el metodo publico Start no sirve (timer);
        // en su lugar invocamos el poll a traves de un ciclo manual reutilizando reflection-free path:
        await mod.PollOnceForTestAsync();
        return (sys, api);
    }

    [Fact]
    public async Task Lock_bloquea_y_reporta_done()
    {
        var (sys, api) = await RunAsync("lock", null);
        Assert.True(sys.Locked);
        Assert.Equal("done", api.ReportedStatus);
        Assert.Equal(42, api.ReportedId);
    }

    [Fact]
    public async Task Shutdown_usa_flag_s_y_gracia_de_params()
    {
        var (sys, _) = await RunAsync("shutdown", "{\"graceSeconds\":120}");
        Assert.Equal("/s", sys.ShutdownFlag);
        Assert.Equal(120, sys.ShutdownGrace);
    }

    [Fact]
    public async Task Shutdown_sin_params_usa_60()
    {
        var (sys, _) = await RunAsync("shutdown", null);
        Assert.Equal(60, sys.ShutdownGrace);
    }

    [Fact]
    public async Task Restart_usa_flag_r()
    {
        var (sys, _) = await RunAsync("restart", null);
        Assert.Equal("/r", sys.ShutdownFlag);
    }

    [Fact]
    public async Task Logoff_cierra_sesion()
    {
        var (sys, _) = await RunAsync("logoff", null);
        Assert.True(sys.LoggedOff);
    }
}
