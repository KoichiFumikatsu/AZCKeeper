using AZCKeeper.K4.Contracts;

namespace AZCKeeper.K4.Modules;

/// <summary>
/// Seguimiento de llamadas: muestrea la ventana activa y acumula los SEGUNDOS en apps de
/// llamada por día. K4 antes solo marcaba un flag booleano por episodio y enviaba CallSeconds=0;
/// esto da el número real que alimenta la productividad (equivalente al CallSessionSeconds de K3).
///
/// Independiente por construcción: no conoce a ActivityModule. ActivityModule lee sus segundos
/// vía CallSecondsForDay(fecha), inyectado por el core — así el resumen del día correcto lleva
/// los segundos del día correcto (incluso en el cierre por rollover).
/// </summary>
public sealed class CallTrackingModule : IKeeperModule
{
    public string Code => "callTracking";

    private readonly IForegroundWindow _fg;
    private readonly IClock _clock;
    private readonly Action<string>? _log;
    private readonly object _lock = new();

    private System.Threading.Timer? _timer;
    private int _sampleSeconds = 15;
    private volatile bool _running;

    // Segundos en llamada por día (yyyy-MM-dd). Se poda para no crecer (solo se conservan ~3 días).
    private readonly Dictionary<string, int> _byDay = new();

    public CallTrackingModule(IForegroundWindow fg, IClock clock, Action<string>? log = null)
    {
        _fg = fg; _clock = clock; _log = log;
    }

    public bool IsRunning => _running;

    public void Configure(ModuleSettings s) => _sampleSeconds = Math.Max(5, s.GetInt("sampleSeconds", 15));

    public void Start()
    {
        lock (_lock)
        {
            if (_running) return;
            _timer = new System.Threading.Timer(_ => Sample(), null, TimeSpan.FromSeconds(_sampleSeconds), TimeSpan.FromSeconds(_sampleSeconds));
            _running = true;
        }
    }

    public void Stop()
    {
        lock (_lock)
        {
            if (!_running) return;
            _timer?.Dispose(); _timer = null;
            _running = false;
        }
    }

    /// <summary>Ejecuta una muestra. Publico para pruebas (el flujo normal es por timer).</summary>
    public void SampleForTest() => Sample();

    private void Sample()
    {
        try
        {
            string? proc = null, title = null;
            try { proc = _fg.ProcessName; title = _fg.Title; } catch { /* ventana ilegible */ }
            if (!CallDetection.IsCallApp(proc, title)) return;

            var key = _clock.Now.ToString("yyyy-MM-dd");
            lock (_lock)
            {
                _byDay[key] = (_byDay.TryGetValue(key, out var v) ? v : 0) + _sampleSeconds;
                if (_byDay.Count > 4)  // poda: conserva los más recientes
                {
                    foreach (var old in _byDay.Keys.OrderBy(k => k).Take(_byDay.Count - 3).ToList())
                        _byDay.Remove(old);
                }
            }
        }
        catch (Exception ex) { _log?.Invoke($"callTracking sample: {ex.Message}"); }
    }

    /// <summary>Segundos acumulados en llamada para una fecha (0 si no hay). Lo lee ActivityModule.</summary>
    public int CallSecondsForDay(DateTime day)
    {
        var key = day.ToString("yyyy-MM-dd");
        lock (_lock) { return _byDay.TryGetValue(key, out var v) ? v : 0; }
    }
}
