using System.Diagnostics;
using Keeper.Agent.Hosting;
using Keeper.Session.Ipc;
using Keeper.Session.Modules.Activity;
using Keeper.Session.Modules.Calls;
using Keeper.Session.Modules.Presence;
using Keeper.Session.Modules.UI;
using Keeper.Session.Modules.Windows;
using Keeper.Shared.Contracts;

namespace Keeper.Session;

internal static class Program
{
    [STAThread]
    private static void Main(string[] args)
    {
        if (args.Length == 2 && args[0].StartsWith(IdentifyProtocol.PipePrefix, StringComparison.Ordinal) && int.TryParse(args[1], out var identifyPid))
        {
            Identify(args[0], identifyPid);
            return;
        }
        if (args.Length != 2 || !int.TryParse(args[1], out var agentPid) || !args[0].StartsWith("AZCKeeper.v4.", StringComparison.Ordinal)) return;
        using var process = Process.GetCurrentProcess();
        using var mutex = new Mutex(true, $"Local\\AZCKeeper.v4.Session.{process.SessionId}", out var created);
        if (!created) return;
        ApplicationConfiguration.Initialize();
        using var dispatcher = new Control();
        _ = dispatcher.Handle;
        using var stopping = new CancellationTokenSource();
        Application.ApplicationExit += (_, _) => stopping.Cancel();
        var task = Task.Run(async () =>
        {
            var sink = new SessionBuffer();
            var foreground = new WindowsForegroundSource();
            var calls = new CallDetector(foreground);
            var activityState = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData), "AZCKeeper", "v4", "activity-state.json");
            var activity = new ActivityTracker(new WindowsInputIdleSource(), () => calls.IsInCall, statePath: activityState);
            var screen = new LockScreen(dispatcher);
            var presenceFile = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData), "AZCKeeper", "v4", "presence-day.txt");
            await using var modules = new ModuleHost([calls, activity, new WindowTracker(foreground, activity), new PresenceTracker(presenceFile), screen],
                new ModuleContext(sink, TimeProvider.System, _ => { }, stopping.Token));
            await modules.InitAsync();
            try { await new SessionClient(args[0], agentPid, modules, sink, screen).RunAsync(stopping.Token); }
            catch (Exception ex) when (ex is IOException or OperationCanceledException or UnauthorizedAccessException) { }
        });
        _ = task.ContinueWith(_ => dispatcher.BeginInvoke(Application.Exit), TaskScheduler.Default);
        Application.Run();
    }

    // Modo identificacion del alta: solo la ventana de cedula, sin modulos de captura.
    private static void Identify(string pipe, int agentPid)
    {
        using var process = Process.GetCurrentProcess();
        using var mutex = new Mutex(true, $"Local\\AZCKeeper.v4.Identify.{process.SessionId}", out var created);
        if (!created) return;
        ApplicationConfiguration.Initialize();
        using var dispatcher = new Control();
        _ = dispatcher.Handle;
        using var stopping = new CancellationTokenSource();
        var task = Task.Run(async () =>
        {
            try { await IdentifyPrompt.RunAsync(pipe, agentPid, dispatcher, stopping.Token); }
            catch (Exception ex) when (ex is IOException or OperationCanceledException or UnauthorizedAccessException or TimeoutException) { }
        });
        _ = task.ContinueWith(_ => dispatcher.BeginInvoke(Application.Exit), TaskScheduler.Default);
        Application.Run();
    }
}
