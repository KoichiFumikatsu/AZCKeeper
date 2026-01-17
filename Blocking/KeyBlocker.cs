namespace AZCKeeper_Cliente.Blocking
{
    /// <summary>
    /// Módulo de bloqueo de teclas o combinaciones específicas.
    /// 
    /// Responsabilidades:
    /// - Integrarse con KeyboardHook para interceptar ciertas combinaciones.
    /// - Decidir si una combinación debe bloquearse según configuración.
    /// - Aplicar restricciones para evitar acciones no deseadas (según
    ///   el modelo de negocio y dentro de las limitaciones técnicas de Windows).
    /// </summary>
    internal class KeyBlocker
    {
        /// <summary>
        /// Activa la lógica de bloqueo (por ejemplo, habilitando una lista
        /// de combinaciones a bloquear).
        /// </summary>
        public void Enable()
        {
            // TODO: Activar reglas de bloqueo.
        }

        /// <summary>
        /// Desactiva la lógica de bloqueo.
        /// </summary>
        public void Disable()
        {
            // TODO: Desactivar reglas de bloqueo.
        }

        /// <summary>
        /// Método que podría ser llamado por KeyboardHook al detectar
        /// una combinación de teclas, para decidir si debe bloquearse.
        /// </summary>
        /// <param name="keyCombination">Representación de la combinación detectada.</param>
        /// <returns>true si debe bloquearse; false en caso contrario.</returns>
        public bool ShouldBlock(string keyCombination)
        {
            // TODO: Evaluar reglas de bloqueo.
            return false;
        }
    }
}
