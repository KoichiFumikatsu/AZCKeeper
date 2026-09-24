namespace Keeper.Shared.Contracts;

public enum HardeningMode { Auto, Panel }

// Local journal, not a new wire contract. Agent projects it into SecurityReport.
public sealed record HardeningState
{
    public Guid Revision { get; init; } = Guid.NewGuid();
    public HardeningMode Mode { get; init; } = HardeningMode.Panel;
    public string Status { get; init; } = "waiting_panel";
    public int Step { get; init; }
    public string? ErrorCode { get; init; }
    public string AdminName { get; init; } = "azcadmin";
    public string? AdminSid { get; init; }
    public string[] RestoreAdminSids { get; init; } = [];
    public string[] AddedUsersSids { get; init; } = [];
    public bool? PreviousNetworkDeny { get; init; }
    public int? PreviousVisibility { get; init; }
    public bool VisibilityCaptured { get; init; }
    public bool RecoveryRequired { get; init; }
    public bool LogoffRequired { get; init; }
}
