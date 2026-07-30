namespace AZCKeeper.K4.Contracts;

/// <summary>Reloj inyectable, para que los módulos sean testeables sin depender de la hora real.</summary>
public interface IClock
{
    DateTime Now { get; }
    DateTime UtcNow { get; }
}

public sealed class SystemClock : IClock
{
    public DateTime Now => DateTime.Now;
    public DateTime UtcNow => DateTime.UtcNow;
}

/// <summary>Ventana en primer plano. La implementación Windows usa user32; los tests usan un fake.</summary>
public interface IForegroundWindow
{
    /// <summary>Proceso de la ventana activa (ej. "chrome.exe"), o null si no se puede leer.</summary>
    string? ProcessName { get; }
    /// <summary>Título de la ventana activa, o null.</summary>
    string? Title { get; }
}

/// <summary>Segundos de inactividad del usuario (sin teclado/ratón).</summary>
public interface IIdleMonitor
{
    int IdleSeconds { get; }
}

/// <summary>Captura de pantalla. La implementación Windows usa Graphics.CopyFromScreen.</summary>
public interface IScreenCapturer
{
    CaptureResult? Capture();
}

public sealed record CaptureResult(byte[] Bytes, int Width, int Height);

/// <summary>
/// Sube el blob de una captura a object storage y devuelve su object_key. La BD solo
/// guarda metadata (decisión 2026-06-09). La implementación real (Nextcloud/MinIO) es
/// infra pendiente; hasta entonces, un stub que devuelve la key calculada sin subir.
/// </summary>
public interface IBlobStore
{
    /// <summary>Devuelve (objectKey, subido). Si subido=false, la metadata igual se reporta.</summary>
    Task<(string ObjectKey, bool Uploaded)> PutAsync(string suggestedKey, byte[] bytes);
}
