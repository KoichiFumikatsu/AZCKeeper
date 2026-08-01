using AZCKeeper.K4.Contracts;

namespace AZCKeeper.K4.Platform;

/// <summary>
/// Reloj que corrige la hora local con un offset aprendido del servidor (serverTimeUtc del
/// handshake). NO toca el reloj del SO: solo ajusta lo que los módulos usan para marcar tiempo,
/// así un equipo con la hora desfasada no reporta episodios/actividad con timestamps torcidos
/// (equivalente al TimeSync de K3). El offset se aplica solo si es significativo (&gt;2s) para no
/// oscilar por el jitter de red.
/// </summary>
public sealed class ServerSyncedClock : IClock
{
    private long _offsetTicks;   // se lee/escribe atomico via Interlocked

    public DateTime Now    => DateTime.Now.AddTicks(System.Threading.Interlocked.Read(ref _offsetTicks));
    public DateTime UtcNow => DateTime.UtcNow.AddTicks(System.Threading.Interlocked.Read(ref _offsetTicks));

    /// <summary>Aprende el offset desde la hora UTC del servidor. Ignora ajustes triviales.</summary>
    public void SyncTo(DateTime serverUtc)
    {
        var diff = serverUtc - DateTime.UtcNow;   // sin el offset actual: mide el desfase real del SO
        if (Math.Abs(diff.TotalSeconds) >= 2)
            System.Threading.Interlocked.Exchange(ref _offsetTicks, diff.Ticks);
    }
}
