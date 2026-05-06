using System;
using System.Threading;
using System.IO;
using System.Windows.Forms;
using AZCKeeper_Cliente.Core;
using AZCKeeper_Cliente.Logging;

namespace AZCKeeper_Cliente
{
    /// <summary>
    /// Punto de entrada principal de la aplicación AZC Keeper Cliente.
    /// 
    /// Responsabilidades:
    /// - Configurar el manejo global de excepciones.
    /// - Garantizar que sólo exista una instancia del cliente (Mutex).
    /// - Inicializar el núcleo (CoreService).f
    /// - Mantener un ApplicationContext "invisible" para que la app
    ///   corra en segundo plano sin ventanas principales.
    /// </summary>
    internal static class Program
    {
        /// <summary>
        /// Nombre del mutex para garantizar una sola instancia
        /// del proceso AZCKeeper_Cliente.
        /// </summary>
        private const string SingleInstanceMutexName = "AZCKeeper_Cliente_SingleInstance";

        /// <summary>
        /// Referencia estática al CoreService para acceso desde ProcessExit.
        /// </summary>
        private static CoreService _coreService;
        private static readonly string StartupTracePath =
            Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.ApplicationData), "AZCKeeper", "Logs", "startup-trace.log");

        /// <summary>
        /// Método Main: arranque de la aplicación Windows.
        /// </summary>
        [STAThread]
        private static void Main()
        {
            AppendStartupTrace("Main() entered.");
            // Configuramos manejadores globales de excepciones para registrar
            // cualquier error inesperado.
            AppDomain.CurrentDomain.UnhandledException += CurrentDomain_UnhandledException;
            Application.ThreadException += Application_ThreadException;
            // 🔥 NUEVO: Capturar cierre de aplicación (Ctrl+C, Task Manager, etc)
            AppDomain.CurrentDomain.ProcessExit += (s, e) =>
            {
                AppendStartupTrace("ProcessExit fired.");
                LocalLogger.Info("Program: ProcessExit detectado. Ejecutando flush final...");
                _coreService?.Stop(); // Llama Stop() si coreService fue creado
            };
            // Mutex para asegurar que sólo haya una instancia del cliente.
            using (var mutex = new Mutex(initiallyOwned: true, name: SingleInstanceMutexName, createdNew: out bool isNewInstance))
            {
                if (!isNewInstance)
                {
                    AppendStartupTrace("Second instance aborted by mutex.");
                    // Ya existe otra instancia: registramos y salimos.
                    LocalLogger.Warn("Se detectó una instancia previa de AZCKeeper_Cliente. Se aborta el arranque de la nueva instancia.");
                    return;
                }

                try
                {
                    AppendStartupTrace("Before Application setup.");
                    Application.EnableVisualStyles();
                    Application.SetCompatibleTextRenderingDefault(false);

                    // Núcleo de la aplicación.
                    _coreService = new CoreService();
                    AppendStartupTrace("CoreService created.");

                    // Secuencia de inicialización del núcleo (config, logger, API, módulos, etc.).
                    _coreService.Initialize();
                    AppendStartupTrace("CoreService.Initialize() returned.");

                    // Arranque de módulos (timers, trackers, etc.).
                    _coreService.Start();
                    AppendStartupTrace("CoreService.Start() returned.");

                    // ApplicationContext mínimo sin formularios principales visibles.
                    using (var context = new ApplicationContext())
                    {
                        AppendStartupTrace("Application.Run() starting.");
                        Application.Run(context);
                    }

                    // Al salir del loop de mensajes, detenemos ordenadamente los módulos.
                    AppendStartupTrace("Application.Run() exited.");
                    _coreService.Stop();
                }
                catch (Exception ex)
                {
                    AppendStartupTrace("Main() catch: " + ex.Message);
                    // Cualquier excepción no manejada en Main se registra aquí.
                    LocalLogger.Error(ex, "Error crítico durante el arranque/ejecución de la aplicación en Main().");
                }
            }
        }

        private static void AppendStartupTrace(string message)
        {
            try
            {
                Directory.CreateDirectory(Path.GetDirectoryName(StartupTracePath) ?? ".");
                File.AppendAllText(StartupTracePath, $"{DateTime.Now:yyyy-MM-dd HH:mm:ss.fff} {message}{Environment.NewLine}");
            }
            catch
            {
            }
        }

        /// <summary>
        /// Maneja excepciones no controladas en hilos de Windows Forms.
        /// </summary>
        private static void Application_ThreadException(object sender, System.Threading.ThreadExceptionEventArgs e)
        {
            LocalLogger.Error(e.Exception, "Excepción no controlada en hilo de Windows Forms (Application.ThreadException).");
        }

        /// <summary>
        /// Maneja excepciones no controladas que ocurren en el AppDomain.
        /// </summary>
        private static void CurrentDomain_UnhandledException(object sender, UnhandledExceptionEventArgs e)
        {
            if (e.ExceptionObject is Exception ex)
            {
                LocalLogger.Error(ex, "Excepción no controlada en AppDomain (CurrentDomain.UnhandledException).");
            }
            else
            {
                LocalLogger.Error("Se produjo una excepción no controlada en AppDomain, pero no se pudo castear a Exception.");
            }
        }
    }
}
