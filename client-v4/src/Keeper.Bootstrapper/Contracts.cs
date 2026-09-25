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
    void CreateDirectory(string path);
    void CopyFile(string source, string destination);
    void DeleteInstallDirectory();
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

public sealed record InstallationConfig(string ApiBase, Guid TenantId, Guid DeviceId, bool EnableHklm = true)
{
    [JsonIgnore(Condition = JsonIgnoreCondition.WhenWritingNull)]
    public string? EnrollmentTicket { get; init; }
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
        if (TenantId == Guid.Empty || DeviceId == Guid.Empty)
            throw new ArgumentException("tenant_id y device_id deben ser UUID no vacios del equipo de prueba.");
        if (!dryRun && uri.Host.EndsWith(".invalid", StringComparison.OrdinalIgnoreCase))
            throw new ArgumentException("Configure un backend real en installation.json antes de instalar; .invalid es solo para --dry-run.");
    }
}

public sealed record BootstrapOptions(bool DryRun, bool Uninstall, bool ElevatedChild, string PayloadDirectory, string ConfigPath)
{
    public bool SystemMode { get; init; }
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
        var harden = false;
        var unharden = false;
        string? hardeningConfig = null;
        var payload = Path.Combine(baseDirectory, "agent");
        var config = Path.Combine(baseDirectory, "installation.json");
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
                case "--elevated-child": elevatedChild = true; break;
                case "--payload": payload = ReadPath(ref i); break;
                case "--config": config = ReadPath(ref i); break;
                default: throw new ArgumentException($"Argumento desconocido o incompleto: {args[i]}");
            }
        }
        if (systemInstall && uninstall)
            throw new ArgumentException("--system-install no se puede combinar con --uninstall ni --system-uninstall.");
        if ((harden || unharden) && (systemInstall || uninstall) || harden && unharden)
            throw new ArgumentException("Los modos harden/unharden/install/uninstall son excluyentes.");
        if (hardeningConfig is not null && !harden && !unharden)
            throw new ArgumentException("--hardening-config requiere --harden o --unharden; instalacion usa hardening en installation.json.");
        return new(dryRun, uninstall, elevatedChild, payload, config)
        {
            SystemMode = systemInstall || systemUninstall, Harden = harden, Unharden = unharden,
            HardeningConfigPath = hardeningConfig
        };

        string ReadPath(ref int index)
        {
            if (index + 1 >= args.Length || string.IsNullOrWhiteSpace(args[index + 1]) || args[index + 1].StartsWith('-'))
                throw new ArgumentException($"{args[index]} requiere una ruta como valor; no puede faltar ni comenzar con '-'.");
            return Path.GetFullPath(args[++index]);
        }
    }
}
