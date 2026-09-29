using Keeper.Agent.Modules.Update;

namespace Keeper.Agent.Tests;

public sealed class PackageLayoutTests
{
    private static void Touch(string path) { Directory.CreateDirectory(Path.GetDirectoryName(path)!); File.WriteAllText(path, "x"); }

    [Fact]
    public void FormatoCompartidoPrefiereElBootstrapperDentroDeAgent()
    {
        using var d = new TestDirectory();
        Touch(Path.Combine(d.Root, "agent", "Keeper.Agent.exe"));
        Touch(Path.Combine(d.Root, "agent", "Keeper.Bootstrapper.exe"));
        Touch(Path.Combine(d.Root, "Keeper.Bootstrapper.exe"));
        Assert.Equal((Path.Combine(d.Root, "agent", "Keeper.Bootstrapper.exe"), Path.Combine(d.Root, "agent")), ReleasePackageLayout.Resolve(d.Root));
    }

    [Fact]
    public void FormatoAnteriorUsaElBootstrapperDeLaRaiz()
    {
        using var d = new TestDirectory();
        Touch(Path.Combine(d.Root, "agent", "Keeper.Agent.exe"));
        Touch(Path.Combine(d.Root, "Keeper.Bootstrapper.exe"));
        Assert.Equal((Path.Combine(d.Root, "Keeper.Bootstrapper.exe"), Path.Combine(d.Root, "agent")), ReleasePackageLayout.Resolve(d.Root));
    }

    [Theory]
    [InlineData(false, true)]    // sin agente
    [InlineData(true, false)]    // sin bootstrapper en ningun sitio
    public void PaqueteIncompletoSeRechaza(bool agent, bool bootstrapper)
    {
        using var d = new TestDirectory();
        if (agent) Touch(Path.Combine(d.Root, "agent", "Keeper.Agent.exe"));
        if (bootstrapper) Touch(Path.Combine(d.Root, "Keeper.Bootstrapper.exe"));
        Assert.Equal("update_package_incomplete", Assert.Throws<FileNotFoundException>(() => ReleasePackageLayout.Resolve(d.Root)).Message);
    }
}
