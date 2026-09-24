using System.Runtime.Versioning;
using System.Security;
using System.Security.AccessControl;
using System.Security.Cryptography;
using System.Security.Principal;
using System.Text;
using System.Text.Json;
using Keeper.Shared.Contracts;

namespace Keeper.Bootstrapper.Hardening;

[SupportedOSPlatform("windows")]
public sealed class WindowsHardeningRunner(ISystemPaths paths, Action<string> log) : IHardeningRunner
{
    public int Run(HardeningConfig config, bool dryRun, bool explicitCommand, bool undo = false)
    {
        var directory = Path.Combine(paths.InstallDirectory, "v4", "hardening");
        return new HardeningCoordinator(new WindowsLocalAccounts(), new WindowsSecurityPolicy(),
            new DpapiHardeningSecret(config.PasswordFile ?? Path.Combine(directory, "password.dpapi")),
            new FileHardeningStateStore(paths, directory), log).Run(config, dryRun, explicitCommand, undo);
    }
}

[SupportedOSPlatform("windows")]
public sealed class DpapiHardeningSecret(string path) : IHardeningSecret
{
    public SecureString Read()
    {
        ProtectedHardeningFile.Validate(path);
        if (new FileInfo(path).Length > 16384) throw new InvalidDataException("secret_file_too_large");
        var encrypted = File.ReadAllBytes(path);
        var bytes = ProtectedData.Unprotect(encrypted, null, DataProtectionScope.LocalMachine);
        var characters = new char[Encoding.UTF8.GetMaxCharCount(bytes.Length)];
        var secret = new SecureString();
        try
        {
            var length = new UTF8Encoding(false, true).GetChars(bytes, characters);
            if (length == 0 || characters.AsSpan(0, length).Contains('\0')) throw new InvalidDataException("invalid_secret");
            foreach (var character in characters.AsSpan(0, length)) secret.AppendChar(character);
            secret.MakeReadOnly();
            return secret;
        }
        catch { secret.Dispose(); throw; }
        finally { CryptographicOperations.ZeroMemory(bytes); Array.Clear(characters); }
    }
}

[SupportedOSPlatform("windows")]
internal static class ProtectedHardeningFile
{
    public static void Validate(string path)
    {
        RejectLinks(path);
        ValidateSecurity(new FileInfo(path).GetAccessControl(AccessControlSections.Access | AccessControlSections.Owner));
    }
    public static void ValidateDirectory(string path)
    {
        RejectLinks(path);
        ValidateSecurity(new DirectoryInfo(path).GetAccessControl(AccessControlSections.Access | AccessControlSections.Owner));
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
        if (owner is not ("S-1-5-18" or AccountSids.Administrators)) throw new IOException("hardening_file_owner_not_privileged");
        var descriptor = new RawSecurityDescriptor(security.GetSecurityDescriptorBinaryForm(), 0);
        if (descriptor.DiscretionaryAcl is null) throw new IOException("hardening_file_null_dacl");
        foreach (FileSystemAccessRule rule in security.GetAccessRules(true, true, typeof(SecurityIdentifier)))
            if (rule.AccessControlType == AccessControlType.Allow && rule.IdentityReference.Value is not ("S-1-5-18" or AccountSids.Administrators))
                throw new IOException("hardening_file_acl_not_private");
    }
}

[SupportedOSPlatform("windows")]
public sealed class FileHardeningStateStore(ISystemPaths paths, string directory) : IHardeningStateStore
{
    private string StatePath => Path.Combine(directory, "state.json");
    public IDisposable Acquire()
    {
        paths.ValidateInstallTree();
        ProtectedHardeningFile.ValidateDirectory(paths.InstallDirectory);
        ProtectedHardeningFile.ValidateDirectory(Path.GetDirectoryName(directory)!);
        paths.CreateProtectedDirectory(directory);
        return new FileStream(Path.Combine(directory, "operation.lock"), FileMode.OpenOrCreate,
            FileAccess.ReadWrite, FileShare.None, 1, FileOptions.DeleteOnClose);
    }
    public HardeningState? Read()
    {
        if (!File.Exists(StatePath)) return null;
        ProtectedHardeningFile.Validate(StatePath);
        return JsonSerializer.Deserialize<HardeningState>(File.ReadAllBytes(StatePath), InstallationConfig.Json)
            ?? throw new InvalidDataException("invalid_hardening_state");
    }
    public void Write(HardeningState state)
    {
        var temporary = Path.Combine(directory, "state." + Guid.NewGuid().ToString("N") + ".tmp");
        try
        {
            using (var stream = new FileStream(temporary, FileMode.CreateNew, FileAccess.Write, FileShare.None,
                       4096, FileOptions.WriteThrough))
            {
                JsonSerializer.Serialize(stream, state, InstallationConfig.Json);
                stream.Flush(true);
            }
            File.Move(temporary, StatePath, true);
        }
        finally { if (File.Exists(temporary)) File.Delete(temporary); }
    }
}
