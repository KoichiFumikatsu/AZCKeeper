using System.Runtime.InteropServices;
using Keeper.Shared.Contracts;
using Keeper.Shared.Protocol;

namespace Keeper.Agent.Modules.Devices;

public sealed record DeviceSpecs(string Hostname, string Os, string Architecture, int LogicalProcessors, ulong MemoryBytes);

// Datos de Windows que la ficha necesita. Separado del modulo para poder probar la traduccion sin registro real.
public sealed record WindowsVersionInfo(string? EditionId, string? CurrentBuild, int? Ubr, string? DisplayVersion);

public static class InventoryFormat
{
    // RuntimeInformation.OSDescription dice "Microsoft Windows 10.0.26200" hasta en Windows 11 y no trae la edicion;
    // Home vs Pro es lo que decide si hay AppLocker/BitLocker gestionable, asi que se toma EditionID del registro.
    public static string Edition(WindowsVersionInfo v)
    {
        var family = int.TryParse(v.CurrentBuild, out var build) && build >= 22000 ? "Windows 11" : "Windows 10";
        var edition = v.EditionId switch
        {
            null or "" => null,
            "Core" or "CoreSingleLanguage" or "CoreCountrySpecific" => "Home",
            "Professional" => "Pro",
            "ProfessionalWorkstation" => "Pro for Workstations",
            "ProfessionalEducation" => "Pro Education",
            "Enterprise" or "EnterpriseS" => "Enterprise",
            "Education" => "Education",
            var other => other
        };
        return Truncate(edition is null ? family : $"{family} {edition}", 100);
    }

    public static string? Build(WindowsVersionInfo v) =>
        v.CurrentBuild is null ? null : Truncate(v.Ubr is { } ubr ? $"{v.CurrentBuild}.{ubr}" : v.CurrentBuild, 80);

    public static string Cpu(string? name, int logical) =>
        Truncate($"{(string.IsNullOrWhiteSpace(name) ? "CPU" : string.Join(' ', name.Split(' ', StringSplitOptions.RemoveEmptyEntries)))} ({logical} hilos)", 160);

    private static string Truncate(string value, int max) => value.Length <= max ? value : value[..max];
}

public sealed class Inventory(Func<DeviceInventory, CancellationToken, Task>? publish = null) : ModuleBase
{
    public override string Name => "Inventory";
    public DeviceSpecs? Specs { get; private set; }
    public DeviceInventory? Current { get; private set; }

    public override async Task InitAsync(ModuleContext ctx)
    {
        var memory = new MemoryStatus { Length = (uint)Marshal.SizeOf<MemoryStatus>() };
        if (!OperatingSystem.IsWindows()) throw new PlatformNotSupportedException("inventory_requires_windows");
        if (!GlobalMemoryStatusEx(ref memory)) throw new System.ComponentModel.Win32Exception(Marshal.GetLastWin32Error());
        Specs = new(Environment.MachineName, RuntimeInformation.OSDescription, RuntimeInformation.OSArchitecture.ToString(), Environment.ProcessorCount, memory.TotalPhysical);
        await base.InitAsync(ctx);
        // Una vez por arranque: CPU, RAM, disco y build no cambian sin reiniciar el equipo (y el agente).
        Current = Collect(memory.TotalPhysical);
        if (publish is not null) await publish(Current, ctx.StoppingToken);
    }

    [System.Runtime.Versioning.SupportedOSPlatform("windows")]
    private static DeviceInventory Collect(ulong memoryBytes)
    {
        using var nt = Microsoft.Win32.Registry.LocalMachine.OpenSubKey(@"SOFTWARE\Microsoft\Windows NT\CurrentVersion");
        var version = new WindowsVersionInfo(nt?.GetValue("EditionID") as string, nt?.GetValue("CurrentBuild") as string,
            nt?.GetValue("UBR") is int ubr ? ubr : null, nt?.GetValue("DisplayVersion") as string);
        using var cpu = Microsoft.Win32.Registry.LocalMachine.OpenSubKey(@"HARDWARE\DESCRIPTION\System\CentralProcessor\0");
        long? disk = null;
        try { disk = new DriveInfo(Path.GetPathRoot(Environment.SystemDirectory)!).TotalSize; }
        catch (Exception ex) when (ex is IOException or UnauthorizedAccessException or ArgumentException) { }
        return new DeviceInventory
        {
            OsEdition = InventoryFormat.Edition(version), OsBuild = InventoryFormat.Build(version),
            Cpu = InventoryFormat.Cpu(cpu?.GetValue("ProcessorNameString") as string, Environment.ProcessorCount),
            RamBytes = checked((long)memoryBytes), DiskBytes = disk,
            Architecture = RuntimeInformation.OSArchitecture == Architecture.Arm64 ? DeviceInventoryArchitecture.Arm64 : DeviceInventoryArchitecture.X64
        };
    }

    [StructLayout(LayoutKind.Sequential)] private struct MemoryStatus
    {
        public uint Length, Load;
        public ulong TotalPhysical, AvailablePhysical, TotalPage, AvailablePage, TotalVirtual, AvailableVirtual, Extended;
    }
    [DllImport("kernel32.dll", SetLastError = true)] [return: MarshalAs(UnmanagedType.Bool)] private static extern bool GlobalMemoryStatusEx(ref MemoryStatus memory);
}
