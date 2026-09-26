using Keeper.Agent.Modules.Devices;

namespace Keeper.Agent.Tests;

public sealed class WipeTests
{
    private static string SeedUsers(string root, IEnumerable<string> profiles)
    {
        var users = Path.Combine(root, "Users");
        foreach (var p in profiles)
        {
            var docs = Path.Combine(users, p, "Documents");
            Directory.CreateDirectory(docs);
            File.WriteAllText(Path.Combine(docs, "datos.txt"), "confidencial");
        }
        return users;
    }

    [Fact]
    public void WipeProfilesBorraUsuariosPeroPreservaSistemaYCuentasDeIt()
    {
        var root = Path.Combine(Path.GetTempPath(), "keeper-wipe-" + Guid.NewGuid().ToString("N"));
        string[] preserved = ["Default", "Public", "Administrator", "azcadmin"];
        string[] wiped = ["colaborador1", "colaborador2"];
        try
        {
            var users = SeedUsers(root, preserved.Concat(wiped));
            WindowsDeviceActions.WipeProfiles(users, default);
            foreach (var p in preserved) Assert.True(Directory.Exists(Path.Combine(users, p)), $"{p} debe preservarse");
            foreach (var w in wiped) Assert.False(Directory.Exists(Path.Combine(users, w)), $"{w} debe borrarse");
        }
        finally { try { Directory.Delete(root, true); } catch { } }
    }

    [Fact]
    public void WipeProfilesPreservaNombresSinDistinguirMayusculas()
    {
        var root = Path.Combine(Path.GetTempPath(), "keeper-wipe-" + Guid.NewGuid().ToString("N"));
        try
        {
            var users = SeedUsers(root, ["ADMINISTRATOR", "AzcAdmin", "usuario"]);
            WindowsDeviceActions.WipeProfiles(users, default);
            Assert.True(Directory.Exists(Path.Combine(users, "ADMINISTRATOR")));
            Assert.True(Directory.Exists(Path.Combine(users, "AzcAdmin")));
            Assert.False(Directory.Exists(Path.Combine(users, "usuario")));
        }
        finally { try { Directory.Delete(root, true); } catch { } }
    }

    [Fact]
    public void WipeProfilesFallaSiNoExisteElDirectorioDeUsuarios()
    {
        var missing = Path.Combine(Path.GetTempPath(), "keeper-no-existe-" + Guid.NewGuid().ToString("N"));
        Assert.Throws<DirectoryNotFoundException>(() => WindowsDeviceActions.WipeProfiles(missing, default));
    }
}
