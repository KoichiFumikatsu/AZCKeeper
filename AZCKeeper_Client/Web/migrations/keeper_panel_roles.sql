-- ============================================================
-- MIGRACIÓN: keeper_panel_roles.sql
-- Sistema de roles dinámicos del panel admin
-- Aplicar en: pipezafra_soporte_db
-- ORDEN: después de keeper_admin_auth.sql y keeper_panel_settings.sql
-- ============================================================

-- 1. Tabla de roles del panel
CREATE TABLE IF NOT EXISTS `keeper_panel_roles` (
  `id`              int NOT NULL AUTO_INCREMENT,
  `slug`            varchar(50) NOT NULL COMMENT 'Identificador único del rol (se usa en panel_role)',
  `label`           varchar(100) NOT NULL COMMENT 'Nombre visible',
  `description`     varchar(255) DEFAULT NULL COMMENT 'Descripción del rol',
  `hierarchy_level` int NOT NULL DEFAULT 0 COMMENT 'Nivel jerárquico — mayor = más poder',
  `color_bg`        varchar(50) NOT NULL DEFAULT 'bg-gray-100' COMMENT 'Clase Tailwind para fondo del badge',
  `color_text`      varchar(50) NOT NULL DEFAULT 'text-gray-700' COMMENT 'Clase Tailwind para texto del badge',
  `is_system`       tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 = rol del sistema, no se puede borrar ni cambiar slug',
  `permissions`     json DEFAULT NULL COMMENT 'Permisos granulares por módulo: {"users":{"can_edit":true,...}, ...}',
  `created_at`      timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_panel_role_slug` (`slug`),
  KEY `ix_panel_role_hierarchy` (`hierarchy_level`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Roles dinámicos del panel de control Keeper';

-- 2. Seed: roles del sistema (los 3 originales)
INSERT INTO `keeper_panel_roles` (`slug`, `label`, `description`, `hierarchy_level`, `color_bg`, `color_text`, `is_system`, `permissions`) VALUES
('superadmin', 'Superadmin', 'Acceso total al sistema. No puede ser restringido.', 100, 'bg-red-100', 'text-red-800', 1,
  JSON_OBJECT(
    'users',       JSON_OBJECT('can_view', true, 'can_edit', true, 'can_delete', true, 'can_export', true),
    'devices',     JSON_OBJECT('can_view', true, 'can_edit', true, 'can_delete', true),
    'policies',    JSON_OBJECT('can_view', true, 'can_create', true, 'can_edit', true, 'can_delete', true, 'can_force_push', true),
    'releases',    JSON_OBJECT('can_view', true, 'can_create', true, 'can_edit', true, 'can_delete', true),
    'admin-users', JSON_OBJECT('can_view', true, 'can_create', true, 'can_edit', true, 'can_delete', true),
    'assignments', JSON_OBJECT('can_view', true, 'can_edit', true),
    'settings',    JSON_OBJECT('can_view', true, 'can_edit', true),
    'roles',       JSON_OBJECT('can_view', true, 'can_create', true, 'can_edit', true, 'can_delete', true)
  )
),
('admin', 'Admin', 'Gestión operativa dentro de su scope de firma/área.', 50, 'bg-blue-100', 'text-blue-800', 1,
  JSON_OBJECT(
    'users',       JSON_OBJECT('can_view', true, 'can_edit', true, 'can_delete', false, 'can_export', true),
    'devices',     JSON_OBJECT('can_view', true, 'can_edit', true, 'can_delete', false),
    'policies',    JSON_OBJECT('can_view', false, 'can_create', false, 'can_edit', false, 'can_delete', false, 'can_force_push', false),
    'releases',    JSON_OBJECT('can_view', false, 'can_create', false, 'can_edit', false, 'can_delete', false),
    'admin-users', JSON_OBJECT('can_view', false, 'can_create', false, 'can_edit', false, 'can_delete', false),
    'assignments', JSON_OBJECT('can_view', false, 'can_edit', false),
    'settings',    JSON_OBJECT('can_view', false, 'can_edit', false),
    'roles',       JSON_OBJECT('can_view', false, 'can_create', false, 'can_edit', false, 'can_delete', false)
  )
),
('viewer', 'Viewer', 'Solo lectura. Ve datos pero no puede modificar nada.', 10, 'bg-gray-100', 'text-gray-600', 1,
  JSON_OBJECT(
    'users',       JSON_OBJECT('can_view', true, 'can_edit', false, 'can_delete', false, 'can_export', false),
    'devices',     JSON_OBJECT('can_view', true, 'can_edit', false, 'can_delete', false),
    'policies',    JSON_OBJECT('can_view', false, 'can_create', false, 'can_edit', false, 'can_delete', false, 'can_force_push', false),
    'releases',    JSON_OBJECT('can_view', false, 'can_create', false, 'can_edit', false, 'can_delete', false),
    'admin-users', JSON_OBJECT('can_view', false, 'can_create', false, 'can_edit', false, 'can_delete', false),
    'assignments', JSON_OBJECT('can_view', false, 'can_edit', false),
    'settings',    JSON_OBJECT('can_view', false, 'can_edit', false),
    'roles',       JSON_OBJECT('can_view', false, 'can_create', false, 'can_edit', false, 'can_delete', false)
  )
)
ON DUPLICATE KEY UPDATE slug = slug;

-- 3. ALTER: cambiar panel_role de ENUM a VARCHAR para soportar roles custom
--    Esto permite insertar cualquier slug de keeper_panel_roles como rol.
ALTER TABLE `keeper_admin_accounts`
  MODIFY COLUMN `panel_role` varchar(50) NOT NULL DEFAULT 'viewer'
  COMMENT 'Slug del rol (FK lógica a keeper_panel_roles.slug)';
