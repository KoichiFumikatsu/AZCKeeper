namespace AZCKeeper_Cliente.Tracking
{
    /// <summary>
    /// Hook global de teclado - NO IMPLEMENTADO.
    /// 
    /// Nota: Esta funcionalidad NO es necesaria porque ActivityTracker
    /// ya detecta actividad de teclado usando GetLastInputInfo() de Win32,
    /// que es más eficiente y no requiere hooks globales.
    /// 
    /// Los hooks solo serían necesarios para:
    /// - KeyBlocker (bloqueo de combinaciones específicas)
    /// - Keylogging (NO recomendado por privacidad)
    /// 
    /// Estado actual: Stub vacío que no hace nada si se activa.
    /// </summary>
    internal class KeyboardHook
    {
        /// <summary>
        /// Inicia el hook global de teclado.
        /// NO IMPLEMENTADO - ActivityTracker ya maneja detección de actividad.
        /// </summary>
        public void Start()
        {
            // NO IMPLEMENTADO: ActivityTracker usa GetLastInputInfo() para detectar
            // actividad de teclado sin necesidad de hooks globales.
            AZCKeeper_Cliente.Logging.LocalLogger.Info("KeyboardHook.Start(): NO IMPLEMENTADO. ActivityTracker ya detecta actividad de teclado vía GetLastInputInfo().");
        }

        /// <summary>
        /// Detiene el hook global de teclado.
        /// </summary>
        public void Stop()
        {
            // No hay nada que detener
        }
    }
}
