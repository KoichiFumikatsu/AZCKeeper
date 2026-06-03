-- ════════════════════════════════════════════
-- Migración: install-coverage — excepciones
-- Fecha: 2026-06-03
-- Agrega flag is_exempt para empleados que NO aplican Keeper
-- (gerentes, personal de servicio, contratistas externos, etc.)
-- También permite tener excepción SIN nota: note_text pasa a NULLABLE.
-- ════════════════════════════════════════════

ALTER TABLE `keeper_install_coverage_notes`
    MODIFY COLUMN `note_text` TEXT NULL,
    ADD COLUMN `is_exempt` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Empleado no aplica Keeper (gerencia, servicio, etc.)';
