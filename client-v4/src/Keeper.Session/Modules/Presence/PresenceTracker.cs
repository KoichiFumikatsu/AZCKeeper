using Keeper.Shared.Contracts;

namespace Keeper.Session.Modules.Presence;

public sealed class PresenceTracker(string statePath) : ModuleBase
{
    public override string Name => "Presence";
    private DateOnly? _reported;
    private WorkSchedule _schedule = new(TimeZoneInfo.Local);
    public DateTimeOffset? FirstLogin { get; private set; }
    public TimeSpan? Lateness { get; private set; }
    public override async Task InitAsync(ModuleContext ctx)
    {
        await base.InitAsync(ctx);
        FirstLogin = ctx.Clock.GetUtcNow();
        if (File.Exists(statePath) && DateOnly.TryParse(await File.ReadAllTextAsync(statePath, ctx.StoppingToken), out var day)) _reported = day;
    }
    public override Task ApplyPolicyAsync(EffectivePolicy policy)
    {
        _schedule = WorkSchedule.FromPolicy(policy);
        return base.ApplyPolicyAsync(policy);
    }
    public override async Task TickAsync(CancellationToken ct)
    {
        if (Policy is null) return;
        var now = Context.Clock.GetUtcNow();
        var day = _schedule.Day(now);
        if (_reported == day) return;
        var login = _schedule.Day(FirstLogin!.Value) == day ? FirstLogin.Value : now;
        var shift = _schedule.ShiftStart(login);
        Lateness = shift is null ? null : login > shift ? login - shift : TimeSpan.Zero;
        await ReportAsync(Lateness > TimeSpan.Zero ? "late_login" : "first_login", ct);
        Directory.CreateDirectory(Path.GetDirectoryName(statePath)!);
        await File.WriteAllTextAsync(statePath, day.ToString("yyyy-MM-dd"), ct);
        _reported = day;
    }
}
