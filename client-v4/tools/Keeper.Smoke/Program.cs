using System.Net.Http.Headers;
using System.Text.Json;
using Keeper.Agent.Hosting;
using Keeper.Agent.Migration;
using Keeper.Agent.Policy;
using Keeper.Agent.Storage;
using Keeper.Agent.Transport;
using Keeper.Shared.Contracts;
using Keeper.Shared.Protocol;

// Prueba de humo del TRANSPORTE del cliente contra un entorno real.
// Verifica lo que hasta ahora nunca se habia ejercitado: que el agente pueda firmar
// (RFC 9421), enrolarse y que una sincronizacion con el desglose horario llegue al servidor.
//
//   huella   -> imprime la huella de la clave del equipo (la necesita IT para el enrolamiento)
//   enrolar  -> canjea el ticket por un token de dispositivo
//   enviar   -> sincroniza un snapshot de actividad con desglose
//
// La clave vive junto al ejecutable, no en ProgramData: esto es un arnes, no el agente.

var keyPath = Path.Combine(AppContext.BaseDirectory, "smoke-device-key.dpapi");
var statePath = Path.Combine(AppContext.BaseDirectory, "smoke-outbox.json");

if (args.Length == 0) { Console.Error.WriteLine("modos: huella | enrolar | enviar"); return 2; }

using var key = await DeviceKeyStore.LoadOrCreateAsync(keyPath, default);
using var signer = new HttpMessageSigner(key);

switch (args[0])
{
    case "huella":
        Console.WriteLine(MigrationEnrollment.Thumbprint(signer));
        return 0;

    case "diagnostico":
    {
        // Muestra exactamente lo que el cliente pone en el cable y lo que contesta el servidor.
        using var http = Client(args[1]);
        var body = JsonSerializer.SerializeToUtf8Bytes(
            new ChallengeRequest { DeviceId = null, EnrollmentTicket = args[2] }, ProtocolJson.Options);
        Console.WriteLine("peticion: " + System.Text.Encoding.UTF8.GetString(body));
        using var request = new HttpRequestMessage(HttpMethod.Post, new Uri(http.BaseAddress!, "client/auth/challenges"));
        request.Content = new ByteArrayContent(body);
        request.Content.Headers.ContentType = new MediaTypeHeaderValue("application/json");
        using var response = await http.SendAsync(request);
        Console.WriteLine($"challenge http={(int)response.StatusCode}");
        var challengeBody = await response.Content.ReadAsStringAsync();
        Console.WriteLine("respuesta: " + challengeBody);
        if (!response.IsSuccessStatusCode) return 1;

        var nonce = JsonSerializer.Deserialize<Challenge>(challengeBody, ProtocolJson.Options)!.Nonce;
        var loginBody = JsonSerializer.SerializeToUtf8Bytes(new DeviceLogin
        {
            DeviceId = null, EnrollmentTicket = args[2], PublicKey = signer.PublicKey,
            AgentVersion = "4.0.0", Hostname = Environment.MachineName,
        }, ProtocolJson.Options);
        using var login = new HttpRequestMessage(HttpMethod.Post, new Uri(http.BaseAddress!, "client/login"));
        login.Content = new ByteArrayContent(loginBody);
        login.Content.Headers.ContentType = new MediaTypeHeaderValue("application/json");
        signer.Sign(login, loginBody, DateTimeOffset.UtcNow, nonce);
        Console.WriteLine("Signature-Input: " + string.Join(" ", login.Headers.GetValues("Signature-Input")));
        using var loginResponse = await http.SendAsync(login);
        Console.WriteLine($"login http={(int)loginResponse.StatusCode}");
        Console.WriteLine("respuesta: " + await loginResponse.Content.ReadAsStringAsync());
        return 0;
    }

    case "enrolar":
    {
        if (args.Length < 5) { Console.Error.WriteLine("uso: enrolar <apiBase> <tenantId> <deviceId> <ticket>"); return 2; }
        using var http = Client(args[1]);
        var token = await MigrationEnrollment.LoginAsync(http, signer, Guid.Parse(args[2]), Guid.Parse(args[3]), args[4], default);
        // El bearer no se imprime nunca; solo la prueba de que la identidad quedo establecida.
        Console.WriteLine($"OK enrolado  device={token.DeviceId}  tenant={token.TenantId}  expira_en={token.ExpiresIn}s");
        return 0;
    }

    case "enviar":
    {
        if (args.Length < 4) { Console.Error.WriteLine("uso: enviar <apiBase> <tenantId> <deviceId>"); return 2; }
        var device = Guid.Parse(args[3]);
        using var http = Client(args[1]);
        using var outbox = new DurableOutbox(statePath);

        var today = DateOnly.FromDateTime(DateTime.UtcNow.AddDays(-1));
        await outbox.EnqueueAsync(new ActivitySnapshot
        {
            SnapshotId = Guid.NewGuid(), Sequence = DateTimeOffset.UtcNow.ToUnixTimeSeconds(), Day = today,
            ActiveSeconds = 7200, IdleSeconds = 1800, CallSeconds = 600,
            WorkHoursActiveSeconds = 5400, WorkHoursIdleSeconds = 1200,
            LunchActiveSeconds = 900, LunchIdleSeconds = 300,
            AfterHoursActiveSeconds = 900, AfterHoursIdleSeconds = 300,
            FirstActivityAt = today.ToDateTime(new TimeOnly(13, 5)), LastActivityAt = today.ToDateTime(new TimeOnly(22, 40)),
            SampleCount = 9000, UtcOffsetMinutes = -300,
        }, default);

        // Sin modulos: al arnes le interesa el transporte, no aplicar politica al equipo.
        await using var host = new ModuleHost([], new ModuleContext(outbox, TimeProvider.System, Console.Error.WriteLine, default));
        await host.InitAsync();
        var policy = new PolicyCoordinator(new SignedFilePolicyStore(Path.Combine(AppContext.BaseDirectory, "smoke-policy.json"), key), host, device);
        await policy.RestoreAsync(default);

        var client = new SyncClient(http, signer, device, outbox, policy, TimeProvider.System);
        var next = await client.ExecuteAsync(default);
        Console.WriteLine($"OK sincronizado  proxima_sincronizacion_en={next}s  peticiones={client.RequestCount}");
        return 0;
    }

    default:
        Console.Error.WriteLine("modo desconocido");
        return 2;
}

static HttpClient Client(string apiBase)
{
    var handler = new HttpClientHandler { AllowAutoRedirect = false, UseCookies = false };
    return new HttpClient(handler) { BaseAddress = new Uri(apiBase), Timeout = TimeSpan.FromSeconds(45) };
}
