using System.Security.Cryptography;
using Keeper.Shared.Contracts;
using Keeper.Shared.Protocol;

namespace Keeper.Agent.Modules.Security;

public sealed class TamperGuard(IReadOnlyDictionary<string, string> expectedHashes) : ModuleBase
{
    public override string Name => "TamperGuard";
    private DateTimeOffset _next;
    public override async Task TickAsync(CancellationToken ct)
    {
        if (Context.Clock.GetUtcNow() < _next) return;
        _next = Context.Clock.GetUtcNow().AddMinutes(5);
        if (expectedHashes.Count == 0) { State = "unsupported"; return; }
        foreach (var (path, expected) in expectedHashes)
        {
            if (!File.Exists(path)) { State = "failed"; await ReportAsync("binary_missing", ct, LogEntryLevel.Error); return; }
            await using var stream = File.OpenRead(path);
            var actual = Convert.ToHexString(await SHA256.HashDataAsync(stream, ct));
            if (!actual.Equals(expected, StringComparison.OrdinalIgnoreCase))
            { State = "failed"; await ReportAsync("binary_modified", ct, LogEntryLevel.Error); return; }
        }
        State = "applied";
    }
}
