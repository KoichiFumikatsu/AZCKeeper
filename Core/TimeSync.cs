using System;
using AZCKeeper_Cliente.Logging;

namespace AZCKeeper_Cliente.Core
{
    /// <summary>
    /// Sincroniza tiempo del cliente con el servidor para timestamps precisos.
    /// Calcula offset basado en serverTimeUtc recibido en handshake.
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
                var clientTime = DateTime.UtcNow;

                _offsetSeconds = (clientTime - serverTime).TotalSeconds;
                _isSynced = true;

                LocalLogger.Info($"TimeSync: sincronizado. Offset={_offsetSeconds:F2}s (cliente {(_offsetSeconds > 0 ? "adelantado" : "atrasado")})");
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

        public static bool IsSynced => _isSynced;
    }
}