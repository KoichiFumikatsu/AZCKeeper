using System;
using System.IO;
using AZCKeeper.K4.Shell;
using Xunit;

namespace AZCKeeper.K4.Tests;

/// <summary>
/// El store cifra con DPAPI real (CurrentUser) sobre disco temporal. Verifica el
/// round-trip y que ausencia/corrupción se traten como "no hay", no como excepción —
/// un cliente no debe morir porque el token no exista todavía o un blob esté dañado.
/// </summary>
public class K4CredentialStoreTests : IDisposable
{
    private readonly string _dir;
    private readonly K4CredentialStore _store;

    public K4CredentialStoreTests()
    {
        _dir = Path.Combine(Path.GetTempPath(), "k4cred_" + Guid.NewGuid().ToString("N"));
        _store = new K4CredentialStore(_dir);
    }

    public void Dispose()
    {
        try { Directory.Delete(_dir, recursive: true); } catch { }
    }

    [Fact]
    public void Token_RoundTrip()
    {
        Assert.Null(_store.LoadToken()); // sin archivo -> null, no excepción
        _store.SaveToken("abc123");
        Assert.Equal("abc123", _store.LoadToken());
    }

    [Fact]
    public void Token_Clear_BorraElArchivo()
    {
        _store.SaveToken("abc123");
        _store.ClearToken();
        Assert.Null(_store.LoadToken());
    }

    [Fact]
    public void Credenciales_RoundTrip_PreservaContrasenaConSaltos()
    {
        // La contraseña trae un \n propio. Como solo el PRIMER \n separa cc de pass, la
        // contraseña se conserva entera (el CC es una cédula, nunca trae saltos).
        _store.SaveCredentials("K4TEST", "pa\nss:con raros");
        var creds = _store.LoadCredentials();
        Assert.NotNull(creds);
        Assert.Equal("K4TEST", creds!.Value.Cc);
        Assert.Equal("pa\nss:con raros", creds.Value.Password);
    }

    [Fact]
    public void HasCredentials_ReflejaElEstado()
    {
        Assert.False(_store.HasCredentials());
        _store.SaveCredentials("u", "p");
        Assert.True(_store.HasCredentials());
        _store.ClearCredentials();
        Assert.False(_store.HasCredentials());
    }

    [Fact]
    public void EscrituraAtomica_NoDejaTemp()
    {
        _store.SaveToken("x");
        Assert.False(File.Exists(Path.Combine(_dir, "token.bin.tmp")));
        Assert.True(File.Exists(Path.Combine(_dir, "token.bin")));
    }
}
