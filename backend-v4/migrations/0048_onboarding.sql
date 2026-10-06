-- Puesta en marcha (fase 4, B1): lo que decide una persona por cliente. La evidencia técnica (personas, equipos,
-- reportes) se sigue calculando; aquí solo queda el piloto elegido (persona y equipo), el responsable, las revisiones
-- o aplazamientos por paso y el cierre con autor y fecha. Versión de fila para que dos administradores no se pisen.
-- Sin secretos ni porcentajes. Autores = principals del cliente (la auditoría usa los mismos).
-- Repetible (CREATE TABLE IF NOT EXISTS). MySQL 8.4 y MariaDB 11.4.

CREATE TABLE IF NOT EXISTS onboarding_states (
  tenant_id BINARY(16) NOT NULL,
  status ENUM('open','closed') NOT NULL DEFAULT 'open',
  pilot_user_id BINARY(16) NULL,
  pilot_device_id BINARY(16) NULL,
  pilot_selected_at DATETIME(6) NULL,
  pilot_selected_by BINARY(16) NULL,
  responsible_kind ENUM('platform','person') NULL,
  responsible_user_id BINARY(16) NULL,
  closed_at DATETIME(6) NULL,
  closed_by BINARY(16) NULL,
  closed_criteria_version SMALLINT UNSIGNED NULL,
  reopened_at DATETIME(6) NULL,
  reopened_by BINARY(16) NULL,
  reopen_reason VARCHAR(300) NULL,
  version BIGINT UNSIGNED NOT NULL DEFAULT 1,
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_by BINARY(16) NOT NULL,
  PRIMARY KEY (tenant_id),
  CONSTRAINT onboarding_pilot_user_fk FOREIGN KEY (tenant_id, pilot_user_id) REFERENCES users (tenant_id, id),
  CONSTRAINT onboarding_pilot_device_fk FOREIGN KEY (tenant_id, pilot_device_id) REFERENCES devices (tenant_id, id),
  CONSTRAINT onboarding_responsible_fk FOREIGN KEY (tenant_id, responsible_user_id) REFERENCES users (tenant_id, id),
  CONSTRAINT onboarding_pilot_by_fk FOREIGN KEY (tenant_id, pilot_selected_by) REFERENCES principals (tenant_id, id),
  CONSTRAINT onboarding_closed_by_fk FOREIGN KEY (tenant_id, closed_by) REFERENCES principals (tenant_id, id),
  CONSTRAINT onboarding_reopened_by_fk FOREIGN KEY (tenant_id, reopened_by) REFERENCES principals (tenant_id, id),
  CONSTRAINT onboarding_updated_by_fk FOREIGN KEY (tenant_id, updated_by) REFERENCES principals (tenant_id, id),
  CONSTRAINT onboarding_device_needs_person CHECK (pilot_device_id IS NULL OR pilot_user_id IS NOT NULL),
  CONSTRAINT onboarding_closed_has_author CHECK (status = 'open' OR (closed_at IS NOT NULL AND closed_by IS NOT NULL AND closed_criteria_version IS NOT NULL)),
  CONSTRAINT onboarding_responsible_person CHECK ((responsible_kind = 'person' AND responsible_user_id IS NOT NULL) OR ((responsible_kind IS NULL OR responsible_kind = 'platform') AND responsible_user_id IS NULL)),
  FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS onboarding_step_decisions (
  tenant_id BINARY(16) NOT NULL,
  step TINYINT UNSIGNED NOT NULL,
  decision ENUM('reviewed','deferred') NOT NULL,
  note VARCHAR(300) NULL,
  criteria_version SMALLINT UNSIGNED NOT NULL,
  decided_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  decided_by BINARY(16) NOT NULL,
  PRIMARY KEY (tenant_id, step),
  CONSTRAINT onboarding_step_state_fk FOREIGN KEY (tenant_id) REFERENCES onboarding_states (tenant_id),
  CONSTRAINT onboarding_step_by_fk FOREIGN KEY (tenant_id, decided_by) REFERENCES principals (tenant_id, id),
  CONSTRAINT onboarding_step_range CHECK (step BETWEEN 1 AND 8),
  FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
