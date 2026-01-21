using System;
using System.Runtime.InteropServices;
using AZCKeeper_Cliente.Core;
using AZCKeeper_Cliente.Logging;

namespace AZCKeeper_Cliente.Tracking
{
    /// <summary>
    /// ActivityTracker mide tiempo ACTIVO vs INACTIVO del usuario.
    ///
    /// Diseño clave:
    /// - NO asume que cada tick del timer es exacto.
    /// - Calcula el tiempo real transcurrido usando delta entre timestamps UTC.
    /// - Clasifica cada delta como activo/inactivo según:
    ///   - idleSeconds (GetLastInputInfo) vs _inactivityThresholdSeconds
    /// - Controla cambio de día local:
    ///   - si el delta cruza medianoche, divide el intervalo en dos (día viejo y nuevo).
    /// - Protege contra deltas absurdos:
    ///   - suspensión/hibernación/cambios de hora → ignora deltas muy grandes.
    ///
    /// Extensión de negocio:
    /// - ActivityOverridePredicate:
    ///   - Permite considerar ACTIVO aunque no haya input (ej: está en llamada).
    /// - ActivityOverrideMaxIdleSeconds:
    ///   - Límite de seguridad para evitar falsos positivos si el usuario se fue (AWAY)
    ///     dejando una llamada abierta.
    ///
    /// Integración:
    /// - OnDayClosed(day, activeSeconds, inactiveSeconds):
    ///   callback para enviar resumen a API cuando se cierra el día.
    ///
    /// NUEVO:
    /// - SeedDayTotals(day, active, inactive):
    ///   permite retomar acumulados del día desde backend/BD.
    /// </summary>
    internal class ActivityTracker
    {
        // -------------------- Concurrencia --------------------
        private readonly object _lock = new object();

        // -------------------- Seed / retomar día desde backend --------------------
        private bool _hasSeedForToday;
        private double _seedDayActiveSeconds;
        private double _seedDayInactiveSeconds;
        private DateTime _seedDayLocalDate;

        private double _seedDayWorkActiveSeconds;
        private double _seedDayWorkIdleSeconds;
        private double _seedDayLunchActiveSeconds;
        private double _seedDayLunchIdleSeconds;
        private double _seedDayAfterHoursActiveSeconds;
        private double _seedDayAfterHoursIdleSeconds;

        // -------------------- Configuración base --------------------
        private readonly double _intervalSeconds;
        private readonly double _inactivityThresholdSeconds;

        // -------------------- Timer interno --------------------
        private System.Timers.Timer _timer;

        // Categorización de tiempo
        private double _currentDayWorkActiveSeconds;
        private double _currentDayWorkIdleSeconds;
        private double _currentDayLunchActiveSeconds;
        private double _currentDayLunchIdleSeconds;
        private double _currentDayAfterHoursActiveSeconds;
        private double _currentDayAfterHoursIdleSeconds;

        // -------------------- Estado interno --------------------
        private DateTime _lastSampleUtc;
        private DateTime _currentDayLocalDate;
        private double _currentDayActiveSeconds;
        private double _currentDayInactiveSeconds;
        private double _sessionActiveSeconds;
        private double _sessionInactiveSeconds;
        private DateTime _startLocalTime;

        internal WorkSchedule WorkSchedule { get; set; } = new WorkSchedule();

        // -------------------- Exposición de métricas --------------------
        internal DateTime StartLocalTime { get { lock (_lock) return _startLocalTime; } }
        internal DateTime CurrentDayLocalDate { get { lock (_lock) return _currentDayLocalDate; } }
        internal double CurrentDayActiveSeconds { get { lock (_lock) return _currentDayActiveSeconds; } }
        internal double CurrentDayInactiveSeconds { get { lock (_lock) return _currentDayInactiveSeconds; } }
        internal double SessionActiveSeconds { get { lock (_lock) return _sessionActiveSeconds; } }
        internal double SessionInactiveSeconds { get { lock (_lock) return _sessionInactiveSeconds; } }

        // Propiedades públicas para acceso desde CoreService
        internal double CurrentDayWorkActiveSeconds { get { lock (_lock) return _currentDayWorkActiveSeconds; } }
        internal double CurrentDayWorkIdleSeconds { get { lock (_lock) return _currentDayWorkIdleSeconds; } }
        internal double CurrentDayLunchActiveSeconds { get { lock (_lock) return _currentDayLunchActiveSeconds; } }
        internal double CurrentDayLunchIdleSeconds { get { lock (_lock) return _currentDayLunchIdleSeconds; } }
        internal double CurrentDayAfterHoursActiveSeconds { get { lock (_lock) return _currentDayAfterHoursActiveSeconds; } }
        internal double CurrentDayAfterHoursIdleSeconds { get { lock (_lock) return _currentDayAfterHoursIdleSeconds; } }

        // -------------------- Integración: callback de cierre de día --------------------
        public Action<DateTime, double, double> OnDayClosed { get; set; }

        // -------------------- Override de actividad (ej: llamada) --------------------
        public Func<bool> ActivityOverridePredicate { get; set; }
        public double ActivityOverrideMaxIdleSeconds { get; set; } = 300.0;

        public ActivityTracker(double intervalSeconds, double inactivityThresholdSeconds = 15.0)
        {
            _intervalSeconds = intervalSeconds;
            _inactivityThresholdSeconds = inactivityThresholdSeconds;
        }

        /// <summary>
        /// Permite retomar el acumulado del día desde backend/BD.
        /// - Ideal: llamar antes de Start().
        /// - Si se llama después de Start(), igual aplica (si es el mismo día).
        /// </summary>
        public void SeedDayTotals(DateTime dayLocalDate, double activeSeconds, double inactiveSeconds,
            double workActive = 0, double workIdle = 0,
            double lunchActive = 0, double lunchIdle = 0,
            double afterActive = 0, double afterIdle = 0)
        {
            if (activeSeconds < 0) activeSeconds = 0;
            if (inactiveSeconds < 0) inactiveSeconds = 0;

            lock (_lock)
            {
                _seedDayLocalDate = dayLocalDate.Date;
                _seedDayActiveSeconds = activeSeconds;
                _seedDayInactiveSeconds = inactiveSeconds;
                _seedDayWorkActiveSeconds = workActive;
                _seedDayWorkIdleSeconds = workIdle;
                _seedDayLunchActiveSeconds = lunchActive;
                _seedDayLunchIdleSeconds = lunchIdle;
                _seedDayAfterHoursActiveSeconds = afterActive;
                _seedDayAfterHoursIdleSeconds = afterIdle;
                _hasSeedForToday = true;

                // Si el tracker ya está en marcha y el día coincide, aplicar inmediatamente.
                if (_timer != null && _currentDayLocalDate == _seedDayLocalDate)
                {
                    _currentDayActiveSeconds = Math.Max(_currentDayActiveSeconds, _seedDayActiveSeconds);
                    _currentDayInactiveSeconds = Math.Max(_currentDayInactiveSeconds, _seedDayInactiveSeconds);

                    _currentDayWorkActiveSeconds = Math.Max(_currentDayWorkActiveSeconds, workActive);
                    _currentDayWorkIdleSeconds = Math.Max(_currentDayWorkIdleSeconds, workIdle);
                    _currentDayLunchActiveSeconds = Math.Max(_currentDayLunchActiveSeconds, lunchActive);
                    _currentDayLunchIdleSeconds = Math.Max(_currentDayLunchIdleSeconds, lunchIdle);
                    _currentDayAfterHoursActiveSeconds = Math.Max(_currentDayAfterHoursActiveSeconds, afterActive);
                    _currentDayAfterHoursIdleSeconds = Math.Max(_currentDayAfterHoursIdleSeconds, afterIdle);

                    LocalLogger.Info($"ActivityTracker.SeedDayTotals(): seed aplicado en caliente. Day={_seedDayLocalDate:yyyy-MM-dd} Active={_currentDayActiveSeconds:F0}s Inactive={_currentDayInactiveSeconds:F0}s");
                }
                else
                {
                    LocalLogger.Info($"ActivityTracker.SeedDayTotals(): seed preparado para {_seedDayLocalDate:yyyy-MM-dd}. Active={activeSeconds:F0}s Inactive={inactiveSeconds:F0}s");
                }
            }
        }

        public void Start()
        {
            lock (_lock)
            {
                if (_timer != null)
                    return;

                LocalLogger.Info($"ActivityTracker.Start(): iniciando tracking. Interval={_intervalSeconds:F3}s, Threshold={_inactivityThresholdSeconds:F3}s.");

                _currentDayLocalDate = DateTime.Now.Date;

                // Reset base
                _currentDayActiveSeconds = 0;
                _currentDayInactiveSeconds = 0;

                // Aplicar seed si corresponde al mismo día
                if (_hasSeedForToday && _seedDayLocalDate == _currentDayLocalDate)
                {
                    _currentDayActiveSeconds = Math.Max(_currentDayActiveSeconds, _seedDayActiveSeconds);
                    _currentDayInactiveSeconds = Math.Max(_currentDayInactiveSeconds, _seedDayInactiveSeconds);
                    _currentDayWorkActiveSeconds = Math.Max(_currentDayWorkActiveSeconds, _seedDayWorkActiveSeconds);
                    _currentDayWorkIdleSeconds = Math.Max(_currentDayWorkIdleSeconds, _seedDayWorkIdleSeconds);
                    _currentDayLunchActiveSeconds = Math.Max(_currentDayLunchActiveSeconds, _seedDayLunchActiveSeconds);
                    _currentDayLunchIdleSeconds = Math.Max(_currentDayLunchIdleSeconds, _seedDayLunchIdleSeconds);
                    _currentDayAfterHoursActiveSeconds = Math.Max(_currentDayAfterHoursActiveSeconds, _seedDayAfterHoursActiveSeconds);
                    _currentDayAfterHoursIdleSeconds = Math.Max(_currentDayAfterHoursIdleSeconds, _seedDayAfterHoursIdleSeconds);

                    LocalLogger.Info($"ActivityTracker.Start(): seed aplicado. Active={_currentDayActiveSeconds:F0}s Inactive={_currentDayInactiveSeconds:F0}s " +
                    $"Work={_currentDayWorkActiveSeconds:F0}s Lunch={_currentDayLunchActiveSeconds:F0}s After={_currentDayAfterHoursActiveSeconds:F0}s");
                }
                else if (_hasSeedForToday)
                {
                    LocalLogger.Warn($"ActivityTracker.Start(): seed ignorado. SeedDay={_seedDayLocalDate:yyyy-MM-dd}, Today={_currentDayLocalDate:yyyy-MM-dd}");
                }

                _sessionActiveSeconds = 0;
                _sessionInactiveSeconds = 0;

                _lastSampleUtc = TimeSync.UtcNow;
                _startLocalTime = TimeSync.Now;


                _timer = new System.Timers.Timer(_intervalSeconds * 1000.0);
                _timer.AutoReset = true;
                _timer.Elapsed += Timer_Elapsed;
                _timer.Start();
            }
        }

        public void Stop()
        {
            lock (_lock)
            {
                if (_timer == null)
                    return;

                LocalLogger.Info("ActivityTracker.Stop(): deteniendo tracking.");

                _timer.Stop();
                _timer.Elapsed -= Timer_Elapsed;
                _timer.Dispose();
                _timer = null;
            }
        }

        private void Timer_Elapsed(object sender, System.Timers.ElapsedEventArgs e)
        {
            try
            {
                DateTime nowUtc = TimeSync.UtcNow;
                DateTime nowLocal = nowUtc.ToLocalTime();

                DateTime lastSampleUtc;
                lock (_lock) lastSampleUtc = _lastSampleUtc;

                double deltaSeconds = (nowUtc - lastSampleUtc).TotalSeconds;
                if (deltaSeconds <= 0)
                {
                    lock (_lock) _lastSampleUtc = nowUtc;
                    return;
                }

                const double MaxReasonableDeltaSeconds = 6 * 60 * 60; // 6 horas
                if (deltaSeconds > MaxReasonableDeltaSeconds)
                {
                    DateTime lastLocalForDelta = lastSampleUtc.ToLocalTime();
                    DateTime lastDateForDelta = lastLocalForDelta.Date;
                    DateTime nowDateForDelta = nowLocal.Date;

                    LocalLogger.Warn($"ActivityTracker.Timer_Elapsed(): deltaSeconds atípico ({deltaSeconds:F3}s). Se ignora para acumulado. lastUtc={lastSampleUtc:O}, nowUtc={nowUtc:O}");

                    lock (_lock) _lastSampleUtc = nowUtc;

                    if (lastDateForDelta != nowDateForDelta)
                    {
                        CloseCurrentDay(lastDateForDelta);

                        lock (_lock)
                        {
                            _currentDayLocalDate = nowDateForDelta;
                            _currentDayActiveSeconds = 0;
                            _currentDayInactiveSeconds = 0;

                            // Si hay seed para el nuevo día (raro), aplicar
                            if (_hasSeedForToday && _seedDayLocalDate == _currentDayLocalDate)
                            {
                                _currentDayActiveSeconds = Math.Max(_currentDayActiveSeconds, _seedDayActiveSeconds);
                                _currentDayInactiveSeconds = Math.Max(_currentDayInactiveSeconds, _seedDayInactiveSeconds);
                            }
                        }
                    }

                    return;
                }

                double idleSeconds = GetIdleSeconds();
                bool isActive = idleSeconds < _inactivityThresholdSeconds;

                if (!isActive && ActivityOverridePredicate != null)
                {
                    bool overrideActive = false;
                    try { overrideActive = ActivityOverridePredicate(); } catch { overrideActive = false; }

                    if (overrideActive && idleSeconds < ActivityOverrideMaxIdleSeconds)
                        isActive = true;
                }

                DateTime lastLocal = lastSampleUtc.ToLocalTime();
                DateTime lastDate = lastLocal.Date;
                DateTime nowDate = nowLocal.Date;

                if (lastDate == nowDate)
                {
                    AccumulateInterval(lastDate, deltaSeconds, isActive);
                }
                else
                {
                    SplitIntervalAcrossDayBoundary(lastLocal, nowLocal, deltaSeconds, isActive);
                }

                lock (_lock) _lastSampleUtc = nowUtc;
            }
            catch (Exception ex)
            {
                LocalLogger.Error(ex, "ActivityTracker.Timer_Elapsed(): error durante el cálculo de actividad.");
            }
        }
        public (DateTime DayLocalDate, double ActiveSeconds, double InactiveSeconds,
                double WorkActive, double WorkIdle,
                double LunchActive, double LunchIdle,
                double AfterActive, double AfterIdle) GetCurrentDaySnapshot()
        {
            lock (_lock)
            {
                return (
                    _currentDayLocalDate,
                    _currentDayActiveSeconds,
                    _currentDayInactiveSeconds,
                    _currentDayWorkActiveSeconds,
                    _currentDayWorkIdleSeconds,
                    _currentDayLunchActiveSeconds,
                    _currentDayLunchIdleSeconds,
                    _currentDayAfterHoursActiveSeconds,
                    _currentDayAfterHoursIdleSeconds
                );
            }
        }

        private void AccumulateInterval(DateTime dayLocalDate, double deltaSeconds, bool isActive)
        {
            if (dayLocalDate != CurrentDayLocalDate)
            {
                CloseCurrentDay(CurrentDayLocalDate);
                lock (_lock)
                {
                    _currentDayLocalDate = dayLocalDate;
                    ResetDayCounters();
                }
            }

            // Determinar categoría de tiempo
            TimeCategory category = WorkSchedule.GetTimeCategory(DateTime.Now);

            lock (_lock)
            {
                if (isActive)
                {
                    _currentDayActiveSeconds += deltaSeconds;
                    _sessionActiveSeconds += deltaSeconds;

                    switch (category)
                    {
                        case TimeCategory.WorkHours:
                            _currentDayWorkActiveSeconds += deltaSeconds;
                            break;
                        case TimeCategory.LunchTime:
                            _currentDayLunchActiveSeconds += deltaSeconds;
                            break;
                        case TimeCategory.AfterHours:
                            _currentDayAfterHoursActiveSeconds += deltaSeconds;
                            break;
                    }
                }
                else
                {
                    _currentDayInactiveSeconds += deltaSeconds;
                    _sessionInactiveSeconds += deltaSeconds;

                    switch (category)
                    {
                        case TimeCategory.WorkHours:
                            _currentDayWorkIdleSeconds += deltaSeconds;
                            break;
                        case TimeCategory.LunchTime:
                            _currentDayLunchIdleSeconds += deltaSeconds;
                            break;
                        case TimeCategory.AfterHours:
                            _currentDayAfterHoursIdleSeconds += deltaSeconds;
                            break;
                    }
                }
            }
        }
        private void ResetDayCounters()
        {
            _currentDayActiveSeconds = 0;
            _currentDayInactiveSeconds = 0;
            _currentDayWorkActiveSeconds = 0;
            _currentDayWorkIdleSeconds = 0;
            _currentDayLunchActiveSeconds = 0;
            _currentDayLunchIdleSeconds = 0;
            _currentDayAfterHoursActiveSeconds = 0;
            _currentDayAfterHoursIdleSeconds = 0;
        }
        private void SplitIntervalAcrossDayBoundary(DateTime lastLocal, DateTime nowLocal, double totalDeltaSeconds, bool isActive)
        {
            DateTime oldDate = lastLocal.Date;
            DateTime newDate = nowLocal.Date;
            DateTime midnightNewDay = newDate;

            double oldPartSeconds = (midnightNewDay - lastLocal).TotalSeconds;
            if (oldPartSeconds < 0) oldPartSeconds = 0;

            double newPartSeconds = totalDeltaSeconds - oldPartSeconds;
            if (newPartSeconds < 0) newPartSeconds = 0;

            if (oldPartSeconds > 0)
                AccumulateInterval(oldDate, oldPartSeconds, isActive);

            CloseCurrentDay(oldDate);

            lock (_lock)
            {
                _currentDayLocalDate = newDate;
                _currentDayActiveSeconds = 0;
                _currentDayInactiveSeconds = 0;

                if (_hasSeedForToday && _seedDayLocalDate == _currentDayLocalDate)
                {
                    _currentDayActiveSeconds = Math.Max(_currentDayActiveSeconds, _seedDayActiveSeconds);
                    _currentDayInactiveSeconds = Math.Max(_currentDayInactiveSeconds, _seedDayInactiveSeconds);
                }
            }

            if (newPartSeconds > 0)
                AccumulateInterval(newDate, newPartSeconds, isActive);
        }

        private void CloseCurrentDay(DateTime dayLocalDate)
        {
            double active;
            double inactive;

            lock (_lock)
            {
                // El cierre siempre reporta lo acumulado "actual" del tracker
                active = _currentDayActiveSeconds;
                inactive = _currentDayInactiveSeconds;
            }

            try
            {
                LocalLogger.Info($"ActivityTracker.CloseCurrentDay(): día {dayLocalDate:yyyy-MM-dd} cerrado. Activo={active:F3}s, Inactivo={inactive:F3}s.");
                OnDayClosed?.Invoke(dayLocalDate, active, inactive);
            }
            catch (Exception ex)
            {
                LocalLogger.Error(ex, "ActivityTracker.CloseCurrentDay(): error al ejecutar OnDayClosed.");
            }
        }

        #region Idle time (GetLastInputInfo)

        [StructLayout(LayoutKind.Sequential)]
        private struct LASTINPUTINFO
        {
            public uint cbSize;
            public uint dwTime;
        }

        [DllImport("user32.dll")]
        private static extern bool GetLastInputInfo(ref LASTINPUTINFO plii);

        private static double GetIdleSeconds()
        {
            try
            {
                var lastInputInfo = new LASTINPUTINFO
                {
                    cbSize = (uint)Marshal.SizeOf(typeof(LASTINPUTINFO))
                };

                if (!GetLastInputInfo(ref lastInputInfo))
                    return 0;

                long lastInputTick = lastInputInfo.dwTime;
                long currentTick = Environment.TickCount;

                long diff = currentTick - lastInputTick;
                if (diff < 0)
                    return 0;

                return diff / 1000.0;
            }
            catch
            {
                return 0;
            }
        }

        #endregion
    }
}
