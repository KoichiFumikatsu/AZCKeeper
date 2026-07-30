using System;
using System.Collections.Generic;
using System.Linq;
using AZCKeeper.K4.Contracts;
using AZCKeeper.K4.Core;
using Xunit;

namespace AZCKeeper.K4.Tests;

/// <summary>
/// El criterio de Koichi hecho prueba: si desconecto (o si falla) un módulo, no daña a
/// los demás. Estos tests fijan esa invariante para el core de Keeper 4.
/// </summary>
public class ModuleHostTests
{
    private sealed class FakeModule : IKeeperModule
    {
        public string Code { get; }
        public bool ThrowOnStart { get; init; }
        private bool _running;
        public FakeModule(string code) => Code = code;
        public bool IsRunning => _running;
        public void Configure(ModuleSettings s) { }
        public void Start() { if (ThrowOnStart) throw new InvalidOperationException("boom"); _running = true; }
        public void Stop() => _running = false;
    }

    private static Dictionary<string, ModuleSettings> On(params string[] codes)
        => codes.ToDictionary(c => c, _ => new ModuleSettings { Enabled = true }, StringComparer.Ordinal);

    [Fact]
    public void Apply_EnciendeLosHabilitados()
    {
        var host = new ModuleHost();
        var a = new FakeModule("a"); var b = new FakeModule("b");
        host.Register(a); host.Register(b);

        host.Apply(On("a", "b"));

        Assert.True(a.IsRunning);
        Assert.True(b.IsRunning);
    }

    [Fact]
    public void Apply_ApagaEnCalienteSinReinicio()
    {
        var host = new ModuleHost();
        var a = new FakeModule("a");
        host.Register(a);

        host.Apply(On("a"));
        Assert.True(a.IsRunning);

        host.Apply(new Dictionary<string, ModuleSettings> { ["a"] = new() { Enabled = false } });
        Assert.False(a.IsRunning); // se apago sin reiniciar el proceso
    }

    [Fact]
    public void Apply_UnModuloQueFallaNoTumbaALosDemas()
    {
        var errores = new List<string>();
        var host = new ModuleHost(onError: (code, _) => errores.Add(code));
        var malo = new FakeModule("malo") { ThrowOnStart = true };
        var bueno = new FakeModule("bueno");
        host.Register(malo); host.Register(bueno);

        host.Apply(On("malo", "bueno"));

        Assert.False(malo.IsRunning);        // el que falla queda apagado
        Assert.True(bueno.IsRunning);        // el otro arranca igual
        Assert.Contains("malo", errores);    // el fallo se reporta, no se traga en silencio
    }

    [Fact]
    public void Snapshot_ReflejaElEstadoReal()
    {
        var host = new ModuleHost();
        var a = new FakeModule("a"); var b = new FakeModule("b");
        host.Register(a); host.Register(b);
        host.Apply(On("a")); // solo a

        var snap = host.Snapshot().ToDictionary(s => s.Code, s => s.Running);

        Assert.True(snap["a"]);
        Assert.False(snap["b"]);
    }

    [Fact]
    public void Register_RechazaCodigoDuplicado()
    {
        var host = new ModuleHost();
        host.Register(new FakeModule("x"));
        Assert.Throws<InvalidOperationException>(() => host.Register(new FakeModule("x")));
    }
}
