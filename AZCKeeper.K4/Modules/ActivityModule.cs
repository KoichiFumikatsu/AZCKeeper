using AZCKeeper.K4.Contracts;

namespace AZCKeeper.K4.Modules;

/// <summary>
/// Seguimiento de actividad: acumula segundos activos vs inactivos del día y los envía
/// como resumen. Manda EL TOTAL del día (el servidor asigna, no acumula — el diseño sin
/// GREATEST). Las banderas de cobertura windowTracked/callTracked las consulta al core
/// vía una función inyectada (isModuleRunning), sin conocer esos módulos directamente.
/// </summary>
public sealed class ActivityModule : IKeeperModule, IFlushable
{
    public string Code => "activityTracking";

    private readonly IApiClient _api;
    private readonly IIdleMonitor _idle;
    private readonly IClock _clock;
    private readonly Func<string, bool> _isModuleRunning;
    private readonly Func<DateTime, int>? _callSecondsForDay;   // segundos en llamada, del CallTrackingModule
    private readonly Func<WorkSchedule>? _scheduleProvider;     // horario laboral, del ultimo handshake
    private readonly Action<string>? _log;
    private readonly object _lock = new();

    private System.Threading.Timer? _timer;
    private int _sampleSeconds = 15;
    private int _idleThreshold = 60;
    private int _sendEverySeconds = 300;

    private DateTime _day;
    private int _active, _idleAcc;
    private int _workActive, _workIdle, _lunchActive, _lunchIdle, _afterActive, _afterIdle;
    private DateTime? _firstEvent, _lastEvent;
    private DateTime _lastSend;
    private volatile bool _running;

    public ActivityModule(IApiClient api, IIdleMonitor idle, IClock clock, Func<string, bool> isModuleRunning,
        Action<string>? log = null, Func<DateTime, int>? callSecondsForDay = null, Func<WorkSchedule>? scheduleProvider = null)
    {
        _api = api; _idle = idle; _clock = clock; _isModuleRunning = isModuleRunning; _log = log;
        _callSecondsForDay = callSecondsForDay; _scheduleProvider = scheduleProvider;
    }

    private void ResetCounters()
    {
        _active = 0; _idleAcc = 0;
        _workActive = 0; _workIdle = 0; _lunchActive = 0; _lunchIdle = 0; _afterActive = 0; _afterIdle = 0;
        _firstEvent = null; _lastEvent = null;
    }

    public bool IsRunning => _running;

    public void Configure(ModuleSettings s)
    {
        _sampleSeconds  = Math.Max(5, s.GetInt("sampleSeconds", 15));
        _idleThreshold  = Math.Max(30, s.GetInt("idleThresholdSeconds", 60));
        _sendEverySeconds = Math.Max(60, s.GetInt("sendIntervalSeconds", 300));
    }

    public void Start()
    {
        lock (_lock)
        {
            if (_running) return;
            _day = _clock.Now.Date; ResetCounters(); _lastSend = _clock.Now;
            _timer = new System.Threading.Timer(_ => Tick(), null, TimeSpan.FromSeconds(_sampleSeconds), TimeSpan.FromSeconds(_sampleSeconds));
            _running = true;
        }
    }

    public void Stop()
    {
        ActivityDayDto? finalDay = null;
        lock (_lock)
        {
            if (!_running) return;
            _timer?.Dispose(); _timer = null;
            finalDay = BuildDay();
            _running = false;
        }
        if (finalDay != null) FireSend(finalDay);
    }

    private void Tick()
    {
        try
        {
            ActivityDayDto? toSend = null;
            lock (_lock)
            {
                var now = _clock.Now;

                // Rollover de día: cerrar el anterior y reiniciar.
                if (now.Date != _day)
                {
                    var prev = BuildDay();
                    _day = now.Date; ResetCounters(); _lastSend = now;
                    // enviar el cierre del dia anterior fuera del lock
                    FireSend(prev);
                }

                bool userActive = _idle.IdleSeconds < _idleThreshold;
                var band = (_scheduleProvider?.Invoke() ?? WorkSchedule.Default).Classify(now);
                if (userActive)
                {
                    _active += _sampleSeconds;
                    switch (band) { case WorkSchedule.Band.Work: _workActive += _sampleSeconds; break; case WorkSchedule.Band.Lunch: _lunchActive += _sampleSeconds; break; default: _afterActive += _sampleSeconds; break; }
                    _firstEvent ??= now;
                    _lastEvent = now;
                }
                else
                {
                    _idleAcc += _sampleSeconds;
                    switch (band) { case WorkSchedule.Band.Work: _workIdle += _sampleSeconds; break; case WorkSchedule.Band.Lunch: _lunchIdle += _sampleSeconds; break; default: _afterIdle += _sampleSeconds; break; }
                }

                if ((now - _lastSend).TotalSeconds >= _sendEverySeconds)
                {
                    toSend = BuildDay();
                    _lastSend = now;
                }
            }
            if (toSend != null) FireSend(toSend);
        }
        catch (Exception ex) { _log?.Invoke($"activityTracking tick: {ex.Message}"); }
    }

    // Debe llamarse bajo _lock. windowTracked/callTracked se consultan al core.
    private ActivityDayDto BuildDay()
    {
        bool window = SafeRunning("windowTracking");
        bool call = SafeRunning("callTracking");
        int callSecs = call && _callSecondsForDay != null ? SafeCallSeconds(_day) : 0;
        var sched = _scheduleProvider?.Invoke() ?? WorkSchedule.Default;
        return new ActivityDayDto(
            DayDate: _day.ToString("yyyy-MM-dd"),
            TzOffsetMinutes: (int)TimeZoneInfo.Local.GetUtcOffset(_day).TotalMinutes, // Colombia = -300
            IsWorkday: sched.IsWorkday(_day),
            ActivityTracked: true,
            WindowTracked: window,
            CallTracked: call,
            ActiveSeconds: _active,
            IdleSeconds: _idleAcc,
            CallSeconds: callSecs,
            WorkActiveSeconds: _workActive,
            WorkIdleSeconds: _workIdle,
            LunchActiveSeconds: _lunchActive,
            LunchIdleSeconds: _lunchIdle,
            AfterHoursActiveSeconds: _afterActive,
            AfterHoursIdleSeconds: _afterIdle,
            FirstEventAt: _firstEvent?.ToString("yyyy-MM-dd HH:mm:ss"),
            LastEventAt: _lastEvent?.ToString("yyyy-MM-dd HH:mm:ss"));
    }

    private bool SafeRunning(string code)
    {
        try { return _isModuleRunning(code); } catch { return false; }
    }

    private int SafeCallSeconds(DateTime day)
    {
        try { return _callSecondsForDay?.Invoke(day) ?? 0; } catch { return 0; }
    }

    private async void FireSend(ActivityDayDto day)
    {
        try { await _api.SendActivityDayAsync(day); }
        catch (Exception ex) { _log?.Invoke($"activityTracking send: {ex.Message}"); }
    }

    /// <summary>
    /// Cierre gracioso: para el timer, arma el resumen final del día y ESPERA a enviarlo.
    /// Deja _running=false para que un Stop() posterior no re-envíe. Nunca lanza.
    /// </summary>
    public async Task FlushAsync()
    {
        ActivityDayDto? finalDay;
        lock (_lock)
        {
            if (!_running) { finalDay = null; }
            else
            {
                _timer?.Dispose(); _timer = null;
                finalDay = BuildDay();
                _running = false;
            }
        }
        if (finalDay != null)
        {
            try { await _api.SendActivityDayAsync(finalDay); }
            catch (Exception ex) { _log?.Invoke($"activityTracking flushAsync: {ex.Message}"); }
        }
    }
}
