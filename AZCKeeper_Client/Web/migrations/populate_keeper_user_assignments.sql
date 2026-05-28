-- ============================================================
-- MIGRACIÓN: populate_keeper_user_assignments.sql
-- Poblar keeper_user_assignments con datos de employee (legacy)
-- Aplicar en: pipezafra_soporte_db
-- Fecha: 2026-03-06
-- ============================================================
--
-- NOTA: Este script es un SEED INICIAL para poblar los datos de
-- los keeper_users que ya existen pero nunca han hecho login
-- desde la versión que incluye sync automático.
--
-- A partir de ahora, ClientLogin.php::syncAssignment() se encarga
-- de crear/actualizar keeper_user_assignments en cada login,
-- así que este script solo es necesario una vez.
--
-- Mapeo de columnas:
--   employee.company     → keeper_user_assignments.firm_id   (FK firm.id)
--   employee.area_id     → keeper_user_assignments.area_id   (FK areas.id)
--   employee.position_id → keeper_user_assignments.cargo_id  (FK cargos.id)
--
-- Solo inserta para keeper_users que NO tengan ya un registro en
-- keeper_user_assignments (idempotente).
-- ============================================================

-- 1. Preview: ver qué se va a insertar (ejecutar solo para verificar)
-- SELECT
--     ku.id AS keeper_user_id,
--     ku.display_name,
--     e.company       AS firm_id,
--     f.name          AS firm_name,
--     e.area_id,
--     ar.nombre       AS area_name,
--     e.position_id   AS cargo_id,
--     c.nombre        AS cargo_name
-- FROM keeper_users ku
-- JOIN employee e       ON e.id = ku.legacy_employee_id
-- LEFT JOIN firm f      ON f.id = e.company
-- LEFT JOIN areas ar    ON ar.id = e.area_id
-- LEFT JOIN cargos c    ON c.id = e.position_id
-- WHERE NOT EXISTS (
--     SELECT 1 FROM keeper_user_assignments kua
--     WHERE kua.keeper_user_id = ku.id
-- )
-- ORDER BY ku.id;

-- 2. Insertar asignaciones
INSERT INTO keeper_user_assignments (keeper_user_id, firm_id, area_id, cargo_id, assigned_by, assigned_at, updated_at)
SELECT
    ku.id,
    e.company,        -- employee.company = firm.id
    e.area_id,        -- employee.area_id = areas.id
    e.position_id,    -- employee.position_id = cargos.id
    NULL,             -- migración automática, sin admin asignado
    NOW(),
    NOW()
FROM keeper_users ku
JOIN employee e ON e.id = ku.legacy_employee_id
WHERE NOT EXISTS (
    SELECT 1 FROM keeper_user_assignments kua
    WHERE kua.keeper_user_id = ku.id
);

-- 3. Verificación: cuántos se insertaron
SELECT
    COUNT(*) AS total_assignments,
    COUNT(firm_id) AS con_firma,
    COUNT(area_id) AS con_area,
    COUNT(cargo_id) AS con_cargo
FROM keeper_user_assignments;

-- 4. Verificación detallada: primeros 10 registros con nombres resueltos
SELECT
    ku.display_name,
    ku.email,
    f.name AS firma,
    ar.nombre AS area,
    c.nombre AS cargo
FROM keeper_user_assignments kua
JOIN keeper_users ku ON ku.id = kua.keeper_user_id
LEFT JOIN firm f     ON f.id = kua.firm_id
LEFT JOIN areas ar   ON ar.id = kua.area_id
LEFT JOIN cargos c   ON c.id = kua.cargo_id
ORDER BY ku.display_name
LIMIT 10;
