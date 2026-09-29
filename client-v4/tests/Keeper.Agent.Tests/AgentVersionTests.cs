using Keeper.Agent.Migration;
using Keeper.Agent.Storage;

namespace Keeper.Agent.Tests;

public sealed class AgentVersionTests
{
    [Fact]
    public void MarcaDetectaPrimerArranqueYCambioDeVersionPeroNoRepeticion()
    {
        using var directory = new TestDirectory();
        var path = directory.File("agent-version.txt");
        Assert.True(AgentVersionMarker.Changed(path, new Version(4, 0, 2)));   // sin marca previa (update desde <= 4.0.2)
        Assert.False(AgentVersionMarker.Changed(path, new Version(4, 0, 2)));  // reinicio normal: conserva token
        Assert.True(AgentVersionMarker.Changed(path, new Version(4, 0, 3)));   // update aplicado
        Assert.Equal("4.0.3", File.ReadAllText(path));
        Assert.False(File.Exists(path + ".tmp"));
    }

    [Fact]
    public void ElLoginReportaLaVersionRealDelEnsambladoNoUnaFija()
    {
        using var key = System.Security.Cryptography.ECDsa.Create(System.Security.Cryptography.ECCurve.NamedCurves.nistP256);
        using var signer = new Keeper.Agent.Transport.HttpMessageSigner(key);
        var login = MigrationEnrollment.CreateLogin(signer, Guid.NewGuid(), null);
        Assert.Equal(AgentIdentity.Version.ToString(3), login.AgentVersion);
    }
}
