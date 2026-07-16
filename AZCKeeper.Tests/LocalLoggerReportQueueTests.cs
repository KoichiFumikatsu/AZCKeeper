using System.Linq;
using AZCKeeper_Cliente.Logging;
using Xunit;

namespace AZCKeeper.Tests
{
    /// <summary>
    /// Cola de reporte a keeper_client_log. A diferencia del anillo de la ventana Debug,
    /// esta cola solo suelta una línea cuando el servidor confirma que la recibió.
    /// </summary>
    [Collection("LocalLogger")]
    public class LocalLoggerReportQueueTests
    {
        private static void Limpiar() => LocalLogger.DrainForReport(int.MaxValue);

        [Fact]
        public void Warn_y_error_se_encolan_para_reporte()
        {
            Limpiar();

            LocalLogger.Warn("ApiClient: fallo-de-red-reportable");
            LocalLogger.Error("UpdateManager: fallo-de-update-reportable");

            var batch = LocalLogger.DrainForReport(50);

            Assert.Contains(batch, e => e.Message.Contains("fallo-de-red-reportable") && e.Level == "warn");
            Assert.Contains(batch, e => e.Message.Contains("fallo-de-update-reportable") && e.Level == "error");
        }

        [Fact]
        public void Deduce_el_source_desde_el_prefijo_del_mensaje()
        {
            Limpiar();

            LocalLogger.Warn("ApiClient: x");
            LocalLogger.Warn("UpdateManager: x");
            LocalLogger.Warn("WebBlockingManager: x");
            LocalLogger.Warn("AuthManager: x");
            LocalLogger.Warn("CoreService: x");
            LocalLogger.Warn("AlgoDesconocido: x");

            var sources = LocalLogger.DrainForReport(50).Select(e => e.Source).ToList();

            Assert.Contains("network", sources);
            Assert.Contains("update", sources);
            Assert.Contains("blocking", sources);
            Assert.Contains("auth", sources);
            Assert.Contains("core", sources);
            Assert.Contains("other", sources);
        }

        [Fact]
        public void ReportEvent_encola_info_aunque_el_nivel_filtre_la_salida_a_archivo()
        {
            // La flota corre en nivel Warn: sin esto, los eventos de update (Info) no dejan
            // rastro y un equipo que nunca actualiza es indiagnosticable.
            Limpiar();
            LocalLogger.ConfigureLevels(LocalLogger.LogLevel.Warn, null, false, false);

            LocalLogger.ReportEvent(LocalLogger.LogLevel.Info, "update", "UpdateManager: chequeo-info-reportable");

            var batch = LocalLogger.DrainForReport(50);
            Assert.Contains(batch, e => e.Message.Contains("chequeo-info-reportable")
                                     && e.Level == "info" && e.Source == "update");
        }

        [Fact]
        public void ReportEvent_no_se_salta_el_filtro_de_nivel_para_el_archivo()
        {
            // El reporte al servidor ignora el nivel a propósito; la escritura a disco NO.
            // Detectado en vivo el 2026-07-16: la línea Info del chequeo de update aparecía
            // en el log de un equipo con el nivel en Warn, mientras el resto de Info sí se
            // filtraba. La flota corre en Warn justamente para no llenar disco.
            Limpiar();

            string ruta = System.IO.Path.Combine(
                System.Environment.GetFolderPath(System.Environment.SpecialFolder.ApplicationData),
                "AZCKeeper", "Logs", $"{System.DateTime.Now:yyyy-MM-dd}.log");

            try
            {
                LocalLogger.ConfigureLevels(LocalLogger.LogLevel.Warn, null, enableFileLogging: true, false);

                long antes = System.IO.File.Exists(ruta) ? new System.IO.FileInfo(ruta).Length : 0;

                LocalLogger.ReportEvent(LocalLogger.LogLevel.Info, "update", "marcador-info-no-debe-tocar-disco");

                long despues = System.IO.File.Exists(ruta) ? new System.IO.FileInfo(ruta).Length : 0;

                Assert.Equal(antes, despues);
                Assert.Contains(LocalLogger.DrainForReport(50),
                    e => e.Message.Contains("marcador-info-no-debe-tocar-disco"));
            }
            finally
            {
                LocalLogger.ConfigureLevels(LocalLogger.LogLevel.Warn, null, enableFileLogging: false, false);
            }
        }

        [Fact]
        public void Drenar_vacia_la_cola()
        {
            Limpiar();
            LocalLogger.Warn("ApiClient: linea-unica");

            Assert.Single(LocalLogger.DrainForReport(50));
            Assert.Empty(LocalLogger.DrainForReport(50));
        }

        [Fact]
        public void Drenar_respeta_el_maximo()
        {
            Limpiar();
            for (int i = 0; i < 10; i++) LocalLogger.Warn($"ApiClient: linea-{i}");

            Assert.Equal(4, LocalLogger.DrainForReport(4).Count);
            Assert.Equal(6, LocalLogger.PendingReportCount);
        }

        [Fact]
        public void Requeue_devuelve_las_lineas_que_no_se_pudieron_enviar()
        {
            Limpiar();
            LocalLogger.Warn("ApiClient: linea-que-fallo");

            var batch = LocalLogger.DrainForReport(50);
            Assert.Empty(LocalLogger.DrainForReport(50));

            LocalLogger.RequeueForReport(batch);

            Assert.Contains(LocalLogger.DrainForReport(50), e => e.Message.Contains("linea-que-fallo"));
        }

        [Fact]
        public void La_cola_esta_acotada()
        {
            Limpiar();
            for (int i = 0; i < 500; i++) LocalLogger.Warn($"ApiClient: flood-{i}");

            Assert.True(LocalLogger.PendingReportCount <= 200);
        }

        [Fact]
        public void SuppressReporting_corta_el_lazo_de_realimentacion()
        {
            // Sin esto, el Warn que emite el propio envío de logs se encola para el
            // siguiente envío, que vuelve a fallar, que vuelve a encolar...
            Limpiar();

            using (LocalLogger.SuppressReporting())
            {
                LocalLogger.Warn("ApiClient: fallo-al-reportar-logs");
            }

            Assert.Empty(LocalLogger.DrainForReport(50));
        }

        [Fact]
        public void SuppressReporting_se_revierte_al_salir_del_scope()
        {
            Limpiar();

            using (LocalLogger.SuppressReporting()) { LocalLogger.Warn("ApiClient: suprimido"); }
            LocalLogger.Warn("ApiClient: ya-no-suprimido");

            var batch = LocalLogger.DrainForReport(50);
            Assert.DoesNotContain(batch, e => e.Message.Contains("ApiClient: suprimido"));
            Assert.Contains(batch, e => e.Message.Contains("ya-no-suprimido"));
        }

        [Fact]
        public void Los_secretos_se_redactan_antes_de_encolar()
        {
            Limpiar();

            LocalLogger.Warn("ApiClient: fallo con Authorization: Bearer abc123XYZtoken");

            var entry = LocalLogger.DrainForReport(50).Single(e => e.Message.Contains("fallo con Authorization"));
            Assert.DoesNotContain("abc123XYZtoken", entry.Message);
            Assert.Contains("REDACTED", entry.Message);
        }
    }
}
