using AZCKeeper.K4.Contracts;
using AZCKeeper.K4.Core;
using AZCKeeper.K4.Modules;
using AZCKeeper.K4.Platform;

// Runner de desarrollo del cliente Keeper 4. Registra los módulos reales, corre UN ciclo
// del core contra la API (login -> handshake -> aplica módulos -> reporta estado real) y
// muestra qué quedó corriendo. El servicio/tray final se arma encima de CoreService +
// ModuleHost con un timer de handshake; esto verifica la integración desde el cliente
// compilado.
//
// Uso: AZCKeeper4 [baseUrl] [cc] [deviceGuid]

string baseUrl = args.Length > 0 ? args[0] : "http://devkeep.azclegal.com/public/index.php/api";
string cc = args.Length > 1 ? args[1] : "K4TEST";
string guid = args.Length > 2 ? args[2] : "a1b2c3d4-e5f6-4789-8abc-de0123456789";

var api = new K4ApiClient(baseUrl, guid);
var clock = new SystemClock();
var host = new ModuleHost(onError: (code, ex) => Console.WriteLine($"  [modulo {code}] error: {ex.Message}"));
void Log(string m) => Console.WriteLine($"  {m}");

// Registro de módulos: se cuelgan del core, ninguno conoce a otro. Cada uno recibe solo
// lo que necesita (el canal, el reloj, y su proveedor de plataforma).
var fg = new WinForegroundWindow();
var idle = new WinIdleMonitor();
host.Register(new ActivityModule(api, idle, clock, host.IsModuleRunning, Log));
host.Register(new WindowModule(api, fg, clock, Log));
host.Register(new CommandModule(api, clock, Log));
host.Register(new ScreenshotModule(api, new WinScreenCapturer(), new StubBlobStore(), clock, Log));

var core = new CoreService(api, host, cc, "K4-DevRunner", "4.0.0.0", log: Log);

Console.WriteLine($"Keeper 4 dev runner -> {baseUrl}\n");
bool ok = await core.RunOnceAsync();

Console.WriteLine("\nEstado real de módulos tras el handshake:");
foreach (var s in host.Snapshot())
    Console.WriteLine($"  {s.Code}: {(s.Running ? "corriendo" : "detenido")}");

// Dejar correr un momento para que los timers capturen algo, luego apagar limpio.
if (ok) { Console.WriteLine("\n(corriendo 8s para muestrear...)"); await Task.Delay(8000); }
core.StopAll();
// El flush de Stop es async (fire-and-forget); en este runner corto le damos margen
// para completar antes de que el proceso salga. El tray app real no sale, asi que no
// necesita esto — pero el cierre gracioso con flush sincrono queda como follow-up.
await Task.Delay(2000);

Console.WriteLine($"\n{(ok ? "CICLO OK" : "CICLO FALLIDO")}");
return ok ? 0 : 1;
