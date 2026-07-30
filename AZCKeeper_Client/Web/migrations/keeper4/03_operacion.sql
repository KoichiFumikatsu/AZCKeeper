-- Keeper 4 — operacion: politicas, estado real de modulos, seguridad.

CREATE TABLE keeper_policy_assignments (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  scope       ENUM('global','user','device') NOT NULL,
  user_id     BIGINT UNSIGNED NULL,
  device_id   BIGINT UNSIGNED NULL,
  version     INT UNSIGNED NOT NULL DEFAULT 1,
  priority    INT NOT NULL DEFAULT 0,
  is_active   TINYINT(1) NOT NULL DEFAULT 1,
  policy_json JSON NOT NULL,
  updated_by  BIGINT UNSIGNED NULL COMMENT 'keeper_admin_accounts.id',
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_policy_scope (scope, is_active, priority),
  KEY ix_policy_user (user_id, is_active),
  KEY ix_policy_device (device_id, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Eco del estado REAL de los modulos en cada equipo, no de la politica recibida.
-- Es lo que permite distinguir tres estados que en el 3 eran indistinguibles:
-- apagado a proposito, encendido y reportando, encendido y sin reportar.
CREATE TABLE keeper_device_module_state (
  device_id   BIGINT UNSIGNED NOT NULL,
  module_code VARCHAR(64) NOT NULL,
  is_running  TINYINT(1) NOT NULL,
  reported_at DATETIME NOT NULL,
  detail_json JSON NULL,
  PRIMARY KEY (device_id, module_code),
  KEY ix_dms_reported (reported_at),
  CONSTRAINT fk_dms_device FOREIGN KEY (device_id) REFERENCES keeper_devices (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Estado de controles de seguridad observados en el equipo (Modulo de Seguridad).
CREATE TABLE keeper_security_state (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id       BIGINT UNSIGNED NOT NULL,
  device_id     BIGINT UNSIGNED NOT NULL,
  reported_at   DATETIME NOT NULL,
  agent_present TINYINT(1) NOT NULL DEFAULT 0,
  controls_json LONGTEXT NOT NULL,
  controls_hash CHAR(64) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_security_state_device (device_id),
  KEY ix_security_state_reported (reported_at),
  KEY ix_security_state_user (user_id),
  CONSTRAINT fk_secstate_device FOREIGN KEY (device_id) REFERENCES keeper_devices (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE keeper_work_schedules (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id           BIGINT UNSIGNED NULL COMMENT 'NULL = horario global',
  work_start_time   TIME NOT NULL DEFAULT '07:00:00',
  work_end_time     TIME NOT NULL DEFAULT '19:00:00',
  lunch_start_time  TIME NOT NULL DEFAULT '12:00:00',
  lunch_end_time    TIME NOT NULL DEFAULT '13:00:00',
  applicable_days   VARCHAR(20) NOT NULL DEFAULT '1,2,3,4,5',
  timezone          VARCHAR(64) NOT NULL DEFAULT 'America/Bogota',
  is_active         TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  KEY ix_ws_user (user_id, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE keeper_client_releases (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  version      VARCHAR(32) NOT NULL,
  download_url VARCHAR(512) NOT NULL,
  size_bytes   BIGINT UNSIGNED NULL,
  is_active    TINYINT(1) NOT NULL DEFAULT 0,
  is_beta      TINYINT(1) NOT NULL DEFAULT 0,
  notes        TEXT NULL,
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_release_version (version),
  KEY ix_release_active (is_active, is_beta)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE keeper_client_log (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id     BIGINT UNSIGNED NULL,
  device_id   BIGINT UNSIGNED NULL,
  level       ENUM('debug','info','warn','error') NOT NULL DEFAULT 'info',
  source      VARCHAR(64) NOT NULL DEFAULT 'other',
  message     VARCHAR(1024) NOT NULL,
  meta_json   JSON NULL,
  logged_at   DATETIME NOT NULL,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_clog_device_time (device_id, logged_at),
  KEY ix_clog_level_time (level, logged_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
