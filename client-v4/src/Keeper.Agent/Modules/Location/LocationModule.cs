using Keeper.Shared.Contracts;
using Keeper.Shared.Location;
using Keeper.Shared.Protocol;

namespace Keeper.Agent.Modules.Location;

public sealed class LocationModule(LocationSettingsStore? settings, Func<bool> domainJoined,
    Func<Guid>? network = null, Func<LocationPointNetworkType>? networkType = null) : ModuleBase
{
    public override string Name => "Location";
    public LocationCapture? Pending { get; private set; }
    private bool _enabled;
    private bool _knownPolicy;
    private long? _lastSettings, _lastPoint, _pendingStarted;
    private Guid _network;
    private readonly SemaphoreSlim _gate = new(1, 1);
    private Guid Network => network?.Invoke() ?? LocationNetwork.Current;
    public override async Task ApplyPolicyAsync(EffectivePolicy policy)
    {
        await base.ApplyPolicyAsync(policy);
        await _gate.WaitAsync(Context.StoppingToken);
        try
        {
            _knownPolicy = true;
            _enabled = policy.LocationTracking == true && settings is not null && !domainJoined();
            if (!_enabled) Pending = null;
            _lastSettings = null;
            _lastPoint = null;
        }
        finally { _gate.Release(); }
    }
    public override async Task TickAsync(CancellationToken ct)
    {
        if (!_knownPolicy || settings is null) return;
        await _gate.WaitAsync(ct);
        try
        {
            var now = Context.Clock.GetUtcNow();
            var timestamp = Context.Clock.GetTimestamp();
            if (_lastSettings is null || Context.Clock.GetElapsedTime(_lastSettings.Value, timestamp) >= TimeSpan.FromMinutes(2))
            {
                if (domainJoined()) { _enabled = false; Pending = null; }
                var changed = await settings.ApplyAsync(_enabled, ct);
                _lastSettings = timestamp;
                if (changed) await ReportAsync(_enabled ? "location_restored" : "location_released", ct);
            }
            State = _enabled ? "ready" : "disabled";
            if (!_enabled) return;
            var current = Network;
            if (Pending is { } old && current != old.NetworkId) Pending = null;
            if (Pending is { } pending && _pendingStarted is { } started && Context.Clock.GetElapsedTime(started, timestamp) >= TimeSpan.FromSeconds(20))
            {
                await Context.Outbox.EnqueueAsync(new LocationPoint { EventId = pending.EventId, At = pending.At,
                    NetworkId = pending.NetworkId, NetworkType = networkType?.Invoke() ?? LocationNetwork.Type() }, ct);
                Pending = null;
            }
            if (Pending is null && (_lastPoint is null || Context.Clock.GetElapsedTime(_lastPoint.Value, timestamp) >= TimeSpan.FromMinutes(15) || current != _network))
            {
                Pending = new(Guid.NewGuid(), current, now);
                _network = current;
                _lastPoint = _pendingStarted = timestamp;
            }
        }
        finally { _gate.Release(); }
    }
    public async Task AcceptAsync(LocationPoint point, CancellationToken ct)
    {
        SessionProtocol.Validate(point);
        await _gate.WaitAsync(ct);
        try
        {
            if (!_enabled || Pending is not { } request || request.EventId != point.EventId ||
                request.NetworkId != point.NetworkId || request.NetworkId != Network) return;
            await Context.Outbox.EnqueueAsync(point with { At = request.At }, ct);
            Pending = null;
        }
        finally { _gate.Release(); }
    }
}
