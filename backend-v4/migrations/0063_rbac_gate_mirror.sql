-- Una sola llave para la autogestión de roles y cuentas (decisión Q3 de Koichi, 2026-10-15;
-- docs/design/2026-10-14-superadmin-por-tareas.md §1.4). Desde aquí manda la función vendible autogestion_accesos
-- (tenant_features): AdminAccess::rolesGate ya no lee tenants.rbac_self_management. La columna se conserva como espejo
-- de la función para quien la consulte directo en la base (el panel ya no tiene la casilla «La empresa gestiona sus
-- propios roles y cuentas»; PATCH /tenants/{id}/rbac-gate y PUT /tenants/{id}/features la mantienen al día).
--
-- Nadie pierde acceso: antes, gestionar roles y delegar accesos exigía la casilla Y la función (la función la exigía
-- AdminAccess por el permiso roles.gestionar). Un cliente con la casilla encendida y sin la función no podía usarla;
-- uno con la función y sin la casilla gana la autogestión, que es lo que la función vendida promete.
-- No se tocan tenant_features ni sesiones: el acceso efectivo depende solo de la función. Idempotente.
UPDATE tenants t
SET t.rbac_self_management = EXISTS (SELECT 1 FROM tenant_features f WHERE f.tenant_id = t.tenant_id AND f.feature_code = 'autogestion_accesos')
WHERE t.rbac_self_management <> EXISTS (SELECT 1 FROM tenant_features f WHERE f.tenant_id = t.tenant_id AND f.feature_code = 'autogestion_accesos');
