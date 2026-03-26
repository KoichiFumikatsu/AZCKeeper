-- ============================================================
-- Migration: keeper_data_sources
-- Registra fuentes de datos externas (legacy DBs, CSV, API)
-- por firma, permitiendo multi-tenancy.
-- ============================================================

CREATE TABLE IF NOT EXISTS keeper_data_sources (
  id              BIGINT       NOT NULL AUTO_INCREMENT,
  firma_id        BIGINT       DEFAULT NULL COMMENT 'NULL = fuente legacy global/default',
  source_type     ENUM('mysql','csv','api') NOT NULL DEFAULT 'mysql',
  label           VARCHAR(255) NOT NULL,
  -- MySQL connection fields
  db_host         VARCHAR(255) DEFAULT NULL,
  db_port         INT          DEFAULT 3306,
  db_name         VARCHAR(255) DEFAULT NULL,
  db_user         VARCHAR(255) DEFAULT NULL,
  db_pass         VARBINARY(512) DEFAULT NULL COMMENT 'Cifrado AES-256-CBC con APP_KEY',
  -- CSV source fields
  csv_path        VARCHAR(500) DEFAULT NULL,
  -- API source fields
  api_url         VARCHAR(500) DEFAULT NULL,
  -- Schema flexibility
  employee_table  VARCHAR(255) DEFAULT 'employee' COMMENT 'Nombre de la tabla de empleados en la BD fuente',
  -- Status & tracking
  is_active       TINYINT(1)   NOT NULL DEFAULT 1,
  last_sync       TIMESTAMP    NULL DEFAULT NULL,
  created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ds_firma (firma_id),
  CONSTRAINT fk_ds_firma FOREIGN KEY (firma_id) REFERENCES keeper_firmas(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
