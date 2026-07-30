using AZCKeeper.K4.Contracts;

namespace AZCKeeper.K4.Core;

/// <summary>
/// Composition root de Keeper 4. Hace login, corre el ciclo de handshake, aplica la
/// configuración efectiva al ModuleHost (que enciende/apaga módulos en caliente) y
/// reporta el estado REAL de los módulos. No conoce el detalle de ningún módulo: todo
/// pasa por el host y el contrato.
///
/// A diferencia de Keeper 3, aplicar la config NO exige reiniciar: el host llama
/// Start/Stop según cada handshake.
/// </summary>
public sealed class CoreService
{
    private readonly K4ApiClient _api;
    private readonly ModuleHost _host;
    private readonly string _cc, _deviceName, _version;
    private readonly Action<string>? _log;

    public CoreService(K4ApiClient api, ModuleHost host, string cc, string deviceName, string version, Action<string>? log = null)
    {
        _api = api; _host = host; _cc = cc; _deviceName = deviceName; _version = version; _log = log;
    }

    /// <summary>Un ciclo completo: asegura sesión, handshake, aplica, reporta estado.</summary>
    public async Task<bool> RunOnceAsync()
    {
        if (!_api.HasToken)
        {
            var login = await _api.LoginAsync(_cc, _deviceName, _version);
            if (!login.Ok)
            {
                _log?.Invoke($"login: {login.Status}");
                return false;
            }
            _log?.Invoke("login ok");
        }

        var hs = await _api.HandshakeAsync(_version, _deviceName);
        if (hs is null) { _log?.Invoke("handshake sin respuesta"); return false; }

        var config = hs.ToModuleConfig();
        _host.Apply(config);

        // Eco del estado real: qué módulos están corriendo de verdad.
        await _api.ReportModuleStateAsync(_host.Snapshot());
        _log?.Invoke($"handshake aplicado ({config.Count} módulos en config, {_host.Modules.Count} registrados)");
        return true;
    }

    public void StopAll() => _host.StopAll();
}
