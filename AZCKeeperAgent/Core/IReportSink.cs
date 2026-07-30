namespace AZCKeeperAgent.Core;

/// <summary>
/// A donde el agente deja su reporte para que el cliente lo transporte. Es una interfaz
/// para poder probar el ciclo sin tocar disco (sink en memoria en los tests) y para no
/// atar el nucleo del agente a una ruta concreta de Windows.
/// </summary>
public interface IReportSink
{
    /// <summary>Persiste el reporte del ultimo ciclo. Debe ser atomico: un lector nunca
    /// debe ver un archivo a medio escribir.</summary>
    void Publish(AgentReport report);
}
