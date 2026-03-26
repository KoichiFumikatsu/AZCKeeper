-- MIGRACIÓN: add_manual_override_assignments.sql
-- Agrega flag manual_override a keeper_user_assignments.
-- Cuando manual_override = 1, LegacySyncService NO sobreescribe el registro.
-- Keeper pasa a ser fuente de verdad para ese usuario.

ALTER TABLE `keeper_user_assignments`
    ADD COLUMN `manual_override` TINYINT(1) NOT NULL DEFAULT 0
    COMMENT 'Si 1, el sync legacy no sobreescribe esta asignación'
    AFTER `cargo_id`;
