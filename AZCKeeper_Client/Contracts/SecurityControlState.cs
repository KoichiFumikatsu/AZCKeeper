namespace AZCKeeper_Cliente.Contracts
{
    /// <summary>
    /// Estado observado de un control de seguridad en el equipo.
    ///
    /// Vive en Contracts/ y no en Security/ a proposito: lo consumen tanto el modulo
    /// Security (que lo produce) como Network/ApiClient (que lo envia). Si viviera en
    /// Security/, ApiClient tendria que importar Security y se crearia una dependencia
    /// entre modulos. Contracts/ no depende de nada.
    /// </summary>
    internal sealed class SecurityControlState
    {
        public bool Present { get; set; }
        public object Value { get; set; }
    }
}
