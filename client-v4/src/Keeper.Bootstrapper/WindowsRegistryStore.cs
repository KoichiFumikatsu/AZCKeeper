using System.Runtime.Versioning;
using Microsoft.Win32;

namespace Keeper.Bootstrapper;

[SupportedOSPlatform("windows")]
public sealed class WindowsRegistryStore : IRegistryStore
{
    public void SetEnvironment(string serviceName, string[] values)
    {
        using var machine = RegistryKey.OpenBaseKey(RegistryHive.LocalMachine, RegistryView.Registry64);
        using var key = machine.OpenSubKey(WindowsConstants.ServiceKey(serviceName), writable: true)
            ?? throw new InvalidOperationException("El servicio debe existir antes de configurar su entorno.");
        key.SetValue("Environment", values, RegistryValueKind.MultiString);
    }

    public void DeleteTree(string path)
    {
        using var machine = RegistryKey.OpenBaseKey(RegistryHive.LocalMachine, RegistryView.Registry64);
        machine.DeleteSubKeyTree(path, throwOnMissingSubKey: false);
    }

    public void DeleteValue(string path, string name)
    {
        using var machine = RegistryKey.OpenBaseKey(RegistryHive.LocalMachine, RegistryView.Registry64);
        using var key = machine.OpenSubKey(path, writable: true);
        key?.DeleteValue(name, throwOnMissingValue: false);
    }
}
