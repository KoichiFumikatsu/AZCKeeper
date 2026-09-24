using System.ComponentModel;
using System.Diagnostics;
using System.IO.Compression;
using System.Reflection;
using System.Security.AccessControl;
using System.Security.Cryptography;
using System.Security.Principal;
using System.Text.Json;
using System.Text.Json.Nodes;
using Keeper.Agent.Migration;
using Keeper.Agent.Storage;
using Keeper.Shared.Protocol;
using Microsoft.Win32;

namespace Keeper.Bridge;

internal sealed record BridgeTrust(InstallationTrustDocument Trust, BridgePublisher Publisher);
internal sealed record Receipt(long Sequence, long PreviousSequence, string Sha256);

internal static class Program
{
    private static readonly string MachineRoot = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.CommonApplicationData), "AZCKeeper", "bridge");
    private static readonly string UserRoot = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData), "AZCKeeper", "migration");
    private static readonly string MachineAttempt = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.CommonApplicationData), "AZCKeeper", "migration-attempt.json");

    public static async Task<int> Main(string[] args)
    {
        if (args.Length == 1 && args[0] is "--enrollment-key" or "--enroll")
        {
            try { return await EnrollmentCommand.RunAsync(args[0]); }
            catch { Console.WriteLine("{\"phase\":\"excepcion\",\"code\":\"enrollment_failed\"}"); return 1; }
        }
        string? restartK3 = null;
        if (args.Length == 3 && !args[0].StartsWith("--", StringComparison.Ordinal))
        {
            // Wire format of K3's existing updater: targetDir, extractPath, oldExe. Never copy over K3.
            var target = Path.GetFullPath(args[0]).TrimEnd(Path.DirectorySeparatorChar);
            var source = Path.GetFullPath(args[1]).TrimEnd(Path.DirectorySeparatorChar);
            var oldExe = Path.GetFullPath(args[2]);
            if (!string.Equals(Path.GetDirectoryName(oldExe), target, StringComparison.OrdinalIgnoreCase) ||
                !string.Equals(source, AppContext.BaseDirectory.TrimEnd(Path.DirectorySeparatorChar), StringComparison.OrdinalIgnoreCase) ||
                !File.Exists(oldExe)) return 1;
            restartK3 = oldExe;
            args = ["--launch", Path.Combine(source, "release.json"), Path.Combine(source, "bridge.zip")];
        }
        var result = await RunAsync(args);
        if (restartK3 is not null)
        {
            // The legacy caller exits after launching its updater; preserve service until v4 is verified.
            await Task.Delay(2000);
            try
            {
                // Otherwise the unchanged K3 assembly immediately redownloads/restarts the same bridge forever.
                var configPath = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.ApplicationData), "AZCKeeper", "Config", "client_config.json");
                var config = JsonNode.Parse(await File.ReadAllBytesAsync(configPath))!.AsObject();
                var updates = config["Updates"]?.AsObject() ?? throw new InvalidDataException("legacy_updates_missing");
                Directory.CreateDirectory(UserRoot);
                var previousPath = Path.Combine(UserRoot, "legacy-update-setting.json");
                if (!File.Exists(previousPath)) await AtomicFile.WriteAsync(previousPath, JsonSerializer.SerializeToUtf8Bytes(new { EnableAutoUpdate = updates["EnableAutoUpdate"]?.GetValue<bool>() }), default);
                updates["EnableAutoUpdate"] = false;
                await AtomicFile.WriteAsync(configPath, JsonSerializer.SerializeToUtf8Bytes(config), default);
                Process.Start(new ProcessStartInfo(restartK3) { UseShellExecute = true, WorkingDirectory = Path.GetDirectoryName(restartK3) });
            }
            catch (Win32Exception) { return 1; }
        }
        return result;
    }

    private static async Task<int> RunAsync(string[] args)
    {
        try
        {
            if (args.Length != 3 || args[0] is not ("--launch" or "--elevated")) throw new ArgumentException("bridge_arguments");
            using var resource = Assembly.GetExecutingAssembly().GetManifestResourceStream("bridge-trust.json")!;
            var pins = JsonSerializer.Deserialize<BridgeTrust>(resource) ?? throw new InvalidDataException("bridge_trust_missing");
            using var trust = InstalledTrust.FromDocument(pins.Trust, AppContext.BaseDirectory);
            if (trust.ReleaseKeys.Count == 0) throw new InvalidDataException("bridge_not_provisioned");
            var releasePath = Path.GetFullPath(args[1]);
            var packagePath = Path.GetFullPath(args[2]);
            var releaseBytes = await File.ReadAllBytesAsync(releasePath);
            var release = JsonSerializer.Deserialize<Release>(releaseBytes, ProtocolJson.Options) ?? throw new InvalidDataException("release_missing");
            using var machineKey = Registry.LocalMachine.OpenSubKey(@"SOFTWARE\AZCKeeper\Migration");
            var highest = Convert.ToInt64(machineKey?.GetValue("Sequence", 0) ?? 0);
            var receiptPath = Path.Combine(MachineRoot, "receipt.json");
            var receipt = File.Exists(receiptPath) ? JsonSerializer.Deserialize<Receipt>(await File.ReadAllBytesAsync(receiptPath)) : null;
            var resume = receipt is not null && receipt.Sequence == release.Sequence && receipt.Sha256 == release.Sha256 && highest == release.Sequence;
            var floor = resume ? receipt!.PreviousSequence : highest;
            await using var package = new FileStream(packagePath, FileMode.Open, FileAccess.Read, FileShare.Read);
            await BridgePackageVerifier.VerifyAsync(release, package, trust, floor,
                System.Runtime.InteropServices.RuntimeInformation.OSArchitecture == System.Runtime.InteropServices.Architecture.Arm64 ? ReleaseArchitecture.Arm64 : ReleaseArchitecture.X64, default);
            Authenticode.Verify(Environment.ProcessPath!, pins.Publisher);

            if (args[0] == "--launch")
            {
                Directory.CreateDirectory(UserRoot);
                var attemptPath = Path.Combine(UserRoot, "attempt.json");
                // Recorded before ShellExecute: crashes and cancellations cannot cause a second prompt.
                if (File.Exists(attemptPath) || File.Exists(MachineAttempt)) return 2;
                Directory.CreateDirectory(Path.GetDirectoryName(MachineAttempt)!);
                using (var machineAttempt = new FileStream(MachineAttempt, FileMode.CreateNew, FileAccess.Write, FileShare.None))
                {
                    JsonSerializer.Serialize(machineAttempt, new { phase = "excepcion", code = "elevation_pending_or_cancelled", sequence = release.Sequence });
                    machineAttempt.Flush(flushToDisk: true);
                }
                using (var attempt = new FileStream(attemptPath, FileMode.CreateNew, FileAccess.Write, FileShare.None))
                    JsonSerializer.Serialize(attempt, new { phase = "excepcion", code = "elevation_pending_or_cancelled", sequence = release.Sequence });
                try
                {
                    var start = new ProcessStartInfo(Environment.ProcessPath!) { UseShellExecute = true, Verb = "runas" };
                    start.ArgumentList.Add("--elevated"); start.ArgumentList.Add(releasePath); start.ArgumentList.Add(packagePath);
                    using var elevated = Process.Start(start) ?? throw new InvalidOperationException("elevation_start_failed");
                    await elevated.WaitForExitAsync();
                    return elevated.ExitCode;
                }
                catch (Win32Exception ex) when (ex.NativeErrorCode == 1223)
                {
                    // K3 reads this result and forwards the report through its authorized IT adapter.
                    await File.WriteAllTextAsync(attemptPath, "{\"phase\":\"excepcion\",\"code\":\"uac_cancelled\"}");
                    Console.WriteLine("{\"phase\":\"excepcion\",\"code\":\"uac_cancelled\"}");
                    return 1223;
                }
            }

            using var identity = WindowsIdentity.GetCurrent();
            if (!new WindowsPrincipal(identity).IsInRole(WindowsBuiltInRole.Administrator)) throw new UnauthorizedAccessException("elevation_required");
            SecureDirectory(Path.GetDirectoryName(MachineRoot)!);
            SecureDirectory(MachineRoot);
            if (File.Exists(MachineAttempt)) SecureFile(MachineAttempt);
            using var lease = new FileStream(Path.Combine(MachineRoot, "bridge.lock"), FileMode.OpenOrCreate, FileAccess.ReadWrite, FileShare.None);
            // Re-read under the lease: another accepted release may have advanced the floor during verification/UAC.
            using (var currentKey = Registry.LocalMachine.OpenSubKey(@"SOFTWARE\AZCKeeper\Migration"))
                highest = Convert.ToInt64(currentKey?.GetValue("Sequence", 0) ?? 0);
            receipt = File.Exists(receiptPath) ? JsonSerializer.Deserialize<Receipt>(await File.ReadAllBytesAsync(receiptPath)) : null;
            resume = receipt is not null && receipt.Sequence == release.Sequence && receipt.Sha256 == release.Sha256 && highest == release.Sequence;
            floor = resume ? receipt!.PreviousSequence : highest;
            package.Position = 0;
            await BridgePackageVerifier.VerifyAsync(release, package, trust, floor,
                System.Runtime.InteropServices.RuntimeInformation.OSArchitecture == System.Runtime.InteropServices.Architecture.Arm64 ? ReleaseArchitecture.Arm64 : ReleaseArchitecture.X64, default);
            var staging = Path.Combine(MachineRoot, release.Sequence + "-" + Guid.NewGuid().ToString("N"));
            SecureDirectory(staging);
            package.Position = 0;
            using (var zip = new ZipArchive(package, ZipArchiveMode.Read, leaveOpen: true))
            {
                if (zip.Entries.Count > 2000 || zip.Entries.Sum(e => e.Length) > 1024L * 1024 * 1024) throw new InvalidDataException("bridge_package_too_large");
                var paths = new HashSet<string>(StringComparer.OrdinalIgnoreCase);
                foreach (var entry in zip.Entries)
                {
                    var path = BridgePackageVerifier.SafeEntryPath(staging, entry.FullName);
                    if (!paths.Add(path) || (entry.ExternalAttributes & 0x400) != 0 || ((entry.ExternalAttributes >> 16) & 0xF000) == 0xA000)
                        throw new InvalidDataException("bridge_unsafe_archive");
                    if (entry.FullName.EndsWith('/')) { SecureDirectory(path); continue; }
                    SecureDirectory(Path.GetDirectoryName(path)!);
                    entry.ExtractToFile(path, overwrite: false);
                    SecureFile(path);
                }
            }
            foreach (var file in new[] { "Keeper.msi", "Keeper.Bridge.exe", "Bootstrap.ps1", "Migration.Journal.ps1", "Migration.Enrollment.ps1", "Migration.Native.cs", "Entry.ps1", "Integration.ps1", "libsodium.dll", "publisher.cer", "deployment.json" })
                if (!File.Exists(Path.Combine(staging, file))) throw new InvalidDataException("incomplete_bridge_package");
            Authenticode.Verify(Path.Combine(staging, "Keeper.msi"), pins.Publisher);
            Authenticode.Verify(Path.Combine(staging, "Keeper.Bridge.exe"), pins.Publisher);
            using (var cert = new System.Security.Cryptography.X509Certificates.X509Certificate2(Path.Combine(staging, "publisher.cer")))
                BridgePackageVerifier.VerifyPublisher(cert, pins.Publisher, DateTimeOffset.UtcNow);
            var nextReceipt = new Receipt(release.Sequence, Math.Max(trust.InstalledSequence, floor), release.Sha256);
            await AtomicFile.WriteAsync(receiptPath, JsonSerializer.SerializeToUtf8Bytes(nextReceipt), default);
            SecureFile(receiptPath);
            using (var writable = Registry.LocalMachine.CreateSubKey(@"SOFTWARE\AZCKeeper\Migration"))
            {
                var registryAcl = new RegistrySecurity();
                registryAcl.SetOwner(new SecurityIdentifier("S-1-5-32-544"));
                registryAcl.SetAccessRuleProtection(true, false);
                foreach (var sid in new[] { "S-1-5-18", "S-1-5-32-544" })
                    registryAcl.AddAccessRule(new RegistryAccessRule(new SecurityIdentifier(sid), RegistryRights.FullControl, AccessControlType.Allow));
                registryAcl.AddAccessRule(new RegistryAccessRule(new SecurityIdentifier("S-1-5-32-545"), RegistryRights.ReadKey, AccessControlType.Allow));
                writable.SetAccessControl(registryAcl);
                writable.SetValue("Sequence", release.Sequence, RegistryValueKind.QWord);
            }
            // Entry receives pins from the compiled launcher; a downloaded certificate cannot nominate itself.
            await File.WriteAllBytesAsync(Path.Combine(staging, "publisher-pin.json"), JsonSerializer.SerializeToUtf8Bytes(pins.Publisher));
            SecureFile(Path.Combine(staging, "publisher-pin.json"));
            var script = Path.Combine(staging, "Entry.ps1");
            var powershell = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.System), @"WindowsPowerShell\v1.0\powershell.exe");
            var execute = new ProcessStartInfo(powershell) { UseShellExecute = false, CreateNoWindow = true };
            foreach (var arg in new[] { "-NoProfile", "-NonInteractive", "-ExecutionPolicy", "Bypass", "-File", script, "-Schedule" }) execute.ArgumentList.Add(arg);
            using var worker = Process.Start(execute) ?? throw new InvalidOperationException("worker_start_failed");
            await worker.WaitForExitAsync();
            return worker.ExitCode;
        }
        catch
        {
            // Never print arbitrary exception messages or HTTP payloads.
            Directory.CreateDirectory(UserRoot);
            await File.WriteAllTextAsync(Path.Combine(UserRoot, "exception.json"), "{\"phase\":\"excepcion\",\"code\":\"bridge_verification_failed\"}");
            Console.WriteLine("{\"phase\":\"excepcion\",\"code\":\"bridge_verification_failed\"}");
            return 1;
        }
    }

    private static void SecureDirectory(string path)
    {
        for (var parent = new DirectoryInfo(path); parent is not null; parent = parent.Parent)
            if (parent.Exists && (parent.Attributes & FileAttributes.ReparsePoint) != 0) throw new IOException("bridge_reparse_point");
        var acl = new DirectorySecurity();
        // The work user's SID must not remain owner after demotion (owners can change the DACL).
        acl.SetOwner(new SecurityIdentifier("S-1-5-32-544"));
        acl.SetAccessRuleProtection(true, false);
        foreach (var sid in new[] { "S-1-5-18", "S-1-5-32-544" })
            acl.AddAccessRule(new FileSystemAccessRule(new SecurityIdentifier(sid), FileSystemRights.FullControl,
                InheritanceFlags.ContainerInherit | InheritanceFlags.ObjectInherit, PropagationFlags.None, AccessControlType.Allow));
        new DirectoryInfo(path).Create(acl);
        new DirectoryInfo(path).SetAccessControl(acl);
    }

    private static void SecureFile(string path)
    {
        var acl = new FileSecurity();
        acl.SetOwner(new SecurityIdentifier("S-1-5-32-544"));
        acl.SetAccessRuleProtection(true, false);
        foreach (var sid in new[] { "S-1-5-18", "S-1-5-32-544" })
            acl.AddAccessRule(new FileSystemAccessRule(new SecurityIdentifier(sid), FileSystemRights.FullControl, AccessControlType.Allow));
        new FileInfo(path).SetAccessControl(acl);
    }
}
