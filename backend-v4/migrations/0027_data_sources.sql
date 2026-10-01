-- Fuentes de datos externas (docs/architecture/v4-integraciones-fuentes-de-datos.md, F1).
-- Un dueño por dominio y por empresa; vínculos con ids externos; registro de cada vuelta de sincronización.
CREATE TABLE IF NOT EXISTS data_sources (
  tenant_id BINARY(16) NOT NULL,
  id BINARY(16) NOT NULL,
  name VARCHAR(120) NOT NULL,
  kind ENUM('rest','sql_view','push') NOT NULL,
  config_ciphertext BLOB NOT NULL,
  interval_minutes SMALLINT UNSIGNED NULL,
  full_reconcile_local TIME NOT NULL DEFAULT '02:00:00',
  mode ENUM('simulate','live') NOT NULL DEFAULT 'simulate',
  active BOOLEAN NOT NULL DEFAULT TRUE,
  max_deactivations SMALLINT UNSIGNED NOT NULL DEFAULT 5,
  max_deactivation_percent TINYINT UNSIGNED NOT NULL DEFAULT 5,
  min_list_percent TINYINT UNSIGNED NOT NULL DEFAULT 50,
  last_run_at DATETIME(6) NULL,
  last_success_at DATETIME(6) NULL,
  last_full_at DATETIME(6) NULL,
  updated_since DATETIME(6) NULL,
  version BIGINT UNSIGNED NOT NULL DEFAULT 1,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (tenant_id, id),
  FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id),
  CHECK (interval_minutes IS NULL OR interval_minutes BETWEEN 5 AND 1440),
  CHECK (max_deactivation_percent BETWEEN 1 AND 100 AND min_list_percent BETWEEN 0 AND 100)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS data_domain_owners (
  tenant_id BINARY(16) NOT NULL,
  domain ENUM('firmas','sedes','areas','cargos','horarios','festivos','personas','jerarquia','ausencias') NOT NULL,
  source_id BINARY(16) NOT NULL,
  PRIMARY KEY (tenant_id, domain),
  FOREIGN KEY (tenant_id, source_id) REFERENCES data_sources (tenant_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS entity_links (
  tenant_id BINARY(16) NOT NULL,
  entity_type ENUM('org_unit','schedule','holiday','user','absence','door') NOT NULL,
  entity_id BINARY(16) NOT NULL,
  source_id BINARY(16) NOT NULL,
  external_id VARCHAR(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  link_method ENUM('created','matched_document','matched_name','manual') NOT NULL,
  linked_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (tenant_id, source_id, entity_type, external_id),
  UNIQUE KEY entity_links_entity (tenant_id, entity_type, entity_id, source_id),
  FOREIGN KEY (tenant_id, source_id) REFERENCES data_sources (tenant_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS sync_runs (
  tenant_id BINARY(16) NOT NULL,
  id BINARY(16) NOT NULL,
  source_id BINARY(16) NOT NULL,
  kind ENUM('incremental','full') NOT NULL,
  trigger_kind ENUM('schedule','manual','approval') NOT NULL,
  simulate BOOLEAN NOT NULL,
  status ENUM('running','applied','blocked','failed','simulated') NOT NULL,
  started_at DATETIME(6) NOT NULL,
  finished_at DATETIME(6) NULL,
  counts JSON NULL,
  blocked_reason VARCHAR(80) NULL,
  error_code VARCHAR(80) NULL,
  generated_at DATETIME(6) NULL,
  PRIMARY KEY (tenant_id, id),
  KEY sync_runs_source (tenant_id, source_id, started_at),
  FOREIGN KEY (tenant_id, source_id) REFERENCES data_sources (tenant_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS sync_items (
  tenant_id BINARY(16) NOT NULL,
  id BINARY(16) NOT NULL,
  run_id BINARY(16) NOT NULL,
  domain VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  external_id VARCHAR(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL,
  entity_id BINARY(16) NULL,
  action ENUM('create','update','deactivate','link','error','pending') NOT NULL,
  reason VARCHAR(80) NULL,
  label VARCHAR(200) NULL,
  fields JSON NULL,
  payload JSON NULL,
  resolution ENUM('approved','rejected') NULL,
  resolved_at DATETIME(6) NULL,
  PRIMARY KEY (tenant_id, id),
  KEY sync_items_run (tenant_id, run_id),
  KEY sync_items_pending (tenant_id, action, resolution),
  FOREIGN KEY (tenant_id, run_id) REFERENCES sync_runs (tenant_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT INTO permissions (code, label, resource, action, delegable, platform_only)
SELECT 'integraciones.ver', 'Ver las fuentes de datos y el historial de sincronización', 'integraciones', 'ver', 1, 0
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE code = 'integraciones.ver');
INSERT INTO permissions (code, label, resource, action, delegable, platform_only)
SELECT 'integraciones.aprobar', 'Aprobar cambios bloqueados de una sincronización (retiros masivos, cédulas, vínculos)', 'integraciones', 'aprobar', 1, 0
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE code = 'integraciones.aprobar');
