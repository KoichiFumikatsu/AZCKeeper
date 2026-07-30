using System.Text.Json;

namespace AZCKeeperAgent.Core;

/// <summary>
/// Serializa el reporte del agente a la forma EXACTA que el endpoint del server espera
/// recibir dentro de "agentEnforcement". El agente elevado no habla con la red: escribe
/// este JSON a un archivo local y el cliente (ya autenticado) lo transporta tal cual.
///
/// El contrato de campos (camelCase) tiene que calzar con SecurityReport::sanitizeAgent
/// del backend: elevated, canEnforce, selfTestError, agentVersion, reportedAt (ISO-8601
/// UTC), applied (lista de codigos), failed (lista de {code, reason}). Si cambia un lado,
/// cambia el otro.
/// </summary>
public static class CourierPayload
{
    private static readonly JsonSerializerOptions Opts = new() { WriteIndented = true };

    public static string Build(AgentReport report)
    {
        var payload = new
        {
            elevated      = report.Elevated,
            canEnforce    = report.CanEnforce,
            selfTestError = report.SelfTestError,
            agentVersion  = report.AgentVersion,
            reportedAt    = report.AtUtc.ToUniversalTime().ToString("yyyy-MM-ddTHH:mm:ssZ"),
            applied       = report.AppliedControls,
            // Error -> reason: el backend nombra "reason" el motivo del fallo del control.
            failed        = report.FailedControls.Select(f => new { code = f.Code, reason = f.Error }).ToArray(),
        };
        return JsonSerializer.Serialize(payload, Opts);
    }
}
