using System;
using AZCKeeper_Cliente.Logging;

namespace AZCKeeper_Cliente.Core
{
    /// <summary>
    /// Sincroniza tiempo del cliente con el servidor para timestamps precisos.
    /// Calcula offset basado en serverTimeUtc recibido en handshake.
    ///
    /// Comunicación:
    /// - CoreService llama UpdateFromServer() al recibir handshake.
    /// - ActivityTracker usa TimeSync.UtcNow/Now para muestreo consistente.
    /// </summary>
    internal static class TimeSync
    {
        private static double _offsetSeconds = 0;
        private static bool _isSynced = false;

        /// <summary>
        /// Actualiza offset basado en tiempo del servidor.
        /// </summary>
        public static void UpdateFromServer(string serverTimeUtcIso)
        {
            try
            {
                if (string.IsNullOrWhiteSpace(serverTimeUtcIso)) return;

                var serverTime = DateTime.Parse(serverTimeUtcIso, null, System.Globalization.DateTimeStyles.RoundtripKind);
                
                // Validar que el tiempo del servidor sea realmente UTC
                if (serverTime.Kind != DateTimeKind.Utc && serverTime.Kind != DateTimeKind.Unspecified)
                {
                    LocalLogger.Warn($"TimeSync: serverTime tiene Kind={serverTime.Kind}, esperado UTC. Convirtiendo...");
                    serverTime = serverTime.ToUniversalTime();
                }
                else if (serverTime.Kind == DateTimeKind.Unspecified)
                {
                    // Asumir UTC si no tiene Kind especificado (común en ISO strings)
                    serverTime = DateTime.SpecifyKind(serverTime, DateTimeKind.Utc);
                }

                var clientTime = DateTime.UtcNow;

                _offsetSeconds = (clientTime - serverTime).TotalSeconds;
                
                // Validar que el offset no sea absurdo (más de 1 hora indica problema)
                if (Math.Abs(_offsetSeconds) > 3600)
                {
                    LocalLogger.Warn($"TimeSync: ⚠️ Offset sospechoso detectado ({_offsetSeconds:F0}s = {_offsetSeconds/60:F1}min). " +
                        $"ServerTime={serverTime:O}, ClientTime={clientTime:O}. Posible diferencia de zona horaria o reloj descalibrado.");
                }
                
                _isSynced = true;

                LocalLogger.Info($"TimeSync: sincronizado. Offset={_offsetSeconds:F2}s (cliente {(_offsetSeconds > 0 ? "adelantado" : "atrasado")}). " +
                    $"ServerUTC={serverTime:yyyy-MM-dd HH:mm:ss}, ClientUTC={clientTime:yyyy-MM-dd HH:mm:ss}");
            }
            catch (Exception ex)
            {
                LocalLogger.Error(ex, "TimeSync.UpdateFromServer(): error al parsear serverTimeUtc.");
            }
        }

        /// <summary>
        /// Retorna tiempo UTC ajustado al servidor.
        /// </summary>
        public static DateTime UtcNow => DateTime.UtcNow.AddSeconds(-_offsetSeconds);

        /// <summary>
        /// Retorna tiempo local ajustado al servidor.
        /// </summary>
        public static DateTime Now => UtcNow.ToLocalTime();

        /// <summary>
        /// Indica si ya se recibió un tiempo válido del servidor.
        /// </summary>
        public static bool IsSynced => _isSynced;
    }
}