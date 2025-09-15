using System;
using System.IO;
using ModularApp.Core;

namespace ModularApp.Modules.Starter
{
    public sealed class StarterModule : IAppModule
    {
        private AppCore _core;
        public string Name => "Starter";
        public bool Enabled => _core == null ? true : _core.Config.Modules.StarterEnabled;
        public void Init(AppCore core) { _core = core; }

        public void Start()
        {
            Directory.CreateDirectory(Path.Combine(AppContext.BaseDirectory, "logs"));

            var cfg = _core.Config.General;
            var exe = System.Diagnostics.Process.GetCurrentProcess().MainModule.FileName;
            const string appName = "AZCKeeper";
            const string args = "--silent";

            if (cfg.FirstRun && cfg.AutoStart)
            {
                RegistryHelper.SetRunAtLogin(appName, exe, args);
                _core.Logger.Info("[Starter] Autorun registrado (primer arranque)");
                cfg.FirstRun = false;
                _core.Config.SaveGeneral();
            }

            if (cfg.AutoStart)
            {
                bool ok = RegistryHelper.EnsureRunAtLogin(appName, exe, args, out string reason);
                if (ok) _core.Logger.Info("[Starter] Autorun OK");
                else _core.Logger.Warn("[Starter] Autorun reparado: " + reason);
            }
            else
            {
                if (RegistryHelper.RemoveRunAtLogin(appName))
                    _core.Logger.Info("[Starter] Autorun eliminado por configuración");
            }
        }

        public void Stop() { }
        public void Dispose() { }
    }
}
