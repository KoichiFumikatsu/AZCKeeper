using ModularApp.Core;

namespace ModularApp.Modules.Xml
{
    public sealed class XmlModule : IAppModule
    {
        private AppCore _core;
        public string Name { get { return "Xml"; } }
        public bool Enabled { get { return _core == null ? true : _core.Config.Modules.XmlEnabled; } }
        public void Init(AppCore core) { _core = core; }
        public void Start() { if (_core != null) _core.Logger.Info("[Xml] Config XML lista."); }
        public void Stop() { }
        public void Dispose() { }
    }
}
