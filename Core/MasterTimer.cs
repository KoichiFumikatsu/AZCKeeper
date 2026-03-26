using System;
using AZCKeeper_Cliente.Logging;

namespace AZCKeeper_Cliente.Core
{
    internal class MasterTimer
    {
        private System.Timers.Timer _timer;
        private long _tickCount = 0;

        public event Action OnActivityTick;     // cada 1s
        public event Action OnWindowTick;       // cada 2s
        public event Action OnFlushTick;        // cada 6s
        public event Action OnRetryTick;        // cada 30s

        public void Start()
        {
            _timer = new System.Timers.Timer(1000); // Base: 1s
            _timer.AutoReset = true;
            _timer.Elapsed += (s, e) =>
            {
                _tickCount++;

                OnActivityTick?.Invoke();                    // cada 1s
                if (_tickCount % 2 == 0) OnWindowTick?.Invoke();   // cada 2s
                if (_tickCount % 6 == 0) OnFlushTick?.Invoke();    // cada 6s
                if (_tickCount % 30 == 0) OnRetryTick?.Invoke();   // cada 30s
            };
            _timer.Start();
            LocalLogger.Info("MasterTimer: iniciado (1s base).");
        }

        public void Stop()
        {
            _timer?.Stop();
            _timer?.Dispose();
            _timer = null;
        }
    }
}