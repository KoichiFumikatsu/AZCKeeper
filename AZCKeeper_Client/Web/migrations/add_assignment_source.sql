-- ============================================================
-- add_assignment_source.sql — precedencia de fuentes en keeper_user_assignments
-- (K3-ADP-07c, integración con MOAZC/One).
--
-- `source`: quién fijó la asignación vigente.
--   legacy → sincronizada desde la BD legacy (login / panel), como hasta hoy.
--   panel  → editada en el panel (coincide con manual_override = 1: la excepción manual).
--   one    → aplicada desde One por /api/external/assignments. El sincronizador
--            legacy NO la revierte mientras la fuente sea One; la excepción manual
--            (manual_override) manda sobre ambas y se conserva tal cual.
-- `source_version`: intención idempotente del origen (One manda intent_version).
-- ============================================================
ALTER TABLE `keeper_user_assignments`
    ADD COLUMN `source` ENUM('legacy','panel','one') NOT NULL DEFAULT 'legacy'
        COMMENT 'Fuente de la asignación vigente (legacy | panel | one)' AFTER `manual_override`,
    ADD COLUMN `source_version` VARCHAR(64) DEFAULT NULL
        COMMENT 'Versión/intención idempotente del origen (intent_version de One)' AFTER `source`,
    ADD COLUMN `source_applied_at` TIMESTAMP NULL DEFAULT NULL
        COMMENT 'Cuándo aplicó el origen externo' AFTER `source_version`,
    ADD KEY `ix_keeper_assignment_source` (`source`),
    ADD KEY `ix_keeper_assignment_updated` (`updated_at`);
