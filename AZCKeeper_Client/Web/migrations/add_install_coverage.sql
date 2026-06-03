-- ════════════════════════════════════════════
-- Migración: install-coverage
-- Fecha: 2026-06-03
-- Página /admin/install-coverage.php — checklist de empleados activos
-- en BD legacy que no tienen instalación correcta de Keeper.
-- ════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS `keeper_install_coverage_notes` (
    `id`                 BIGINT       NOT NULL AUTO_INCREMENT,
    `legacy_employee_id` INT          NOT NULL,
    `note_text`          TEXT         NOT NULL,
    `created_at`         TIMESTAMP    NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`         TIMESTAMP    NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `updated_by`         BIGINT       DEFAULT NULL COMMENT 'FK lógica keeper_admin_accounts.id',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_install_cov_employee` (`legacy_employee_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Setting del umbral de heartbeat (días). Default 7.
INSERT IGNORE INTO `keeper_panel_settings` (`setting_key`, `setting_value`, `updated_at`)
VALUES ('install_coverage_heartbeat_days', '7', NOW());
