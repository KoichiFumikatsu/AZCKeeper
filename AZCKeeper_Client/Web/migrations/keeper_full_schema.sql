-- ============================================================
-- keeper_full_schema.sql
-- Script completo para crear todas las tablas keeper_* desde cero
-- Compatible con MySQL 8.0+
-- 24 tablas en orden de dependencias FK
-- Incluye tablas organizacionales propias (keeper_firmas, keeper_areas, etc.)
-- Fecha: 2026-03-07
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ════════════════════════════════════════════
-- GRUPO 1: TABLAS SIN DEPENDENCIAS FK
-- ════════════════════════════════════════════

-- 1. keeper_module_catalog
CREATE TABLE IF NOT EXISTS `keeper_module_catalog` (
  `module_code`        varchar(64)  NOT NULL,
  `name`               varchar(190) NOT NULL,
  `default_enabled`    tinyint(1)   NOT NULL DEFAULT '0',
  `config_schema_json` json         DEFAULT NULL,
  `active`             tinyint(1)   NOT NULL DEFAULT '1',
  `created_at`         timestamp    NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`         timestamp    NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`module_code`),
  KEY `ix_keeper_module_active` (`active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 2. keeper_panel_roles
CREATE TABLE IF NOT EXISTS `keeper_panel_roles` (
  `id`              int          NOT NULL AUTO_INCREMENT,
  `slug`            varchar(50)  NOT NULL COMMENT 'Identificador único del rol',
  `label`           varchar(100) NOT NULL COMMENT 'Nombre visible',
  `description`     varchar(255) DEFAULT NULL COMMENT 'Descripción del rol',
  `hierarchy_level` int          NOT NULL DEFAULT '0' COMMENT 'Nivel jerárquico — mayor = más poder',
  `color_bg`        varchar(50)  NOT NULL DEFAULT 'bg-gray-100',
  `color_text`      varchar(50)  NOT NULL DEFAULT 'text-gray-700',
  `is_system`       tinyint(1)   NOT NULL DEFAULT '0' COMMENT '1 = rol del sistema',
  `permissions`     json         DEFAULT NULL COMMENT 'Permisos granulares por módulo',
  `created_at`      timestamp    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      timestamp    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_panel_role_slug` (`slug`),
  KEY `ix_panel_role_hierarchy` (`hierarchy_level`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Roles dinámicos del panel de control Keeper';

-- 3. keeper_panel_settings
CREATE TABLE IF NOT EXISTS `keeper_panel_settings` (
  `id`            int          NOT NULL AUTO_INCREMENT,
  `setting_key`   varchar(100) NOT NULL COMMENT 'Clave única del setting',
  `setting_value` json         NOT NULL COMMENT 'Valor en formato JSON',
  `updated_by`    bigint       DEFAULT NULL COMMENT 'keeper_admin_accounts.id de quien lo modificó',
  `updated_at`    timestamp    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_panel_setting_key` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Configuración dinámica del panel de control Keeper';

-- 4. keeper_client_releases
CREATE TABLE IF NOT EXISTS `keeper_client_releases` (
  `id`              int          NOT NULL AUTO_INCREMENT,
  `version`         varchar(50)  NOT NULL COMMENT 'Semantic version (e.g., 3.0.0.1)',
  `download_url`    varchar(500) NOT NULL COMMENT 'URL to download ZIP package',
  `file_size`       bigint       DEFAULT '0' COMMENT 'File size in bytes',
  `release_notes`   text         COMMENT 'Release notes in markdown',
  `is_beta`         tinyint(1)   DEFAULT '0',
  `force_update`    tinyint(1)   DEFAULT '0',
  `minimum_version` varchar(50)  DEFAULT NULL COMMENT 'Minimum required version',
  `is_active`       tinyint(1)   DEFAULT '1',
  `release_date`    date         DEFAULT NULL,
  `created_at`      timestamp    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      timestamp    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_version` (`version`),
  KEY `idx_is_active` (`is_active`),
  KEY `idx_is_beta` (`is_beta`),
  KEY `ix_releases_active_beta` (`is_active`, `is_beta`),
  KEY `ix_releases_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 5. keeper_sociedades
CREATE TABLE IF NOT EXISTS `keeper_sociedades` (
  `id`          bigint       NOT NULL AUTO_INCREMENT,
  `nombre`      varchar(255) NOT NULL,
  `nit`         varchar(50)  DEFAULT NULL COMMENT 'NIT o identificación fiscal',
  `descripcion` varchar(500) DEFAULT NULL,
  `activa`      tinyint(1)   NOT NULL DEFAULT 1,
  `created_at`  timestamp    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  timestamp    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_keeper_sociedad_nombre` (`nombre`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Sociedades/organizaciones a las que pertenecen los empleados';

-- 6. keeper_firmas
CREATE TABLE IF NOT EXISTS `keeper_firmas` (
  `id`             bigint       NOT NULL AUTO_INCREMENT,
  `nombre`         varchar(255) NOT NULL,
  `manager`        varchar(255) DEFAULT NULL,
  `mail_manager`   varchar(255) DEFAULT NULL,
  `descripcion`    varchar(500) DEFAULT NULL,
  `activa`         tinyint(1)   NOT NULL DEFAULT 1,
  `legacy_firm_id` int          DEFAULT NULL COMMENT 'Mapeo a firm.id legacy',
  `created_at`     timestamp    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     timestamp    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_keeper_firma_nombre` (`nombre`),
  UNIQUE KEY `uq_keeper_firma_legacy` (`legacy_firm_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Firmas/clientes (ej. bufetes de EE.UU.)';

-- 7. keeper_areas
CREATE TABLE IF NOT EXISTS `keeper_areas` (
  `id`              bigint       NOT NULL AUTO_INCREMENT,
  `nombre`          varchar(255) NOT NULL,
  `descripcion`     varchar(500) DEFAULT NULL,
  `padre_id`        bigint       DEFAULT NULL COMMENT 'Área padre (jerarquía)',
  `activa`          tinyint(1)   NOT NULL DEFAULT 1,
  `legacy_area_id`  int          DEFAULT NULL COMMENT 'Mapeo a areas.id legacy',
  `created_at`      timestamp    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      timestamp    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_keeper_area_nombre` (`nombre`),
  UNIQUE KEY `uq_keeper_area_legacy` (`legacy_area_id`),
  KEY `ix_keeper_area_padre` (`padre_id`),
  CONSTRAINT `fk_keeper_area_padre` FOREIGN KEY (`padre_id`) REFERENCES `keeper_areas`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Áreas organizacionales con jerarquía padre/hijo';

-- 8. keeper_cargos
CREATE TABLE IF NOT EXISTS `keeper_cargos` (
  `id`                bigint       NOT NULL AUTO_INCREMENT,
  `nombre`            varchar(255) NOT NULL,
  `descripcion`       varchar(500) DEFAULT NULL,
  `nivel_jerarquico`  int          NOT NULL DEFAULT 0 COMMENT '1=Director, 2=Coordinador, 3=Operativo, etc.',
  `activo`            tinyint(1)   NOT NULL DEFAULT 1,
  `legacy_cargo_id`   int          DEFAULT NULL COMMENT 'Mapeo a cargos.id legacy',
  `created_at`        timestamp    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`        timestamp    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_keeper_cargo_nombre` (`nombre`),
  UNIQUE KEY `uq_keeper_cargo_legacy` (`legacy_cargo_id`),
  KEY `ix_keeper_cargo_nivel` (`nivel_jerarquico`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Cargos con nivel jerárquico para visibilidad';

-- 9. keeper_sedes
CREATE TABLE IF NOT EXISTS `keeper_sedes` (
  `id`              bigint       NOT NULL AUTO_INCREMENT,
  `nombre`          varchar(255) NOT NULL,
  `codigo`          varchar(50)  DEFAULT NULL,
  `descripcion`     varchar(500) DEFAULT NULL,
  `activa`          tinyint(1)   NOT NULL DEFAULT 1,
  `legacy_sede_id`  int          DEFAULT NULL COMMENT 'Mapeo a sedes.id legacy',
  `created_at`      timestamp    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      timestamp    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_keeper_sede_nombre` (`nombre`),
  UNIQUE KEY `uq_keeper_sede_legacy` (`legacy_sede_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Sedes/ubicaciones físicas';

-- ════════════════════════════════════════════
-- GRUPO 2: keeper_users (tabla central)
-- ════════════════════════════════════════════

-- 10. keeper_users
CREATE TABLE IF NOT EXISTS `keeper_users` (
  `id`                 bigint       NOT NULL AUTO_INCREMENT,
  `legacy_employee_id` int          NOT NULL,
  `cc`                 varchar(20)  DEFAULT NULL,
  `email`              varchar(190) DEFAULT NULL,
  `display_name`       varchar(190) DEFAULT NULL,
  `status`             enum('active','inactive','locked') NOT NULL DEFAULT 'active',
  `password_hash`      varchar(255) DEFAULT NULL,
  `created_at`         timestamp    NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`         timestamp    NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_keeper_users_legacy` (`legacy_employee_id`),
  UNIQUE KEY `uk_keeper_users_cc` (`cc`),
  KEY `ix_keeper_users_email` (`email`),
  KEY `ix_keeper_users_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ════════════════════════════════════════════
-- GRUPO 3: TABLAS QUE DEPENDEN DE keeper_users
-- ════════════════════════════════════════════

-- 11. keeper_devices
CREATE TABLE IF NOT EXISTS `keeper_devices` (
  `id`             bigint       NOT NULL AUTO_INCREMENT,
  `user_id`        bigint       NOT NULL,
  `device_guid`    char(36)     NOT NULL,
  `device_name`    varchar(190) DEFAULT NULL,
  `client_version` varchar(20)  DEFAULT NULL,
  `serial_hint`    varchar(190) DEFAULT NULL,
  `status`         enum('active','revoked') NOT NULL DEFAULT 'active',
  `last_seen_at`   timestamp    NULL DEFAULT NULL,
  `created_at`     timestamp    NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     timestamp    NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_keeper_devices_guid` (`device_guid`),
  KEY `ix_keeper_devices_user` (`user_id`),
  KEY `ix_keeper_devices_last_seen` (`last_seen_at`),
  CONSTRAINT `fk_keeper_devices_user` FOREIGN KEY (`user_id`) REFERENCES `keeper_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 12. keeper_admin_accounts
CREATE TABLE IF NOT EXISTS `keeper_admin_accounts` (
  `id`                 bigint      NOT NULL AUTO_INCREMENT,
  `keeper_user_id`     bigint      NOT NULL COMMENT 'FK keeper_users.id',
  `panel_role`         varchar(50) NOT NULL DEFAULT 'viewer' COMMENT 'Slug del rol (FK lógica a keeper_panel_roles.slug)',
  `firm_scope_id`      bigint      DEFAULT NULL COMMENT 'FK keeper_firmas.id (NULL=todas)',
  `area_scope_id`      bigint      DEFAULT NULL COMMENT 'FK keeper_areas.id (NULL=todas)',
  `sede_scope_id`      bigint      DEFAULT NULL COMMENT 'FK keeper_sedes.id (NULL=todas)',
  `sociedad_scope_id`  bigint      DEFAULT NULL COMMENT 'FK keeper_sociedades.id (NULL=todas)',
  `is_active`          tinyint(1)  NOT NULL DEFAULT '1',
  `created_by`         bigint      DEFAULT NULL COMMENT 'keeper_admin_accounts.id de quien creó esta cuenta',
  `created_at`         timestamp   NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`         timestamp   NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `last_login_at`      timestamp   NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_keeper_admin_user` (`keeper_user_id`),
  KEY `ix_keeper_admin_role` (`panel_role`, `is_active`),
  KEY `ix_keeper_admin_firm` (`firm_scope_id`),
  KEY `ix_keeper_admin_sede` (`sede_scope_id`),
  KEY `ix_keeper_admin_area` (`area_scope_id`),
  KEY `ix_keeper_admin_sociedad` (`sociedad_scope_id`),
  CONSTRAINT `fk_keeper_admin_user` FOREIGN KEY (`keeper_user_id`) REFERENCES `keeper_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Cuentas con acceso al panel de control Keeper';

-- 13. keeper_work_schedules
CREATE TABLE IF NOT EXISTS `keeper_work_schedules` (
  `id`               bigint      NOT NULL AUTO_INCREMENT,
  `user_id`          bigint      DEFAULT NULL,
  `work_start_time`  time        DEFAULT '07:00:00',
  `work_end_time`    time        DEFAULT '19:00:00',
  `lunch_start_time` time        DEFAULT '12:00:00',
  `lunch_end_time`   time        DEFAULT '13:00:00',
  `applicable_days`  varchar(20) DEFAULT '1,2,3,4,5' COMMENT 'Días aplicables CSV (0=Dom..6=Sáb)',
  `timezone`         varchar(50) DEFAULT 'America/Bogota',
  `is_active`        tinyint(1)  DEFAULT '1',
  `created_at`       timestamp   NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_user_schedule` (`user_id`, `is_active`),
  KEY `ix_keeper_ws_active` (`is_active`),
  CONSTRAINT `keeper_work_schedules_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `keeper_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- 14. keeper_user_assignments
CREATE TABLE IF NOT EXISTS `keeper_user_assignments` (
  `id`              bigint     NOT NULL AUTO_INCREMENT,
  `keeper_user_id`  bigint     NOT NULL COMMENT 'FK keeper_users.id',
  `sociedad_id`     bigint     DEFAULT NULL COMMENT 'FK keeper_sociedades.id',
  `firm_id`         bigint     DEFAULT NULL COMMENT 'FK keeper_firmas.id',
  `area_id`         bigint     DEFAULT NULL COMMENT 'FK keeper_areas.id',
  `cargo_id`        bigint     DEFAULT NULL COMMENT 'FK keeper_cargos.id',
  `sede_id`         bigint     DEFAULT NULL COMMENT 'FK keeper_sedes.id',
  `manual_override` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'Si 1, el sync legacy no sobreescribe',
  `assigned_by`     bigint     DEFAULT NULL COMMENT 'keeper_admin_accounts.id de quien asignó',
  `assigned_at`     timestamp  NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      timestamp  NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_keeper_assignment_user` (`keeper_user_id`),
  KEY `ix_keeper_assignment_firm` (`firm_id`),
  KEY `ix_keeper_assignment_area` (`area_id`),
  KEY `ix_keeper_assignment_sede` (`sede_id`),
  KEY `ix_keeper_assignment_sociedad` (`sociedad_id`),
  CONSTRAINT `fk_keeper_assignment_user` FOREIGN KEY (`keeper_user_id`) REFERENCES `keeper_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Sociedad/firma/área/cargo/sede asignados a cada usuario Keeper';

-- ════════════════════════════════════════════
-- GRUPO 4: TABLAS QUE DEPENDEN DE keeper_users + keeper_devices
-- ════════════════════════════════════════════

-- 15. keeper_admin_sessions
CREATE TABLE IF NOT EXISTS `keeper_admin_sessions` (
  `id`         bigint       NOT NULL AUTO_INCREMENT,
  `admin_id`   bigint       NOT NULL COMMENT 'FK keeper_admin_accounts.id',
  `token_hash` char(64)     NOT NULL COMMENT 'SHA-256 del token de cookie',
  `ip`         varchar(64)  DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `created_at` timestamp    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `expires_at` timestamp    NOT NULL COMMENT 'Sesión expira aquí',
  `revoked_at` timestamp    NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_keeper_admin_session_token` (`token_hash`),
  KEY `ix_keeper_admin_session_admin` (`admin_id`, `expires_at`),
  CONSTRAINT `fk_keeper_admin_session` FOREIGN KEY (`admin_id`) REFERENCES `keeper_admin_accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Sesiones activas del panel de control';

-- 16. keeper_sessions
CREATE TABLE IF NOT EXISTS `keeper_sessions` (
  `id`           bigint       NOT NULL AUTO_INCREMENT,
  `user_id`      bigint       NOT NULL,
  `device_id`    bigint       DEFAULT NULL,
  `token_hash`   char(64)     NOT NULL,
  `refresh_hash` char(64)     DEFAULT NULL,
  `issued_at`    timestamp    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `expires_at`   timestamp    NULL DEFAULT NULL,
  `revoked_at`   timestamp    NULL DEFAULT NULL,
  `ip`           varchar(64)  DEFAULT NULL,
  `user_agent`   varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_keeper_sessions_tokenhash` (`token_hash`),
  KEY `ix_keeper_sessions_user` (`user_id`),
  KEY `ix_keeper_sessions_device` (`device_id`),
  KEY `ix_keeper_sessions_expires` (`expires_at`),
  CONSTRAINT `fk_keeper_sessions_user` FOREIGN KEY (`user_id`) REFERENCES `keeper_users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_keeper_sessions_device` FOREIGN KEY (`device_id`) REFERENCES `keeper_devices` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 17. keeper_policy_assignments
CREATE TABLE IF NOT EXISTS `keeper_policy_assignments` (
  `id`          bigint                          NOT NULL AUTO_INCREMENT,
  `scope`       enum('global','user','device')  NOT NULL,
  `user_id`     bigint                          DEFAULT NULL,
  `device_id`   bigint                          DEFAULT NULL,
  `version`     int                             NOT NULL DEFAULT '1',
  `priority`    int                             NOT NULL DEFAULT '100',
  `is_active`   tinyint(1)                      NOT NULL DEFAULT '1',
  `policy_json` json                            NOT NULL,
  `created_at`  timestamp                       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_keeper_policy_scope_active` (`scope`, `is_active`, `priority`),
  KEY `ix_keeper_policy_user_active` (`user_id`, `is_active`, `priority`),
  KEY `ix_keeper_policy_device_active` (`device_id`, `is_active`, `priority`),
  CONSTRAINT `fk_keeper_policy_user` FOREIGN KEY (`user_id`) REFERENCES `keeper_users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_keeper_policy_device` FOREIGN KEY (`device_id`) REFERENCES `keeper_devices` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 18. keeper_activity_day
CREATE TABLE IF NOT EXISTS `keeper_activity_day` (
  `id`                          bigint        NOT NULL AUTO_INCREMENT,
  `user_id`                     bigint        NOT NULL,
  `device_id`                   bigint        NOT NULL,
  `day_date`                    date          NOT NULL,
  `tz_offset_minutes`           smallint      NOT NULL DEFAULT '-300',
  `active_seconds`              int           NOT NULL DEFAULT '0',
  `work_hours_active_seconds`   decimal(12,3) DEFAULT '0.000',
  `work_hours_idle_seconds`     decimal(12,3) DEFAULT '0.000',
  `lunch_active_seconds`        decimal(12,3) DEFAULT '0.000',
  `lunch_idle_seconds`          decimal(12,3) DEFAULT '0.000',
  `after_hours_active_seconds`  decimal(12,3) DEFAULT '0.000',
  `after_hours_idle_seconds`    decimal(12,3) DEFAULT '0.000',
  `is_workday`                  tinyint(1)    NOT NULL DEFAULT '1' COMMENT '1=día laborable, 0=fin de semana',
  `idle_seconds`                int           NOT NULL DEFAULT '0',
  `call_seconds`                int           NOT NULL DEFAULT '0',
  `samples_count`               int           NOT NULL DEFAULT '0',
  `first_event_at`              datetime      DEFAULT NULL,
  `last_event_at`               datetime      DEFAULT NULL,
  `payload_json`                json          DEFAULT NULL,
  `created_at`                  timestamp     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`                  timestamp     NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_keeper_activity_unique` (`user_id`, `device_id`, `day_date`),
  KEY `ix_keeper_activity_day` (`day_date`),
  KEY `ix_keeper_activity_user_day` (`user_id`, `day_date`),
  KEY `fk_keeper_activity_device` (`device_id`),
  KEY `idx_is_workday` (`is_workday`),
  KEY `idx_activity_device_day` (`device_id`, `day_date`),
  KEY `idx_activity_user_day_event` (`user_id`, `day_date`, `last_event_at`),
  CONSTRAINT `fk_keeper_activity_user` FOREIGN KEY (`user_id`) REFERENCES `keeper_users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_keeper_activity_device` FOREIGN KEY (`device_id`) REFERENCES `keeper_devices` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 19. keeper_daily_metrics
CREATE TABLE IF NOT EXISTS `keeper_daily_metrics` (
  `id`           bigint       NOT NULL AUTO_INCREMENT,
  `user_id`      bigint       NOT NULL,
  `device_id`    bigint       NOT NULL,
  `day_date`     date         NOT NULL,
  `metric_key`   varchar(120) NOT NULL,
  `metric_value` bigint       NOT NULL DEFAULT '0',
  `meta_json`    json         DEFAULT NULL,
  `created_at`   timestamp    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_keeper_daily_metrics_unique` (`user_id`, `device_id`, `day_date`, `metric_key`),
  KEY `ix_keeper_daily_metrics_user_day` (`user_id`, `day_date`),
  KEY `ix_keeper_daily_metrics_key_day` (`metric_key`, `day_date`),
  KEY `fk_keeper_daily_metrics_device` (`device_id`),
  CONSTRAINT `fk_keeper_daily_metrics_user` FOREIGN KEY (`user_id`) REFERENCES `keeper_users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_keeper_daily_metrics_device` FOREIGN KEY (`device_id`) REFERENCES `keeper_devices` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 20. keeper_events
CREATE TABLE IF NOT EXISTS `keeper_events` (
  `id`               bigint       NOT NULL AUTO_INCREMENT,
  `user_id`          bigint       NOT NULL,
  `device_id`        bigint       NOT NULL,
  `module_code`      varchar(64)  NOT NULL,
  `event_type`       varchar(100) NOT NULL,
  `start_at`         datetime     DEFAULT NULL,
  `end_at`           datetime     DEFAULT NULL,
  `duration_seconds` int          DEFAULT NULL,
  `numeric_1`        bigint       DEFAULT NULL,
  `numeric_2`        bigint       DEFAULT NULL,
  `numeric_3`        bigint       DEFAULT NULL,
  `numeric_4`        bigint       DEFAULT NULL,
  `text_1`           varchar(190) DEFAULT NULL,
  `text_2`           varchar(190) DEFAULT NULL,
  `payload_json`     json         DEFAULT NULL,
  `day_date`         date         NOT NULL,
  `created_at`       timestamp    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_keeper_events_user_day` (`user_id`, `day_date`),
  KEY `ix_keeper_events_device_day` (`device_id`, `day_date`),
  KEY `ix_keeper_events_type` (`event_type`, `day_date`),
  KEY `ix_keeper_events_module` (`module_code`, `day_date`),
  CONSTRAINT `fk_keeper_events_user` FOREIGN KEY (`user_id`) REFERENCES `keeper_users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_keeper_events_device` FOREIGN KEY (`device_id`) REFERENCES `keeper_devices` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 21. keeper_window_episode
CREATE TABLE IF NOT EXISTS `keeper_window_episode` (
  `id`               bigint       NOT NULL AUTO_INCREMENT,
  `user_id`          bigint       NOT NULL,
  `device_id`        bigint       NOT NULL,
  `start_at`         datetime     NOT NULL,
  `end_at`           datetime     NOT NULL,
  `duration_seconds` int          NOT NULL,
  `process_name`     varchar(190) DEFAULT NULL,
  `app_name`         varchar(190) DEFAULT NULL,
  `window_title`     varchar(512) DEFAULT NULL,
  `is_in_call`       tinyint(1)   NOT NULL DEFAULT '0',
  `call_app_hint`    varchar(190) DEFAULT NULL,
  `day_date`         date         NOT NULL,
  `created_at`       timestamp    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_keeper_we_user_day` (`user_id`, `day_date`),
  KEY `ix_keeper_we_device_day` (`device_id`, `day_date`),
  KEY `ix_keeper_we_start` (`start_at`),
  KEY `ix_keeper_we_process` (`process_name`),
  KEY `ix_keeper_we_user_day_process` (`user_id`, `day_date`, `process_name`),
  CONSTRAINT `fk_keeper_we_user` FOREIGN KEY (`user_id`) REFERENCES `keeper_users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_keeper_we_device` FOREIGN KEY (`device_id`) REFERENCES `keeper_devices` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 22. keeper_device_locks
CREATE TABLE IF NOT EXISTS `keeper_device_locks` (
  `id`                 bigint       NOT NULL AUTO_INCREMENT,
  `device_id`          bigint       NOT NULL,
  `user_id`            bigint       NOT NULL,
  `locked_by_admin_id` bigint       DEFAULT NULL,
  `lock_reason`        varchar(500) DEFAULT NULL,
  `locked_at`          timestamp    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `unlocked_at`        timestamp    NULL DEFAULT NULL,
  `unlock_pin_hash`    char(64)     DEFAULT NULL,
  `is_active`          tinyint(1)   NOT NULL DEFAULT '1',
  PRIMARY KEY (`id`),
  KEY `ix_device_locks_device` (`device_id`, `is_active`),
  KEY `ix_device_locks_user` (`user_id`, `is_active`),
  KEY `ix_device_locks_admin` (`locked_by_admin_id`),
  CONSTRAINT `fk_device_locks_device` FOREIGN KEY (`device_id`) REFERENCES `keeper_devices` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_device_locks_user` FOREIGN KEY (`user_id`) REFERENCES `keeper_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 23. keeper_audit_log
CREATE TABLE IF NOT EXISTS `keeper_audit_log` (
  `id`         bigint       NOT NULL AUTO_INCREMENT,
  `user_id`    bigint       DEFAULT NULL,
  `device_id`  bigint       DEFAULT NULL,
  `event_type` varchar(100) NOT NULL,
  `message`    varchar(512) DEFAULT NULL,
  `meta_json`  json         DEFAULT NULL,
  `created_at` timestamp    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_keeper_audit_user` (`user_id`, `created_at`),
  KEY `ix_keeper_audit_device` (`device_id`, `created_at`),
  KEY `ix_keeper_audit_type` (`event_type`, `created_at`),
  CONSTRAINT `fk_keeper_audit_user` FOREIGN KEY (`user_id`) REFERENCES `keeper_users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_keeper_audit_device` FOREIGN KEY (`device_id`) REFERENCES `keeper_devices` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 24. keeper_handshake_log
CREATE TABLE IF NOT EXISTS `keeper_handshake_log` (
  `id`             bigint      NOT NULL AUTO_INCREMENT,
  `user_id`        bigint      NOT NULL,
  `device_id`      bigint      NOT NULL,
  `client_version` varchar(50) DEFAULT NULL,
  `request_json`   json        DEFAULT NULL,
  `response_json`  json        DEFAULT NULL,
  `created_at`     timestamp   NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_keeper_handshake_user` (`user_id`, `created_at`),
  KEY `ix_keeper_handshake_device` (`device_id`, `created_at`),
  CONSTRAINT `fk_keeper_handshake_user` FOREIGN KEY (`user_id`) REFERENCES `keeper_users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_keeper_handshake_device` FOREIGN KEY (`device_id`) REFERENCES `keeper_devices` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- ════════════════════════════════════════════
-- DATOS INICIALES (semillas)
-- ════════════════════════════════════════════

-- Roles del panel
INSERT IGNORE INTO `keeper_panel_roles` (`id`, `slug`, `label`, `description`, `hierarchy_level`, `color_bg`, `color_text`, `is_system`, `permissions`) VALUES
(1, 'superadmin', 'Super Admin',  'Control total del sistema',      100, 'bg-red-100',    'text-red-700',    1, NULL),
(2, 'admin',      'Administrador','Gestión completa de usuarios',    80, 'bg-orange-100', 'text-orange-700', 1, NULL),
(3, 'manager',    'Manager',      'Supervisión de equipos',          60, 'bg-blue-100',   'text-blue-700',   0, NULL),
(4, 'viewer',     'Visor',        'Solo lectura',                    20, 'bg-gray-100',   'text-gray-700',   0, NULL);

-- Módulos base
INSERT IGNORE INTO `keeper_module_catalog` (`module_code`, `name`, `default_enabled`, `active`) VALUES
('keyboard',   'Keyboard Tracking',  1, 1),
('mouse',      'Mouse Tracking',     1, 1),
('window',     'Window Tracking',    1, 1),
('screenshot', 'Screenshots',        0, 1),
('blocker',    'Key Blocker',        0, 1);
