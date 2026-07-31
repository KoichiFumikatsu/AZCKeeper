-- Keeper 4 — modulo de panel 'diagnostics' en el RBAC.
--
-- El diagnostico en vivo es vista de IT/superadmin (como el tablero de flota). Se agrega
-- al catalogo de modulos de los roles 'it' y 'admin' en keeper_panel_roles. superadmin ya
-- tiene {"all":true}; gerente/viewer NO lo reciben (no ven diagnostico de equipos).
--
-- Idempotente: JSON_ARRAY_APPEND solo si el modulo aun no esta (guardia JSON_CONTAINS).
-- El respaldo en codigo (admin_auth_helpers.php) tambien lo lista, por si esta migracion
-- no corriera; pero la fuente de verdad es la tabla.

UPDATE keeper_panel_roles
SET permissions_json = JSON_ARRAY_APPEND(permissions_json, '$.modules', 'diagnostics')
WHERE role_code IN ('it', 'admin')
  AND JSON_CONTAINS(permissions_json, '"diagnostics"', '$.modules') = 0;
