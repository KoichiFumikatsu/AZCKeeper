using System.Security.Cryptography;
using Keeper.Agent.Hosting;

namespace Keeper.Agent.Tests;

public sealed class SessionTrustTests
{
    private static string Hash(string path) => Convert.ToHexString(SHA256.HashData(File.ReadAllBytes(path))).ToLowerInvariant();

    [Fact]
    public void SingleFileSinDllSeAceptaConSoloElExeEnElTrust()
    {
        using var directory = new TestDirectory();
        var exe = directory.File("Keeper.Session.exe");
        File.WriteAllText(exe, "single-file");
        WindowsSessionLauncher.VerifyTrustedBinaries(exe, new Dictionary<string, string> { [exe] = Hash(exe) });
    }

    [Fact]
    public void ExeFueraDelTrustSeRechaza()
    {
        using var directory = new TestDirectory();
        var exe = directory.File("Keeper.Session.exe");
        File.WriteAllText(exe, "single-file");
        var ex = Assert.Throws<InvalidDataException>(() => WindowsSessionLauncher.VerifyTrustedBinaries(exe, new Dictionary<string, string>()));
        Assert.Equal("session_trust_not_provisioned", ex.Message);
    }

    [Fact]
    public void DllSueltoNoConfiableJuntoAlExeSeRechaza()
    {
        using var directory = new TestDirectory();
        var exe = directory.File("Keeper.Session.exe");
        File.WriteAllText(exe, "apphost");
        File.WriteAllText(directory.File("Keeper.Session.dll"), "inyectado");
        var ex = Assert.Throws<InvalidDataException>(() => WindowsSessionLauncher.VerifyTrustedBinaries(exe, new Dictionary<string, string> { [exe] = Hash(exe) }));
        Assert.Equal("session_trust_not_provisioned", ex.Message);
    }

    [Fact]
    public void BinarioModificadoSeRechaza()
    {
        using var directory = new TestDirectory();
        var exe = directory.File("Keeper.Session.exe");
        File.WriteAllText(exe, "original");
        var trusted = new Dictionary<string, string> { [exe] = Hash(exe) };
        File.WriteAllText(exe, "modificado");
        Assert.Throws<CryptographicException>(() => WindowsSessionLauncher.VerifyTrustedBinaries(exe, trusted));
    }
}
