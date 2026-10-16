-- Notificaciones de la barra superior del panel (encargo 2026-10-15, docs/design/2026-10-15-encargo-notificaciones.md).
-- 1) Apps sospechosas tiene marca de revisada, igual que doble empleo (dual_job_alerts.reviewed). El recalculo diario
--    (ComplianceWorker) conserva las detecciones ya revisadas.
-- 2) Estado por cuenta del panel (principal admin de esa empresa), no por navegador: cuando abrio la lista por ultima vez
--    y que resumen del dia vio (llegadas tarde, ausencias), mas un resumen del dia cacheado unos minutos para que el
--    contador de cada pagina no recalcule la puntualidad de todo el personal.
-- Repetible: DDL condicional por information_schema y CREATE TABLE IF NOT EXISTS. MySQL 8.4 y MariaDB 11.4.

SET @sql = IF(EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE()
    AND table_name = 'suspicious_app_detections' AND column_name = 'reviewed'),
  'DO 0',
  'ALTER TABLE suspicious_app_detections ADD COLUMN reviewed BOOLEAN NOT NULL DEFAULT FALSE');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = IF(EXISTS (SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE()
    AND table_name = 'suspicious_app_detections' AND index_name = 'suspicious_detections_pending'),
  'DO 0',
  'ALTER TABLE suspicious_app_detections ADD INDEX suspicious_detections_pending (tenant_id, reviewed, day)');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = IF(EXISTS (SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE()
    AND table_name = 'dual_job_alerts' AND index_name = 'dual_job_alerts_pending'),
  'DO 0',
  'ALTER TABLE dual_job_alerts ADD INDEX dual_job_alerts_pending (tenant_id, reviewed, day)');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS panel_notification_state (
  tenant_id BINARY(16) NOT NULL,
  principal_id BINARY(16) NOT NULL,
  seen_at DATETIME(6) NULL,
  seen_day DATE NULL,
  seen_late INT UNSIGNED NOT NULL DEFAULT 0,
  seen_absent INT UNSIGNED NOT NULL DEFAULT 0,
  digest_day DATE NULL,
  digest_late INT UNSIGNED NULL,
  digest_revision BIGINT UNSIGNED NULL,
  digest_at DATETIME(6) NULL,
  PRIMARY KEY (tenant_id, principal_id),
  FOREIGN KEY (tenant_id, principal_id) REFERENCES principals (tenant_id, id) ON DELETE CASCADE,
  FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
