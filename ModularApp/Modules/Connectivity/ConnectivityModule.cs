using System;
using System.Net.Http;
using System.Threading.Tasks;
using System.Windows.Forms;
using ModularApp.Core;

namespace ModularApp.Modules.Connectivity
{
    public interface IConnectivityService
    {
        bool IsOnline { get; }
        event Action<bool> ConnectivityChanged; // true=online, false=offline
        void ForceCheck();
    }

    public sealed class ConnectivityModule : IAppModule, IConnectivityService
    {
        private AppCore _core;
        private System.Windows.Forms.Timer _timer;
        private static readonly HttpClient _http = new HttpClient();
        private bool _isOnline;
        private int _intervalMs = 10000; // 10s

        public string Name { get { return "Connectivity"; } }
        public bool Enabled { get { return true; } }

        public bool IsOnline { get { return _isOnline; } }
        public event Action<bool> ConnectivityChanged;

        public void Init(AppCore core)
        {
            _core = core;
            _http.Timeout = TimeSpan.FromSeconds(2);
        }

        public void Start()
        {
            _timer = new System.Windows.Forms.Timer();
            _timer.Interval = _intervalMs;
            _timer.Tick += async (s, e) => await CheckAsync();
            _timer.Start();
            // primer check inmediato
            var _ = CheckAsync();
        }

        public void Stop() { if (_timer != null) _timer.Stop(); }
        public void Dispose() { if (_timer != null) _timer.Dispose(); }

        public void ForceCheck()
        {
            var _ = CheckAsync();
        }

        private async Task CheckAsync()
        {
            bool online = await ProbeAsync();
            if (online != _isOnline)
            {
                _isOnline = online;
                if (_core != null) _core.Logger.Info("[Connectivity] " + (online ? "Online" : "Offline"));
                var h = ConnectivityChanged; if (h != null) h(_isOnline);
            }
        }

        // HEAD/GET a un endpoint 204. Evita páginas cautivas
        private async Task<bool> ProbeAsync()
        {
            try
            {
                using (var req = new HttpRequestMessage(HttpMethod.Get, "https://clients3.google.com/generate_204"))
                {
                    var resp = await _http.SendAsync(req);
                    try { return ((int)resp.StatusCode) == 204; }
                    finally { resp.Dispose(); }
                }
            }
            catch { return false; }
        }
    }
}
