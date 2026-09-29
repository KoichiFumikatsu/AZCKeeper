using System.Text.Json;
using System.Text.Json.Serialization;
using Keeper.Bootstrapper.Hardening;

namespace Keeper.Bootstrapper;

public interface IElevation
{
    bool IsElevated { get; }
    int Relaunch(string[] arguments);
}

public interface IServiceControl
{
    bool Exists(string name);
    void Stop(string name);
    void Configure(ServiceDefinition definition, bool exists);
    void ConfigureRecovery(string name);
    void Start(string name);
    void Delete(string name);
}

public interface ISystemPaths
{
    string InstallDirectory { get; }
    string ReadText(string path);
    IReadOnlyList<string> PayloadFiles(string directory);
    void ValidateInstallTree(bool uninstall = false);
    void CreateProtectedDirectory(string path);
    void ApplyBinaryAcl(string path);
    void CreateDirectory(string path);
    void CopyFile(string source, string destination);
    void DeleteInstallDirectory();
}

// Vuelta atras del auto-update: respaldo de bin, comprobacion de que la version nueva sincroniza y restauracion.
public enum UpdateHealth { Healthy, NetworkUnknown, Unhealthy }
public interface IUpdateGuard
{
    void Backup(string bin);
    void Restore(string bin);
    // Espera a que la version nueva haga un sync exitoso despues de `since`. NetworkUnknown: el agente esta vivo
    // pero tampoco el bootstrapper llega al servidor (la red, no la version: se conserva).
    UpdateHealth WaitHealthy(DateTimeOffset since);
    long? PayloadSequence(string payload);
    void BlockRelease(long sequence);
}

// Rescate independiente del agente (recovery\Keeper-Recovery.ps1 + tarea horaria SYSTEM). Se instala una vez:
// un --system-update solo lo instala si falta, para que un update roto no pueda romper el rescate.
public interface IRescueInstaller
{
    bool IsInstalled { get; }
    void Install(string payload);
    void Remove();
}

public interface IRegistryStore
{
    void SetEnvironment(string serviceName, string[] values);
    void DeleteTree(string path);
    void DeleteValue(string path, string name);
}

public sealed record ServiceDefinition(string Name, string Executable)
{
    public string Account => "LocalSystem";
    public string StartType => "auto";
    public string ServiceType => "own";
    public string[] ScArguments(bool exists) =>
        [exists ? "config" : "create", Name, "binPath=", $"\"{Executable}\"", "type=", ServiceType,
            "start=", StartType, "obj=", Account, "DisplayName=", "AZCKeeper v4"];

    public string Describe(bool exists) =>
        $"sc.exe {(exists ? "config" : "create")} {Name} binPath= '\"{Executable}\"' type= {ServiceType} start= {StartType} obj= {Account} DisplayName= 'AZCKeeper v4'";
}

// Dos formas de alta: paquete por equipo (tenant_id + device_id + enrollment_ticket) o paquete generico de la empresa
// (enrollment_key, sin device_id): el equipo pide alta y el servidor le asigna su device_id al aprobarla.
public sealed record InstallationConfig(string ApiBase, Guid TenantId = default, Guid DeviceId = default, bool EnableHklm = true)
{
    [JsonIgnore(Condition = JsonIgnoreCondition.WhenWritingNull)]
    public string? EnrollmentTicket { get; init; }
    [JsonIgnore(Condition = JsonIgnoreCondition.WhenWritingNull)]
    public string? EnrollmentKey { get; init; }
    public HardeningConfig Hardening { get; init; } = new();
    public static JsonSerializerOptions Json { get; } = new()
    {
        PropertyNamingPolicy = JsonNamingPolicy.SnakeCaseLower,
        UnmappedMemberHandling = JsonUnmappedMemberHandling.Disallow,
        WriteIndented = true
    };

    static InstallationConfig() => Json.Converters.Add(new JsonStringEnumConverter(allowIntegerValues: false));

    public void Validate(bool dryRun)
    {
        Hardening.Validate();
        if (EnrollmentTicket is not null && (string.IsNullOrWhiteSpace(EnrollmentTicket) || EnrollmentTicket.Any(char.IsControl)))
            throw new ArgumentException("enrollment_ticket debe ser no vacio y no contener caracteres de control.");
        if (!Uri.TryCreate(ApiBase, UriKind.Absolute, out var uri) || uri.Scheme != "https" ||
            !uri.AbsolutePath.EndsWith("/v1/", StringComparison.Ordinal) ||
            uri.UserInfo.Length != 0 || uri.Query.Length != 0 || uri.Fragment.Length != 0)
            throw new ArgumentException("api_base debe ser HTTPS, terminar en /v1/ y no contener credenciales, query ni fragmento.");
        if (EnrollmentKey is not null)
        {
            if (EnrollmentKey.Length is < 20 or > 128 || EnrollmentKey.Any(c => char.IsControl(c) || char.IsWhiteSpace(c)))
                throw new ArgumentException("enrollment_key invalida: copiela completa desde el panel (Alta de equipos > Claves).");
            if (DeviceId != Guid.Empty || EnrollmentTicket is not null)
                throw new ArgumentException("enrollment_key es para el paquete generico: no se combina con device_id ni enrollment_ticket.");
        }
        else if (TenantId == Guid.Empty || DeviceId == Guid.Empty)
            throw new ArgumentException("tenant_id y device_id deben ser UUID no vacios del equipo (o use enrollment_key para el paquete generico).");
        if (!dryRun && uri.Host.EndsWith(".invalid", StringComparison.OrdinalIgnoreCase))
            throw new ArgumentException("Configure un backend real en installation.json antes de instalar; .invalid es solo para --dry-run.");
    }
}

public sealed record BootstrapOptions(bool DryRun, bool Uninstall, bool ElevatedChild, string PayloadDirectory, string ConfigPath)
{
    public bool SystemMode { get; init; }
    public bool SystemUpdate { get; init; }
    public bool Harden { get; init; }
    public bool Unharden { get; init; }
    public string? HardeningConfigPath { get; init; }

    public static BootstrapOptions Parse(string[] args, string baseDirectory)
    {
        var dryRun = false;
        var uninstall = false;
        var elevatedChild = false;
        var systemInstall = false;
        var systemUninstall = false;
        var systemUpdate = false;
        var harden = false;
        var unharden = false;
        string? hardeningConfig = null;
        // Formato compartido: el bootstrapper vive DENTRO de agent\ junto al runtime y los demas binarios, asi que
        // el payload es su propia carpeta y installation.json esta en la carpeta padre. Formato anterior: el
        // bootstrapper en la raiz del paquete y el payload en agent\.
        var shared = File.Exists(Path.Combine(baseDirectory, "Keeper.Agent.exe"));
        var payload = shared ? baseDirectory : Path.Combine(baseDirectory, "agent");
        var config = Path.Combine(shared ? Path.GetDirectoryName(Path.TrimEndingDirectorySeparator(baseDirectory)) ?? baseDirectory : baseDirectory,
            "installation.json");
        for (var i = 0; i < args.Length; i++)
        {
            switch (args[i])
            {
                case "--dry-run": dryRun = true; break;
                case "--harden": harden = true; break;
                case "--unharden": unharden = true; break;
                case "--hardening-config": hardeningConfig = ReadPath(ref i); break;
                case "--uninstall": uninstall = true; break;
                case "--system-install": systemInstall = true; break;
                case "--system-uninstall": systemUninstall = true; uninstall = true; break;
                case "--system-update": systemUpdate = true; break;
                case "--elevated-child": elevatedChild = true; break;
                case "--payload": payload = ReadPath(ref i); break;
                case "--config": config = ReadPath(ref i); break;
                default: throw new ArgumentException($"Argumento desconocido o incompleto: {args[i]}");
            }
        }
        if (systemInstall && uninstall)
            throw new ArgumentException("--system-install no se puede combinar con --uninstall ni --system-uninstall.");
        if (systemUpdate && (systemInstall || uninstall || harden || unharden))
            throw new ArgumentException("--system-update es excluyente con install/uninstall/harden.");
        if ((harden || unharden) && (systemInstall || uninstall) || harden && unharden)
            throw new ArgumentException("Los modos harden/unharden/install/uninstall son excluyentes.");
        if (hardeningConfig is not null && !harden && !unharden)
            throw new ArgumentException("--hardening-config requiere --harden o --unharden; instalacion usa hardening en installation.json.");
        return new(dryRun, uninstall, elevatedChild, payload, config)
        {
            SystemMode = systemInstall || systemUninstall || systemUpdate, Harden = harden, Unharden = unharden,
            SystemUpdate = systemUpdate, HardeningConfigPath = hardeningConfig
        };

        string ReadPath(ref int index)
        {
            if (index + 1 >= args.Length || string.IsNullOrWhiteSpace(args[index + 1]) || args[index + 1].StartsWith('-'))
                throw new ArgumentException($"{args[index]} requiere una ruta como valor; no puede faltar ni comenzar con '-'.");
            return Path.GetFullPath(args[++index]);
        }
    }
}
