using System.ComponentModel;
using System.Diagnostics;
using System.Runtime.Versioning;
using System.ServiceProcess;
using Microsoft.Win32;
using TimeoutException = System.TimeoutException;

namespace Keeper.Bootstrapper;

[SupportedOSPlatform("windows")]
public sealed class WindowsServiceControl : IServiceControl
{
    private static readonly TimeSpan Timeout = TimeSpan.FromSeconds(60);

    public bool Exists(string name)
    {
        using var machine = RegistryKey.OpenBaseKey(RegistryHive.LocalMachine, RegistryView.Registry64);
        using var key = machine.OpenSubKey(WindowsConstants.ServiceKey(name), writable: false);
        return key is not null;
    }

    public void Stop(string name)
    {
        using var service = new ServiceController(name);
        if (service.Status == ServiceControllerStatus.StartPending) service.WaitForStatus(ServiceControllerStatus.Running, Timeout);
        if (service.Status == ServiceControllerStatus.Stopped) return;
        if (service.Status != ServiceControllerStatus.StopPending) service.Stop();
        service.WaitForStatus(ServiceControllerStatus.Stopped, Timeout);
    }

    public void Configure(ServiceDefinition definition, bool exists) => RunSc(definition.ScArguments(exists));

    public void Start(string name)
    {
        using var service = new ServiceController(name);
        if (service.Status != ServiceControllerStatus.Running)
        {
            if (service.Status != ServiceControllerStatus.StartPending) service.Start();
            service.WaitForStatus(ServiceControllerStatus.Running, Timeout);
        }
    }

    public void Delete(string name)
    {
        RunSc(["delete", name]);
        var timer = Stopwatch.StartNew();
        while (Exists(name))
        {
            if (timer.Elapsed >= Timeout) throw new TimeoutException("SCM mantiene KeeperAgent pendiente de eliminar. Cierre services.msc y reintente antes de borrar archivos.");
            Thread.Sleep(200);
        }
    }

    private static void RunSc(string[] arguments)
    {
        var start = new ProcessStartInfo(Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.System), "sc.exe"))
        {
            UseShellExecute = false, CreateNoWindow = true, RedirectStandardOutput = true, RedirectStandardError = true
        };
        foreach (var argument in arguments) start.ArgumentList.Add(argument);
        using var process = Process.Start(start) ?? throw new InvalidOperationException("No se pudo iniciar sc.exe.");
        var output = process.StandardOutput.ReadToEndAsync();
        var error = process.StandardError.ReadToEndAsync();
        if (!process.WaitForExit((int)Timeout.TotalMilliseconds))
        {
            process.Kill(entireProcessTree: true);
            process.WaitForExit();
            var capturedOutput = ObserveOutput(output);
            var capturedError = ObserveOutput(error);
            throw new TimeoutException($"sc.exe excedio 60 segundos. stdout: {capturedOutput} stderr: {capturedError}");
        }
        Task.WhenAll(output, error).GetAwaiter().GetResult();
        if (process.ExitCode != 0) throw new Win32Exception(process.ExitCode, $"sc.exe: {output.Result} {error.Result}");
    }

    private static string ObserveOutput(Task<string> output)
    {
        try { return output.GetAwaiter().GetResult(); }
        catch (Exception ex) { return $"[No se pudo completar la lectura: {ex.Message}]"; }
    }
}
