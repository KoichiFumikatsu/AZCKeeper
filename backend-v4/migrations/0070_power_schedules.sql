SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci;
SET time_zone = '+00:00';

-- Apagado/reinicio programado (spec docs/superpowers/specs/2026-10-27-apagado-programado-design.md).
CREATE TABLE IF NOT EXISTS power_schedules (
  tenant_id BINARY(16) NOT NULL,
  id BINARY(16) NOT NULL,
  name VARCHAR(80) NOT NULL,
  action ENUM('shutdown','restart') NOT NULL DEFAULT 'shutdown',
  recurrence ENUM('once','weekly') NOT NULL,
  days_mask TINYINT UNSIGNED NOT NULL DEFAULT 0,          -- bit 0 = lunes ... bit 6 = domingo (solo weekly)
  time_local CHAR(5) NOT NULL,                            -- HH:MM en tenants.timezone
  run_on DATE NULL,                                       -- solo once
  valid_from DATE NOT NULL,
  valid_until DATE NULL,
  message VARCHAR(280) NOT NULL DEFAULT '',
  idle_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 15,
  notice_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 10,
  postpone_minutes SMALLINT UNSIGNED NULL DEFAULT 60,
  window_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 180,
  status ENUM('active','paused') NOT NULL DEFAULT 'active',
  version BIGINT UNSIGNED NOT NULL DEFAULT 1,
  created_by BINARY(16) NOT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (tenant_id, id),
  KEY (tenant_id, status),
  CONSTRAINT power_schedules_tenant_fk FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id),
  CONSTRAINT power_schedules_shape CHECK ((recurrence='once' AND run_on IS NOT NULL AND days_mask=0) OR (recurrence='weekly' AND run_on IS NULL AND days_mask BETWEEN 1 AND 127))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS power_schedule_targets (
  tenant_id BINARY(16) NOT NULL,
  id BINARY(16) NOT NULL,
  schedule_id BINARY(16) NOT NULL,
  kind ENUM('tenant','site','area','user','device') NOT NULL,
  ref_id BINARY(16) NOT NULL,                             -- tenant_id para kind=tenant
  excluded BOOLEAN NOT NULL DEFAULT FALSE,
  PRIMARY KEY (tenant_id, id),
  UNIQUE KEY (tenant_id, schedule_id, kind, ref_id, excluded),
  CONSTRAINT power_schedule_targets_schedule_fk FOREIGN KEY (tenant_id, schedule_id) REFERENCES power_schedules (tenant_id, id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS power_schedule_runs (
  tenant_id BINARY(16) NOT NULL,
  id BINARY(16) NOT NULL,
  schedule_id BINARY(16) NOT NULL,
  device_id BINARY(16) NOT NULL,
  occurrence_at DATETIME(6) NOT NULL,
  outcome ENUM('executed_idle','executed_after_notice','postponed','executed_by_person','cancelled_by_it','replaced','skipped_suspended','not_needed','unsupported') NOT NULL,
  detail VARCHAR(100) NULL,
  event_id BINARY(16) NOT NULL,
  reported_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (tenant_id, id),
  UNIQUE KEY (tenant_id, event_id),
  UNIQUE KEY (tenant_id, schedule_id, device_id, occurrence_at, outcome),
  KEY (tenant_id, schedule_id, device_id, occurrence_at),
  CONSTRAINT power_schedule_runs_schedule_fk FOREIGN KEY (tenant_id, schedule_id) REFERENCES power_schedules (tenant_id, id) ON DELETE CASCADE,
  CONSTRAINT power_schedule_runs_device_fk FOREIGN KEY (tenant_id, device_id) REFERENCES devices (tenant_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
