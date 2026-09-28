using System.Runtime.Versioning;
using System.Security.AccessControl;
using System.Security.Cryptography;
using System.Security.Principal;
using Keeper.Agent.Modules.Security;

namespace Keeper.Agent.Storage;

[SupportedOSPlatform("windows")]
public sealed class DpapiHardeningPasswordProtector : IHardeningPasswordProtector
{
    public byte[] Protect(byte[] plaintext) => ProtectedData.Protect(plaintext, null, DataProtectionScope.LocalMachine);
    public byte[] Unprotect(byte[] encrypted) => ProtectedData.Unprotect(encrypted, null, DataProtectionScope.LocalMachine);
}

[SupportedOSPlatform("windows")]
public sealed class WindowsHardeningPasswordFileSystem : IHardeningPasswordFileSystem
{
    private const AccessControlSections Sections = AccessControlSections.Owner | AccessControlSections.Group | AccessControlSections.Access;

    public void PrepareDirectory(string directory)
    {
        RejectLinks(directory);
        var parent = Directory.GetParent(directory) ?? throw new IOException("hardening_directory_missing");
        ValidateSecurity(parent.GetAccessControl(Sections));
        ValidateSecurity((parent.Parent ?? throw new IOException("hardening_directory_missing")).GetAccessControl(Sections));
        var info = new DirectoryInfo(directory);
        if (info.Exists) ValidateSecurity(info.GetAccessControl(Sections));
        else
        {
            var security = new DirectorySecurity();
            security.SetSecurityDescriptorSddlForm("O:BAG:BAD:P(A;OICI;FA;;;SY)(A;OICI;FA;;;BA)");
            info.Create(security);
        }
    }

    public bool Exists(string path) => File.Exists(path);

    public void EnsureFileSecurity(string path, string sddl)
    {
        RejectLinks(path);
        var file = new FileInfo(path);
        var security = file.GetAccessControl(Sections);
        ValidateSecurity(security);
        if (security.GetSecurityDescriptorSddlForm(Sections) != sddl) file.SetAccessControl(Security(sddl));
    }

    public byte[] ReadAllBytes(string path)
    {
        RejectLinks(path);
        if (new FileInfo(path).Length > 16384) throw new InvalidDataException("secret_file_too_large");
        return File.ReadAllBytes(path);
    }

    public void WriteNew(string path, byte[] encrypted, string sddl)
    {
        RejectLinks(path);
        using var stream = new FileInfo(path).Create(FileMode.CreateNew, FileSystemRights.Write, FileShare.None,
            4096, FileOptions.WriteThrough, Security(sddl));
        stream.Write(encrypted);
        stream.Flush(true);
    }

    public void Move(string source, string destination)
    {
        RejectLinks(source);
        RejectLinks(destination);
        File.Move(source, destination, overwrite: true);
    }

    public void Delete(string path) => File.Delete(path);

    private static FileSecurity Security(string sddl)
    {
        var security = new FileSecurity();
        security.SetSecurityDescriptorSddlForm(sddl);
        return security;
    }

    private static void RejectLinks(string path)
    {
        for (var current = Path.GetFullPath(path); current is not null; current = Path.GetDirectoryName(current))
            if ((File.Exists(current) || Directory.Exists(current)) && (File.GetAttributes(current) & FileAttributes.ReparsePoint) != 0)
                throw new IOException("hardening_reparse_point");
    }

    private static void ValidateSecurity(FileSystemSecurity security)
    {
        var owner = security.GetOwner(typeof(SecurityIdentifier))?.Value;
        if (owner is not ("S-1-5-18" or "S-1-5-32-544")) throw new IOException("hardening_file_owner_not_privileged");
        var descriptor = new RawSecurityDescriptor(security.GetSecurityDescriptorBinaryForm(), 0);
        if (descriptor.DiscretionaryAcl is null) throw new IOException("hardening_file_null_dacl");
        foreach (FileSystemAccessRule rule in security.GetAccessRules(true, true, typeof(SecurityIdentifier)))
            if (rule.AccessControlType == AccessControlType.Allow && rule.IdentityReference.Value is not ("S-1-5-18" or "S-1-5-32-544"))
                throw new IOException("hardening_file_acl_not_private");
    }
}
