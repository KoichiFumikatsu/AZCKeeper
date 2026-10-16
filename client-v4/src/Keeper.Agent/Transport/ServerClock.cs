namespace Keeper.Agent.Transport;

// Desfase entre el reloj de Windows y el del servidor, solo para firmar peticiones (created/expires de la firma).
// El servidor exige |created - su hora| <= 60 s (Signature.php) y esa ventana es la defensa contra repeticiones: no se
// toca. Un equipo con la hora mal no podia darse de alta ni sincronizar (migracion K3 -> v4, 2026-10-16); ahora firma
// con hora_local + desfase. Nada mas del agente usa este desfase: el PIN local y las cuentas regresivas siguen con
// TimeProvider (monotonico o reloj de Windows) y el reloj de Windows lo corrige el modulo TimeSync.
//
// De donde sale: la cabecera HTTP Date de cualquier respuesta (ClockSkewHandler) y server_time del sync.
// Seguridad (un atacante en la red que manipule Date):
// - Solo hay HTTPS (el firmante rechaza otra cosa): cambiar Date exige romper TLS. Aun asi se acota:
// - Se ignoran Date invalidas y desfases de mas de 48 h.
// - Un desfase aprendido de un 401 se usa para UN reintento; si ese reintento no lo confirma (2xx) se vuelve al
//   ultimo confirmado. Confirmado = una peticion firmada con ese desfase fue aceptada (el servidor valido su hora).
// - Una firma hecha con un desfase falso no sirve para repetirla despues: el servidor guarda cada nonce aceptado
//   (device_signature_nonces) y el login usa el nonce del challenge, de un solo uso y con 60 s de vida; la peticion
//   de alta es idempotente por la clave publica del equipo. Lo peor que logra un atacante asi es que el equipo no
//   sincronice, cosa que ya puede hacer cortando la red.
// - No se guarda en disco: tras reiniciar el servicio el primer 401 lo recalcula en el acto (un reintento) y el
//   modulo TimeSync corrige la hora de Windows, asi que un archivo solo agregaria estado viejo que vigilar.
public sealed class ServerClock(TimeProvider clock, Action<string>? log = null)
{
    public static readonly TimeSpan MaxOffset = TimeSpan.FromHours(48);
    // Diferencia que justifica reintentar un 401 de firma (la ventana del servidor es 60 s).
    public static readonly TimeSpan RetryThreshold = TimeSpan.FromSeconds(30);
    // Date tiene resolucion de 1 s y la latencia suma: por debajo de esto no se mueve el desfase (evita vaivenes).
    public static readonly TimeSpan Noise = TimeSpan.FromSeconds(2);
    private readonly object _gate = new();
    private TimeSpan _offset;
    private TimeSpan _confirmed;

    public TimeProvider Local => clock;
    public TimeSpan Offset { get { lock (_gate) return _offset; } }
    public TimeSpan Confirmed { get { lock (_gate) return _confirmed; } }
    public DateTimeOffset ToServer(DateTimeOffset local) => local + Offset;

    // Desfase medido contra la hora local actual, o null si la hora del servidor no es usable.
    public TimeSpan? Measure(DateTimeOffset? serverTime)
    {
        if (serverTime is not { } server || server.Year < 2020 || server.Year > 2200) return null;
        var offset = server - clock.GetUtcNow();
        return offset.Duration() > MaxOffset ? null : offset;
    }

    // Aplica una hora del servidor. confirmed: llego en una respuesta 2xx a una peticion firmada (o es server_time).
    public TimeSpan? Observe(DateTimeOffset? serverTime, bool confirmed)
    {
        if (Measure(serverTime) is not { } measured) return null;
        lock (_gate)
        {
            var previous = _offset;
            if ((measured - _offset).Duration() > Noise) _offset = measured.Duration() <= Noise ? TimeSpan.Zero : measured;
            if (confirmed) _confirmed = _offset;
            if ((_offset - previous).Duration() >= RetryThreshold)
                log?.Invoke($"reloj: la hora de Windows difiere {(int)Math.Round(_offset.TotalSeconds)} s de la del servidor; las firmas usan la hora del servidor");
            return _offset;
        }
    }

    // La hora de Windows salto (la corrigio TimeSync o alguien la cambio): el desfase se mueve lo mismo al reves para
    // no firmar con uno viejo. Si quedara fuera de rango se descarta y lo recalcula la proxima respuesta.
    public void ShiftLocal(TimeSpan jump)
    {
        lock (_gate)
        {
            _offset -= jump;
            _confirmed -= jump;
            if (_offset.Duration() > MaxOffset) _offset = TimeSpan.Zero;
            if (_confirmed.Duration() > MaxOffset) _confirmed = TimeSpan.Zero;
        }
    }

    // Una peticion firmada con el desfase actual fue aceptada.
    public void Confirm() { lock (_gate) _confirmed = _offset; }

    // El reintento con un desfase sin confirmar tambien fallo: se descarta.
    public void Revert() { lock (_gate) _offset = _confirmed; }
}
