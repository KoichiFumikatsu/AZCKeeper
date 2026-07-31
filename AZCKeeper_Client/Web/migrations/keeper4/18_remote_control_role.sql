-- Keeper 4 — modulo de panel 'remote-control' en el RBAC.
--
-- El control remoto (apagar/reiniciar/logoff/bloquear/renombrar/diagnosticar un equipo) es
-- una capacidad que se otorga por ROL, ademas del tier de la firma. Se agrega al catalogo de
-- los roles it/admin; superadmin ya tiene {"all":true}. gerente/viewer NO lo reciben.
-- Roles como supervisor/coordinador se crean desde roles.php con este modulo DESMARCADO.
-- Idempotente.
UPDATE keeper_panel_roles
SET permissions_json = JSON_ARRAY_APPEND(permissions_json, '$.modules', 'remote-control')
WHERE role_code IN ('it', 'admin')
  AND JSON_CONTAINS(permissions_json, '"remote-control"', '$.modules') = 0;
