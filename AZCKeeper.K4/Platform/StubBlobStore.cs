using AZCKeeper.K4.Contracts;

namespace AZCKeeper.K4.Platform;

/// <summary>
/// Implementación temporal de IBlobStore mientras el object storage real es infra
/// pendiente (decisión 2026-06-09: la BD solo guarda metadata). No sube nada: solo
/// calcula/devuelve la key sugerida para que el módulo pueda reportar metadata igual.
/// </summary>
public sealed class StubBlobStore : IBlobStore
{
    public Task<(string ObjectKey, bool Uploaded)> PutAsync(string suggestedKey, byte[] bytes)
    {
        // TODO infra: subir a object storage (Nextcloud cloud.azclegal.com); hoy solo calcula la key
        return Task.FromResult((suggestedKey, false));
    }
}
