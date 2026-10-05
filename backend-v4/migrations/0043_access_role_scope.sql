-- Accesos: 1 rol + 1 alcance por persona (docs/architecture/v4-clientes-funciones-accesos.md §4 y §6, fase 3).
-- El alcance deja de vivir en el rol: va en la persona (access_grants.user_id) o, para una cuenta de grupo, en la cuenta
-- dentro de cada otra empresa de su grupo (admin_tenant_id + admin_id). Las tablas viejas (user_roles,
-- admin_account_roles, role_area_scopes, role_site_scopes y roles.scope_kind) quedan congeladas: el codigo deja de
-- leerlas y escribirlas; se borran en la fase 4.

CREATE TABLE IF NOT EXISTS access_grants (
  tenant_id BINARY(16) NOT NULL,
  id BINARY(16) NOT NULL,
  user_id BINARY(16) NULL,
  admin_tenant_id BINARY(16) NULL,
  admin_id BINARY(16) NULL,
  role_id BINARY(16) NOT NULL,
  scope_kind ENUM('all','filters','team','self') NOT NULL DEFAULT 'self',
  source ENUM('manual','rule','default') NOT NULL DEFAULT 'manual',
  rule_id BINARY(16) NULL,
  version BIGINT UNSIGNED NOT NULL DEFAULT 1,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (tenant_id, id),
  UNIQUE KEY access_grants_user (tenant_id, user_id),
  UNIQUE KEY access_grants_account (tenant_id, admin_tenant_id, admin_id),
  KEY access_grants_role (tenant_id, role_id),
  KEY access_grants_holder (admin_tenant_id, admin_id),
  CONSTRAINT access_grants_user_fk FOREIGN KEY (tenant_id, user_id) REFERENCES users (tenant_id, id),
  CONSTRAINT access_grants_account_fk FOREIGN KEY (admin_tenant_id, admin_id) REFERENCES admin_accounts (tenant_id, id),
  CONSTRAINT access_grants_role_fk FOREIGN KEY (tenant_id, role_id) REFERENCES roles (tenant_id, id),
  CONSTRAINT access_grants_rule_fk FOREIGN KEY (tenant_id, rule_id) REFERENCES panel_role_rules (tenant_id, id),
  CONSTRAINT access_grants_one_holder CHECK ((user_id IS NOT NULL AND admin_id IS NULL AND admin_tenant_id IS NULL)
    OR (user_id IS NULL AND admin_id IS NOT NULL AND admin_tenant_id IS NOT NULL AND admin_tenant_id <> tenant_id)),
  CONSTRAINT access_grants_group_scope CHECK (user_id IS NOT NULL OR scope_kind IN ('all','filters')),
  CONSTRAINT access_grants_rule_source CHECK ((source = 'rule') = (rule_id IS NOT NULL)),
  FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS access_grant_units (
  tenant_id BINARY(16) NOT NULL,
  grant_id BINARY(16) NOT NULL,
  unit_id BINARY(16) NOT NULL,
  unit_kind VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  PRIMARY KEY (tenant_id, grant_id, unit_id),
  KEY access_grant_units_unit (tenant_id, unit_id),
  CONSTRAINT access_grant_units_grant FOREIGN KEY (tenant_id, grant_id) REFERENCES access_grants (tenant_id, id) ON DELETE CASCADE,
  CONSTRAINT access_grant_units_org FOREIGN KEY (tenant_id, unit_id, unit_kind) REFERENCES org_units (tenant_id, id, kind),
  CONSTRAINT access_grant_units_kind CHECK (unit_kind IN ('company','site','area')),
  FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Ciudad: texto de la sede (org_units.city); la collation ai_ci la compara sin mayusculas ni tildes.
CREATE TABLE IF NOT EXISTS access_grant_cities (
  tenant_id BINARY(16) NOT NULL,
  grant_id BINARY(16) NOT NULL,
  city VARCHAR(80) NOT NULL,
  PRIMARY KEY (tenant_id, grant_id, city),
  CONSTRAINT access_grant_cities_grant FOREIGN KEY (tenant_id, grant_id) REFERENCES access_grants (tenant_id, id) ON DELETE CASCADE,
  CONSTRAINT access_grant_cities_text CHECK (CHAR_LENGTH(TRIM(city)) > 0),
  FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Reporte de la migracion (§6): personas o cuentas de grupo que tenian varios roles. Sin FK a roles/personas: sobrevive.
CREATE TABLE IF NOT EXISTS access_migration_report (
  tenant_id BINARY(16) NOT NULL,
  id BINARY(16) NOT NULL,
  user_id BINARY(16) NULL,
  admin_tenant_id BINARY(16) NULL,
  admin_id BINARY(16) NULL,
  kept_role_id BINARY(16) NULL,
  dropped_roles VARCHAR(2000) NOT NULL,
  lost_permissions VARCHAR(2000) NOT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (tenant_id, id),
  FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Plantillas de la plataforma (§4): Corporativo, Gerente, Coordinador, IT, Colaborador.
INSERT INTO role_templates (seed_key, name, scope_kind, platform_only)
SELECT 'corporativo', 'Corporativo', 'tenant', 0
WHERE NOT EXISTS (SELECT 1 FROM role_templates WHERE seed_key = 'corporativo');

INSERT INTO role_template_permissions (seed_key, permission_code)
SELECT 'corporativo', p.code FROM permissions p
WHERE p.platform_only = FALSE
  AND p.code IN ('empresas.ver', 'empresas.editar', 'usuarios.ver', 'organizacion.ver', 'horarios.ver', 'equipos.ver', 'reglas.ver', 'roles.ver',
    'reportes.ver', 'actividad.ver', 'actividad.titulos_ver', 'presencia.ver', 'presencia.ubicacion_ver', 'festivos.ver', 'cumplimiento.ver',
    'cobertura.ver', 'auditoria.ver', 'notificaciones.ver', 'integraciones.ver', 'tiers.ver')
  AND NOT EXISTS (SELECT 1 FROM role_template_permissions t WHERE t.seed_key = 'corporativo' AND t.permission_code = p.code);

UPDATE role_templates SET name = 'Gerente', version = version + 1 WHERE seed_key = 'gerencia' AND name <> 'Gerente';
UPDATE role_templates SET name = 'Coordinador', version = version + 1 WHERE seed_key = 'coordinacion' AND name <> 'Coordinador';

-- Rol Corporativo en cada cliente existente (los demas roles del cliente no se renombran ni se borran).
INSERT INTO roles (tenant_id, id, name, seed_key, seed_version, scope_kind)
SELECT t.tenant_id, UNHEX(REPLACE(UUID(), '-', '')), 'Corporativo', 'corporativo', 1, 'tenant'
FROM tenants t
WHERE t.tenant_id <> 0x00000000000040008000000000000001 AND t.deleted_at IS NULL
  AND NOT EXISTS (SELECT 1 FROM roles r WHERE r.tenant_id = t.tenant_id AND (r.seed_key = 'corporativo' OR r.name = 'Corporativo'));

INSERT INTO role_permissions (tenant_id, role_id, permission_code)
SELECT r.tenant_id, r.id, tp.permission_code
FROM roles r JOIN role_template_permissions tp ON tp.seed_key = 'corporativo'
WHERE r.seed_key = 'corporativo'
  AND NOT EXISTS (SELECT 1 FROM role_permissions x WHERE x.tenant_id = r.tenant_id AND x.role_id = r.id AND x.permission_code = tp.permission_code);

SET @access_tenant = NULL;

-- BEGIN conversion (tests/access.php la vuelve a ejecutar con @access_tenant = un cliente de prueba; sin ; dentro de textos)
-- 1. Personas: de sus roles activos queda uno, el de mayor alcance (todo > sede/area con mas personas > equipo > propio);
--    su alcance pasa a la persona. Colaborador solo = acceso por defecto; puesto por regla = origen regla.
INSERT INTO access_grants (tenant_id, id, user_id, role_id, scope_kind, source, rule_id)
SELECT ranked.tenant_id, UNHEX(REPLACE(UUID(), '-', '')), ranked.user_id, ranked.role_id,
  CASE ranked.scope_kind WHEN 'tenant' THEN 'all' WHEN 'site' THEN 'filters' WHEN 'area' THEN 'filters' WHEN 'team' THEN 'team' ELSE 'self' END,
  CASE WHEN ranked.rule_id IS NOT NULL THEN 'rule' WHEN ranked.seed_key IN ('colaborador', 'collaborator') THEN 'default' ELSE 'manual' END,
  ranked.rule_id
FROM (
  SELECT c.*, ROW_NUMBER() OVER (PARTITION BY c.tenant_id, c.user_id ORDER BY c.kind_rank DESC, c.reach DESC, c.permission_count DESC, c.role_id) rn
  FROM (
    SELECT ur.tenant_id, ur.user_id, ur.role_id, ur.rule_id, r.scope_kind, r.seed_key,
      CASE r.scope_kind WHEN 'tenant' THEN 4 WHEN 'site' THEN 3 WHEN 'area' THEN 3 WHEN 'team' THEN 2 ELSE 1 END kind_rank,
      CASE r.scope_kind
        WHEN 'site' THEN (SELECT COUNT(*) FROM users x JOIN role_site_scopes s ON s.tenant_id = x.tenant_id AND s.site_id = x.site_id
          WHERE s.tenant_id = r.tenant_id AND s.role_id = r.id AND x.status = 'active')
        WHEN 'area' THEN (SELECT COUNT(*) FROM users x JOIN role_area_scopes s ON s.tenant_id = x.tenant_id AND s.area_id = x.area_id
          WHERE s.tenant_id = r.tenant_id AND s.role_id = r.id AND x.status = 'active')
        ELSE 0 END reach,
      (SELECT COUNT(*) FROM role_permissions p WHERE p.tenant_id = r.tenant_id AND p.role_id = r.id) permission_count
    FROM user_roles ur JOIN roles r ON r.tenant_id = ur.tenant_id AND r.id = ur.role_id AND r.active = TRUE
    WHERE @access_tenant IS NULL OR ur.tenant_id = @access_tenant
  ) c
) ranked
WHERE ranked.rn = 1
  AND NOT EXISTS (SELECT 1 FROM access_grants g WHERE g.tenant_id = ranked.tenant_id AND g.user_id = ranked.user_id);

-- 2. Cuentas de grupo en otras empresas: solo roles de toda la empresa, sede o area (equipo/propio no ven nada alli).
INSERT INTO access_grants (tenant_id, id, admin_tenant_id, admin_id, role_id, scope_kind, source)
SELECT ranked.tenant_id, UNHEX(REPLACE(UUID(), '-', '')), ranked.admin_tenant_id, ranked.admin_id, ranked.role_id,
  IF(ranked.scope_kind = 'tenant', 'all', 'filters'), 'manual'
FROM (
  SELECT c.*, ROW_NUMBER() OVER (PARTITION BY c.tenant_id, c.admin_tenant_id, c.admin_id ORDER BY c.kind_rank DESC, c.reach DESC, c.permission_count DESC, c.role_id) rn
  FROM (
    SELECT g.tenant_id, g.admin_tenant_id, g.admin_id, g.role_id, r.scope_kind,
      CASE r.scope_kind WHEN 'tenant' THEN 4 ELSE 3 END kind_rank,
      CASE r.scope_kind
        WHEN 'site' THEN (SELECT COUNT(*) FROM users x JOIN role_site_scopes s ON s.tenant_id = x.tenant_id AND s.site_id = x.site_id
          WHERE s.tenant_id = r.tenant_id AND s.role_id = r.id AND x.status = 'active')
        WHEN 'area' THEN (SELECT COUNT(*) FROM users x JOIN role_area_scopes s ON s.tenant_id = x.tenant_id AND s.area_id = x.area_id
          WHERE s.tenant_id = r.tenant_id AND s.role_id = r.id AND x.status = 'active')
        ELSE 0 END reach,
      (SELECT COUNT(*) FROM role_permissions p WHERE p.tenant_id = r.tenant_id AND p.role_id = r.id) permission_count
    FROM admin_account_roles g JOIN roles r ON r.tenant_id = g.tenant_id AND r.id = g.role_id AND r.active = TRUE
    WHERE r.scope_kind IN ('tenant', 'site', 'area') AND (@access_tenant IS NULL OR g.tenant_id = @access_tenant)
  ) c
) ranked
WHERE ranked.rn = 1
  AND NOT EXISTS (SELECT 1 FROM access_grants x WHERE x.tenant_id = ranked.tenant_id AND x.admin_tenant_id = ranked.admin_tenant_id AND x.admin_id = ranked.admin_id);

-- 3. Filtros: las sedes o areas del rol que quedo pasan al acceso (nadie gana ni pierde visibilidad).
INSERT INTO access_grant_units (tenant_id, grant_id, unit_id, unit_kind)
SELECT g.tenant_id, g.id, s.site_id, 'site'
FROM access_grants g
JOIN roles r ON r.tenant_id = g.tenant_id AND r.id = g.role_id AND r.scope_kind = 'site'
JOIN role_site_scopes s ON s.tenant_id = r.tenant_id AND s.role_id = r.id
WHERE g.scope_kind = 'filters' AND (@access_tenant IS NULL OR g.tenant_id = @access_tenant)
  AND NOT EXISTS (SELECT 1 FROM access_grant_units u WHERE u.tenant_id = g.tenant_id AND u.grant_id = g.id);

INSERT INTO access_grant_units (tenant_id, grant_id, unit_id, unit_kind)
SELECT g.tenant_id, g.id, a.area_id, 'area'
FROM access_grants g
JOIN roles r ON r.tenant_id = g.tenant_id AND r.id = g.role_id AND r.scope_kind = 'area'
JOIN role_area_scopes a ON a.tenant_id = r.tenant_id AND a.role_id = r.id
WHERE g.scope_kind = 'filters' AND (@access_tenant IS NULL OR g.tenant_id = @access_tenant)
  AND NOT EXISTS (SELECT 1 FROM access_grant_units u WHERE u.tenant_id = g.tenant_id AND u.grant_id = g.id);

-- 4. Reporte (§6): toda persona con varios roles activos; lost_permissions vacio = no perdio nada.
INSERT INTO access_migration_report (tenant_id, id, user_id, kept_role_id, dropped_roles, lost_permissions)
SELECT g.tenant_id, UNHEX(REPLACE(UUID(), '-', '')), g.user_id, g.role_id,
  (SELECT GROUP_CONCAT(CONCAT(r.name, ' (', r.scope_kind, ')') ORDER BY r.name SEPARATOR ', ')
     FROM user_roles ur JOIN roles r ON r.tenant_id = ur.tenant_id AND r.id = ur.role_id AND r.active = TRUE
     WHERE ur.tenant_id = g.tenant_id AND ur.user_id = g.user_id AND ur.role_id <> g.role_id),
  COALESCE((SELECT GROUP_CONCAT(DISTINCT p.permission_code ORDER BY p.permission_code SEPARATOR ', ')
     FROM user_roles ur JOIN roles r ON r.tenant_id = ur.tenant_id AND r.id = ur.role_id AND r.active = TRUE
     JOIN role_permissions p ON p.tenant_id = r.tenant_id AND p.role_id = r.id
     WHERE ur.tenant_id = g.tenant_id AND ur.user_id = g.user_id AND ur.role_id <> g.role_id
       AND NOT EXISTS (SELECT 1 FROM role_permissions k WHERE k.tenant_id = g.tenant_id AND k.role_id = g.role_id AND k.permission_code = p.permission_code)), '')
FROM access_grants g
WHERE g.user_id IS NOT NULL AND (@access_tenant IS NULL OR g.tenant_id = @access_tenant)
  AND (SELECT COUNT(*) FROM user_roles ur JOIN roles r ON r.tenant_id = ur.tenant_id AND r.id = ur.role_id AND r.active = TRUE
       WHERE ur.tenant_id = g.tenant_id AND ur.user_id = g.user_id) > 1
  AND NOT EXISTS (SELECT 1 FROM access_migration_report m WHERE m.tenant_id = g.tenant_id AND m.user_id = g.user_id);

-- 5. Reporte de cuentas de grupo: varios roles en una empresa, o ninguno utilizable (kept_role_id NULL).
INSERT INTO access_migration_report (tenant_id, id, admin_tenant_id, admin_id, kept_role_id, dropped_roles, lost_permissions)
SELECT x.tenant_id, UNHEX(REPLACE(UUID(), '-', '')), x.admin_tenant_id, x.admin_id, g.role_id,
  (SELECT GROUP_CONCAT(CONCAT(r.name, ' (', r.scope_kind, ')') ORDER BY r.name SEPARATOR ', ')
     FROM admin_account_roles ar JOIN roles r ON r.tenant_id = ar.tenant_id AND r.id = ar.role_id AND r.active = TRUE
     WHERE ar.tenant_id = x.tenant_id AND ar.admin_tenant_id = x.admin_tenant_id AND ar.admin_id = x.admin_id AND (g.role_id IS NULL OR ar.role_id <> g.role_id)),
  COALESCE((SELECT GROUP_CONCAT(DISTINCT p.permission_code ORDER BY p.permission_code SEPARATOR ', ')
     FROM admin_account_roles ar JOIN roles r ON r.tenant_id = ar.tenant_id AND r.id = ar.role_id AND r.active = TRUE
     JOIN role_permissions p ON p.tenant_id = r.tenant_id AND p.role_id = r.id
     WHERE ar.tenant_id = x.tenant_id AND ar.admin_tenant_id = x.admin_tenant_id AND ar.admin_id = x.admin_id AND (g.role_id IS NULL OR ar.role_id <> g.role_id)
       AND NOT EXISTS (SELECT 1 FROM role_permissions k WHERE k.tenant_id = x.tenant_id AND k.role_id = g.role_id AND k.permission_code = p.permission_code)), '')
FROM (SELECT DISTINCT ar.tenant_id, ar.admin_tenant_id, ar.admin_id
      FROM admin_account_roles ar JOIN roles r ON r.tenant_id = ar.tenant_id AND r.id = ar.role_id AND r.active = TRUE
      WHERE @access_tenant IS NULL OR ar.tenant_id = @access_tenant) x
LEFT JOIN access_grants g ON g.tenant_id = x.tenant_id AND g.admin_tenant_id = x.admin_tenant_id AND g.admin_id = x.admin_id
WHERE (g.id IS NULL OR (SELECT COUNT(*) FROM admin_account_roles ar JOIN roles r ON r.tenant_id = ar.tenant_id AND r.id = ar.role_id AND r.active = TRUE
       WHERE ar.tenant_id = x.tenant_id AND ar.admin_tenant_id = x.admin_tenant_id AND ar.admin_id = x.admin_id) > 1)
  AND NOT EXISTS (SELECT 1 FROM access_migration_report m WHERE m.tenant_id = x.tenant_id AND m.admin_tenant_id = x.admin_tenant_id AND m.admin_id = x.admin_id);
-- END conversion

-- Al final: los ALTER no son idempotentes; si algo anterior falla, reintentar el archivo es seguro.
-- Plantillas que se ofrecen a clientes nuevos: las cinco del §4. Direccion, RRHH y Admin Empresa quedan solo en clientes que ya las tienen.
ALTER TABLE role_templates ADD COLUMN offered BOOLEAN NOT NULL DEFAULT TRUE;
UPDATE role_templates SET offered = FALSE WHERE seed_key IN ('direccion', 'rrhh', 'admin_empresa');
