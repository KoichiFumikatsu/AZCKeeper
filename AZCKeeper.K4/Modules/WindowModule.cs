using AZCKeeper.K4.Contracts;

namespace AZCKeeper.K4.Modules;

/// <summary>
/// Seguimiento de ventanas: muestrea la ventana activa y arma episodios (proceso +
/// título + duración). Los bufferiza y los envía en lote. No conoce a otros módulos:
/// recibe el canal (IApiClient), el reloj y la ventana activa por constructor.
/// </summary>
public sealed class WindowModule : IKeeperModule, IFlushable
{
    public string Code => "windowTracking";

    private readonly IApiClient _api;
    private readonly IForegroundWindow _fg;
    private readonly IClock _clock;
    private readonly Action<string>? _log;
    private readonly object _lock = new();

    private System.Threading.Timer? _timer;
    private int _sampleSeconds = 5;
    private const int FlushThreshold = 20;
    private const double MinEpisodeSeconds = 1.0;

    private readonly List<EpisodeDto> _buffer = new();
    private string? _curProcess;
    private string? _curTitle;
    private DateTime _curStart;
    private volatile bool _running;

    public WindowModule(IApiClient api, IForegroundWindow fg, IClock clock, Action<string>? log = null)
    {
        _api = api; _fg = fg; _clock = clock; _log = log;
    }

    public bool IsRunning => _running;

    public void Configure(ModuleSettings settings) => _sampleSeconds = Math.Max(2, settings.GetInt("sampleSeconds", 5));

    public void Start()
    {
        lock (_lock)
        {
            if (_running) return;
            _curProcess = null; _curTitle = null; _curStart = _clock.Now;
            _timer = new System.Threading.Timer(_ => Sample(), null, TimeSpan.FromSeconds(_sampleSeconds), TimeSpan.FromSeconds(_sampleSeconds));
            _running = true;
        }
    }

    public void Stop()
    {
        List<EpisodeDto>? toFlush = null;
        lock (_lock)
        {
            if (!_running) return;
            _timer?.Dispose(); _timer = null;
            CloseCurrent(_clock.Now);
            if (_buffer.Count > 0) { toFlush = new List<EpisodeDto>(_buffer); _buffer.Clear(); }
            _running = false;
        }
        if (toFlush != null) FireFlush(toFlush);
    }

    private void Sample()
    {
        try
        {
            var proc = _fg.ProcessName;
            if (proc is null) return; // no se pudo leer; no romper el episodio en curso
            var title = _fg.Title;
            List<EpisodeDto>? toFlush = null;

            lock (_lock)
            {
                if (_curProcess is null)
                {
                    _curProcess = proc; _curTitle = title; _curStart = _clock.Now;
                }
                else if (!string.Equals(proc, _curProcess, StringComparison.Ordinal) ||
                         !string.Equals(title, _curTitle, StringComparison.Ordinal))
                {
                    CloseCurrent(_clock.Now);
                    _curProcess = proc; _curTitle = title; _curStart = _clock.Now;
                }
                if (_buffer.Count >= FlushThreshold) { toFlush = new List<EpisodeDto>(_buffer); _buffer.Clear(); }
            }

            if (toFlush != null) FireFlush(toFlush);
        }
        catch (Exception ex) { _log?.Invoke($"windowTracking sample: {ex.Message}"); }
    }

    // Debe llamarse bajo _lock.
    private void CloseCurrent(DateTime end)
    {
        if (_curProcess is null) return;
        var dur = (end - _curStart).TotalSeconds;
        if (dur >= MinEpisodeSeconds)
        {
            _buffer.Add(new EpisodeDto(
                _curStart.ToString("yyyy-MM-dd HH:mm:ss"),
                end.ToString("yyyy-MM-dd HH:mm:ss"),
                (int)Math.Round(dur), _curProcess, _curTitle, IsCallApp(_curProcess, _curTitle)));
        }
        _curProcess = null; _curTitle = null;
    }

    private static bool IsCallApp(string proc, string? title) => CallDetection.IsCallApp(proc, title);

    private async void FireFlush(List<EpisodeDto> episodes)
    {
        try { await _api.SendEpisodesAsync(episodes); }
        catch (Exception ex) { _log?.Invoke($"windowTracking flush: {ex.Message}"); }
    }

    /// <summary>
    /// Cierre gracioso: para el timer, cierra el episodio en curso, y ESPERA a enviar lo
    /// bufferizado. Deja _running=false, así un Stop() posterior no re-envía. Nunca lanza.
    /// </summary>
    public async Task FlushAsync()
    {
        List<EpisodeDto> toFlush;
        lock (_lock)
        {
            _timer?.Dispose(); _timer = null;
            CloseCurrent(_clock.Now);
            toFlush = _buffer.Count > 0 ? new List<EpisodeDto>(_buffer) : new List<EpisodeDto>();
            _buffer.Clear();
            _running = false;
        }
        if (toFlush.Count > 0)
        {
            try { await _api.SendEpisodesAsync(toFlush); }
            catch (Exception ex) { _log?.Invoke($"windowTracking flushAsync: {ex.Message}"); }
        }
    }
}
