using System;
using System.Net.Sockets;

namespace AZCKeeper_Cliente.Network
{
    /// <summary>
    /// Clase de fallo de red. Determina cuánto conviene esperar antes de reintentar.
    /// </summary>
    internal enum NetworkFailureKind
    {
        /// <summary>El intento no cuenta como fallo de red (2xx, 401, 404...).</summary>
        Success = 0,

        /// <summary>No se pudo resolver el host. Suele durar segundos.</summary>
        Dns = 1,

        /// <summary>Corte de transporte o error del backend (reset, timeout, 5xx).</summary>
        Transient = 2,

        /// <summary>El servidor nos está frenando o baneando (403/429).</summary>
        Throttled = 3
    }

    /// <summary>
    /// Decide el backoff de red del cliente según la CLASE de fallo, no solo su existencia.
    ///
    /// El circuit breaker original nació para no sostener bans de CSF/LFD del hosting
    /// compartido (100+ equipos tras un NAT único reintentando en tormenta), y por eso
    /// escalaba hasta 30 minutos. El problema: metía en ese mismo camino los fallos de
    /// DNS de la sede, que duran segundos. Un parpadeo de DNS costaba media hora sin
    /// handshake — y por tanto sin política ni chequeo de update.
    ///
    /// Un ban no rompe la resolución DNS: si el host no resuelve, esperar 30 minutos no
    /// aporta nada. De ahí la separación de topes por clase.
    /// </summary>
    internal sealed class NetworkBackoffPolicy
    {
        public const double DnsBaseSeconds = 5.0;
        public const double DnsCapSeconds = 60.0;

        public const double TransientBaseSeconds = 30.0;
        public const double TransientCapSeconds = 300.0;

        public const double ThrottledBaseSeconds = 30.0;
        public const double ThrottledCapSeconds = 1800.0;

        private const double JitterFraction = 0.2;

        private readonly object _lock = new object();
        private readonly Func<DateTime> _nowUtc;
        private readonly Func<double> _rng;

        private int _consecutiveFailures;
        private DateTime _untilUtc = DateTime.MinValue;
        private NetworkFailureKind _lastKind = NetworkFailureKind.Success;

        public NetworkBackoffPolicy() : this(() => DateTime.UtcNow, null) { }

        public NetworkBackoffPolicy(Func<DateTime> nowUtc, Func<double> rng)
        {
            _nowUtc = nowUtc ?? (() => DateTime.UtcNow);

            if (rng == null)
            {
                var random = new Random();
                lock (random) { _rng = () => random.NextDouble(); }
            }
            else
            {
                _rng = rng;
            }
        }

        public bool IsBackingOff { get { lock (_lock) { return _nowUtc() < _untilUtc; } } }
        public DateTime BackoffUntilUtc { get { lock (_lock) { return _untilUtc; } } }
        public int ConsecutiveFailures { get { lock (_lock) { return _consecutiveFailures; } } }
        public NetworkFailureKind LastKind { get { lock (_lock) { return _lastKind; } } }

        /// <summary>
        /// Clasifica el resultado de un intento de red. <paramref name="ex"/> es null si hubo respuesta HTTP.
        /// </summary>
        public static NetworkFailureKind Classify(int statusCode, Exception ex)
        {
            if (ex != null)
                return IsDnsFailure(ex) ? NetworkFailureKind.Dns : NetworkFailureKind.Transient;

            if (statusCode == 403 || statusCode == 429)
                return NetworkFailureKind.Throttled;

            if (statusCode >= 500 && statusCode <= 599)
                return NetworkFailureKind.Transient;

            return NetworkFailureKind.Success;
        }

        /// <summary>
        /// Registra el resultado de un intento REAL de red y ajusta el backoff.
        /// Devuelve la clase de fallo detectada.
        /// </summary>
        public NetworkFailureKind Register(int statusCode, Exception ex)
        {
            var kind = Classify(statusCode, ex);

            lock (_lock)
            {
                if (kind == NetworkFailureKind.Success)
                {
                    _consecutiveFailures = 0;
                    _untilUtc = DateTime.MinValue;
                    _lastKind = kind;
                    return kind;
                }

                // Cambiar de clase arranca una escalada nueva: los intentos fallidos por
                // DNS no son evidencia de que el servidor nos esté frenando, ni al revés.
                if (kind != _lastKind)
                    _consecutiveFailures = 0;

                _lastKind = kind;
                _consecutiveFailures++;
                _untilUtc = _nowUtc().AddSeconds(DelayFor(kind, _consecutiveFailures));
                return kind;
            }
        }

        public void Reset()
        {
            lock (_lock)
            {
                _consecutiveFailures = 0;
                _untilUtc = DateTime.MinValue;
                _lastKind = NetworkFailureKind.Success;
            }
        }

        private double DelayFor(NetworkFailureKind kind, int failureNumber)
        {
            double baseSeconds, capSeconds;

            switch (kind)
            {
                case NetworkFailureKind.Dns:
                    baseSeconds = DnsBaseSeconds; capSeconds = DnsCapSeconds; break;
                case NetworkFailureKind.Throttled:
                    baseSeconds = ThrottledBaseSeconds; capSeconds = ThrottledCapSeconds; break;
                default:
                    baseSeconds = TransientBaseSeconds; capSeconds = TransientCapSeconds; break;
            }

            double seconds = Math.Min(capSeconds, baseSeconds * Math.Pow(2, failureNumber - 1));
            return Math.Min(capSeconds, seconds + seconds * JitterFraction * _rng());
        }

        private static bool IsDnsFailure(Exception ex)
        {
            for (var e = ex; e != null; e = e.InnerException)
            {
                if (e is SocketException se &&
                    (se.SocketErrorCode == SocketError.HostNotFound ||
                     se.SocketErrorCode == SocketError.NoData ||
                     se.SocketErrorCode == SocketError.TryAgain))
                    return true;
            }

            return false;
        }
    }
}
