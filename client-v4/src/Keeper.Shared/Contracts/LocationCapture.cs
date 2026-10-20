using System.Net.NetworkInformation;
using Keeper.Shared.Protocol;

namespace Keeper.Shared.Contracts;

public sealed record LocationCapture(Guid EventId, Guid NetworkId, DateTimeOffset At);

public static class LocationNetwork
{
    private static readonly object Gate = new();
    private static Guid _id = Guid.NewGuid();
    static LocationNetwork()
    {
        NetworkChange.NetworkAddressChanged += (_, _) => Changed();
        NetworkChange.NetworkAvailabilityChanged += (_, _) => Changed();
    }
    public static Guid Current { get { lock (Gate) return _id; } }
    public static void Changed() { lock (Gate) _id = Guid.NewGuid(); }
    public static LocationPointNetworkType Type()
    {
        var active = NetworkInterface.GetAllNetworkInterfaces().Where(n => n.OperationalStatus == OperationalStatus.Up);
        if (active.Any(n => n.NetworkInterfaceType == NetworkInterfaceType.Wireless80211)) return LocationPointNetworkType.Wifi;
        return active.Any(n => n.NetworkInterfaceType is NetworkInterfaceType.Ethernet or NetworkInterfaceType.GigabitEthernet)
            ? LocationPointNetworkType.Cable : LocationPointNetworkType.Unknown;
    }
}
