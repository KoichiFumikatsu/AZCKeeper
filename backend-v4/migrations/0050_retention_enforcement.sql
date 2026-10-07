-- Retención aplicada (decisión del dueño 2026-10-07): el trabajo nocturno config/retention-cron.php borra lo que quede
-- fuera de los plazos de retention_settings, pero solo en los clientes con enforce = TRUE. Por defecto queda APAGADO
-- (piloto): la revisión nocturna solo cuenta lo que se borraría y lo deja en retention_runs.
--
-- 1) retention_settings.enforce: interruptor por cliente (FALSE = solo revisión).
-- 2) retention_runs: una fila por cliente y revisión (modo, plazos usados, conteos por tipo de dato, duración, error).
-- 3) client_logs (tenant_id, at): índice para borrar por fecha dentro de un cliente sin recorrer todos sus equipos.
--
-- Repetible: CREATE TABLE IF NOT EXISTS y DDL condicional por information_schema. MySQL 8.4 y MariaDB 11.4.

SET @sql = IF(EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE()
    AND table_name = 'retention_settings' AND column_name = 'enforce'),
  'DO 0',
  'ALTER TABLE retention_settings ADD COLUMN enforce BOOLEAN NOT NULL DEFAULT FALSE');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS retention_runs (
  tenant_id BINARY(16) NOT NULL,
  id BINARY(16) NOT NULL,
  run_date DATE NOT NULL,
  started_at DATETIME(6) NOT NULL,
  finished_at DATETIME(6) NULL,
  mode ENUM('dry_run','applied') NOT NULL,
  raw_before DATE NOT NULL,
  aggregate_before DATE NOT NULL,
  logs_before DATETIME(6) NOT NULL,
  raw_rows BIGINT UNSIGNED NOT NULL DEFAULT 0,
  raw_days INT UNSIGNED NOT NULL DEFAULT 0,
  aggregate_rows BIGINT UNSIGNED NOT NULL DEFAULT 0,
  aggregate_days INT UNSIGNED NOT NULL DEFAULT 0,
  log_rows BIGINT UNSIGNED NOT NULL DEFAULT 0,
  table_rows JSON NULL,
  complete BOOLEAN NOT NULL DEFAULT FALSE,
  duration_ms INT UNSIGNED NOT NULL DEFAULT 0,
  error VARCHAR(200) NULL,
  PRIMARY KEY (tenant_id, id),
  KEY retention_runs_latest (tenant_id, started_at),
  CONSTRAINT retention_runs_tenant_fk FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

SET @sql = IF(EXISTS (SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE()
    AND table_name = 'client_logs' AND index_name = 'client_logs_tenant_at'),
  'DO 0',
  'ALTER TABLE client_logs ADD INDEX client_logs_tenant_at (tenant_id, at)');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
