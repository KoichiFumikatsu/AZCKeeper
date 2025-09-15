using ModularApp.Core;

namespace ModularApp.Modules.Logs
{
    public sealed class LogsModule : IAppModule
    {
        private AppCore _core;
        public string Name { get { return "Logs"; } }
        public bool Enabled { get { return _core == null ? true : _core.Config.Modules.LogsEnabled; } }

        public void Init(AppCore core) { _core = core; }
        public void Start() { if (_core != null) _core.Logger.Info("[Logs] Logger inicializado"); }
        public void Stop() { }
        public void Dispose() { }
    }
}
