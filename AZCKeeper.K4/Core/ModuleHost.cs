using AZCKeeper.K4.Contracts;

namespace AZCKeeper.K4.Core;

/// <summary>
/// El "core único" del que cuelgan los módulos. Los registra, y en cada handshake
/// aplica la configuración efectiva encendiendo/apagando EN CALIENTE (sin reinicio, el
/// defecto de Keeper 3). Cada módulo se aísla en su propio try/catch: si uno falla al
/// arrancar o configurarse, los demás siguen. El host no conoce el detalle de ningún
/// módulo; solo el contrato IKeeperModule.
/// </summary>
public sealed class ModuleHost
{
    private readonly Dictionary<string, IKeeperModule> _modules = new(StringComparer.Ordinal);
    private readonly Action<string, Exception>? _onError;

    public ModuleHost(Action<string, Exception>? onError = null) => _onError = onError;

    public void Register(IKeeperModule module)
    {
        if (_modules.ContainsKey(module.Code))
            throw new InvalidOperationException($"Módulo duplicado: {module.Code}");
        _modules[module.Code] = module;
    }

    public IReadOnlyCollection<IKeeperModule> Modules => _modules.Values;

    /// <summary>
    /// Aplica la config efectiva: cada módulo se configura y se enciende o apaga según
    /// su ModuleSettings. Un módulo ausente en la config queda como está. El fallo de un
    /// módulo se aísla y se reporta, sin abortar a los demás.
    /// </summary>
    public void Apply(IReadOnlyDictionary<string, ModuleSettings> config)
    {
        foreach (var (code, module) in _modules)
        {
            if (!config.TryGetValue(code, out var settings)) continue;
            try
            {
                module.Configure(settings);
                if (settings.Enabled && !module.IsRunning) module.Start();
                else if (!settings.Enabled && module.IsRunning) module.Stop();
            }
            catch (Exception ex)
            {
                _onError?.Invoke(code, ex);
                // No re-lanza: el siguiente módulo se aplica igual.
            }
        }
    }

    /// <summary>
    /// ¿Está corriendo el módulo con este código? Lo usan otros módulos para consultar
    /// cobertura (ej. actividad pregunta si windowTracking corre) SIN conocer la clase
    /// del otro módulo: dependen de esta abstraccion del host, no unos de otros.
    /// </summary>
    public bool IsModuleRunning(string code)
        => _modules.TryGetValue(code, out var m) && SafeRunning(m);

    /// <summary>Estado real de cada módulo, para el eco al servidor.</summary>
    public IReadOnlyList<ModuleStateDto> Snapshot()
        => _modules.Values.Select(m => new ModuleStateDto(m.Code, SafeRunning(m))).ToList();

    private bool SafeRunning(IKeeperModule m)
    {
        try { return m.IsRunning; } catch { return false; }
    }

    public void StopAll()
    {
        foreach (var module in _modules.Values)
        {
            try { if (module.IsRunning) module.Stop(); }
            catch (Exception ex) { _onError?.Invoke(module.Code, ex); }
        }
    }
}
