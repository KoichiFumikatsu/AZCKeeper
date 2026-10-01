-- F3 (spec de integraciones §7): entradas por puerta empujadas por el sistema de acceso (uhppoted u otro).
-- Solo las puertas de acceso al edificio alimentan la presencia; lo que no se puede emparejar espera 30 dias.
ALTER TABLE doors ADD COLUMN counts_for_presence BOOLEAN NOT NULL DEFAULT TRUE;

CREATE TABLE IF NOT EXISTS unmatched_check_ins (
  tenant_id BINARY(16) NOT NULL,
  id BINARY(16) NOT NULL,
  source_identity VARCHAR(160) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  event_ref VARCHAR(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  door_ref VARCHAR(160) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  document VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  direction ENUM('in','out') NOT NULL,
  occurred_at DATETIME(6) NOT NULL,
  reason ENUM('unknown_door','unknown_person') NOT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (tenant_id, id),
  UNIQUE KEY unmatched_event (tenant_id, source_identity, event_ref),
  KEY unmatched_reason (tenant_id, reason, door_ref),
  FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT INTO scopes (code, description)
SELECT 'check-ins:write', 'Envío de entradas por puerta (sistema de control de acceso)'
WHERE NOT EXISTS (SELECT 1 FROM scopes WHERE code = 'check-ins:write');
