namespace AZCKeeper_Cliente.Tracking
{
    /// <summary>
    /// Implementa un hook global de mouse (si es necesario).
    /// 
    /// Responsabilidades:
    /// - Recibir eventos globales de movimiento/clic del mouse.
    /// - Notificar actividad para complementar el tracking.
    /// </summary>
    internal class MouseHook
    {
        /// <summary>
        /// Inicia el hook global de mouse.
        /// </summary>
        public void Start()
        {
            // TODO: Registrar el hook global de mouse con Win32.
        }

        /// <summary>
        /// Detiene el hook global de mouse.
        /// </summary>
        public void Stop()
        {
            // TODO: Liberar el hook global de mouse.
        }
    }
}
