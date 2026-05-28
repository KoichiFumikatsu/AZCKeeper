-- ============================================================
-- MIGRACIÓN: add_sede_support.sql
-- Agrega soporte de sedes a keeper_user_assignments y
-- keeper_admin_accounts para filtrado por sede.
-- Aplicar en: pipezafra_soporte_db
-- ============================================================

-- 1. Agregar sede_id a keeper_user_assignments
ALTER TABLE `keeper_user_assignments`
  ADD COLUMN `sede_id` INT DEFAULT NULL COMMENT 'FK sedes.id' AFTER `cargo_id`,
  ADD KEY `ix_keeper_assignment_sede` (`sede_id`);

-- 2. Agregar sede_scope_id a keeper_admin_accounts
ALTER TABLE `keeper_admin_accounts`
  ADD COLUMN `sede_scope_id` INT DEFAULT NULL COMMENT 'Solo ve empleados de esta sede (NULL=todas)' AFTER `area_scope_id`,
  ADD KEY `ix_keeper_admin_sede` (`sede_scope_id`);

-- 3. Poblar sede_id desde employee para los assignments existentes
UPDATE `keeper_user_assignments` kua
JOIN `keeper_users` ku ON ku.id = kua.keeper_user_id
JOIN `employee` e ON e.id = ku.legacy_employee_id
SET kua.sede_id = e.sede_id
WHERE kua.sede_id IS NULL AND e.sede_id IS NOT NULL;
