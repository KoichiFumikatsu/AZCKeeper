using System.Runtime.Versioning;
using AZCKeeperAgent.Core;

namespace AZCKeeperAgent.Platform;

/// <summary>
/// Deja el reporte del agente en %ProgramData%\AZCKeeper\agent-report.json. %ProgramData%
/// es legible por el usuario (el cliente corre como el usuario y lo transporta) pero solo
/// escribible por admin/SYSTEM, asi que un usuario sin privilegio no puede falsificar el
/// reporte para fingir que el equipo esta protegido.
///
/// Escritura ATOMICA: escribe a un .tmp y hace File.Move con overwrite. Asi el cliente
/// nunca lee un JSON a medio escribir (leeria basura y creeria que el agente fallo).
/// </summary>
[SupportedOSPlatform("windows")]
public sealed class FileReportSink : IReportSink
{
    private readonly string _path;

    public FileReportSink(string? path = null)
    {
        var dir = path is not null
            ? System.IO.Path.GetDirectoryName(path)!
            : System.IO.Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.CommonApplicationData), "AZCKeeper");
        Directory.CreateDirectory(dir);
        _path = path ?? System.IO.Path.Combine(dir, "agent-report.json");
    }

    public string Path => _path;

    public void Publish(AgentReport report)
    {
        var json = CourierPayload.Build(report);
        var tmp = _path + ".tmp";
        File.WriteAllText(tmp, json);
        File.Move(tmp, _path, overwrite: true);
    }
}
