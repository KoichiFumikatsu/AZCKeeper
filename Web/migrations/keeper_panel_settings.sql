-- ============================================================
-- MIGRACIÓN: keeper_panel_settings.sql
-- Configuración dinámica del panel admin (visibilidad de módulos, etc.)
-- Aplicar en: pipezafra_soporte_db
-- ============================================================

CREATE TABLE IF NOT EXISTS `keeper_panel_settings` (
  `id`            int NOT NULL AUTO_INCREMENT,
  `setting_key`   varchar(100) NOT NULL COMMENT 'Clave única del setting',
  `setting_value` json NOT NULL COMMENT 'Valor en formato JSON',
  `updated_by`    bigint DEFAULT NULL COMMENT 'keeper_admin_accounts.id de quien lo modificó',
  `updated_at`    timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_panel_setting_key` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Configuración dinámica del panel de control Keeper';

-- Seed: visibilidad de módulos por defecto
-- superadmin ve todo, admin ve operativo, viewer solo dashboard+usuarios
INSERT INTO `keeper_panel_settings` (`setting_key`, `setting_value`) VALUES
('menu_visibility', JSON_OBJECT(
  'dashboard',   JSON_ARRAY('superadmin', 'admin', 'viewer'),
  'users',       JSON_ARRAY('superadmin', 'admin', 'viewer'),
  'devices',     JSON_ARRAY('superadmin', 'admin'),
  'policies',    JSON_ARRAY('superadmin'),
  'releases',    JSON_ARRAY('superadmin'),
  'admin-users', JSON_ARRAY('superadmin'),
  'assignments', JSON_ARRAY('superadmin'),
  'settings',    JSON_ARRAY('superadmin')
))
ON DUPLICATE KEY UPDATE setting_key = setting_key;
