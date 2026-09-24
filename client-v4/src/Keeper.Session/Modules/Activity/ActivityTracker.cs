using System.ComponentModel;
using System.Runtime.InteropServices;
using Keeper.Shared.Contracts;
using Keeper.Shared.Protocol;
using Keeper.Session.Modules.Presence;

namespace Keeper.Session.Modules.Activity;

public interface IInputIdleSource { TimeSpan GetIdleTime(); }
public sealed class WindowsInputIdleSource : IInputIdleSource
{
    public TimeSpan GetIdleTime()
    {
        var info = new LastInput { Size = (uint)Marshal.SizeOf<LastInput>() };
        if (!GetLastInputInfo(ref info)) throw new Win32Exception(Marshal.GetLastWin32Error());
        return TimeSpan.FromMilliseconds(unchecked((uint)Environment.TickCount - info.Tick));
    }
    [StructLayout(LayoutKind.Sequential)] private struct LastInput { public uint Size; public uint Tick; }
    [DllImport("user32.dll", SetLastError = true)] [return: MarshalAs(UnmanagedType.Bool)]
    private static extern bool GetLastInputInfo(ref LastInput info);
}

public sealed record ActivityInterval(DateTimeOffset Start, DateTimeOffset End, bool Active, TimeCategory Category);
public sealed record ActivityTotals(double Active, double Idle);

/// <summary>
/// Acumulado del dia que acompaña al desglose por franja. Tres campos existen porque en K3
/// median otra cosa de lo que su nombre prometia:
/// <list type="bullet">
/// <item><c>Call</c> se reinicia con el dia; en K3 solo se ponia a cero al arrancar el
/// programa, de modo que un equipo encendido una semana acumulaba y llegaba a registrar
/// 184 horas de llamada en un dia de 24.</item>
/// <item><c>First</c> es la primera actividad REAL observada; en K3 era el primer envio
/// HTTP, asi que un equipo encendido a las 07:00 con trabajo desde las 09:00 marcaba 07:00.</item>
/// <item><c>Samples</c> cuenta muestras de inactividad tomadas; en K3 contaba envios HTTP
/// (~2.880 al dia) y no medía nada del usuario.</item>
/// </list>
/// </summary>
public sealed record DayActivity(double Active, double Idle, double Call,
    DateTimeOffset? First, DateTimeOffset? Last, long Samples);

public sealed class ActivityTracker(IInputIdleSource input, Func<bool>? inCall = null,
    double idleThresholdSeconds = 600, double callMaxIdleSeconds = 300) : ModuleBase
{
    public override string Name => "ActivityTracker";
    private DateTimeOffset _last;
    private long _timestamp;
    private long _sequence;
    public WorkSchedule Schedule { get; set; } = new(TimeZoneInfo.Local);
    public IReadOnlyList<ActivityInterval> Intervals { get; private set; } = [];
    public Dictionary<(DateOnly Day, TimeCategory Category), ActivityTotals> Totals { get; } = [];
    public Dictionary<DateOnly, DayActivity> Days { get; } = [];
    public bool IsActive { get; private set; }
    public override async Task InitAsync(ModuleContext ctx)
    {
        await base.InitAsync(ctx);
        _last = ctx.Clock.GetUtcNow(); _timestamp = ctx.Clock.GetTimestamp();
    }
    public override Task ApplyPolicyAsync(EffectivePolicy policy)
    {
        Schedule = WorkSchedule.FromPolicy(policy);
        return base.ApplyPolicyAsync(policy);
    }
    public override async Task TickAsync(CancellationToken ct)
    {
        ct.ThrowIfCancellationRequested();
        var now = Context.Clock.GetUtcNow();
        var stamp = Context.Clock.GetTimestamp();
        var elapsed = Context.Clock.GetElapsedTime(_timestamp, stamp).TotalSeconds;
        var start = _last;
        _last = now; _timestamp = stamp; Intervals = [];
        if (elapsed <= 0) return;
        if (elapsed > 30 || (now - start).TotalSeconds <= 0 || Math.Abs((now - start).TotalSeconds - elapsed) > 2)
        {
            await ReportAsync("capture_gap", ct);
            return;
        }
        var idle = input.GetIdleTime().TotalSeconds;
        var inCallNow = inCall?.Invoke() == true;
        IsActive = idle < idleThresholdSeconds || inCallNow && idle < callMaxIdleSeconds;
        var intervals = new List<ActivityInterval>();
        // Split at seconds so midnight and schedule boundaries cannot charge the wrong day/category.
        while (start < now)
        {
            var end = start.AddTicks(TimeSpan.TicksPerSecond - start.Ticks % TimeSpan.TicksPerSecond);
            if (end > now) end = now;
            var category = Schedule.GetTimeCategory(start);
            var key = (Schedule.Day(start), category);
            var old = Totals.GetValueOrDefault(key) ?? new ActivityTotals(0, 0);
            var seconds = (end - start).TotalSeconds;
            Totals[key] = new(old.Active + (IsActive ? seconds : 0), old.Idle + (IsActive ? 0 : seconds));

            var dayKey = key.Item1;
            var day = Days.GetValueOrDefault(dayKey) ?? new DayActivity(0, 0, 0, null, null, 0);
            Days[dayKey] = day with
            {
                Active = day.Active + (IsActive ? seconds : 0),
                Idle = day.Idle + (IsActive ? 0 : seconds),
                // Las llamadas son un subconjunto del tiempo activo, nunca un contador aparte.
                Call = day.Call + (IsActive && inCallNow ? seconds : 0),
                First = IsActive ? day.First ?? start : day.First,
                Last = IsActive ? end : day.Last,
            };
            intervals.Add(new(start, end, IsActive, category));
            start = end;
        }
        // Una muestra por tick, no por envio: es lo que respalda los contadores de arriba.
        var sampledDay = Schedule.Day(now);
        var sampled = Days.GetValueOrDefault(sampledDay) ?? new DayActivity(0, 0, 0, null, null, 0);
        Days[sampledDay] = sampled with { Samples = sampled.Samples + 1 };

        var cutoff = Schedule.Day(now).AddDays(-7);
        foreach (var key in Totals.Keys.Where(k => k.Day < cutoff).ToArray()) Totals.Remove(key);
        foreach (var key in Days.Keys.Where(d => d < cutoff).ToArray()) Days.Remove(key);
        Intervals = intervals;

        // Se publica el dia que acaba de moverse, y tambien el anterior cuando el tick cruza
        // medianoche: de lo contrario el ultimo tramo del dia viejo no llegaria nunca.
        foreach (var day in intervals.Select(i => Schedule.Day(i.Start)).Distinct())
        {
            var snapshot = Snapshot(day, ++_sequence);
            if (snapshot is not null) await Context.Outbox.EnqueueAsync(snapshot, ct);
        }
    }

    /// <summary>
    /// Contadores absolutos del dia, listos para enviar. El servidor hace upsert monotonico,
    /// de modo que reenviar el mismo dia corrige en vez de duplicar.
    /// </summary>
    public ActivitySnapshot? Snapshot(DateOnly day, long sequence)
    {
        if (!Days.TryGetValue(day, out var totals)) return null;
        var perCategory = (TimeCategory category) => Totals.GetValueOrDefault((day, category)) ?? new ActivityTotals(0, 0);
        var work = perCategory(TimeCategory.WorkHours);
        var lunch = perCategory(TimeCategory.LunchTime);
        var after = perCategory(TimeCategory.AfterHours);
        var offset = Schedule.Zone.GetUtcOffset(day.ToDateTime(TimeOnly.MinValue));
        return new ActivitySnapshot
        {
            SnapshotId = Guid.NewGuid(),
            Sequence = sequence,
            Day = day,
            ActiveSeconds = (long)Math.Round(totals.Active),
            IdleSeconds = (long)Math.Round(totals.Idle),
            CallSeconds = (long)Math.Round(totals.Call),
            WorkHoursActiveSeconds = (long)Math.Round(work.Active),
            WorkHoursIdleSeconds = (long)Math.Round(work.Idle),
            LunchActiveSeconds = (long)Math.Round(lunch.Active),
            LunchIdleSeconds = (long)Math.Round(lunch.Idle),
            AfterHoursActiveSeconds = (long)Math.Round(after.Active),
            AfterHoursIdleSeconds = (long)Math.Round(after.Idle),
            FirstActivityAt = totals.First,
            LastActivityAt = totals.Last,
            SampleCount = totals.Samples,
            UtcOffsetMinutes = (long)offset.TotalMinutes,
        };
    }
}
