-- Eventos de fuentes: cursor independiente de updated_since y referencias Unicode de One.
ALTER TABLE data_domain_owners
  MODIFY COLUMN domain ENUM('firmas','sedes','areas','cargos','horarios','festivos','personas','jerarquia','ausencias','empresas','entradas') NOT NULL;
ALTER TABLE data_sources ADD COLUMN door_events_from_date DATE NULL;

CREATE TABLE IF NOT EXISTS data_source_door_cursors (
  tenant_id BINARY(16) NOT NULL,
  source_id BINARY(16) NOT NULL,
  last_log_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (tenant_id, source_id),
  FOREIGN KEY (tenant_id, source_id) REFERENCES data_sources (tenant_id, id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

ALTER TABLE doors MODIFY COLUMN source_ref VARCHAR(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL;
ALTER TABLE check_ins MODIFY COLUMN direction ENUM('in','out') NULL;
ALTER TABLE unmatched_check_ins
  MODIFY COLUMN door_ref VARCHAR(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  MODIFY COLUMN document VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NULL,
  MODIFY COLUMN direction ENUM('in','out') NULL,
  ADD COLUMN data_source_id BINARY(16) NULL,
  ADD COLUMN person_external_id VARCHAR(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL;
