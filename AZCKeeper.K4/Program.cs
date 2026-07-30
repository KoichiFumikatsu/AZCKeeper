using AZCKeeper.K4.Core;
using AZCKeeper.K4.Modules;

// Runner de desarrollo del cliente Keeper 4. Corre UN ciclo del core contra la API
// (login -> handshake -> aplica módulos -> reporta estado) y sale. Sirve para verificar
// la integración desde el cliente compilado. El tray app / servicio final se arma
// encima de CoreService + ModuleHost.
//
// Uso: AZCKeeper4 [baseUrl] [cc] [deviceGuid]

string baseUrl = args.Length > 0 ? args[0] : "http://devkeep.azclegal.com/public/index.php/api";
string cc = args.Length > 1 ? args[1] : "K4TEST";
string guid = args.Length > 2 ? args[2] : "a1b2c3d4-e5f6-4789-8abc-de0123456789";

var api = new K4ApiClient(baseUrl, guid);
var host = new ModuleHost(onError: (code, ex) => Console.WriteLine($"  [modulo {code}] error: {ex.Message}"));

// Registro de módulos. Aquí se cuelgan del core; ninguno conoce a otro.
host.Register(new HeartbeatModule());

var core = new CoreService(api, host, cc, "K4-DevRunner", "4.0.0.0", log: m => Console.WriteLine($"  {m}"));

Console.WriteLine($"Keeper 4 dev runner -> {baseUrl}\n");
bool ok = await core.RunOnceAsync();

Console.WriteLine("\nEstado de módulos tras el handshake:");
foreach (var s in host.Snapshot())
    Console.WriteLine($"  {s.Code}: {(s.Running ? "corriendo" : "detenido")}");

Console.WriteLine($"\n{(ok ? "CICLO OK" : "CICLO FALLIDO")}");
return ok ? 0 : 1;
