using System.Diagnostics;
using System.Runtime.Versioning;
using System.ServiceProcess;
using System.Text;
using System.Text.Json;
using Microsoft.Win32;

namespace Keeper.Agent.Modules.Enforcement;

[SupportedOSPlatform("windows")]
internal sealed class WindowsAppLockerMachine(Action<string> log) : IAppLockerMachine
{
    private const string JournalPath = @"SOFTWARE\AZCKeeper\v4\AppLocker";
    private const string ServicePath = @"SYSTEM\CurrentControlSet\Services\AppIDSvc";

    public AppLockerBackup? ReadBackup()
    {
        using var hklm = OpenMachine();
        using var key = hklm.OpenSubKey(JournalPath);
        return key?.GetValue("Backup") is string json
            ? JsonSerializer.Deserialize<AppLockerBackup>(json) ?? throw new InvalidDataException("invalid_applocker_backup")
            : null;
    }

    public void SaveBackup(AppLockerBackup backup)
    {
        using var hklm = OpenMachine();
        using var key = hklm.CreateSubKey(JournalPath, writable: true);
        key.SetValue("Backup", JsonSerializer.Serialize(backup), RegistryValueKind.String);
        key.Flush();
    }

    public void DeleteBackup()
    {
        using var hklm = OpenMachine();
        using var key = hklm.OpenSubKey(JournalPath, writable: true);
        key?.DeleteValue("Backup", throwOnMissingValue: false);
        key?.Flush();
    }

    public string ReadLocalPolicy() => PowerShell("Get-AppLockerPolicy -Local -Xml").Trim();

    public ApplicationIdentityStartup ReadStartup()
    {
        using var hklm = OpenMachine();
        using var key = hklm.OpenSubKey(ServicePath) ?? throw new InvalidOperationException("appidsvc_missing");
        return new((int)(key.GetValue("Start") ?? throw new InvalidDataException("appidsvc_start_missing")),
            key.GetValue("DelayedAutoStart") as int?);
    }

    public void EnsureAutomaticAndRunning()
    {
        Run(SystemExecutable("sc.exe"), ["config", "AppIDSvc", "start=", "auto"]);
        using var service = new ServiceController("AppIDSvc");
        if (service.Status == ServiceControllerStatus.Running) return;
        if (service.Status == ServiceControllerStatus.StopPending)
            service.WaitForStatus(ServiceControllerStatus.Stopped, TimeSpan.FromSeconds(20));
        if (service.Status != ServiceControllerStatus.StartPending) service.Start();
        service.WaitForStatus(ServiceControllerStatus.Running, TimeSpan.FromSeconds(20));
    }

    public void SetLocalPolicy(string policyXml)
    {
        var temporary = Path.GetTempFileName();
        try
        {
            File.WriteAllText(temporary, policyXml, new UTF8Encoding(false));
            PowerShell($"Set-AppLockerPolicy -XmlPolicy '{temporary.Replace("'", "''", StringComparison.Ordinal)}'");
        }
        finally { File.Delete(temporary); }
    }

    public void RestoreStartup(ApplicationIdentityStartup startup)
    {
        // AppIDSvc is protected: SCM refuses restoring Manual; persist the original startup for reboot.
        using var hklm = OpenMachine();
        using var key = hklm.OpenSubKey(ServicePath, writable: true) ?? throw new InvalidOperationException("appidsvc_missing");
        key.SetValue("Start", startup.Start, RegistryValueKind.DWord);
        if (startup.DelayedAutoStart is { } delayed) key.SetValue("DelayedAutoStart", delayed, RegistryValueKind.DWord);
        else key.DeleteValue("DelayedAutoStart", throwOnMissingValue: false);
        key.Flush();
        log("AppLocker withdrawn; AppIDSvc startup restored. The protected service may remain running until reboot.");
    }

    private static RegistryKey OpenMachine() => RegistryKey.OpenBaseKey(RegistryHive.LocalMachine, RegistryView.Registry64);

    private static string SystemExecutable(string relative) => Path.Combine(
        Environment.GetFolderPath(Environment.SpecialFolder.Windows),
        Environment.Is64BitOperatingSystem && !Environment.Is64BitProcess ? "Sysnative" : "System32", relative);

    private static string PowerShell(string command) => Run(SystemExecutable(@"WindowsPowerShell\v1.0\powershell.exe"),
        ["-NoLogo", "-NoProfile", "-NonInteractive", "-EncodedCommand", Convert.ToBase64String(Encoding.Unicode.GetBytes(
            "$ErrorActionPreference = 'Stop'; [Console]::OutputEncoding = [Text.UTF8Encoding]::new($false); Import-Module AppLocker; " + command))]);

    private static string Run(string executable, IReadOnlyList<string> arguments)
    {
        var start = new ProcessStartInfo(executable)
        {
            UseShellExecute = false, CreateNoWindow = true, RedirectStandardOutput = true,
            RedirectStandardError = true, StandardOutputEncoding = Encoding.UTF8, StandardErrorEncoding = Encoding.UTF8
        };
        foreach (var argument in arguments) start.ArgumentList.Add(argument);
        using var process = Process.Start(start) ?? throw new InvalidOperationException("applocker_process_start_failed");
        var output = process.StandardOutput.ReadToEndAsync();
        var error = process.StandardError.ReadToEndAsync();
        if (!process.WaitForExit(60_000))
        {
            process.Kill(entireProcessTree: true);
            process.WaitForExit();
            Task.WhenAll(output, error).GetAwaiter().GetResult();
            throw new System.TimeoutException("applocker_process_timeout");
        }
        Task.WhenAll(output, error).GetAwaiter().GetResult();
        if (process.ExitCode != 0) throw new InvalidOperationException($"applocker_process_exit_{process.ExitCode}: {error.Result.Trim()}");
        return output.Result;
    }
}
