using Keeper.Session.Modules.Windows;
using Keeper.Shared.Contracts;

namespace Keeper.Session.Modules.Calls;

public sealed class CallDetector(IForegroundSource source, IReadOnlyList<string>? processes = null,
    IReadOnlyList<string>? titles = null) : ModuleBase
{
    public override string Name => "CallDetector";
    public bool IsInCall { get; private set; }
    public static bool Matches(ForegroundWindow? window, IReadOnlyList<string> processes, IReadOnlyList<string> titles) =>
        window is not null && (processes.Any(k => !string.IsNullOrWhiteSpace(k) && window.ProcessName.Contains(k, StringComparison.OrdinalIgnoreCase)) ||
            titles.Any(k => !string.IsNullOrWhiteSpace(k) && window.Title.Contains(k, StringComparison.OrdinalIgnoreCase)));
    public override Task TickAsync(CancellationToken ct)
    {
        ct.ThrowIfCancellationRequested();
        IsInCall = Matches(source.Read(), processes ?? ["ms-teams", "zoom", "webex"], titles ?? ["Google Meet", "Microsoft Teams", "Zoom Meeting"]);
        return Task.CompletedTask;
    }
}
