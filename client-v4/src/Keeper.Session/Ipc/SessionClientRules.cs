using Keeper.Session.Modules.Activity;
using Keeper.Shared.Contracts;
using Keeper.Shared.Protocol;

namespace Keeper.Session.Ipc;

// Reglas puras del canal de sesion, separadas de SessionClient para probarlas sin tuberia.
public static class SessionClientRules
{
    // Solo el bloqueo de TI (DeviceLock, LockScreen.LockRequested) es «sin uso» inmediato (spec 3): un ano. El bloqueo de
    // Windows (Win+L) envia la inactividad real: bloqueada mas de idle_minutes cuenta sola como sin uso. En llamada = en uso (0).
    // Si Windows no responde, null (desconocido): el planificador lo trata como «en uso».
    public static long? IdleSeconds(bool windowsLocked, bool itLocked, bool inCall, IInputIdleSource idle)
    {
        _ = windowsLocked;
        if (itLocked) return SessionProtocol.MaxIdleSeconds;
        if (inCall) return 0;
        try { return Math.Clamp((long)idle.GetIdleTime().TotalSeconds, 0, SessionProtocol.MaxIdleSeconds); }
        catch (System.ComponentModel.Win32Exception) { return null; }
    }
}

// Un aviso invalido del agente se ignora (se oculta el aviso) en vez de cortar la sesion: cortarla hacia que el agente la
// relanzara y le reenviara el mismo aviso en bucle. Se deja un Warn por cada aviso invalido distinto.
public sealed class PowerNoticeGate
{
    private PowerNotice? _lastInvalid;

    public PowerNotice? Filter(PowerNotice? incoming, bool shutdown, out LogEntry? warn)
    {
        warn = null;
        if (incoming is null || shutdown) return null;
        try { SessionProtocol.Validate(incoming); return incoming; }
        catch (InvalidDataException)
        {
            if (incoming != _lastInvalid)
            {
                _lastInvalid = incoming;
                warn = new LogEntry { EventId = Guid.NewGuid(), At = DateTimeOffset.UtcNow, Level = LogEntryLevel.Warn,
                    Component = "SessionClient", Code = "power_notice_invalid" };
            }
            return null;
        }
    }
}
