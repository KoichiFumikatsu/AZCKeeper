namespace AZCKeeper.K4.Contracts;

/// <summary>
/// Un módulo de Keeper. El contrato que hace que los módulos sean INDEPENDIENTES por
/// construcción: cada módulo solo conoce este contrato y lo que recibe por constructor
/// (nunca a otro módulo). El core los enciende y apaga; desconectar uno no toca a los
/// demás. Es el principio que se rompió en Keeper 3 (donde apagar un módulo exigía
/// reinicio y el estado quedaba inconsistente).
/// </summary>
public interface IKeeperModule
{
    /// <summary>Código de catálogo: 'activityTracking', 'windowTracking', etc.</summary>
    string Code { get; }

    /// <summary>Recibe la configuración efectiva del handshake. Puede llamarse varias veces.</summary>
    void Configure(ModuleSettings settings);

    /// <summary>Arranca el módulo. Idempotente: llamar dos veces no duplica.</summary>
    void Start();

    /// <summary>Detiene el módulo y libera sus recursos. Idempotente.</summary>
    void Stop();

    /// <summary>Estado REAL de ejecución, para el eco al servidor (keeper_device_module_state).</summary>
    bool IsRunning { get; }
}
