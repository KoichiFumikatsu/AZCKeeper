namespace AZCKeeperAgent.Core;

/// <summary>
/// Un ciclo del agente: auto-test de privilegio; si puede hacer cumplir, aplica las
/// políticas; construye el reporte para el servidor. Toda la lógica orquestada aquí,
/// testeable sin correr elevado (el registro es una interfaz).
///
/// Regla dura: NO se aplican políticas si el auto-test falla. No tiene sentido intentar
/// escribir 40 controles si ya sabemos que no hay privilegio — y evita 40 excepciones.
/// El reporte igual sale (con CanEnforce=false), para que el panel muestre el fallo.
/// </summary>
public sealed class AgentCycle
{
    private readonly IPrivilegedRegistry _reg;
    private readonly string _version;
    private readonly Func<DateTime> _nowUtc;

    public AgentCycle(IPrivilegedRegistry reg, string version, Func<DateTime>? nowUtc = null)
    {
        _reg = reg; _version = version; _nowUtc = nowUtc ?? (() => DateTime.UtcNow);
    }

    public AgentReport Run(IReadOnlyList<DesiredControl> controls)
    {
        var test = SelfTest.Run(_reg, _nowUtc);
        EnforcementReport? enforcement = null;
        if (test.CanEnforce)
            enforcement = new PolicyEnforcer(_reg).Apply(controls);
        return AgentReport.From(test, enforcement, _version, _nowUtc());
    }
}
