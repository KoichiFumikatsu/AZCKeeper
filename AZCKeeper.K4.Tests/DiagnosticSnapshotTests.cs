using System;
using System.Collections.Generic;
using System.IO;
using System.Linq;
using System.Reflection;
using AZCKeeper.K4.Contracts;
using AZCKeeper.K4.Core;
using AZCKeeper.K4.Shell;
using Xunit;

namespace AZCKeeper.K4.Tests;

/// <summary>
/// El snapshot de diagnostico refleja el ESPERADO vs REAL de cada modulo, y solo manda los
/// logs nuevos desde el cursor. Se testea leyendo el POCO anonimo por reflexion.
/// </summary>
public class DiagnosticSnapshotTests : IDisposable
{
    private readonly string _dir;

    public DiagnosticSnapshotTests()
    {
        _dir = Path.Combine(Path.GetTempPath(), "k4snap_" + Guid.NewGuid().ToString("N"));
        Directory.CreateDirectory(_dir);
    }
    public void Dispose() { try { Directory.Delete(_dir, true); } catch { } }

    private sealed class FakeModule : IKeeperModule
    {
        public string Code { get; }
        private bool _running;
        public FakeModule(string code, bool running) { Code = code; _running = running; }
        public bool IsRunning => _running;
        public void Configure(ModuleSettings s) { }
        public void Start() => _running = true;
        public void Stop() => _running = false;
    }

    private sealed class FakeFg : IForegroundWindow
    {
        public string? ProcessName => "chrome.exe";
        public string? Title => "algo";
    }
    private sealed class FakeIdle : IIdleMonitor { public int IdleSeconds => 7; }

    private static object[] Arr(object payload, string prop)
        => ((System.Collections.IEnumerable)payload.GetType().GetProperty(prop)!.GetValue(payload)!)
           .Cast<object>().ToArray();

    private static object Val(object o, string prop)
        => o.GetType().GetProperty(prop)!.GetValue(o)!;

    [Fact]
    public void Modules_reflejan_esperado_vs_real()
    {
        var host = new ModuleHost();
        host.Register(new FakeModule("windowTracking", running: true));
        host.Register(new FakeModule("screenshots", running: false));

        // El servidor esperaba AMBOS encendidos; screenshots esta caido (esperado!=real).
        var expected = new Dictionary<string, bool> { ["windowTracking"] = true, ["screenshots"] = true };
        var logger = new LocalLogger(_dir);
        var net = new DiagNet(false, null, 200, 0, "4.0.0.0");

        var res = DiagnosticSnapshot.Build(host, expected, logger, 0, new FakeFg(), new FakeIdle(), net);
        var modules = Arr(res.Payload, "modules");

        var win = modules.First(m => (string)Val(m, "code") == "windowTracking");
        var shot = modules.First(m => (string)Val(m, "code") == "screenshots");

        Assert.True((bool)Val(win, "expected"));
        Assert.True((bool)Val(win, "running"));
        Assert.True((bool)Val(shot, "expected"));
        Assert.False((bool)Val(shot, "running"));   // esperado ON, real OFF -> el panel lo pinta rojo
    }

    [Fact]
    public void Solo_incluye_logs_nuevos_y_avanza_el_cursor()
    {
        var host = new ModuleHost();
        var logger = new LocalLogger(_dir);
        logger.Info("mod", "viejo");
        long cursor = logger.CurrentSeq;
        logger.Warn("mod", "nuevo");

        var res = DiagnosticSnapshot.Build(host, new Dictionary<string, bool>(), logger, cursor,
            new FakeFg(), new FakeIdle(), new DiagNet(false, null, 0, 0, "4.0.0.0"));

        var logs = Arr(res.Payload, "logs");
        Assert.Single(logs);
        Assert.Equal("nuevo", (string)Val(logs[0], "message"));
        Assert.True(res.MaxSeq > cursor);
    }

    [Fact]
    public void Activity_trae_el_foco_actual()
    {
        var res = DiagnosticSnapshot.Build(new ModuleHost(), new Dictionary<string, bool>(),
            new LocalLogger(_dir), 0, new FakeFg(), new FakeIdle(),
            new DiagNet(false, null, 0, 0, "4.0.0.0"));

        var act = Val(res.Payload, "activity");
        Assert.Equal("chrome.exe", (string)Val(act, "process"));
        Assert.Equal(7, (int)Val(act, "idleSeconds"));
    }
}
