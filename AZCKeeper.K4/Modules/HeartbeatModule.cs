using AZCKeeper.K4.Contracts;

namespace AZCKeeper.K4.Modules;

/// <summary>
/// Módulo mínimo de referencia: solo lleva su propio estado Start/Stop. Sirve para
/// probar el lifecycle del host y como plantilla del contrato — cualquier módulo real
/// (actividad, ventanas, comandos) sigue esta forma: sin dependencias de otros módulos,
/// idempotente en Start/Stop, IsRunning refleja el estado real.
/// </summary>
public sealed class HeartbeatModule : IKeeperModule
{
    public string Code => "activityTracking";
    private bool _running;

    public bool IsRunning => _running;
    public void Configure(ModuleSettings settings) { /* nada que configurar */ }
    public void Start() => _running = true;
    public void Stop() => _running = false;
}
