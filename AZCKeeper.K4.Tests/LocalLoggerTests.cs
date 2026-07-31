using System;
using System.IO;
using System.Linq;
using AZCKeeper.K4.Shell;
using Xunit;

namespace AZCKeeper.K4.Tests;

/// <summary>
/// El LocalLogger de K4: anillo acotado, secuencia monotona para "lo nuevo desde", memoria
/// del ultimo error por modulo, y archivo diario. Escribe a un temporal para no ensuciar
/// %LOCALAPPDATA%.
/// </summary>
public class LocalLoggerTests : IDisposable
{
    private readonly string _dir;

    public LocalLoggerTests()
    {
        _dir = Path.Combine(Path.GetTempPath(), "k4log_" + Guid.NewGuid().ToString("N"));
        Directory.CreateDirectory(_dir);
    }

    public void Dispose() { try { Directory.Delete(_dir, true); } catch { } }

    [Fact]
    public void RecentSince_devuelve_solo_lo_posterior_al_cursor()
    {
        var log = new LocalLogger(_dir, capacity: 100);
        log.Info("mod", "uno");
        log.Info("mod", "dos");
        long cursor = log.CurrentSeq;
        log.Info("mod", "tres");

        var nuevos = log.RecentSince(cursor);

        Assert.Single(nuevos);
        Assert.Equal("tres", nuevos[0].Message);
        Assert.True(nuevos[0].Seq > cursor);
    }

    [Fact]
    public void El_anillo_respeta_la_capacidad()
    {
        var log = new LocalLogger(_dir, capacity: 10);
        for (int i = 0; i < 20; i++) log.Info("mod", $"m{i}");

        var all = log.RecentSince(0);

        Assert.Equal(10, all.Count);
        Assert.Equal("m19", all.Last().Message);   // se conservan los mas recientes
        Assert.Equal("m10", all.First().Message);  // los 10 primeros se descartaron
    }

    [Fact]
    public void LastErrorFor_recuerda_el_ultimo_error_por_modulo()
    {
        var log = new LocalLogger(_dir);
        log.Error("windowTracking", "no se pudo leer la ventana");
        log.Info("windowTracking", "recuperado");   // un Info no borra el ultimo error

        Assert.Equal("no se pudo leer la ventana", log.LastErrorFor("windowTracking"));
        Assert.Null(log.LastErrorFor("activityTracking"));
    }

    [Fact]
    public void Escribe_un_archivo_diario()
    {
        var log = new LocalLogger(_dir);
        log.Warn("net", "backoff activo");

        var files = Directory.GetFiles(_dir, "keeper_*.log");
        Assert.Single(files);
        Assert.Contains("backoff activo", File.ReadAllText(files[0]));
    }
}
