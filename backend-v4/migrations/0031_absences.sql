-- F4 (spec de integraciones §3.6): ausencias de RR. HH. (vacaciones, licencias, incapacidades, permisos).
-- Una ausencia aprobada que cubre la jornada de un dia deja ese dia sin tiempo esperado: no es ausencia ni tardanza.
CREATE TABLE IF NOT EXISTS absences (
  tenant_id BINARY(16) NOT NULL,
  id BINARY(16) NOT NULL,
  user_id BINARY(16) NOT NULL,
  kind ENUM('vacaciones','licencia_remunerada','licencia_no_remunerada','incapacidad','permiso','calamidad','otro') NOT NULL,
  starts_at DATETIME(6) NOT NULL,
  ends_at DATETIME(6) NOT NULL,
  all_day BOOLEAN NOT NULL DEFAULT TRUE,
  status ENUM('approved','cancelled') NOT NULL DEFAULT 'approved',
  note VARCHAR(200) NULL,
  version BIGINT UNSIGNED NOT NULL DEFAULT 1,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (tenant_id, id),
  KEY absences_user (tenant_id, user_id, starts_at),
  FOREIGN KEY (tenant_id, user_id) REFERENCES users (tenant_id, id),
  CONSTRAINT absences_order CHECK (ends_at > starts_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
