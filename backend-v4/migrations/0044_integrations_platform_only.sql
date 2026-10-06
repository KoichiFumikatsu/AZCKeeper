-- R1 (revision final de la fase 3): configurar una fuente de datos (URL, token, dueño de personas/cargos/jerarquia) o
-- aprobar lo que trae equivale a decidir cargos y jefes, y con ellos los roles que dan las reglas por cargo. Quien
-- controla el feed se puede subir a si mismo sin pasar por la delegacion. Por eso gestionar y aprobar integraciones pasan
-- a ser solo de la plataforma (platform_only, no delegables). Ver integraciones sigue siendo del cliente.
-- Se quitan de los roles y plantillas de los clientes; cada quita queda en permission_revocations (sin FK a roles: sobrevive).

CREATE TABLE IF NOT EXISTS permission_revocations (
  tenant_id BINARY(16) NOT NULL,
  role_id BINARY(16) NOT NULL,
  permission_code VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  role_name VARCHAR(120) NOT NULL,
  holders INT UNSIGNED NOT NULL,
  reason VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  revoked_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (tenant_id, role_id, permission_code),
  FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

UPDATE permissions SET platform_only = TRUE, delegable = FALSE, version = version + 1
WHERE code IN ('integraciones.gestionar', 'integraciones.aprobar') AND (platform_only = FALSE OR delegable = TRUE);

-- holders: personas y cuentas de grupo con ese rol en ese cliente al momento de quitarlo.
INSERT INTO permission_revocations (tenant_id, role_id, permission_code, role_name, holders, reason)
SELECT p.tenant_id, p.role_id, p.permission_code, r.name,
  (SELECT COUNT(*) FROM access_grants g WHERE g.tenant_id = p.tenant_id AND g.role_id = p.role_id),
  'integrations_platform_only'
FROM role_permissions p JOIN roles r ON r.tenant_id = p.tenant_id AND r.id = p.role_id
WHERE p.permission_code IN ('integraciones.gestionar', 'integraciones.aprobar')
  AND p.tenant_id <> 0x00000000000040008000000000000001
  AND NOT EXISTS (SELECT 1 FROM permission_revocations x WHERE x.tenant_id = p.tenant_id AND x.role_id = p.role_id AND x.permission_code = p.permission_code);

DELETE FROM role_permissions
WHERE permission_code IN ('integraciones.gestionar', 'integraciones.aprobar')
  AND tenant_id <> 0x00000000000040008000000000000001;

DELETE FROM role_template_permissions
WHERE permission_code IN ('integraciones.gestionar', 'integraciones.aprobar')
  AND seed_key IN (SELECT seed_key FROM role_templates WHERE platform_only = FALSE);
