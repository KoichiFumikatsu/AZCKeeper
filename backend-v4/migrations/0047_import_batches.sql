-- Cargas unicas de datos de prueba (tools/import-one-snapshot.php) y su borrado (tools/wipe-import.php).
-- Un lote guarda que creo, que solo vinculo (ya existia) y que actualizo, para poder quitar despues EXACTAMENTE lo creado.
-- Sin datos personales: ids, external_id de la fuente y, en `previous`, solo los ids de unidades/horario/jefe anteriores.
-- Repetible (CREATE TABLE IF NOT EXISTS). MySQL 8.4 y MariaDB 11.4.

CREATE TABLE IF NOT EXISTS import_batches (
  tenant_id BINARY(16) NOT NULL,
  id BINARY(16) NOT NULL,
  kind VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  source_generated_at VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
  status ENUM('applied','partially_wiped','wiped') NOT NULL DEFAULT 'applied',
  counts JSON NOT NULL,
  wipe_counts JSON NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  wiped_at DATETIME(6) NULL,
  PRIMARY KEY (tenant_id, id),
  UNIQUE KEY (id),
  KEY (tenant_id, kind, created_at),
  FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS import_batch_items (
  tenant_id BINARY(16) NOT NULL,
  batch_id BINARY(16) NOT NULL,
  entity_type VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  entity_id BINARY(16) NOT NULL,
  external_id VARCHAR(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL,
  action ENUM('created','matched','updated') NOT NULL,
  previous JSON NULL,
  wipe_result VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NULL,
  wiped_at DATETIME(6) NULL,
  PRIMARY KEY (tenant_id, batch_id, entity_type, entity_id),
  KEY (tenant_id, entity_type, external_id),
  KEY (tenant_id, entity_type, entity_id),
  CHECK (entity_type IN ('org_unit','schedule','user')),
  FOREIGN KEY (tenant_id, batch_id) REFERENCES import_batches (tenant_id, id),
  FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
