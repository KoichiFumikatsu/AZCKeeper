-- F5 (spec de integraciones §8.3-8.4 y decisión 15): jerarquía, alcance "mi equipo", reglas cargo -> rol y
-- exclusión de directivos del monitoreo.

-- A quién reporta cada persona (dominio "jerarquia"; lo puede mandar One).
ALTER TABLE users
  ADD COLUMN reports_to_user_id BINARY(16) NULL AFTER sociedad_id,
  ADD COLUMN monitoring_override ENUM('auto','monitored','excluded') NOT NULL DEFAULT 'auto' AFTER reports_to_user_id,
  ADD CONSTRAINT users_reports_to FOREIGN KEY (tenant_id, reports_to_user_id) REFERENCES users (tenant_id, id),
  ADD CONSTRAINT users_reports_to_self CHECK (reports_to_user_id IS NULL OR reports_to_user_id <> id);

-- Alcance "team": la persona y quienes le reportan, directa o indirectamente.
ALTER TABLE roles
  MODIFY scope_kind ENUM('tenant','area','site','self','team') NOT NULL,
  ADD COLUMN assignable_by_rule BOOLEAN NOT NULL DEFAULT FALSE;

-- Roles puestos por una regla (se quitan solos cuando la persona deja de cumplirla); los manuales no se tocan.
CREATE TABLE IF NOT EXISTS panel_role_rules (
  tenant_id BINARY(16) NOT NULL,
  id BINARY(16) NOT NULL,
  name VARCHAR(120) NOT NULL,
  condition_kind ENUM('position','manages','position_in_area') NOT NULL,
  position_id BINARY(16) NULL,
  area_id BINARY(16) NULL,
  role_id BINARY(16) NOT NULL,
  active BOOLEAN NOT NULL DEFAULT TRUE,
  version BIGINT UNSIGNED NOT NULL DEFAULT 1,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (tenant_id, id),
  FOREIGN KEY (tenant_id, role_id) REFERENCES roles (tenant_id, id),
  FOREIGN KEY (tenant_id, position_id) REFERENCES org_units (tenant_id, id),
  FOREIGN KEY (tenant_id, area_id) REFERENCES org_units (tenant_id, id),
  CONSTRAINT panel_role_rules_shape CHECK (
    (condition_kind = 'position' AND position_id IS NOT NULL AND area_id IS NULL) OR
    (condition_kind = 'manages' AND position_id IS NULL AND area_id IS NULL) OR
    (condition_kind = 'position_in_area' AND position_id IS NOT NULL AND area_id IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

ALTER TABLE user_roles
  ADD COLUMN rule_id BINARY(16) NULL,
  ADD CONSTRAINT user_roles_rule FOREIGN KEY (tenant_id, rule_id) REFERENCES panel_role_rules (tenant_id, id) ON DELETE CASCADE;

-- Cargos que no se monitorean (directivos y dueños). La excepción por persona va en users.monitoring_override.
CREATE TABLE IF NOT EXISTS monitoring_excluded_positions (
  tenant_id BINARY(16) NOT NULL,
  position_id BINARY(16) NOT NULL,
  PRIMARY KEY (tenant_id, position_id),
  FOREIGN KEY (tenant_id, position_id) REFERENCES org_units (tenant_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT INTO permissions (code, label, resource, action, delegable, platform_only)
SELECT 'roles.reglas', 'Configurar la asignación automática de roles por cargo y jerarquía', 'roles', 'reglas', 0, 0
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE code = 'roles.reglas');
