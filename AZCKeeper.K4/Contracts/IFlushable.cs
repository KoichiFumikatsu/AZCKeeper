namespace AZCKeeper.K4.Contracts;

/// <summary>
/// Un módulo que bufferea datos y necesita vaciarlos con AWAIT real antes de que el
/// proceso muera. Es opcional: solo lo implementan los módulos con buffer (ventanas,
/// actividad). El cierre gracioso del cliente llama FlushAsync y lo espera, en vez del
/// flush async-void fire-and-forget que se perdía al salir el proceso.
/// </summary>
public interface IFlushable
{
    /// <summary>Drena lo pendiente y ESPERA a que se envíe. Idempotente. No debe lanzar.</summary>
    Task FlushAsync();
}
