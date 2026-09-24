using System.Runtime.InteropServices;
using Keeper.Shared.Contracts;

namespace Keeper.Agent.Modules.Devices;

public sealed record DeviceSpecs(string Hostname, string Os, string Architecture, int LogicalProcessors, ulong MemoryBytes);
public sealed class Inventory : ModuleBase
{
    public override string Name => "Inventory";
    public DeviceSpecs? Specs { get; private set; }
    public override Task InitAsync(ModuleContext ctx)
    {
        var memory = new MemoryStatus { Length = (uint)Marshal.SizeOf<MemoryStatus>() };
        if (!OperatingSystem.IsWindows()) throw new PlatformNotSupportedException("inventory_requires_windows");
        if (!GlobalMemoryStatusEx(ref memory)) throw new System.ComponentModel.Win32Exception(Marshal.GetLastWin32Error());
        Specs = new(Environment.MachineName, RuntimeInformation.OSDescription, RuntimeInformation.OSArchitecture.ToString(), Environment.ProcessorCount, memory.TotalPhysical);
        return base.InitAsync(ctx);
    }
    [StructLayout(LayoutKind.Sequential)] private struct MemoryStatus
    {
        public uint Length, Load;
        public ulong TotalPhysical, AvailablePhysical, TotalPage, AvailablePage, TotalVirtual, AvailableVirtual, Extended;
    }
    [DllImport("kernel32.dll", SetLastError = true)] [return: MarshalAs(UnmanagedType.Bool)] private static extern bool GlobalMemoryStatusEx(ref MemoryStatus memory);
}
