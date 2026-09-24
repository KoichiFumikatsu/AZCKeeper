using System.Diagnostics;
using System.Runtime.InteropServices;
using System.Text;
using Keeper.Session.Modules.Activity;
using Keeper.Shared.Contracts;
using Keeper.Shared.Protocol;

namespace Keeper.Session.Modules.Windows;

public sealed record ForegroundWindow(string ProcessName, string Title);
public interface IForegroundSource { ForegroundWindow? Read(); }
public sealed class WindowsForegroundSource : IForegroundSource
{
    public ForegroundWindow? Read()
    {
        var window = GetForegroundWindow();
        if (window == IntPtr.Zero) return null;
        GetWindowThreadProcessId(window, out var pid);
        try
        {
            using var process = Process.GetProcessById((int)pid);
            var title = new StringBuilder(1025);
            GetWindowTextW(window, title, title.Capacity);
            return new(process.ProcessName, title.ToString());
        }
        catch (ArgumentException) { return null; }
        catch (System.ComponentModel.Win32Exception) { return null; }
    }
    [DllImport("user32.dll")] private static extern IntPtr GetForegroundWindow();
    [DllImport("user32.dll")] private static extern uint GetWindowThreadProcessId(IntPtr hwnd, out uint pid);
    [DllImport("user32.dll", CharSet = CharSet.Unicode)] private static extern int GetWindowTextW(IntPtr hwnd, StringBuilder text, int count);
}

public sealed class WindowTracker(IForegroundSource source, ActivityTracker activity) : ModuleBase
{
    public override string Name => "WindowTracker";
    private ForegroundWindow? _window;
    private DateTimeOffset _start;
    private DateTimeOffset _end;
    private double _active, _idle;
    private DateOnly _day;
    private Presence.TimeCategory _category;
    public override Task InitAsync(ModuleContext ctx)
    {
        _window = source.Read(); _start = _end = ctx.Clock.GetUtcNow();
        return base.InitAsync(ctx);
    }
    public override async Task TickAsync(CancellationToken ct)
    {
        foreach (var interval in activity.Intervals)
        {
            var day = activity.Schedule.Day(interval.Start);
            if (_end != interval.Start || day != _day || interval.Category != _category || (interval.End - _start).TotalSeconds > 120)
            {
                await CloseAsync(ct);
                _start = interval.Start;
            }
            _day = day; _category = interval.Category;
            if (interval.Active) _active += (interval.End - interval.Start).TotalSeconds;
            else _idle += (interval.End - interval.Start).TotalSeconds;
            _end = interval.End;
        }
        var next = source.Read();
        if (next != _window || activity.Intervals.Count == 0)
        {
            await CloseAsync(ct);
            _window = next; _start = _end = Context.Clock.GetUtcNow();
        }
    }
    private async Task CloseAsync(CancellationToken ct)
    {
        if (_window is not null && _end > _start && _active + _idle >= 1)
        {
            await Context.Outbox.EnqueueAsync(new Episode
            {
                EventId = Guid.NewGuid(), StartedAt = _start, EndedAt = _end,
                ProcessName = _window.ProcessName, WindowTitle = _window.Title,
                ActiveSeconds = (long)_active, IdleSeconds = (long)_idle
            }, ct);
        }
        _active = _idle = 0; _start = _end;
    }
    public override Task ShutdownAsync() => CloseAsync(CancellationToken.None);
}
