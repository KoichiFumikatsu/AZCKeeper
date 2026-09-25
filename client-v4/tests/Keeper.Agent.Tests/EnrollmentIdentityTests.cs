using System.Security.Cryptography;
using System.Text;
using System.Text.Json;
using Keeper.Agent.Hosting;

namespace Keeper.Agent.Tests;

public sealed class EnrollmentIdentityTests
{
    [Fact]
    public async Task PrintEnrollmentIsStableBackendBase64UrlAndReadOnly()
    {
        using var directory = new TestDirectory();
        var path = directory.File("device-key.dpapi");
        byte[] fixture = [1, 2, 3];
        await File.WriteAllBytesAsync(path, fixture);
        var parameters = new ECParameters
        {
            Curve = ECCurve.NamedCurves.nistP256,
            Q = new ECPoint
            {
                X = Convert.FromHexString("6b17d1f2e12c4247f8bce6e563a440f277037d812deb33a0f4a13945d898c296"),
                Y = Convert.FromHexString("4fe342e2fe1a7f9b8ee7eb4a7c0f9e162bce33576b315ececbb6406837bf51f5")
            },
            D = [.. new byte[31], 1]
        };
        var calls = 0;
        Task<ECDsa> Load(string actualPath, CancellationToken ct)
        {
            Assert.Equal(path, actualPath);
            calls++;
            return Task.FromResult(ECDsa.Create(parameters));
        }
        string? Setting(string name) => name switch
        {
            "KEEPER_DATA_DIR" => directory.Root,
            "KEEPER_DEVICE_ID" => Samples.Device.ToString(),
            _ => null
        };
        using var first = new StringWriter();
        using var second = new StringWriter();
        await EnrollmentIdentityCommand.PrintAsync(first, default, Setting, Load);
        await EnrollmentIdentityCommand.PrintAsync(second, default, Setting, Load);
        Assert.Equal(first.ToString(), second.ToString());
        using var document = JsonDocument.Parse(first.ToString());
        var root = document.RootElement;
        var thumbprint = root.GetProperty("public_key_thumbprint").GetString()!;
        Assert.Matches("^[A-Za-z0-9_-]{43}$", thumbprint);
        var decoded = Convert.FromBase64String(thumbprint.Replace('-', '+').Replace('_', '/') + "=");
        Assert.Equal(32, decoded.Length);
        const string canonical = "{\"crv\":\"P-256\",\"kty\":\"EC\",\"x\":\"axfR8uEsQkf4vOblY6RA8ncDfYEt6zOg9KE5RdiYwpY\",\"y\":\"T-NC4v4af5uO5-tKfA-eFivOM1drMV7Oy7ZAaDe_UfU\"}";
        Assert.Equal(SHA256.HashData(Encoding.UTF8.GetBytes(canonical)), decoded);
        Assert.Equal(Samples.Device, root.GetProperty("device_id").GetGuid());
        Assert.Equal(Environment.MachineName, root.GetProperty("hostname").GetString());
        var jwk = root.GetProperty("public_key");
        Assert.Equal("EC", jwk.GetProperty("kty").GetString());
        Assert.Equal("P-256", jwk.GetProperty("crv").GetString());
        Assert.Equal("axfR8uEsQkf4vOblY6RA8ncDfYEt6zOg9KE5RdiYwpY", jwk.GetProperty("x").GetString());
        Assert.Equal("T-NC4v4af5uO5-tKfA-eFivOM1drMV7Oy7ZAaDe_UfU", jwk.GetProperty("y").GetString());
        Assert.False(jwk.TryGetProperty("d", out _));
        Assert.Equal(2, calls);
        Assert.Equal(fixture, await File.ReadAllBytesAsync(path));
        Assert.Equal(path, Assert.Single(Directory.GetFiles(directory.Root)));
    }

    [Fact]
    public async Task PrintEnrollmentMissingKeyDoesNotCreateIdentityOrDirectories()
    {
        using var directory = new TestDirectory();
        using var output = new StringWriter();
        await Assert.ThrowsAsync<FileNotFoundException>(() => EnrollmentIdentityCommand.PrintAsync(output, default,
            name => name == "KEEPER_DATA_DIR" ? directory.Root : Samples.Device.ToString()));
        Assert.Empty(output.ToString());
        Assert.Empty(Directory.GetFileSystemEntries(directory.Root));
    }
}
