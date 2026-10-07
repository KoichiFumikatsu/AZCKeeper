-- Limpieza de la fase 4 (2026-10-07). Requiere el código que ya no lee ni escribe estas tablas: aplicar en la misma
-- ventana que el despliegue de ese código.
--
-- 1) Tiers y suscripciones (0004, 0021): los sustituyen los planes de persona (0041, PersonPlans). La API externa
--    cambia el scope tiers:read por plans:read (/ext/v1/plans y /ext/v1/members/{id}/plan); en producción no había
--    integraciones con credenciales. Se quitan también los permisos tiers.ver y tiers.gestionar, que ya no protegen
--    ninguna operación.
-- 2) RBAC viejo congelado por 0043 (user_roles, admin_account_roles, role_area_scopes, role_site_scopes y
--    roles.scope_kind): el acceso vive en access_grants. access_migration_report se conserva.
--
-- Repetible: DROP ... IF EXISTS, borrados por valor y DDL condicional por information_schema. Orden seguro de FK:
-- primero las tablas hijas. MySQL 8.4 y MariaDB 11.4.

-- 1a) Scope externo plans:read en lugar de tiers:read (se conserva lo que tuviera cada integración o webhook).
INSERT INTO scopes (code, description)
SELECT 'plans:read', 'Lectura planes de persona'
WHERE NOT EXISTS (SELECT 1 FROM scopes WHERE code = 'plans:read');

INSERT IGNORE INTO integration_scopes (tenant_id, integration_id, scope_code)
SELECT tenant_id, integration_id, 'plans:read' FROM integration_scopes WHERE scope_code = 'tiers:read';

INSERT IGNORE INTO webhook_scopes (tenant_id, webhook_id, scope_code)
SELECT tenant_id, webhook_id, 'plans:read' FROM webhook_scopes WHERE scope_code = 'tiers:read';

DELETE FROM oauth_token_scopes WHERE scope_code = 'tiers:read';
DELETE FROM integration_scopes WHERE scope_code = 'tiers:read';
DELETE FROM webhook_scopes WHERE scope_code = 'tiers:read';
DELETE FROM scopes WHERE code = 'tiers:read';

-- 1b) Permisos de tiers.
DELETE FROM role_permissions WHERE permission_code IN ('tiers.ver', 'tiers.gestionar');
DELETE FROM role_template_permissions WHERE permission_code IN ('tiers.ver', 'tiers.gestionar');
DELETE FROM permissions WHERE code IN ('tiers.ver', 'tiers.gestionar');

-- 1c) Tablas de tiers. Sus triggers (subscriptions_*) se van con la tabla; sus vigencias auxiliares se borran antes.
DELETE FROM assignment_intervals WHERE kind = 'subscriptions';
DROP TABLE IF EXISTS subscriptions;
DROP TABLE IF EXISTS tier_module;
DROP TABLE IF EXISTS tier;

-- 2a) Tablas viejas de roles y alcances (hijas de roles; nadie las referencia).
DROP TABLE IF EXISTS role_area_scopes;
DROP TABLE IF EXISTS role_site_scopes;
DROP TABLE IF EXISTS user_roles;
DROP TABLE IF EXISTS admin_account_roles;

-- 2b) roles.scope_kind y su UNIQUE (tenant_id, id, scope_kind), que solo servía a las FK de role_*_scopes.
SET @old_index = (SELECT index_name FROM information_schema.statistics
  WHERE table_schema = DATABASE() AND table_name = 'roles' AND column_name = 'scope_kind' LIMIT 1);
SET @sql = IF(@old_index IS NULL, 'DO 0', CONCAT('ALTER TABLE roles DROP INDEX `', @old_index, '`'));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = IF(EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'roles' AND column_name = 'scope_kind'),
  'ALTER TABLE roles DROP COLUMN scope_kind', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
