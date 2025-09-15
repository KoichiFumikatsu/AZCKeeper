using System;
using System.Threading;
using System.Windows.Forms;
using ModularApp.Core;
using ModularApp.Modules.Starter;
using ModularApp.Modules.Database;
using ModularApp.Modules.Logs;
using ModularApp.Modules.Login;
using ModularApp.Modules.Xml;
using ModularApp.Modules.Updater;
using ModularApp.Modules.TimerTrack;
using ModularApp.Modules.WindowsTrack;
using ModularApp.UI;
using ModularApp.Modules.Connectivity;
using ModularApp.Modules.Geo;
using AZCKEEPER.ModularApp.Modules.MalwareTrack;

namespace ModularApp
{
    internal static class Program
    {
        private static Mutex _mutex;

        [STAThread]
        static void Main()
        {
            // Fijar el directorio de trabajo al de la app
            try { System.IO.Directory.SetCurrentDirectory(AppContext.BaseDirectory); }
            catch {  }

            Application.EnableVisualStyles();
            Application.SetCompatibleTextRenderingDefault(false);

            bool createdNew;
            _mutex = new Mutex(true, "AZC_Keeper_SingleInstance", out createdNew);
            if (!createdNew) return;

            var config = AppConfig.Load("appsettings.xml");
            var logger = LoggerFactory.CreateFromConfig(config);
            var core = new AppCore(config, logger);

            var manager = new ModuleManager(core);
            manager.Register(new StarterModule());
            manager.Register(new LogsModule());
            manager.Register(new XmlModule());
            manager.Register(new DatabaseModule());
            manager.Register(new GeoModule());
            manager.Register(new LoginModule());
            manager.Register(new UpdaterModule());
            manager.Register(new TimerTrackModule());
            manager.Register(new WindowsTrackModule());
            manager.Register(new ConnectivityModule());

            //manager.Register(new MalwareTrackModule());

            var ctx = new TrayApplicationContext(core, manager);
            try
            {
                Application.Run(ctx);
            }
            finally
            {
                ctx.Dispose();
                manager.StopAll();
                logger.Dispose();
                if (_mutex != null) _mutex.ReleaseMutex();
            }
        }
    }
}
