using System;
using System.Collections.Generic;
using ModularApp.Modules.Connectivity;
using ModularApp.Modules.Database;
using ModularApp.Modules.Login;

namespace ModularApp.Core
{
    public sealed class ModuleManager
    {
        private readonly List<IAppModule> _mods = new List<IAppModule>();
        private readonly AppCore _core;
        public ModuleManager(AppCore core) { _core = core; }

        public void Register(IAppModule m)
        {
            try
            {
                m.Init(_core);
                if (m.Enabled)
                {
                    // registra el módulo
                    _mods.Add(m);
                    _core.Logger.Info("[ModuleManager] Registrado " + m.Name);

                    // expone servicios
                    var db = m as IDatabaseService;
                    if (db != null) _core.RegisterService<IDatabaseService>(db);

                    var login = m as ILoginService;
                    if (login != null) _core.RegisterService<ILoginService>(login);

                    var conn = m as IConnectivityService;
                    if (conn != null) _core.RegisterService<IConnectivityService>(conn);

                }
                else
                {
                    m.Dispose();
                }
            }
            catch (Exception ex)
            {
                _core.Logger.Error("[ModuleManager] Error registrando " + m.Name + ": " + ex.Message);
            }
        }

        public void StartAll()
        {
            foreach (var m in _mods)
            {
                try { m.Start(); }
                catch (Exception ex) { _core.Logger.Error("[ModuleManager] Error al iniciar " + m.Name + ": " + ex.Message); }
            }
        }

        public void StopAll()
        {
            for (int i = _mods.Count - 1; i >= 0; i--)
            {
                var m = _mods[i];
                try { m.Stop(); } catch { }
                try { m.Dispose(); } catch { }
            }
            _mods.Clear();
        }
    }
}
