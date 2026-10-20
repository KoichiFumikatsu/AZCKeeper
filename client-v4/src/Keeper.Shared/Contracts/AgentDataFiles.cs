namespace Keeper.Shared.Contracts;

// Nombres de archivo dentro del directorio de datos del agente (KEEPER_DATA_DIR = <instalacion>\v4),
// compartidos entre el agente y el bootstrapper.
public static class AgentDataFiles
{
    public const string PolicyCache = "policy-cache.json";
    // Lo que el bloqueo del cambio de hora quito o dio en la LSA (TimeChangeLock); el Bootstrapper lo devuelve al desinstalar.
    public const string TimeChangeLock = "time-change-lock.json";
}
