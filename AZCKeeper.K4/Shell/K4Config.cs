using System.Text.Json;
using System.Text.Json.Serialization;

namespace AZCKeeper.K4.Shell;

/// <summary>
/// Configuración del cliente K4, en JSON plano bajo %APPDATA%\AZCKeeper4\Config. Incluye
/// el identificador estable del equipo (deviceId), que se genera una sola vez y se
/// conserva entre arranques — es la identidad con la que el equipo se enrola y reporta.
///
/// El token de sesión NO vive aquí (va cifrado con DPAPI en K4CredentialStore). Aquí solo
/// van parámetros no sensibles: a dónde apuntar, cada cuánto hacer handshake, y las
/// banderas de auto-update.
/// </summary>
public sealed class K4Config
{
    /// <summary>
    /// Entornos que ofrece la pantalla de primer arranque. IT elige uno y no puede escribir mal
    /// una URL. Hoy solo Desarrollo: K4 aun no tiene produccion (prod es K3). Cuando exista, se
    /// agrega una tupla aqui — no hay URLs muertas que confundan.
    /// </summary>
    public static readonly (string Label, string BaseUrl)[] Environments =
    {
        ("Desarrollo", "http://devkeep.azclegal.com/public/index.php/api"),
    };

    public string BaseUrl { get; set; } = "http://devkeep.azclegal.com/public/index.php/api";
    public string DeviceId { get; set; } = "";
    public string Cc { get; set; } = "";
    public string Version { get; set; } = "4.0.0.0";

    public int HandshakeIntervalSeconds { get; set; } = 300;
    public int OfflineRetrySeconds { get; set; } = 30;

    public UpdateSettings Updates { get; set; } = new();

    [JsonIgnore] public string Path { get; private set; } = K4Paths.ConfigFile;

    private static readonly JsonSerializerOptions JsonOpts = new()
    {
        WriteIndented = true,
        PropertyNamingPolicy = JsonNamingPolicy.CamelCase,
    };

    /// <summary>
    /// Carga la config del disco, o crea una nueva por defecto si no existe. Siempre
    /// garantiza un deviceId (lo genera y persiste la primera vez). Si el JSON está
    /// corrupto, arranca con defaults en vez de reventar — un cliente sin config no debe
    /// quedar muerto.
    /// </summary>
    public static K4Config LoadOrCreate(string? path = null)
    {
        path ??= K4Paths.ConfigFile;
        K4Config cfg;
        try
        {
            cfg = File.Exists(path)
                ? JsonSerializer.Deserialize<K4Config>(File.ReadAllText(path), JsonOpts) ?? new K4Config()
                : new K4Config();
        }
        catch (Exception ex) when (ex is JsonException or IOException)
        {
            cfg = new K4Config();
        }
        cfg.Path = path;
        cfg.EnsureDeviceId();
        return cfg;
    }

    /// <summary>Genera un deviceId estable si falta y lo persiste. Idempotente.</summary>
    public void EnsureDeviceId()
    {
        if (string.IsNullOrWhiteSpace(DeviceId))
        {
            DeviceId = Guid.NewGuid().ToString();
            Save();
        }
    }

    /// <summary>Guardado atómico: escribe a .tmp y mueve, para no dejar un JSON a medias.</summary>
    public void Save()
    {
        var dir = System.IO.Path.GetDirectoryName(Path)!;
        Directory.CreateDirectory(dir);
        var tmp = Path + ".tmp";
        File.WriteAllText(tmp, JsonSerializer.Serialize(this, JsonOpts));
        File.Move(tmp, Path, overwrite: true);
    }
}

public sealed class UpdateSettings
{
    public bool Enable { get; set; } = true;
    public int IntervalMinutes { get; set; } = 60;
    public bool AutoDownload { get; set; } = false;
    public bool AllowBeta { get; set; } = false;
}
