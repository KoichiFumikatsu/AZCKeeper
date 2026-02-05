namespace AZCKeeper_Cliente.Tracking
{
    /// <summary>
    /// Hook global de mouse - NO IMPLEMENTADO.
    /// 
    /// Nota: Esta funcionalidad NO es necesaria porque ActivityTracker
    /// ya detecta actividad de mouse usando GetLastInputInfo() de Win32,
    /// que es más eficiente y no requiere hooks globales.
    /// 
    /// Estado actual: Stub vacío que no hace nada si se activa.
    /// </summary>
    internal class MouseHook
    {
        /// <summary>
        /// Inicia el hook global de mouse.
        /// NO IMPLEMENTADO - ActivityTracker ya maneja detección de actividad.
        /// </summary>
        public void Start()
        {
            // NO IMPLEMENTADO: ActivityTracker usa GetLastInputInfo() para detectar
            // actividad de mouse sin necesidad de hooks globales.
            AZCKeeper_Cliente.Logging.LocalLogger.Info("MouseHook.Start(): NO IMPLEMENTADO. ActivityTracker ya detecta actividad de mouse vía GetLastInputInfo().");
        }

        /// <summary>
        /// Detiene el hook global de mouse.
        /// </summary>
        public void Stop()
        {
            // No hay nada que detener
        }
    }
}
