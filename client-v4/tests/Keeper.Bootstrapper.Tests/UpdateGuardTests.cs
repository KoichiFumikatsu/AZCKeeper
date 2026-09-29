using Keeper.Shared.Diagnostics;

namespace Keeper.Bootstrapper.Tests;

public sealed class UpdateGuardTests : IDisposable
{
    private readonly string _root = Path.Combine(Path.GetTempPath(), "keeper-guard-" + Guid.NewGuid().ToString("N"));
    private string Bin => Path.Combine(_root, "bin");
    private string Data => Path.Combine(_root, "v4");

    public UpdateGuardTests() { Directory.CreateDirectory(Bin); Directory.CreateDirectory(Data); }
    public void Dispose() => Directory.Delete(_root, recursive: true);

    [Fact]
    public void RespaldoYRestauracionDejanBinIdenticoAlAnterior()
    {
        File.WriteAllText(Path.Combine(Bin, "Keeper.Agent.exe"), "v6");
        Directory.CreateDirectory(Path.Combine(Bin, "es"));
        File.WriteAllText(Path.Combine(Bin, "es", "r.dll"), "es6");
        var guard = new WindowsUpdateGuard(_root, TimeSpan.Zero, _ => { });
        guard.Backup(Bin);
        File.WriteAllText(Path.Combine(Bin, "Keeper.Agent.exe"), "v7-rota");
        File.WriteAllText(Path.Combine(Bin, "solo-v7.dll"), "nuevo");
        guard.Restore(Bin);
        Assert.Equal("v6", File.ReadAllText(Path.Combine(Bin, "Keeper.Agent.exe")));
        Assert.Equal("es6", File.ReadAllText(Path.Combine(Bin, "es", "r.dll")));
        Assert.False(File.Exists(Path.Combine(Bin, "solo-v7.dll")));
    }

    [Fact]
    public void RestaurarSinRespaldoFalla() =>
        Assert.Throws<IOException>(() => new WindowsUpdateGuard(_root, TimeSpan.Zero, _ => { }).Restore(Bin));

    [Fact]
    public void LeeLaSecuenciaDelPaqueteYEscribeElBloqueo()
    {
        var payload = Path.Combine(_root, "payload");
        Directory.CreateDirectory(payload);
        File.WriteAllText(Path.Combine(payload, "installation-trust.json"), "{\"ReleasePublicKeys\":{},\"BinaryHashes\":{},\"InstalledSequence\":8,\"Channel\":\"stable\"}");
        var guard = new WindowsUpdateGuard(_root, TimeSpan.Zero, _ => { });
        Assert.Equal(8, guard.PayloadSequence(payload));
        Assert.Null(guard.PayloadSequence(Path.Combine(_root, "no-existe")));
        guard.BlockRelease(8);
        Assert.Equal("{\"sequence\":8}", File.ReadAllText(Path.Combine(Data, "update-blocked.json")));
    }

    [Fact]
    public void SyncExitosoPosteriorAlArranqueEsSano()
    {
        var since = DateTimeOffset.UtcNow.AddSeconds(-10);
        new AgentHealth("4.0.7", DateTimeOffset.UtcNow, DateTimeOffset.UtcNow).Write(Path.Combine(Data, "health.json"));
        Assert.Equal(UpdateHealth.Healthy, new WindowsUpdateGuard(_root, TimeSpan.FromSeconds(1), _ => { }).WaitHealthy(since));
    }
}
