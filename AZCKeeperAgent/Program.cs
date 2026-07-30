using System.Text.Json;
using AZCKeeperAgent.Core;
using AZCKeeperAgent.Platform;

// Runner de desarrollo del agente elevado de Keeper 4. Corre UN ciclo con el registro
// real de Windows y muestra el reporte. El punto clave: si se corre SIN elevar, el
// auto-test reporta honestamente que NO tiene privilegio, en vez de fingir que aplicó.
// Ese es el anti-fallo-silencioso — verificable aquí sin instalar el servicio.
//
// El servicio de Windows real (LocalSystem) envuelve este ciclo en un loop y lo POSTea
// al servidor. La instalación del servicio (una vez, elevada) es paso de IT.

const string version = "4.0.0.0";

// Un par de controles de ejemplo (los reales vienen de la política del servidor).
var controls = new List<DesiredControl>
{
    new("chrome.DownloadRestrictions", @"Google\Chrome", "DownloadRestrictions", 3),
    new("chrome.URLBlocklist", @"Google\Chrome\URLBlocklist", null, null,
        IsEnumeratedSubkey: true, ListValues: new[] { "facebook.com", "x.com" }),
};

var reg = new WinPrivilegedRegistry();
var cycle = new AgentCycle(reg, version);
var report = cycle.Run(controls);

Console.WriteLine("Reporte del agente:\n");
Console.WriteLine(JsonSerializer.Serialize(report, new JsonSerializerOptions { WriteIndented = true }));

// Deja el reporte donde el cliente lo transportará al server (courier). El cliente lo
// adjunta a client/security/report y el panel lo pinta. El agente no toca la red.
// %ProgramData%\AZCKeeper solo lo escribe admin/SYSTEM. Si el runner corre sin elevar y
// no puede escribir, es OTRA señal honesta (un usuario no puede falsificar el reporte).
try
{
    var sink = new FileReportSink();
    sink.Publish(report);
    Console.WriteLine($"\nReporte publicado para el courier en: {sink.Path}");
}
catch (UnauthorizedAccessException)
{
    Console.WriteLine("\nNo se pudo publicar el reporte (sin privilegio para escribir %ProgramData%\\AZCKeeper). " +
        "En producción el agente corre como SYSTEM y sí puede; el usuario no, por diseño.");
}

Console.WriteLine();
if (!report.Elevated)
    Console.WriteLine(">> El agente NO está elevado: reporta el fallo honestamente (esto sería ROJO en el panel).");
else if (report.CanEnforce)
    Console.WriteLine(">> El agente está elevado y aplicó las políticas (verde en el panel).");

return report.CanEnforce ? 0 : 2;
