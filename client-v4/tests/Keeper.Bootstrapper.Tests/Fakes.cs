using System.Text.Json;
using Keeper.Bootstrapper;

namespace Keeper.Bootstrapper.Tests;

internal sealed class FakeElevation : IElevation
{
    public bool Elevated { get; set; } = true;
    public bool ForbidCheck { get; set; }
    public bool IsElevated => ForbidCheck ? throw new InvalidOperationException("Dry-run consulted elevation") : Elevated;
    public int Result { get; set; }
    public List<string[]> Requests { get; } = [];
    public int Relaunch(string[] arguments) { Requests.Add(arguments); return Result; }
}

internal sealed class FakeRescue(FakeMachine machine) : IRescueInstaller
{
    public bool IsInstalled { get; set; }
    public void Install(string payload) { machine.Mutations.Add("rescue-install"); IsInstalled = true; }
    public void Remove() { machine.Mutations.Add("rescue-remove"); IsInstalled = false; }
}

internal sealed class FakeGuard(FakeMachine machine) : IUpdateGuard
{
    public UpdateHealth Result { get; set; } = UpdateHealth.Healthy;
    public long? Sequence { get; set; } = 7;
    public long? Blocked { get; private set; }
    public void Backup(string bin) { Assert.False(machine.Running); machine.Mutations.Add("backup"); }
    public void Restore(string bin) { Assert.False(machine.Running); machine.Mutations.Add("restore"); }
    public UpdateHealth WaitHealthy(DateTimeOffset since) { Assert.True(machine.Running); machine.Mutations.Add("health"); return Result; }
    public long? PayloadSequence(string payload) => Sequence;
    public void BlockRelease(long sequence) { Blocked = sequence; machine.Mutations.Add("block"); }
}

internal sealed class FakeMachine : IServiceControl, ISystemPaths, IRegistryStore
{
    public string InstallDirectory => Path.GetFullPath(Path.Combine("fake-machine", "ProgramData", "AZCKeeper"));
    public InstallationConfig Config { get; set; } = new("https://keeper.test/v1/",
        Guid.Parse("11111111-1111-1111-1111-111111111111"), Guid.Parse("22222222-2222-2222-2222-222222222222"));
    public List<string> Payload { get; } = ["Keeper.Agent.exe", "coreclr.dll", "clrjit.dll"];
    public List<string> Mutations { get; } = [];
    public HashSet<string> Directories { get; } = [];
    public Dictionary<string, string> Files { get; } = [];
    public HashSet<string> RegistryTrees { get; } = [];
    public HashSet<(string, string)> RegistryValues { get; } = [];
    public ServiceDefinition? Service { get; set; }
    public bool Running { get; set; }
    public bool FailStop { get; set; }
    public bool FailCopy { get; set; }
    public string[] EnvironmentValues { get; private set; } = [];
    public string ReadText(string path) => JsonSerializer.Serialize(Config, InstallationConfig.Json);
    public IReadOnlyList<string> PayloadFiles(string directory) => Payload;
    public void ValidateInstallTree(bool uninstall = false) { }
    public bool Exists(string name) => Service?.Name == name;
    public void Stop(string name)
    {
        if (FailStop) throw new TimeoutException();
        Assert.Equal(Service!.Name, name);
        Mutations.Add("stop"); Running = false;
    }
    public void Configure(ServiceDefinition definition, bool exists)
    {
        Assert.Equal(Service is not null, exists);
        Assert.False(Running);
        Mutations.Add(exists ? "configure" : "create"); Service = definition;
    }
    public void ConfigureRecovery(string name)
    {
        Assert.Equal(Service!.Name, name);
        Mutations.Add("recovery");
    }
    public void Start(string name)
    {
        Assert.Equal(Service!.Name, name);
        Assert.NotEmpty(EnvironmentValues);
        Mutations.Add("start"); Running = true;
    }
    public void Delete(string name)
    {
        Assert.False(Running);
        Assert.Equal(Service!.Name, name);
        Mutations.Add("delete-service"); Service = null; EnvironmentValues = [];
    }
    public void CreateProtectedDirectory(string path) { Mutations.Add("mkdir-acl"); Directories.Add(path); }
    public void CreateDirectory(string path) { Mutations.Add("mkdir"); Directories.Add(path); }
    public List<string> BinaryAclPaths { get; } = [];
    public void ApplyBinaryAcl(string path) { Assert.False(Running); Mutations.Add("bin-acl"); BinaryAclPaths.Add(path); }
    public void CopyFile(string source, string destination)
    {
        Assert.False(Running);
        if (FailCopy) throw new IOException("copy_failed");
        Mutations.Add("copy"); Files[destination] = "published binary";
    }
    public void DeleteInstallDirectory()
    {
        Assert.Null(Service);
        Mutations.Add("delete-directory"); Directories.Clear(); Files.Clear();
    }
    public void SetEnvironment(string serviceName, string[] values)
    {
        Assert.Equal(Service!.Name, serviceName);
        Mutations.Add("environment"); EnvironmentValues = values;
    }
    public void DeleteTree(string path) { Mutations.Add("delete-tree:" + path); RegistryTrees.Remove(path); }
    public void DeleteValue(string path, string name) { Mutations.Add("delete-value:" + name); RegistryValues.Remove((path, name)); }
}
