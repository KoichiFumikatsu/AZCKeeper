using System.Runtime.InteropServices;
using System.Text;
using Windows.Devices.Geolocation;
using Windows.Networking.Connectivity;
using Windows.Security.Authorization.AppCapabilityAccess;
using Keeper.Shared.Contracts;
using Keeper.Shared.Protocol;

namespace Keeper.Session.Modules.Location;

public sealed class LocationTracker : IDisposable
{
    private Task<LocationPoint>? _capture;
    private Guid _lastRequest;
    private bool _subscribed;
    private CancellationTokenSource? _stopping;
    private readonly object _gate = new();
    private Guid _networkId = Guid.NewGuid();
    public Guid NetworkId { get { lock (_gate) return _networkId; } }
    private void Changed(object sender) { lock (_gate) _networkId = Guid.NewGuid(); }

    public async Task PollAsync(LocationCapture? request, IEventSink sink, CancellationToken ct, bool enabled = true)
    {
        if (!enabled) { Dispose(); return; }
        if (!OperatingSystem.IsWindowsVersionAtLeast(10, 0, 10240)) return;
        if (!_subscribed && request is not null)
        {
            try { NetworkInformation.NetworkStatusChanged += Changed; _subscribed = true; }
            catch (Exception ex) when (ex is not OperationCanceledException) { }
        }
        if (_capture is { IsCompleted: true })
        {
            LocationPoint? result = null;
            try { result = await _capture; }
            catch (OperationCanceledException) when (!ct.IsCancellationRequested) { }
            if (result is not null && request?.EventId == result.EventId) await sink.EnqueueAsync(result, ct);
            _capture = null;
            _stopping?.Dispose(); _stopping = null;
        }
        if (_capture is null && request is { } next && _lastRequest != next.EventId)
        {
            _lastRequest = next.EventId;
            _stopping = CancellationTokenSource.CreateLinkedTokenSource(ct);
            _capture = ReadAsync(next, _stopping.Token);
        }
    }

    private static async Task<LocationPoint> ReadAsync(LocationCapture request, CancellationToken ct)
    {
        var point = new LocationPoint { EventId = request.EventId, At = request.At, NetworkId = request.NetworkId, NetworkType = LocationNetwork.Type() };
        if (!OperatingSystem.IsWindowsVersionAtLeast(10, 0, 10240)) return point;
        try
        {
            if (point.NetworkType == LocationPointNetworkType.Wifi)
            {
                var wifi = Wifi();
                point = point with { WifiSsid = wifi.Ssid, WifiBssid = wifi.Bssid };
            }
            var locator = new Geolocator { DesiredAccuracyInMeters = 100 };
            var position = await locator.GetGeopositionAsync(TimeSpan.FromSeconds(60), TimeSpan.FromSeconds(8)).AsTask(ct);
            var c = position.Coordinate;
            if (Math.Abs((request.At - c.Timestamp).TotalSeconds) <= 120)
                point = point with { Latitude = c.Point.Position.Latitude, Longitude = c.Point.Position.Longitude, AccuracyM = c.Accuracy };
        }
        catch (Exception ex) when (ex is not OperationCanceledException || !ct.IsCancellationRequested) { }
        return point;
    }

    private static (string? Ssid, string? Bssid) Wifi()
    {
        // Consultar BSSID sin permiso puede abrir un aviso de Windows; no lo solicitamos desde el recolector.
        try { if (OperatingSystem.IsWindowsVersionAtLeast(10, 0, 18362) && AppCapability.Create("wiFiControl").CheckAccess() != AppCapabilityAccessStatus.Allowed) return (null, null); }
        catch (Exception ex) when (ex is UnauthorizedAccessException or COMException) { return (null, null); }
        if (WlanOpenHandle(2, IntPtr.Zero, out _, out var handle) != 0) return (null, null);
        try
        {
            if (WlanEnumInterfaces(handle, IntPtr.Zero, out var list) != 0) return (null, null);
            try
            {
                for (var i = 0; i < Math.Min(64, Marshal.ReadInt32(list)); i++)
                {
                    var entry = IntPtr.Add(list, 8 + 532 * i);
                    if (Marshal.ReadInt32(entry, 528) != 1) continue;
                    var id = Marshal.PtrToStructure<Guid>(entry);
                    if (WlanQueryInterface(handle, ref id, 7, IntPtr.Zero, out var size, out var data, out _) != 0) continue;
                    try
                    {
                        if (size < 588) continue;
                        var length = Marshal.ReadInt32(data, 520);
                        if (length is < 0 or > 32) continue;
                        var ssid = new byte[length]; Marshal.Copy(IntPtr.Add(data, 524), ssid, 0, length);
                        var bssid = new byte[6]; Marshal.Copy(IntPtr.Add(data, 560), bssid, 0, 6);
                        return (Encoding.UTF8.GetString(ssid), string.Join(":", bssid.Select(b => b.ToString("x2"))));
                    }
                    finally { WlanFreeMemory(data); }
                }
            }
            finally { WlanFreeMemory(list); }
        }
        finally { WlanCloseHandle(handle, IntPtr.Zero); }
        return (null, null);
    }
    public void Dispose()
    {
        _stopping?.Cancel();
        if (_subscribed && OperatingSystem.IsWindowsVersionAtLeast(10, 0, 10240))
        {
            NetworkInformation.NetworkStatusChanged -= Changed;
            _subscribed = false;
        }
    }
    [DllImport("wlanapi.dll")] private static extern uint WlanOpenHandle(uint version, IntPtr reserved, out uint negotiated, out IntPtr handle);
    [DllImport("wlanapi.dll")] private static extern uint WlanCloseHandle(IntPtr handle, IntPtr reserved);
    [DllImport("wlanapi.dll")] private static extern uint WlanEnumInterfaces(IntPtr handle, IntPtr reserved, out IntPtr list);
    [DllImport("wlanapi.dll")] private static extern uint WlanQueryInterface(IntPtr handle, ref Guid id, int op, IntPtr reserved, out uint size, out IntPtr data, out int type);
    [DllImport("wlanapi.dll")] private static extern void WlanFreeMemory(IntPtr memory);
}
