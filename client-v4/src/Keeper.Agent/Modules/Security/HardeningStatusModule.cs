using System.Text.Json;
using System.Text.Json.Serialization;
using Keeper.Shared.Contracts;

namespace Keeper.Agent.Modules.Security;

public sealed class HardeningStatusModule(Func<CancellationToken, Task<HardeningState?>> read) : ModuleBase
{
    public override string Name => "LocalAccountHardening";
    private Guid? revision;

    public override async Task TickAsync(CancellationToken ct)
    {
        var current = await read(ct);
        if (current is null) { State = "waiting_panel"; return; }
        State = current.RecoveryRequired ? "recovery_required" : current.Status == "hardened"
            ? current.LogoffRequired ? "pending_logoff" : "applied"
            : current.Status == "failed" ? $"failed_step_{current.Step}_panel" : current.Status;
        if (revision == current.Revision) return;
        await ReportAsync($"hardening_{current.Status}_step_{current.Step}_mode_{current.Mode.ToString().ToLowerInvariant()}", ct);
        revision = current.Revision;
    }

    public static HardeningStatusModule FromFile(string path) => new(async ct =>
    {
        if (!File.Exists(path)) return null;
        var options = new JsonSerializerOptions { PropertyNamingPolicy = JsonNamingPolicy.SnakeCaseLower };
        options.Converters.Add(new JsonStringEnumConverter());
        return JsonSerializer.Deserialize<HardeningState>(await File.ReadAllBytesAsync(path, ct), options)
            ?? throw new InvalidDataException("invalid_hardening_state");
    });
}
