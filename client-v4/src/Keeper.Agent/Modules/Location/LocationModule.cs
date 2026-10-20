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
    private DateTimeOffset _nextSettings, _nextPoint;
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
            _nextSettings = DateTimeOffset.MinValue;
            _nextPoint = DateTimeOffset.MinValue;
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
            if (now >= _nextSettings)
            {
                if (domainJoined()) { _enabled = false; Pending = null; }
                var changed = await settings.ApplyAsync(_enabled, ct);
                _nextSettings = now.AddMinutes(2);
                if (changed) await ReportAsync(_enabled ? "location_restored" : "location_released", ct);
            }
            State = _enabled ? "ready" : "disabled";
            if (!_enabled) return;
            var current = Network;
            if (Pending is { } old && current != old.NetworkId) Pending = null;
            if (Pending is { } pending && now - pending.At >= TimeSpan.FromSeconds(20))
            {
                await Context.Outbox.EnqueueAsync(new LocationPoint { EventId = pending.EventId, At = pending.At,
                    NetworkId = pending.NetworkId, NetworkType = networkType?.Invoke() ?? LocationNetwork.Type() }, ct);
                Pending = null;
            }
            if (Pending is null && (now >= _nextPoint || current != _network))
            {
                Pending = new(Guid.NewGuid(), current, now);
                _network = current;
                _nextPoint = now.AddMinutes(15);
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
