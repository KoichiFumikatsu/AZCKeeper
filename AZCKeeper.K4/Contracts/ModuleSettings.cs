namespace AZCKeeper.K4.Contracts;

/// <summary>
/// Configuración efectiva de un módulo, extraída del effectiveConfig del handshake.
/// Ya viene recortada por tier desde el servidor: si el módulo llega deshabilitado,
/// puede ser porque la política lo apagó o porque la firma no lo licenció — al módulo
/// le da igual, solo obedece Enabled.
/// </summary>
public sealed class ModuleSettings
{
    public bool Enabled { get; init; }

    /// <summary>Parámetros libres del módulo (intervalos, dominios, etc.), sin tipar aquí.</summary>
    public IReadOnlyDictionary<string, object?> Params { get; init; }
        = new Dictionary<string, object?>();

    public int GetInt(string key, int fallback)
        => Params.TryGetValue(key, out var v) && v is not null && int.TryParse(v.ToString(), out var i) ? i : fallback;

    public bool GetBool(string key, bool fallback)
        => Params.TryGetValue(key, out var v) && v is not null && bool.TryParse(v.ToString(), out var b) ? b : fallback;
}
