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
    private readonly AZCKeeper.K4.Shell.AgentReportReader? _agentReader;

    // Ultimo estado ESPERADO (de la config del handshake) y ultimo flag de diagnostico.
    // Los lee el loop de diagnostico para armar el snapshot y para saber si debe correr.
    private volatile IReadOnlyDictionary<string, bool> _lastExpected = new Dictionary<string, bool>();
    private volatile DiagnosticsFlag _lastDiagnostics = DiagnosticsFlag.Off;

    /// <summary>Modulos que el servidor espera ON/OFF, del ultimo handshake aplicado.</summary>
    public IReadOnlyDictionary<string, bool> LastExpected => _lastExpected;

    /// <summary>Flag de diagnostico del ultimo handshake (enabled/interval/until).</summary>
    public DiagnosticsFlag LastDiagnostics => _lastDiagnostics;

    public CoreService(K4ApiClient api, ModuleHost host, string cc, string deviceName, string version,
        Action<string>? log = null, AZCKeeper.K4.Shell.AgentReportReader? agentReader = null)
    {
        _api = api; _host = host; _cc = cc; _deviceName = deviceName; _version = version;
        _log = log; _agentReader = agentReader;
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
        if (hs is null)
        {
            // Token inválido/expirado -> re-login silencioso UNA vez. En K4 la identidad es
            // cédula + equipo enrolado (sin contraseña), así que basta re-postear el CC que
            // ya tenemos. Evita quedar sin sesión hasta el próximo reinicio.
            if (_api.LastHandshakeStatus == 401)
            {
                _api.ClearToken();
                var relog = await _api.LoginAsync(_cc, _deviceName, _version);
                if (!relog.Ok) { _log?.Invoke($"re-login: {relog.Status}"); return false; }
                _log?.Invoke("re-login silencioso ok");
                hs = await _api.HandshakeAsync(_version, _deviceName);
            }
            if (hs is null) { _log?.Invoke("handshake sin respuesta"); return false; }
        }

        var config = hs.ToModuleConfig();
        _host.Apply(config);

        // Guardar ESPERADO y flag de diagnostico para el loop de diagnostico.
        _lastExpected = config.ToDictionary(kv => kv.Key, kv => kv.Value.Enabled, StringComparer.Ordinal);
        _lastDiagnostics = hs.Diagnostics;

        // Eco del estado real: qué módulos están corriendo de verdad.
        await _api.ReportModuleStateAsync(_host.Snapshot());
        _log?.Invoke($"handshake aplicado ({config.Count} módulos en config, {_host.Modules.Count} registrados)");

        // Courier del agente elevado: transporta su reporte al panel. El cliente NO lo
        // produce, solo lo reenvía. Ausente -> agentPresent=false (gris en el panel).
        if (_agentReader is not null)
        {
            var courier = _agentReader.Read();
            await _api.ReportSecurityAsync(courier.Present, new { }, courier.Enforcement);
            if (courier.Present && courier.Stale) _log?.Invoke("agente: reporte viejo (posible agente colgado)");
        }

        return true;
    }

    public void StopAll() => _host.StopAll();

    /// <summary>
    /// Cierre gracioso: primero drena los buffers con await real (FlushAllAsync), luego
    /// para todo. Así el último resumen de actividad y los episodios pendientes SÍ salen
    /// antes de que el proceso muera. Es lo que el runner resolvía con un Task.Delay.
    /// </summary>
    public async Task StopAndFlushAsync()
    {
        await _host.FlushAllAsync();
        _host.StopAll();
    }
}
