-- Salud del servidor (de la plataforma, como portal_origins) (2026-10-14): ultima corrida de cada trabajo programado (cron) de la plataforma. Una fila por
-- trabajo (productivity, retention). El mensaje de fallo va recortado y sin valores entre comillas, correos ni numeros largos.
CREATE TABLE IF NOT EXISTS cron_runs (
  tenant_id BINARY(16) NOT NULL,
  job VARCHAR(40) NOT NULL,
  started_at DATETIME(6) NOT NULL,
  finished_at DATETIME(6) NULL,
  last_success_at DATETIME(6) NULL,
  status ENUM('running','ok','failed','skipped') NOT NULL,
  error_class VARCHAR(120) NULL,
  error_message VARCHAR(500) NULL,
  PRIMARY KEY (tenant_id, job),
  FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id),
  CHECK (tenant_id = 0x00000000000040008000000000000001)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
