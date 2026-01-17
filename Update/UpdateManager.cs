namespace AZCKeeper_Cliente.Update
{
    /// <summary>
    /// Módulo responsable de gestionar actualizaciones del cliente.
    /// 
    /// Responsabilidades:
    /// - Consultar periódicamente a la API si existe una nueva versión.
    /// - Comparar la versión actual con la versión remota.
    /// - En caso de actualización disponible, descargar y/o invocar
    ///   un mecanismo de actualización externa (según se defina).
    /// </summary>
    internal class UpdateManager
    {
        /// <summary>
        /// Inicia el ciclo periódico de verificación de actualizaciones.
        /// </summary>
        public void Start()
        {
            // TODO: Implementar verificación periódica de nuevas versiones.
        }

        /// <summary>
        /// Detiene la verificación de actualizaciones.
        /// </summary>
        public void Stop()
        {
            // TODO: Detener cualquier timer o tarea asociada a update.
        }
    }
}
