namespace AZCKeeperAgent.Core;

/// <summary>Un control a aplicar en HKLM. Valor plano o subclave enumerada (listas).</summary>
public sealed record DesiredControl(
    string Code, string SubkeyPath, string? ValueName, object? Value,
    bool IsEnumeratedSubkey = false, IReadOnlyList<string>? ListValues = null);

/// <summary>
/// Aplica la política de endurecimiento en HKLM y reporta, control por control, si se
/// pudo. Re-asserta en cada ciclo (anti-manipulación): aunque la política no cambie, se
/// vuelve a escribir, así un usuario que borre una clave la ve reaparecer al siguiente
/// ciclo. Un control que falla NO aborta los demás; se registra su fallo.
/// </summary>
public sealed class PolicyEnforcer
{
    private readonly IPrivilegedRegistry _reg;
    public PolicyEnforcer(IPrivilegedRegistry reg) => _reg = reg;

    public EnforcementReport Apply(IReadOnlyList<DesiredControl> controls)
    {
        var applied = new List<string>();
        var failed = new List<ControlFailure>();

        foreach (var c in controls)
        {
            try
            {
                if (c.IsEnumeratedSubkey)
                    _reg.WriteEnumeratedSubkey(c.SubkeyPath, c.ListValues ?? Array.Empty<string>());
                else if (c.ValueName is not null && c.Value is not null)
                    _reg.WriteValue(c.SubkeyPath, c.ValueName, c.Value);
                applied.Add(c.Code);
            }
            catch (Exception ex)
            {
                failed.Add(new ControlFailure(c.Code, ex.GetType().Name + ": " + ex.Message));
                // sigue con el siguiente control
            }
        }

        return new EnforcementReport(applied, failed);
    }
}

public sealed record ControlFailure(string Code, string Error);

public sealed record EnforcementReport(IReadOnlyList<string> Applied, IReadOnlyList<ControlFailure> Failed)
{
    public bool AllApplied => Failed.Count == 0;
}
