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
        private static void Limpiar()
        {
            LocalLogger.ResetReportStateForTests();
            // Nivel por defecto para los tests que no lo fijan explícitamente.
            LocalLogger.ConfigureLevels(LocalLogger.LogLevel.Info, null, false, false);
        }

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
            // Patrones distintos (sin dígitos) para no chocar con el cooldown.
            for (int i = 0; i < 10; i++) LocalLogger.Warn($"ApiClient: item-{Alpha(i)}");

            Assert.Equal(4, LocalLogger.DrainForReport(4).Count);
            Assert.Equal(6, LocalLogger.PendingReportCount);
        }

        // Genera sufijos alfabéticos únicos (a, b, ... z, aa, ab, ...) que sobreviven a la
        // normalización del cooldown (que solo quita dígitos).
        private static string Alpha(int n)
        {
            var s = "";
            for (n++; n > 0; n = (n - 1) / 26) s = (char)('a' + (n - 1) % 26) + s;
            return s;
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
            // Patrones distintos: prueba el tope de la cola, no el cooldown.
            for (int i = 0; i < 500; i++) LocalLogger.Warn($"ApiClient: flood-{Alpha(i)}");

            Assert.True(LocalLogger.PendingReportCount <= 200);
        }

        [Fact]
        public void Los_ecos_de_consecuencia_no_se_reportan_pero_la_raiz_si()
        {
            // Una caída de red produce la raíz + eco en cada capa. Al panel solo va la raíz.
            Limpiar();

            LocalLogger.Warn("ApiClient: fallo de red #1 (clase=Transient, status=0). Backoff 30s");
            LocalLogger.Warn("OfflineQueue: payload encolado. Endpoint=client/window-episode");
            LocalLogger.Warn("ApiClient: retry falló para item 3 (client/window-episode). Se reintentará.");
            LocalLogger.Warn("CoreService.FlushWindowEpisodeBufferAsync(): batch falló, 5 episodios encolados.");
            LocalLogger.Warn("ApiClient.SendActivityDayAsync(): red caída. Encolando...");

            var batch = LocalLogger.DrainForReport(50);

            Assert.Single(batch);
            Assert.Contains("fallo de red", batch[0].Message);
        }

        [Fact]
        public void Un_patron_repetido_se_reporta_una_sola_vez_en_la_ventana()
        {
            // La tormenta de reintentos ("fallo de red #1, #2, #3...") colapsa a una fila:
            // los números se normalizan y el cooldown bloquea las repeticiones.
            Limpiar();

            for (int i = 1; i <= 20; i++)
                LocalLogger.Warn($"ApiClient: fallo de red #{i} (clase=Transient, status=0). Backoff {i*30}s");

            Assert.Single(LocalLogger.DrainForReport(50));
        }

        [Fact]
        public void Patrones_distintos_no_se_deduplican()
        {
            Limpiar();

            LocalLogger.Warn("AuthManager: token vencido");
            LocalLogger.Warn("CoreService: horario laboral no aplicado");

            Assert.Equal(2, LocalLogger.DrainForReport(50).Count);
        }

        [Fact]
        public void Los_eventos_deliberados_de_update_no_se_deduplican_por_version()
        {
            // ReportEvent (update) NO pasa por el cooldown: dos versiones distintas normalizan
            // al mismo patrón, pero son eventos deliberados y ambos deben verse.
            Limpiar();

            LocalLogger.ReportEvent(LocalLogger.LogLevel.Info, "update", "UpdateManager: al día en 3.0.2.8");
            LocalLogger.ReportEvent(LocalLogger.LogLevel.Info, "update", "UpdateManager: al día en 3.0.3.0");

            Assert.Equal(2, LocalLogger.DrainForReport(50).Count);
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
