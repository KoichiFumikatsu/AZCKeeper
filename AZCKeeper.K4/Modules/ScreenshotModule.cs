using System.Security.Cryptography;
using AZCKeeper.K4.Contracts;

namespace AZCKeeper.K4.Modules;

/// <summary>
/// Módulo de capturas de pantalla periódicas. Cada ciclo captura la pantalla vía
/// IScreenCapturer, sube el blob vía IBlobStore (hoy un stub: infra de object storage
/// pendiente) y reporta la metadata (hash, tamaño, object_key) vía IApiClient. No conoce
/// a ningún otro módulo, solo el contrato y lo recibido por constructor.
/// </summary>
public sealed class ScreenshotModule : IKeeperModule
{
    public string Code => "screenshots";

    private readonly IApiClient _api;
    private readonly IScreenCapturer _capturer;
    private readonly IBlobStore _blobStore;
    private readonly IClock _clock;
    private readonly Action<string>? _log;

    private readonly object _gate = new();
    private System.Threading.Timer? _timer;
    private int _intervalSeconds = 300;
    private volatile bool _running;
    private int _capturing; // 0/1, guarda contra solapamiento de ciclos

    public ScreenshotModule(IApiClient api, IScreenCapturer capturer, IBlobStore blobStore, IClock clock, Action<string>? log = null)
    {
        _api = api;
        _capturer = capturer;
        _blobStore = blobStore;
        _clock = clock;
        _log = log;
    }

    public bool IsRunning => _running;

    public void Configure(ModuleSettings settings)
    {
        _intervalSeconds = settings.GetInt("intervalSeconds", 300);
    }

    public void Start()
    {
        lock (_gate)
        {
            if (_running) return; // idempotente

            var period = TimeSpan.FromSeconds(Math.Max(1, _intervalSeconds));
            _timer = new System.Threading.Timer(OnTick, null, period, period);
            _running = true;
        }
    }

    public void Stop()
    {
        lock (_gate)
        {
            if (!_running) return; // idempotente
            _timer?.Dispose();
            _timer = null;
            _running = false;
        }
    }

    /// <summary>Callback del Timer: void por contrato, así que el ciclo async se aísla
    /// completo en try/catch para que ninguna excepción se escape sin capturar y mate
    /// el timer o el proceso.</summary>
    private void OnTick(object? state)
    {
        // Evita solapar ciclos si una captura/subida tarda más que el intervalo.
        if (Interlocked.CompareExchange(ref _capturing, 1, 0) != 0) return;

        _ = Task.Run(async () =>
        {
            try
            {
                await CaptureAndReportAsync();
            }
            catch (Exception ex)
            {
                _log?.Invoke($"screenshotModule: error en ciclo de captura: {ex.Message}");
            }
            finally
            {
                Interlocked.Exchange(ref _capturing, 0);
            }
        });
    }

    private async Task CaptureAndReportAsync()
    {
        var capture = _capturer.Capture();
        if (capture is null) return; // ej. sesión sin escritorio; no hay nada que reportar

        var bytes = capture.Bytes;
        var sha256 = ComputeSha256Hex(bytes);

        var utcNow = _clock.UtcNow;
        var unixMillis = new DateTimeOffset(utcNow, TimeSpan.Zero).ToUnixTimeMilliseconds();
        var suggestedKey = $"ss/{utcNow:yyyy}/{utcNow:MM}/{utcNow:dd}/{unixMillis}.png";

        var (objectKey, _) = await _blobStore.PutAsync(suggestedKey, bytes);

        var capturedAt = _clock.Now.ToString("yyyy-MM-dd HH:mm:ss");
        var meta = new ScreenshotMetaDto(capturedAt, objectKey, sha256, bytes.Length, "scheduled", null);
        await _api.SendScreenshotMetaAsync(meta);
    }

    private static string ComputeSha256Hex(byte[] bytes)
    {
        var hash = SHA256.HashData(bytes);
        // Convert.ToHexStringLower llegó en .NET 9; este proyecto es net8.0, así que
        // se pasa a minúsculas explícitamente para no depender de esa API.
        return Convert.ToHexString(hash).ToLowerInvariant();
    }
}
