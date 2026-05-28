-- ============================================================
-- MIGRACIÓN: keeper_admin_auth.sql
-- Sistema de autenticación del panel de control Keeper
-- Aplicar en: pipezafra_soporte_db
-- ============================================================

-- 1. Tabla de cuentas de administradores del panel
--    Vinculada a keeper_users (que ya tiene password_hash).
--    Un keeper_user puede o no tener acceso al panel — esta tabla lo controla.
CREATE TABLE IF NOT EXISTS `keeper_admin_accounts` (
  `id`              bigint NOT NULL AUTO_INCREMENT,
  `keeper_user_id`  bigint NOT NULL COMMENT 'FK keeper_users.id',
  `panel_role`      ENUM('superadmin','admin','viewer') NOT NULL DEFAULT 'viewer'
                    COMMENT 'superadmin=todo, admin=su scope, viewer=solo lectura',
  -- Scope de visibilidad: NULL = sin restricción
  `firm_scope_id`   int DEFAULT NULL COMMENT 'Solo ve empleados de esta firma (NULL=todas)',
  `area_scope_id`   int DEFAULT NULL COMMENT 'Solo ve empleados de esta área (NULL=todas las de la firma)',
  `is_active`       tinyint(1) NOT NULL DEFAULT 1,
  `created_by`      bigint DEFAULT NULL COMMENT 'keeper_admin_accounts.id de quien creó esta cuenta',
  `created_at`      timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `last_login_at`   timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_keeper_admin_user` (`keeper_user_id`),
  KEY `ix_keeper_admin_role` (`panel_role`, `is_active`),
  KEY `ix_keeper_admin_firm` (`firm_scope_id`),
  CONSTRAINT `fk_keeper_admin_user` FOREIGN KEY (`keeper_user_id`) REFERENCES `keeper_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Cuentas con acceso al panel de control Keeper';

-- 2. Sesiones del panel (separadas de keeper_sessions que usa el cliente C#)
CREATE TABLE IF NOT EXISTS `keeper_admin_sessions` (
  `id`              bigint NOT NULL AUTO_INCREMENT,
  `admin_id`        bigint NOT NULL COMMENT 'FK keeper_admin_accounts.id',
  `token_hash`      char(64) NOT NULL COMMENT 'SHA-256 del token de cookie',
  `ip`              varchar(64) DEFAULT NULL,
  `user_agent`      varchar(255) DEFAULT NULL,
  `created_at`      timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `expires_at`      timestamp NOT NULL COMMENT 'Sesión expira aquí',
  `revoked_at`      timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_keeper_admin_session_token` (`token_hash`),
  KEY `ix_keeper_admin_session_admin` (`admin_id`, `expires_at`),
  CONSTRAINT `fk_keeper_admin_session` FOREIGN KEY (`admin_id`) REFERENCES `keeper_admin_accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Sesiones activas del panel de control';

-- 3. Asignaciones de firma/área/cargo para empleados en Keeper
--    Permite asignar en batch. Un keeper_user puede tener 1 asignación activa.
CREATE TABLE IF NOT EXISTS `keeper_user_assignments` (
  `id`              bigint NOT NULL AUTO_INCREMENT,
  `keeper_user_id`  bigint NOT NULL COMMENT 'FK keeper_users.id',
  `firm_id`         int DEFAULT NULL COMMENT 'FK legacy firm.id',
  `area_id`         int DEFAULT NULL COMMENT 'FK legacy areas.id',
  `cargo_id`        int DEFAULT NULL COMMENT 'FK legacy cargos.id',
  `assigned_by`     bigint DEFAULT NULL COMMENT 'keeper_admin_accounts.id de quien asignó',
  `assigned_at`     timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_keeper_assignment_user` (`keeper_user_id`),
  KEY `ix_keeper_assignment_firm` (`firm_id`),
  KEY `ix_keeper_assignment_area` (`area_id`),
  CONSTRAINT `fk_keeper_assignment_user` FOREIGN KEY (`keeper_user_id`) REFERENCES `keeper_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Firma/área/cargo asignados a cada usuario Keeper (para filtros del panel)';

-- ============================================================
-- SEED: Koichi Fumikatsu como superadmin
-- keeper_users.id = 1, legacy_employee_id = 30
-- ============================================================
INSERT IGNORE INTO `keeper_admin_accounts`
  (`keeper_user_id`, `panel_role`, `firm_scope_id`, `area_scope_id`, `is_active`, `created_by`)
VALUES
  (1, 'superadmin', NULL, NULL, 1, NULL);
