-- ============================================================================
-- Script de creación de tablas para AZCKeeper
-- ============================================================================
-- Este script contiene todas las sentencias necesarias para crear las tablas
-- del sistema AZCKeeper en una nueva base de datos.
--
-- Fecha de generación: 2026-02-06
-- Versión: 1.0
--
-- INSTRUCCIONES:
-- 1. Crear una nueva base de datos (o usar una existente)
-- 2. Ejecutar este script completo en orden
-- 3. Todas las tablas, índices y relaciones serán creadas automáticamente
-- ============================================================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

-- ============================================================================
-- SECCIÓN 1: CREACIÓN DE TABLAS
-- ============================================================================

-- ----------------------------------------------------------------------------
-- Tabla: keeper_users
-- Descripción: Almacena información de usuarios del sistema Keeper
-- ----------------------------------------------------------------------------
CREATE TABLE `keeper_users` (
  `id` bigint NOT NULL,
  `legacy_employee_id` int NOT NULL,
  `cc` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `email` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `display_name` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `status` enum('active','inactive','locked') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'active',
  `password_hash` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ----------------------------------------------------------------------------
-- Tabla: keeper_devices
-- Descripción: Dispositivos registrados por cada usuario
-- ----------------------------------------------------------------------------
CREATE TABLE `keeper_devices` (
  `id` bigint NOT NULL,
  `user_id` bigint NOT NULL,
  `device_guid` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `device_name` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `serial_hint` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `status` enum('active','revoked') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'active',
  `last_seen_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ----------------------------------------------------------------------------
-- Tabla: keeper_activity_day
-- Descripción: Resumen diario de actividad por usuario y dispositivo
-- ----------------------------------------------------------------------------
CREATE TABLE `keeper_activity_day` (
  `id` bigint NOT NULL,
  `user_id` bigint NOT NULL,
  `device_id` bigint NOT NULL,
  `day_date` date NOT NULL,
  `tz_offset_minutes` smallint NOT NULL DEFAULT '-300',
  `active_seconds` int NOT NULL DEFAULT '0',
  `work_hours_active_seconds` decimal(12,3) DEFAULT '0.000',
  `work_hours_idle_seconds` decimal(12,3) DEFAULT '0.000',
  `lunch_active_seconds` decimal(12,3) DEFAULT '0.000',
  `lunch_idle_seconds` decimal(12,3) DEFAULT '0.000',
  `after_hours_active_seconds` decimal(12,3) DEFAULT '0.000',
  `after_hours_idle_seconds` decimal(12,3) DEFAULT '0.000',
  `is_workday` tinyint(1) NOT NULL DEFAULT '1' COMMENT '1=día laborable (lun-vie), 0=fin de semana (sáb-dom)',
  `idle_seconds` int NOT NULL DEFAULT '0',
  `call_seconds` int NOT NULL DEFAULT '0',
  `samples_count` int NOT NULL DEFAULT '0',
  `first_event_at` datetime DEFAULT NULL,
  `last_event_at` datetime DEFAULT NULL,
  `payload_json` json DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ----------------------------------------------------------------------------
-- Tabla: keeper_audit_log
-- Descripción: Registro de auditoría de eventos del sistema
-- ----------------------------------------------------------------------------
CREATE TABLE `keeper_audit_log` (
  `id` bigint NOT NULL,
  `user_id` bigint DEFAULT NULL,
  `device_id` bigint DEFAULT NULL,
  `event_type` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `message` varchar(512) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `meta_json` json DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ----------------------------------------------------------------------------
-- Tabla: keeper_client_releases
-- Descripción: Versiones del cliente Keeper disponibles para actualización
-- ----------------------------------------------------------------------------
CREATE TABLE `keeper_client_releases` (
  `id` int NOT NULL,
  `version` varchar(50) COLLATE utf8mb4_general_ci NOT NULL COMMENT 'Semantic version (e.g., 3.0.0.1)',
  `download_url` varchar(500) COLLATE utf8mb4_general_ci NOT NULL COMMENT 'URL to download ZIP package',
  `file_size` bigint DEFAULT '0' COMMENT 'File size in bytes',
  `release_notes` text COLLATE utf8mb4_general_ci COMMENT 'Release notes in markdown',
  `is_beta` tinyint(1) DEFAULT '0' COMMENT 'Is this a beta version?',
  `force_update` tinyint(1) DEFAULT '0' COMMENT 'Force clients to update immediately',
  `minimum_version` varchar(50) COLLATE utf8mb4_general_ci DEFAULT NULL COMMENT 'Minimum required version (older = critical)',
  `is_active` tinyint(1) DEFAULT '1' COMMENT 'Is this release available?',
  `release_date` date DEFAULT NULL COMMENT 'Official release date',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ----------------------------------------------------------------------------
-- Tabla: keeper_daily_metrics
-- Descripción: Métricas diarias personalizadas por usuario y dispositivo
-- ----------------------------------------------------------------------------
CREATE TABLE `keeper_daily_metrics` (
  `id` bigint NOT NULL,
  `user_id` bigint NOT NULL,
  `device_id` bigint NOT NULL,
  `day_date` date NOT NULL,
  `metric_key` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `metric_value` bigint NOT NULL DEFAULT '0',
  `meta_json` json DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ----------------------------------------------------------------------------
-- Tabla: keeper_device_locks
-- Descripción: Registro de bloqueos de dispositivos
-- ----------------------------------------------------------------------------
CREATE TABLE `keeper_device_locks` (
  `id` bigint NOT NULL,
  `device_id` bigint NOT NULL,
  `user_id` bigint NOT NULL,
  `locked_by_admin_id` bigint DEFAULT NULL,
  `lock_reason` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `locked_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `unlocked_at` timestamp NULL DEFAULT NULL,
  `unlock_pin_hash` char(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ----------------------------------------------------------------------------
-- Tabla: keeper_events
-- Descripción: Eventos detallados registrados por los clientes
-- ----------------------------------------------------------------------------
CREATE TABLE `keeper_events` (
  `id` bigint NOT NULL,
  `user_id` bigint NOT NULL,
  `device_id` bigint NOT NULL,
  `module_code` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `event_type` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `start_at` datetime DEFAULT NULL,
  `end_at` datetime DEFAULT NULL,
  `duration_seconds` int DEFAULT NULL,
  `numeric_1` bigint DEFAULT NULL,
  `numeric_2` bigint DEFAULT NULL,
  `numeric_3` bigint DEFAULT NULL,
  `numeric_4` bigint DEFAULT NULL,
  `text_1` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `text_2` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `payload_json` json DEFAULT NULL,
  `day_date` date NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ----------------------------------------------------------------------------
-- Tabla: keeper_handshake_log
-- Descripción: Registro de handshakes (sincronización) entre cliente y servidor
-- ----------------------------------------------------------------------------
CREATE TABLE `keeper_handshake_log` (
  `id` bigint NOT NULL,
  `user_id` bigint NOT NULL,
  `device_id` bigint NOT NULL,
  `client_version` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `request_json` json DEFAULT NULL,
  `response_json` json DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ----------------------------------------------------------------------------
-- Tabla: keeper_module_catalog
-- Descripción: Catálogo de módulos disponibles del sistema
-- ----------------------------------------------------------------------------
CREATE TABLE `keeper_module_catalog` (
  `module_code` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `name` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `default_enabled` tinyint(1) NOT NULL DEFAULT '0',
  `config_schema_json` json DEFAULT NULL,
  `active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ----------------------------------------------------------------------------
-- Tabla: keeper_policy_assignments
-- Descripción: Políticas de configuración asignadas (global, usuario, dispositivo)
-- ----------------------------------------------------------------------------
CREATE TABLE `keeper_policy_assignments` (
  `id` bigint NOT NULL,
  `scope` enum('global','user','device') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `user_id` bigint DEFAULT NULL,
  `device_id` bigint DEFAULT NULL,
  `version` int NOT NULL DEFAULT '1',
  `priority` int NOT NULL DEFAULT '100',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `policy_json` json NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ----------------------------------------------------------------------------
-- Tabla: keeper_sessions
-- Descripción: Sesiones activas de usuarios y dispositivos
-- ----------------------------------------------------------------------------
CREATE TABLE `keeper_sessions` (
  `id` bigint NOT NULL,
  `user_id` bigint NOT NULL,
  `device_id` bigint DEFAULT NULL,
  `token_hash` char(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `refresh_hash` char(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `issued_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `expires_at` timestamp NULL DEFAULT NULL,
  `revoked_at` timestamp NULL DEFAULT NULL,
  `ip` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `user_agent` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ----------------------------------------------------------------------------
-- Tabla: keeper_window_episode
-- Descripción: Episodios de ventanas activas (tracking de aplicaciones)
-- ----------------------------------------------------------------------------
CREATE TABLE `keeper_window_episode` (
  `id` bigint NOT NULL,
  `user_id` bigint NOT NULL,
  `device_id` bigint NOT NULL,
  `start_at` datetime NOT NULL,
  `end_at` datetime NOT NULL,
  `duration_seconds` int NOT NULL,
  `process_name` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `app_name` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `window_title` varchar(512) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_in_call` tinyint(1) NOT NULL DEFAULT '0',
  `call_app_hint` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `day_date` date NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- Tabla: keeper_work_schedules
-- Descripción: Horarios laborales configurados por usuario
-- ----------------------------------------------------------------------------
CREATE TABLE `keeper_work_schedules` (
  `id` bigint NOT NULL,
  `user_id` bigint DEFAULT NULL,
  `work_start_time` time DEFAULT '07:00:00',
  `work_end_time` time DEFAULT '19:00:00',
  `lunch_start_time` time DEFAULT '12:00:00',
  `lunch_end_time` time DEFAULT '13:00:00',
  `timezone` varchar(50) DEFAULT 'America/Bogota',
  `is_active` tinyint(1) DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- ============================================================================
-- SECCIÓN 2: ÍNDICES Y CLAVES PRIMARIAS
-- ============================================================================

-- Índices para keeper_activity_day
ALTER TABLE `keeper_activity_day`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_keeper_activity_unique` (`user_id`,`device_id`,`day_date`),
  ADD UNIQUE KEY `uq_activity_day` (`user_id`,`device_id`,`day_date`),
  ADD KEY `ix_keeper_activity_day` (`day_date`),
  ADD KEY `ix_keeper_activity_user_day` (`user_id`,`day_date`),
  ADD KEY `fk_keeper_activity_device` (`device_id`),
  ADD KEY `idx_is_workday` (`is_workday`);

-- Índices para keeper_audit_log
ALTER TABLE `keeper_audit_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `ix_keeper_audit_user` (`user_id`,`created_at`),
  ADD KEY `ix_keeper_audit_device` (`device_id`,`created_at`),
  ADD KEY `ix_keeper_audit_type` (`event_type`,`created_at`);

-- Índices para keeper_client_releases
ALTER TABLE `keeper_client_releases`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `idx_version` (`version`),
  ADD KEY `idx_is_active` (`is_active`),
  ADD KEY `idx_is_beta` (`is_beta`);

-- Índices para keeper_daily_metrics
ALTER TABLE `keeper_daily_metrics`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_keeper_daily_metrics_unique` (`user_id`,`device_id`,`day_date`,`metric_key`),
  ADD KEY `ix_keeper_daily_metrics_user_day` (`user_id`,`day_date`),
  ADD KEY `ix_keeper_daily_metrics_key_day` (`metric_key`,`day_date`),
  ADD KEY `fk_keeper_daily_metrics_device` (`device_id`);

-- Índices para keeper_devices
ALTER TABLE `keeper_devices`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_keeper_devices_guid` (`device_guid`),
  ADD KEY `ix_keeper_devices_user` (`user_id`),
  ADD KEY `ix_keeper_devices_last_seen` (`last_seen_at`);

-- Índices para keeper_device_locks
ALTER TABLE `keeper_device_locks`
  ADD PRIMARY KEY (`id`),
  ADD KEY `ix_device_locks_device` (`device_id`,`is_active`),
  ADD KEY `ix_device_locks_user` (`user_id`,`is_active`);

-- Índices para keeper_events
ALTER TABLE `keeper_events`
  ADD PRIMARY KEY (`id`),
  ADD KEY `ix_keeper_events_user_day` (`user_id`,`day_date`),
  ADD KEY `ix_keeper_events_device_day` (`device_id`,`day_date`),
  ADD KEY `ix_keeper_events_type` (`event_type`,`day_date`),
  ADD KEY `ix_keeper_events_module` (`module_code`,`day_date`);

-- Índices para keeper_handshake_log
ALTER TABLE `keeper_handshake_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `ix_keeper_handshake_user` (`user_id`,`created_at`),
  ADD KEY `ix_keeper_handshake_device` (`device_id`,`created_at`);

-- Índices para keeper_module_catalog
ALTER TABLE `keeper_module_catalog`
  ADD PRIMARY KEY (`module_code`),
  ADD KEY `ix_keeper_module_active` (`active`);

-- Índices para keeper_policy_assignments
ALTER TABLE `keeper_policy_assignments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `ix_keeper_policy_scope_active` (`scope`,`is_active`,`priority`),
  ADD KEY `ix_keeper_policy_user_active` (`user_id`,`is_active`,`priority`),
  ADD KEY `ix_keeper_policy_device_active` (`device_id`,`is_active`,`priority`);

-- Índices para keeper_sessions
ALTER TABLE `keeper_sessions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_keeper_sessions_tokenhash` (`token_hash`),
  ADD KEY `ix_keeper_sessions_user` (`user_id`),
  ADD KEY `ix_keeper_sessions_device` (`device_id`),
  ADD KEY `ix_keeper_sessions_expires` (`expires_at`);

-- Índices para keeper_users
ALTER TABLE `keeper_users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_keeper_users_legacy` (`legacy_employee_id`),
  ADD UNIQUE KEY `uk_keeper_users_cc` (`cc`),
  ADD KEY `ix_keeper_users_email` (`email`);

-- Índices para keeper_window_episode
ALTER TABLE `keeper_window_episode`
  ADD PRIMARY KEY (`id`),
  ADD KEY `ix_keeper_we_user_day` (`user_id`,`day_date`),
  ADD KEY `ix_keeper_we_device_day` (`device_id`,`day_date`),
  ADD KEY `ix_keeper_we_start` (`start_at`),
  ADD KEY `ix_keeper_we_process` (`process_name`);

-- Índices para keeper_work_schedules
ALTER TABLE `keeper_work_schedules`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_user_schedule` (`user_id`,`is_active`);

-- ============================================================================
-- SECCIÓN 3: AUTO_INCREMENT
-- ============================================================================

ALTER TABLE `keeper_activity_day`
  MODIFY `id` bigint NOT NULL AUTO_INCREMENT;

ALTER TABLE `keeper_audit_log`
  MODIFY `id` bigint NOT NULL AUTO_INCREMENT;

ALTER TABLE `keeper_client_releases`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

ALTER TABLE `keeper_daily_metrics`
  MODIFY `id` bigint NOT NULL AUTO_INCREMENT;

ALTER TABLE `keeper_devices`
  MODIFY `id` bigint NOT NULL AUTO_INCREMENT;

ALTER TABLE `keeper_device_locks`
  MODIFY `id` bigint NOT NULL AUTO_INCREMENT;

ALTER TABLE `keeper_events`
  MODIFY `id` bigint NOT NULL AUTO_INCREMENT;

ALTER TABLE `keeper_handshake_log`
  MODIFY `id` bigint NOT NULL AUTO_INCREMENT;

ALTER TABLE `keeper_policy_assignments`
  MODIFY `id` bigint NOT NULL AUTO_INCREMENT;

ALTER TABLE `keeper_sessions`
  MODIFY `id` bigint NOT NULL AUTO_INCREMENT;

ALTER TABLE `keeper_users`
  MODIFY `id` bigint NOT NULL AUTO_INCREMENT;

ALTER TABLE `keeper_window_episode`
  MODIFY `id` bigint NOT NULL AUTO_INCREMENT;

ALTER TABLE `keeper_work_schedules`
  MODIFY `id` bigint NOT NULL AUTO_INCREMENT;

-- ============================================================================
-- SECCIÓN 4: CLAVES FORÁNEAS (FOREIGN KEYS)
-- ============================================================================

-- Foreign keys para keeper_activity_day
ALTER TABLE `keeper_activity_day`
  ADD CONSTRAINT `fk_keeper_activity_device` FOREIGN KEY (`device_id`) REFERENCES `keeper_devices` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_keeper_activity_user` FOREIGN KEY (`user_id`) REFERENCES `keeper_users` (`id`) ON DELETE CASCADE;

-- Foreign keys para keeper_audit_log
ALTER TABLE `keeper_audit_log`
  ADD CONSTRAINT `fk_keeper_audit_device` FOREIGN KEY (`device_id`) REFERENCES `keeper_devices` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_keeper_audit_user` FOREIGN KEY (`user_id`) REFERENCES `keeper_users` (`id`) ON DELETE SET NULL;

-- Foreign keys para keeper_daily_metrics
ALTER TABLE `keeper_daily_metrics`
  ADD CONSTRAINT `fk_keeper_daily_metrics_device` FOREIGN KEY (`device_id`) REFERENCES `keeper_devices` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_keeper_daily_metrics_user` FOREIGN KEY (`user_id`) REFERENCES `keeper_users` (`id`) ON DELETE CASCADE;

-- Foreign keys para keeper_devices
ALTER TABLE `keeper_devices`
  ADD CONSTRAINT `fk_keeper_devices_user` FOREIGN KEY (`user_id`) REFERENCES `keeper_users` (`id`) ON DELETE CASCADE;

-- Foreign keys para keeper_device_locks
ALTER TABLE `keeper_device_locks`
  ADD CONSTRAINT `fk_device_locks_device` FOREIGN KEY (`device_id`) REFERENCES `keeper_devices` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_device_locks_user` FOREIGN KEY (`user_id`) REFERENCES `keeper_users` (`id`) ON DELETE CASCADE;

-- Foreign keys para keeper_events
ALTER TABLE `keeper_events`
  ADD CONSTRAINT `fk_keeper_events_device` FOREIGN KEY (`device_id`) REFERENCES `keeper_devices` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_keeper_events_user` FOREIGN KEY (`user_id`) REFERENCES `keeper_users` (`id`) ON DELETE CASCADE;

-- Foreign keys para keeper_handshake_log
ALTER TABLE `keeper_handshake_log`
  ADD CONSTRAINT `fk_keeper_handshake_device` FOREIGN KEY (`device_id`) REFERENCES `keeper_devices` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_keeper_handshake_user` FOREIGN KEY (`user_id`) REFERENCES `keeper_users` (`id`) ON DELETE CASCADE;

-- Foreign keys para keeper_policy_assignments
ALTER TABLE `keeper_policy_assignments`
  ADD CONSTRAINT `fk_keeper_policy_device` FOREIGN KEY (`device_id`) REFERENCES `keeper_devices` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_keeper_policy_user` FOREIGN KEY (`user_id`) REFERENCES `keeper_users` (`id`) ON DELETE CASCADE;

-- Foreign keys para keeper_sessions
ALTER TABLE `keeper_sessions`
  ADD CONSTRAINT `fk_keeper_sessions_device` FOREIGN KEY (`device_id`) REFERENCES `keeper_devices` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_keeper_sessions_user` FOREIGN KEY (`user_id`) REFERENCES `keeper_users` (`id`) ON DELETE CASCADE;

-- Foreign keys para keeper_window_episode
ALTER TABLE `keeper_window_episode`
  ADD CONSTRAINT `fk_keeper_we_device` FOREIGN KEY (`device_id`) REFERENCES `keeper_devices` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_keeper_we_user` FOREIGN KEY (`user_id`) REFERENCES `keeper_users` (`id`) ON DELETE CASCADE;

-- Foreign keys para keeper_work_schedules
ALTER TABLE `keeper_work_schedules`
  ADD CONSTRAINT `keeper_work_schedules_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `keeper_users` (`id`) ON DELETE CASCADE;

-- ============================================================================
-- FIN DEL SCRIPT
-- ============================================================================

COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
