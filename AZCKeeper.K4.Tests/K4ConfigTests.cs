using System;
using System.IO;
using AZCKeeper.K4.Shell;
using Xunit;

namespace AZCKeeper.K4.Tests;

/// <summary>
/// El deviceId es la identidad estable del equipo: tiene que generarse una vez y
/// SOBREVIVIR a los reinicios. Si cambiara en cada arranque, el equipo se re-enrolaría
/// como uno nuevo y se perdería su historial. Estos tests fijan esa invariante.
/// </summary>
public class K4ConfigTests : IDisposable
{
    private readonly string _dir;
    private readonly string _path;

    public K4ConfigTests()
    {
        _dir = Path.Combine(Path.GetTempPath(), "k4cfg_" + Guid.NewGuid().ToString("N"));
        Directory.CreateDirectory(_dir);
        _path = Path.Combine(_dir, "client_config.json");
    }

    public void Dispose()
    {
        try { Directory.Delete(_dir, recursive: true); } catch { }
    }

    [Fact]
    public void LoadOrCreate_GeneraDeviceIdYLoPersiste()
    {
        var cfg = K4Config.LoadOrCreate(_path);
        Assert.False(string.IsNullOrWhiteSpace(cfg.DeviceId));
        Assert.True(File.Exists(_path)); // EnsureDeviceId guardó
    }

    [Fact]
    public void SegundaCarga_ReusaElMismoDeviceId()
    {
        var first = K4Config.LoadOrCreate(_path).DeviceId;
        var second = K4Config.LoadOrCreate(_path).DeviceId;
        Assert.Equal(first, second); // estable entre arranques
    }

    [Fact]
    public void Save_EsAtomicoYReleeLoGuardado()
    {
        var cfg = K4Config.LoadOrCreate(_path);
        cfg.Cc = "K4TEST";
        cfg.HandshakeIntervalSeconds = 120;
        cfg.Save();

        Assert.False(File.Exists(_path + ".tmp")); // el temp no queda
        var reread = K4Config.LoadOrCreate(_path);
        Assert.Equal("K4TEST", reread.Cc);
        Assert.Equal(120, reread.HandshakeIntervalSeconds);
    }

    [Fact]
    public void JsonCorrupto_ArrancaConDefaultsSinReventar()
    {
        File.WriteAllText(_path, "{ esto no es json valido ");
        var cfg = K4Config.LoadOrCreate(_path);
        Assert.False(string.IsNullOrWhiteSpace(cfg.DeviceId)); // se recuperó
    }
}
