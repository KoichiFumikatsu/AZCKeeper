using System.Runtime.Versioning;
using System.Security.AccessControl;

namespace Keeper.Bootstrapper;

[SupportedOSPlatform("windows")]
public sealed class WindowsSystemPaths : ISystemPaths
{
    public WindowsSystemPaths() : this(Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.CommonApplicationData), "AZCKeeper")) { }
    internal WindowsSystemPaths(string installDirectory) => InstallDirectory = Path.GetFullPath(installDirectory).TrimEnd(Path.DirectorySeparatorChar);
    public string InstallDirectory { get; }
    public string ReadText(string path) => File.ReadAllText(path);

    public IReadOnlyList<string> PayloadFiles(string directory)
    {
        RejectLinks(directory);
        return Walk(new DirectoryInfo(directory)).OfType<FileInfo>()
            .Select(file => Path.GetRelativePath(directory, file.FullName)).ToArray();
    }

    public void ValidateInstallTree(bool uninstall = false)
    {
        if (uninstall)
        {
            RejectLinks(Path.GetDirectoryName(InstallDirectory)!);
            return;
        }
        RejectLinks(InstallDirectory);
        if (Directory.Exists(InstallDirectory)) _ = Walk(new DirectoryInfo(InstallDirectory)).ToArray();
    }

    public void CreateProtectedDirectory(string path)
    {
        RequireInstallPath(path);
        RejectLinks(path);
        var security = new DirectorySecurity();
        security.SetSecurityDescriptorSddlForm(WindowsConstants.DirectorySddl);
        var directory = new DirectoryInfo(path);
        if (!directory.Exists) directory.Create(security);
        else directory.SetAccessControl(security);
        var fileSecurity = new FileSecurity();
        fileSecurity.SetSecurityDescriptorSddlForm(WindowsConstants.FileSddl);
        foreach (var entry in Walk(directory))
        {
            if (entry is DirectoryInfo child) child.SetAccessControl(security);
            else
            {
                ((FileInfo)entry).SetAccessControl(fileSecurity);
            }
        }
    }

    public void ApplyBinaryAcl(string path)
    {
        RequireInstallPath(path);
        RejectLinks(path);
        var security = new DirectorySecurity();
        security.SetSecurityDescriptorSddlForm(WindowsConstants.BinDirectorySddl);
        var directory = new DirectoryInfo(path);
        directory.SetAccessControl(security);
        var fileSecurity = new FileSecurity();
        fileSecurity.SetSecurityDescriptorSddlForm(WindowsConstants.BinFileSddl);
        foreach (var entry in Walk(directory))
        {
            if (entry is DirectoryInfo child) child.SetAccessControl(security);
            else ((FileInfo)entry).SetAccessControl(fileSecurity);
        }
    }

    public void CopyFile(string source, string destination)
    {
        RequireInstallPath(destination);
        RejectLinks(source);
        RejectLinks(destination);
        Directory.CreateDirectory(Path.GetDirectoryName(destination)!);
        File.Copy(source, destination, overwrite: true);
    }

    public void CreateDirectory(string path)
    {
        RequireInstallPath(path);
        RejectLinks(path);
        Directory.CreateDirectory(path);
    }

    public void DeleteInstallDirectory()
    {
        ValidateInstallTree(uninstall: true);
        var directory = new DirectoryInfo(InstallDirectory);
        if (directory.Exists || directory.LinkTarget is not null) DeleteEntry(directory);
    }

    private static void DeleteEntry(FileSystemInfo entry)
    {
        if (entry is DirectoryInfo directory && (entry.Attributes & FileAttributes.ReparsePoint) == 0)
            foreach (var child in directory.EnumerateFileSystemInfos()) DeleteEntry(child);
        entry.Delete();
    }

    private void RequireInstallPath(string path)
    {
        var full = Path.GetFullPath(path);
        if (!full.Equals(InstallDirectory, StringComparison.OrdinalIgnoreCase) &&
            !full.StartsWith(InstallDirectory + Path.DirectorySeparatorChar, StringComparison.OrdinalIgnoreCase))
            throw new IOException("Ruta fuera de ProgramData\\AZCKeeper.");
    }

    private static void RejectLinks(string path)
    {
        for (var current = Path.GetFullPath(path); current is not null; current = Path.GetDirectoryName(current))
            if ((File.Exists(current) || Directory.Exists(current)) && (File.GetAttributes(current) & FileAttributes.ReparsePoint) != 0)
                throw new IOException($"No se permiten enlaces/reparse points: {current}");
    }

    private static IEnumerable<FileSystemInfo> Walk(DirectoryInfo directory)
    {
        foreach (var entry in directory.EnumerateFileSystemInfos())
        {
            if ((entry.Attributes & FileAttributes.ReparsePoint) != 0) throw new IOException($"No se permiten enlaces/reparse points: {entry.FullName}");
            yield return entry;
            if (entry is DirectoryInfo child)
                foreach (var descendant in Walk(child)) yield return descendant;
        }
    }
}
