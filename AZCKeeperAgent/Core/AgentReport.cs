namespace AZCKeeperAgent.Core;

/// <summary>
/// Lo que el agente reporta al servidor en cada ciclo. Es la fuente del estado que el
/// panel muestra por equipo. La clave es que distingue los tres casos que en Keeper 3
/// eran indistinguibles:
///   - no llega reporte -> "no instalado" (gris)
///   - AgentPresent=true, Elevated=false -> "instalado pero SIN privilegio" (ROJO, fallo visible)
///   - AgentPresent=true, CanEnforce=true -> "instalado y haciendo cumplir" (verde)
/// </summary>
public sealed record AgentReport(
    bool AgentPresent,
    bool Elevated,
    bool CanEnforce,
    string? SelfTestError,
    IReadOnlyList<string> AppliedControls,
    IReadOnlyList<ControlFailure> FailedControls,
    string AgentVersion,
    DateTime AtUtc)
{
    public static AgentReport From(SelfTestResult test, EnforcementReport? enforcement, string version, DateTime atUtc)
        => new(
            AgentPresent: true,
            Elevated: test.Elevated,
            CanEnforce: test.CanEnforce,
            SelfTestError: test.Error,
            AppliedControls: enforcement?.Applied ?? Array.Empty<string>(),
            FailedControls: enforcement?.Failed ?? Array.Empty<ControlFailure>(),
            AgentVersion: version,
            AtUtc: atUtc);
}
