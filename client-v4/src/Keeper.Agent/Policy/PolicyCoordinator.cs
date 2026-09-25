using Keeper.Agent.Storage;
using Keeper.Agent.Hosting;
using Keeper.Shared.Protocol;

namespace Keeper.Agent.Policy;

public sealed class PolicyCoordinator(IPolicyStore store, ModuleHost host, Guid deviceId)
{
    public long? CurrentVersion { get; private set; }
    public EffectivePolicy? Current { get; private set; }

    public async Task RestoreAsync(CancellationToken ct)
    {
        var cached = await store.LoadAsync(ct);
        if (cached is null) return;
        Validate(cached.PolicyVersion, cached.Policy);
        await host.ApplyPolicyAsync(cached.Policy);
        CurrentVersion = cached.PolicyVersion;
        Current = cached.Policy;
    }

    public async Task<bool> ApplyAsync(long version, EffectivePolicy? policy, CancellationToken ct, bool allowTenantChange = false)
    {
        if (policy is null) return false;
        var tenantChanged = allowTenantChange && Current is not null && Current.TenantId != policy.TenantId;
        if (!tenantChanged && version <= CurrentVersion) return false;
        Validate(version, policy, tenantChanged);
        await store.SaveAsync(new CachedPolicy(version, policy), ct);
        await host.ApplyPolicyAsync(policy);
        Current = policy;
        CurrentVersion = version;
        return true;
    }

    private void Validate(long version, EffectivePolicy policy, bool allowTenantChange = false)
    {
        if (version < 1 || policy.DeviceId != deviceId || policy.TenantId == Guid.Empty ||
            !allowTenantChange && Current is not null && Current.TenantId != policy.TenantId ||
            string.IsNullOrWhiteSpace(policy.Version) || policy.Version.Length > 100 ||
            string.IsNullOrWhiteSpace(policy.Etag) || policy.Etag.Length > 160 ||
            policy.Rules is null || policy.Rules.Count > 1000 || policy.Schedules is null || policy.Schedules.Count > 100 ||
            policy.Composition is null || policy.Composition.Count > 30 || policy.ManagementHosts is null || policy.ManagementHosts.Count > 30)
            throw new InvalidDataException("invalid_policy");
        if (policy.Rules.Select(r => r.Id).Distinct().Count() != policy.Rules.Count)
            throw new InvalidDataException("duplicate_rule_id");
        foreach (var rule in policy.Rules)
            if (rule.Id == Guid.Empty || !Enum.IsDefined(rule.Kind) || !Enum.IsDefined(rule.Effect) ||
                rule.Priority is < 0 or > 10000 || rule.Targets is null || rule.Targets.Count > 1000 ||
                rule.Targets.Any(t => string.IsNullOrWhiteSpace(t) || t.Length > 255) ||
                rule.ScheduleId is not null && !policy.Schedules.Any(s => s.Id == rule.ScheduleId))
                throw new InvalidDataException("invalid_rule");
        foreach (var hostname in policy.ManagementHosts)
            if (string.IsNullOrWhiteSpace(hostname) || hostname.Length > 255 || Uri.CheckHostName(hostname) == UriHostNameType.Unknown)
                throw new InvalidDataException("invalid_management_host");
        foreach (var schedule in policy.Schedules)
        {
            if (schedule.Id == Guid.Empty || schedule.TenantId != policy.TenantId || schedule.Days is null || schedule.Days.Count > 7 ||
                schedule.Days.Any(d => d is < 1 or > 7) ||
                !TimeOnly.TryParseExact(schedule.StartLocal, "HH:mm", System.Globalization.CultureInfo.InvariantCulture, System.Globalization.DateTimeStyles.None, out _) ||
                !TimeOnly.TryParseExact(schedule.EndLocal, "HH:mm", System.Globalization.CultureInfo.InvariantCulture, System.Globalization.DateTimeStyles.None, out _))
                throw new InvalidDataException("invalid_schedule");
            try { _ = TimeZoneInfo.FindSystemTimeZoneById(schedule.Timezone); }
            catch (Exception ex) when (ex is TimeZoneNotFoundException or InvalidTimeZoneException or ArgumentException)
            { throw new InvalidDataException("invalid_schedule_timezone", ex); }
        }
    }
}
