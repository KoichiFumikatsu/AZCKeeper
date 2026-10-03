-- Planes por persona de la plataforma (v4-clientes-funciones-accesos, fase 2a). Persona sin plan propio = plan por
-- defecto de su cliente. Un plan solo incluye funciones de nivel persona (lo valida la API con src/Features.php).

CREATE TABLE IF NOT EXISTS person_plans (
  tenant_id BINARY(16) NOT NULL,
  id BINARY(16) NOT NULL,
  name VARCHAR(80) NOT NULL,
  badge_label VARCHAR(120) NOT NULL DEFAULT '',
  active BOOLEAN NOT NULL DEFAULT TRUE,
  version BIGINT UNSIGNED NOT NULL DEFAULT 1,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (tenant_id, id),
  UNIQUE KEY person_plans_name (tenant_id, name),
  FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id),
  CONSTRAINT person_plans_platform CHECK (tenant_id = 0x00000000000040008000000000000001)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS person_plan_items (
  tenant_id BINARY(16) NOT NULL,
  plan_id BINARY(16) NOT NULL,
  feature_code VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  PRIMARY KEY (tenant_id, plan_id, feature_code),
  FOREIGN KEY (tenant_id, plan_id) REFERENCES person_plans (tenant_id, id) ON DELETE CASCADE,
  FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id),
  CONSTRAINT person_plan_items_platform CHECK (tenant_id = 0x00000000000040008000000000000001)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

ALTER TABLE tenants
  ADD COLUMN default_plan_tenant_id BINARY(16) NULL,
  ADD COLUMN default_plan_id BINARY(16) NULL,
  ADD CONSTRAINT tenants_default_plan FOREIGN KEY (default_plan_tenant_id, default_plan_id) REFERENCES person_plans (tenant_id, id),
  ADD CONSTRAINT tenants_default_plan_pair CHECK ((default_plan_id IS NULL) = (default_plan_tenant_id IS NULL));

ALTER TABLE users
  ADD COLUMN plan_tenant_id BINARY(16) NULL,
  ADD COLUMN plan_id BINARY(16) NULL,
  ADD CONSTRAINT users_plan FOREIGN KEY (plan_tenant_id, plan_id) REFERENCES person_plans (tenant_id, id),
  ADD CONSTRAINT users_plan_pair CHECK ((plan_id IS NULL) = (plan_tenant_id IS NULL));

INSERT IGNORE INTO permissions (code, label, resource, action, delegable, platform_only)
  VALUES ('planes.asignar', 'Asignar el plan de cada persona', 'planes', 'asignar', TRUE, FALSE);

-- Plan "Completo": todas las funciones de nivel persona. Queda por defecto en los clientes existentes, asi sus equipos
-- siguen (o empiezan a) recibir sus reglas sin que nadie tenga que asignar planes primero.
INSERT INTO person_plans (tenant_id, id, name, badge_label)
  VALUES (0x00000000000040008000000000000001, UNHEX(REPLACE(UUID(),'-','')), 'Completo', 'Todas las funciones por persona');
INSERT INTO person_plan_items (tenant_id, plan_id, feature_code)
  SELECT p.tenant_id, p.id, f.code FROM person_plans p
  CROSS JOIN (SELECT 'productividad' code UNION ALL SELECT 'titulos' UNION ALL SELECT 'presencia' UNION ALL SELECT 'puertas'
    UNION ALL SELECT 'ubicacion' UNION ALL SELECT 'web' UNION ALL SELECT 'usb' UNION ALL SELECT 'descargas'
    UNION ALL SELECT 'instalaciones' UNION ALL SELECT 'comandos' UNION ALL SELECT 'borrado' UNION ALL SELECT 'endurecimiento'
    UNION ALL SELECT 'cumplimiento') f
  WHERE p.tenant_id = 0x00000000000040008000000000000001 AND p.name = 'Completo';
UPDATE tenants t JOIN person_plans p ON p.tenant_id = 0x00000000000040008000000000000001 AND p.name = 'Completo'
  SET t.default_plan_tenant_id = p.tenant_id, t.default_plan_id = p.id
  WHERE t.tenant_id <> 0x00000000000040008000000000000001 AND t.deleted_at IS NULL;
-- Las politicas de todos los clientes se recompilan con la nueva regla (lo procesa config/compile-pending.php).
INSERT INTO admin_policy_recompiles (tenant_id)
  SELECT tenant_id FROM tenants WHERE tenant_id <> 0x00000000000040008000000000000001 AND deleted_at IS NULL
  ON DUPLICATE KEY UPDATE revision = revision + 1, requested_at = UTC_TIMESTAMP(6);
