using System.ComponentModel;
using System.Runtime.InteropServices;
using System.Text.Json;
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

// Estado persistido del dia: sin esto cada reinicio de la sesion (reinicio del equipo, cierre de sesion, update del
// agente) volvia los contadores y la secuencia a cero, y el servidor (upsert monotonico por dia) rechazaba todas las
// fotos siguientes de ese dia. Regresion vista 2026-09-30: una sola foto aceptada en toda la historia del equipo.
public sealed record ActivityState(long Sequence, List<ActivityStateDay> Days, List<ActivityStateTotal> Totals);
public sealed record ActivityStateDay(DateOnly Day, double Active, double Idle, double Call, DateTimeOffset? First, DateTimeOffset? Last, long Samples);
public sealed record ActivityStateTotal(DateOnly Day, TimeCategory Category, double Active, double Idle);

public sealed class ActivityTracker(IInputIdleSource input, Func<bool>? inCall = null,
    double idleThresholdSeconds = 600, double callMaxIdleSeconds = 300, string? statePath = null) : ModuleBase
{
    public override string Name => "ActivityTracker";
    public static readonly TimeSpan SaveEvery = TimeSpan.FromSeconds(30);
    private DateTimeOffset _last;
    private long _timestamp;
    private long _sequence;
    private DateTimeOffset _saved;
    public WorkSchedule Schedule { get; set; } = new(TimeZoneInfo.Local);
    public IReadOnlyList<ActivityInterval> Intervals { get; private set; } = [];
    public Dictionary<(DateOnly Day, TimeCategory Category), ActivityTotals> Totals { get; } = [];
    public Dictionary<DateOnly, DayActivity> Days { get; } = [];
    public bool IsActive { get; private set; }
    public override async Task InitAsync(ModuleContext ctx)
    {
        await base.InitAsync(ctx);
        _last = ctx.Clock.GetUtcNow(); _timestamp = ctx.Clock.GetTimestamp(); _saved = _last;
        Restore();
    }
    public override Task ShutdownAsync() { Save(); return base.ShutdownAsync(); }

    // Secuencia estrictamente creciente tambien entre procesos: el tiempo en ms es mayor que cualquier secuencia de
    // un proceso anterior (de contadores 1, 2, 3... o de milisegundos ya pasados).
    private long NextSequence() => _sequence = Math.Max(_sequence + 1, Context.Clock.GetUtcNow().ToUnixTimeMilliseconds());

    private void Restore()
    {
        if (statePath is null || !File.Exists(statePath)) return;
        try
        {
            var state = JsonSerializer.Deserialize<ActivityState>(File.ReadAllBytes(statePath));
            if (state is null) return;
            var cutoff = Schedule.Day(Context.Clock.GetUtcNow()).AddDays(-7);
            _sequence = Math.Max(_sequence, state.Sequence);
            foreach (var d in state.Days.Where(d => d.Day >= cutoff)) Days[d.Day] = new(d.Active, d.Idle, d.Call, d.First, d.Last, d.Samples);
            foreach (var t in state.Totals.Where(t => t.Day >= cutoff)) Totals[(t.Day, t.Category)] = new(t.Active, t.Idle);
        }
        catch (Exception ex) when (ex is IOException or JsonException or UnauthorizedAccessException or NotSupportedException) { }
    }

    private void Save()
    {
        if (statePath is null || Context is null) return;
        try
        {
            var state = new ActivityState(_sequence,
                Days.Select(d => new ActivityStateDay(d.Key, d.Value.Active, d.Value.Idle, d.Value.Call, d.Value.First, d.Value.Last, d.Value.Samples)).ToList(),
                Totals.Select(t => new ActivityStateTotal(t.Key.Day, t.Key.Category, t.Value.Active, t.Value.Idle)).ToList());
            Directory.CreateDirectory(Path.GetDirectoryName(statePath)!);
            var temp = statePath + ".tmp";
            File.WriteAllBytes(temp, JsonSerializer.SerializeToUtf8Bytes(state));
            File.Move(temp, statePath, true);
        }
        catch (Exception ex) when (ex is IOException or UnauthorizedAccessException) { }
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
            var snapshot = Snapshot(day, NextSequence());
            if (snapshot is not null) await Context.Outbox.EnqueueAsync(snapshot, ct);
        }
        if (now - _saved >= SaveEvery) { Save(); _saved = now; }
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
