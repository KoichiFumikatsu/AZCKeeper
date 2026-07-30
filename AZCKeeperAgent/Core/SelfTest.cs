namespace AZCKeeperAgent.Core;

/// <summary>
/// El corazón anti-fallo-silencioso. El agente prueba de verdad si puede escribir en
/// HKLM antes de creer que hace cumplir algo: escribe un canario, lo lee, lo borra. Si
/// la escritura falla (sin privilegio, ACL denegada), el agente SABE que no puede hacer
/// cumplir nada y lo reporta como fallo — el estado exacto que en Keeper 3 pasó
/// inadvertido semanas (URLBlocklist en HKCU, solo-lectura para el usuario).
/// </summary>
public static class SelfTest
{
    public const string CanarySubkey = @"AZCKeeper\_selftest";
    private const string CanaryName = "probe";

    /// <summary>Corre el auto-test. Devuelve si el agente puede hacer cumplir políticas.</summary>
    public static SelfTestResult Run(IPrivilegedRegistry reg, Func<DateTime> nowUtc)
    {
        try
        {
            var token = Guid.NewGuid().ToString("N");
            reg.WriteValue(CanarySubkey, CanaryName, token);
            var readBack = reg.ReadValue(CanarySubkey, CanaryName) as string;
            reg.DeleteValue(CanarySubkey, CanaryName);

            if (readBack == token)
                return new SelfTestResult(CanEnforce: true, Elevated: true, Error: null, At: nowUtc());

            // Escribió pero no leyó lo mismo: estado raro, no confiable.
            return new SelfTestResult(false, true, "canary mismatch", nowUtc());
        }
        catch (UnauthorizedAccessException)
        {
            // El caso clave: corre, pero NO tiene privilegio para escribir HKLM.
            return new SelfTestResult(false, false, "access denied writing HKLM", nowUtc());
        }
        catch (Exception ex)
        {
            return new SelfTestResult(false, false, ex.GetType().Name + ": " + ex.Message, nowUtc());
        }
    }
}

/// <summary>
/// Resultado del auto-test. Lo que el agente reporta al panel para que muestre uno de
/// tres estados: no instalado (no llega reporte), instalado-sin-privilegio (Elevated=false,
/// ROJO), instalado-y-haciendo-cumplir (CanEnforce=true, verde).
/// </summary>
public sealed record SelfTestResult(bool CanEnforce, bool Elevated, string? Error, DateTime At);
