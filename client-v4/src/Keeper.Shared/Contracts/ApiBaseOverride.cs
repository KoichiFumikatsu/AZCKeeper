using System.Text.Json;
using System.Text.Json.Serialization;

namespace Keeper.Shared.Contracts;

// {data}\api-base.json: servidor al que el agente se mudo por una orden firmada (agente 4.0.24, RelocationVerifier).
// Tiene prioridad sobre KEEPER_API_BASE (fijada al instalar y que nadie cambia) mientras esa variable siga siendo la de
// cuando se mudo (installed_base): una reinstalacion que apunta a otro servidor manda sobre el archivo. Lo leen el agente
// (arranque), el bootstrapper (salud tras un update) y el rescate (Keeper-Recovery.ps1).
public sealed record ApiBaseOverride(
    [property: JsonPropertyName("api_base")] string ApiBase,
    [property: JsonPropertyName("installed_base")] string InstalledBase,
    [property: JsonPropertyName("sequence")] long Sequence,
    [property: JsonPropertyName("relocation_id")] Guid RelocationId,
    [property: JsonPropertyName("relocated_at")] DateTimeOffset RelocatedAt)
{
    public const string FileName = "api-base.json";
    // Destinos permitidos de una mudanza (lista fija; el servidor exige lo mismo en Relocations::HOST_SUFFIXES).
    public static readonly string[] AllowedHostSuffixes = [".azclegal.com"];

    public static bool AllowedHost(string host) =>
        AllowedHostSuffixes.Any(s => host.EndsWith(s, StringComparison.OrdinalIgnoreCase) && host.Length > s.Length);

    // Base de API valida: https, termina en /v1/, sin credenciales, query ni fragmento.
    public static bool ValidApi(Uri api) => api.IsAbsoluteUri && api.Scheme == Uri.UriSchemeHttps &&
        api.AbsolutePath.EndsWith("/v1/", StringComparison.Ordinal) && api.Query.Length == 0 && api.Fragment.Length == 0 && api.UserInfo.Length == 0;

    // null si no existe o no es valido (nunca lanza: un archivo danado deja la base instalada).
    public static ApiBaseOverride? Read(string dataDirectory)
    {
        try
        {
            var path = Path.Combine(dataDirectory, FileName);
            if (!File.Exists(path)) return null;
            var value = JsonSerializer.Deserialize<ApiBaseOverride>(File.ReadAllBytes(path));
            return value is not null && Uri.TryCreate(value.ApiBase, UriKind.Absolute, out var api) && ValidApi(api) && AllowedHost(api.Host) &&
                value.Sequence > 0 && !string.IsNullOrEmpty(value.InstalledBase) ? value : null;
        }
        catch (Exception ex) when (ex is IOException or UnauthorizedAccessException or JsonException or NotSupportedException) { return null; }
    }

    // Base efectiva: la del archivo si aplica a la variable instalada; si no, la variable tal cual.
    public static string? Resolve(string dataDirectory, string? installedBase) =>
        installedBase is not null && Read(dataDirectory) is { } moved && SameBase(moved.InstalledBase, installedBase) ? moved.ApiBase : installedBase;

    public static bool SameBase(string a, string b) =>
        Uri.TryCreate(a, UriKind.Absolute, out var x) && Uri.TryCreate(b, UriKind.Absolute, out var y) &&
        Uri.Compare(x, y, UriComponents.AbsoluteUri, UriFormat.SafeUnescaped, StringComparison.OrdinalIgnoreCase) == 0;

    public void Write(string dataDirectory)
    {
        var path = Path.Combine(dataDirectory, FileName);
        var temporary = path + ".tmp";
        File.WriteAllBytes(temporary, JsonSerializer.SerializeToUtf8Bytes(this, new JsonSerializerOptions { WriteIndented = true }));
        File.Move(temporary, path, overwrite: true);
    }
}
