namespace AZCKeeper_Cliente.Tracking
{
    /// <summary>
    /// Implementa un hook global de teclado (si es necesario).
    /// 
    /// Responsabilidades:
    /// - Recibir eventos globales de tecla presionada/soltada.
    /// - Notificar actividad (sin registrar el contenido de las teclas).
    /// - Integrarse con KeyBlocker para bloquear ciertas combinaciones
    ///   si la configuración lo indica.
    /// 
    /// Importante:
    /// - Este módulo NO debe comportarse como un keylogger;
    ///   solo debe usar la información necesaria para tracking/bloqueo.
    /// </summary>
    internal class KeyboardHook
    {
        /// <summary>
        /// Inicia el hook global de teclado.
        /// </summary>
        public void Start()
        {
            // TODO: Registrar el hook global de teclado con Win32.
        }

        /// <summary>
        /// Detiene el hook global de teclado.
        /// </summary>
        public void Stop()
        {
            // TODO: Liberar el hook global de teclado.
        }
    }
}
