-- Partitioned immutable episodes; unpartitioned ledger provides tenant FKs and cross-date deduplication.

SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci;

SET time_zone = '+00:00';

CREATE TABLE IF NOT EXISTS retention_settings (
  tenant_id BINARY(16) NOT NULL,
  raw_days SMALLINT UNSIGNED NOT NULL DEFAULT 90,
  late_arrival_days SMALLINT UNSIGNED NOT NULL DEFAULT 30,
  aggregate_months SMALLINT UNSIGNED NOT NULL DEFAULT 13,
  log_days SMALLINT UNSIGNED NOT NULL DEFAULT 30,
  PRIMARY KEY (tenant_id),
  CHECK (raw_days >= late_arrival_days AND late_arrival_days BETWEEN 1 AND 90 AND raw_days <= 3650 AND aggregate_months >= 1 AND log_days >= 1),
  FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS episode_ingest_keys (
  tenant_id BINARY(16) NOT NULL,
  device_id BINARY(16) NOT NULL,
  event_id BINARY(16) NOT NULL,
  user_id BINARY(16) NOT NULL,
  device_assignment_id BINARY(16) NOT NULL,
  user_assignment_id BINARY(16) NOT NULL,
  event_date DATE NOT NULL,
  body_hash BINARY(32) NOT NULL,
  received_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (tenant_id, device_id, event_id),
  KEY (event_date, tenant_id),
  FOREIGN KEY (tenant_id, device_assignment_id, device_id, user_id) REFERENCES device_assignments (tenant_id, id, device_id, user_id),
  FOREIGN KEY (tenant_id, user_assignment_id, user_id) REFERENCES user_assignments (tenant_id, id, user_id),
  FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS episodes (
  tenant_id BINARY(16) NOT NULL,
  device_id BINARY(16) NOT NULL,
  event_id BINARY(16) NOT NULL,
  user_id BINARY(16) NOT NULL,
  device_assignment_id BINARY(16) NOT NULL,
  user_assignment_id BINARY(16) NOT NULL,
  event_date DATE NOT NULL,
  started_at DATETIME(6) NOT NULL,
  ended_at DATETIME(6) NOT NULL,
  process_name VARCHAR(160) NOT NULL,
  window_title VARCHAR(512) NULL,
  active_seconds INT UNSIGNED NOT NULL,
  idle_seconds INT UNSIGNED NOT NULL,
  body_hash BINARY(32) NOT NULL,
  received_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (tenant_id, device_id, event_id, event_date),
  KEY episode_member_time (tenant_id, user_id, started_at, event_id),
  KEY episode_device_time (tenant_id, device_id, started_at, event_id),
  KEY episode_tenant_time (tenant_id, started_at, event_id),
  CHECK (event_date = DATE(started_at)),
  CHECK (ended_at > started_at AND ended_at <= started_at + INTERVAL 24 HOUR),
  CHECK (active_seconds + idle_seconds <= TIMESTAMPDIFF(SECOND, started_at, ended_at))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
PARTITION BY RANGE COLUMNS (event_date) (
  PARTITION p_before_202607 VALUES LESS THAN ('2026-07-01'),
  PARTITION p202607 VALUES LESS THAN ('2026-08-01'),
  PARTITION p202608 VALUES LESS THAN ('2026-09-01'),
  PARTITION p202609 VALUES LESS THAN ('2026-10-01'),
  PARTITION p202610 VALUES LESS THAN ('2026-11-01'),
  PARTITION p202611 VALUES LESS THAN ('2026-12-01'),
  PARTITION p202612 VALUES LESS THAN ('2027-01-01'),
  PARTITION p202701 VALUES LESS THAN ('2027-02-01'),
  PARTITION p202702 VALUES LESS THAN ('2027-03-01'),
  PARTITION p202703 VALUES LESS THAN ('2027-04-01'),
  PARTITION p202704 VALUES LESS THAN ('2027-05-01'),
  PARTITION p202705 VALUES LESS THAN ('2027-06-01'),
  PARTITION p202706 VALUES LESS THAN ('2027-07-01'),
  PARTITION p202707 VALUES LESS THAN ('2027-08-01'),
  PARTITION p202708 VALUES LESS THAN ('2027-09-01'),
  PARTITION p202709 VALUES LESS THAN ('2027-10-01'),
  PARTITION p202710 VALUES LESS THAN ('2027-11-01'),
  PARTITION p202711 VALUES LESS THAN ('2027-12-01'),
  PARTITION p202712 VALUES LESS THAN ('2028-01-01'),
  PARTITION p_future VALUES LESS THAN (MAXVALUE)
);

CREATE TABLE IF NOT EXISTS activity_snapshots (
  tenant_id BINARY(16) NOT NULL,
  device_id BINARY(16) NOT NULL,
  day DATE NOT NULL,
  snapshot_id BINARY(16) NOT NULL,
  sequence BIGINT UNSIGNED NOT NULL,
  user_id BINARY(16) NOT NULL,
  device_assignment_id BINARY(16) NOT NULL,
  active_seconds INT UNSIGNED NOT NULL,
  idle_seconds INT UNSIGNED NOT NULL,
  body_hash BINARY(32) NOT NULL,
  received_at DATETIME(6) NOT NULL,
  PRIMARY KEY (tenant_id, device_id, day),
  UNIQUE KEY (tenant_id, device_id, snapshot_id),
  FOREIGN KEY (tenant_id, device_assignment_id, device_id, user_id) REFERENCES device_assignments (tenant_id, id, device_id, user_id),
  CHECK (sequence > 0 AND active_seconds + idle_seconds <= 86400),
  FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS app_classification (
  tenant_id BINARY(16) NOT NULL,
  id BINARY(16) NOT NULL,
  process_name VARCHAR(160) NOT NULL,
  category ENUM('productive','neutral','unproductive','unclassified') NOT NULL,
  version BIGINT UNSIGNED NOT NULL DEFAULT 1,
  PRIMARY KEY (tenant_id, id),
  UNIQUE KEY (tenant_id, process_name),
  FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS episode_daily (
  tenant_id BINARY(16) NOT NULL,
  day DATE NOT NULL,
  user_id BINARY(16) NOT NULL,
  user_assignment_id BINARY(16) NOT NULL,
  device_id BINARY(16) NOT NULL,
  process_name VARCHAR(160) NOT NULL,
  category ENUM('productive','neutral','unproductive','unclassified') NOT NULL,
  active_seconds BIGINT UNSIGNED NOT NULL,
  idle_seconds BIGINT UNSIGNED NOT NULL,
  episode_count BIGINT UNSIGNED NOT NULL,
  calculation_version VARCHAR(80) NOT NULL,
  calculated_at DATETIME(6) NOT NULL,
  data_through DATETIME(6) NOT NULL,
  PRIMARY KEY (tenant_id, day, user_id, user_assignment_id, device_id, process_name),
  FOREIGN KEY (tenant_id, device_id) REFERENCES devices (tenant_id, id),
  FOREIGN KEY (tenant_id, user_assignment_id, user_id) REFERENCES user_assignments (tenant_id, id, user_id),
  KEY (tenant_id, user_id, day),
  KEY (day),
  FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS day_summary (
  tenant_id BINARY(16) NOT NULL,
  day DATE NOT NULL,
  user_id BINARY(16) NOT NULL,
  user_assignment_id BINARY(16) NOT NULL,
  active_seconds BIGINT UNSIGNED NOT NULL,
  idle_seconds BIGINT UNSIGNED NOT NULL,
  productive_seconds BIGINT UNSIGNED NOT NULL,
  expected_seconds INT UNSIGNED NOT NULL,
  coverage_percent DECIMAL(5,2) NOT NULL,
  productivity_percent DECIMAL(5,2) NULL,
  calculation_version VARCHAR(80) NOT NULL,
  timezone VARCHAR(64) NOT NULL,
  calculated_at DATETIME(6) NOT NULL,
  data_through DATETIME(6) NOT NULL,
  PRIMARY KEY (tenant_id, day, user_id, user_assignment_id),
  FOREIGN KEY (tenant_id, user_assignment_id, user_id) REFERENCES user_assignments (tenant_id, id, user_id),
  KEY (tenant_id, user_id, day),
  KEY (day),
  CHECK (coverage_percent BETWEEN 0 AND 100 AND (productivity_percent IS NULL OR productivity_percent BETWEEN 0 AND 100)),
  FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS focus_daily (
  tenant_id BINARY(16) NOT NULL,
  day DATE NOT NULL,
  user_id BINARY(16) NOT NULL,
  user_assignment_id BINARY(16) NOT NULL,
  focus_seconds BIGINT UNSIGNED NOT NULL,
  focus_score DECIMAL(5,2) NULL,
  calculation_version VARCHAR(80) NOT NULL,
  calculated_at DATETIME(6) NOT NULL,
  data_through DATETIME(6) NOT NULL,
  PRIMARY KEY (tenant_id, day, user_id, user_assignment_id),
  FOREIGN KEY (tenant_id, user_assignment_id, user_id) REFERENCES user_assignments (tenant_id, id, user_id),
  KEY (tenant_id, user_id, day),
  KEY (day),
  CHECK (focus_score IS NULL OR focus_score BETWEEN 0 AND 100),
  FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS rollup_days (
  tenant_id BINARY(16) NOT NULL,
  day DATE NOT NULL,
  dirty BOOLEAN NOT NULL DEFAULT TRUE,
  input_revision BIGINT UNSIGNED NOT NULL DEFAULT 1,
  calculated_revision BIGINT UNSIGNED NOT NULL DEFAULT 0,
  calculation_version VARCHAR(80) NULL,
  calculated_at DATETIME(6) NULL,
  data_through DATETIME(6) NULL,
  finalized_at DATETIME(6) NULL,
  PRIMARY KEY (tenant_id, day),
  KEY (tenant_id, dirty, day),
  CHECK (calculated_revision <= input_revision),
  FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS doors (
  tenant_id BINARY(16) NOT NULL,
  id BINARY(16) NOT NULL,
  site_id BINARY(16) NOT NULL,
  site_kind VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'site',
  CHECK (site_kind = 'site'),
  FOREIGN KEY (tenant_id, site_id, site_kind) REFERENCES org_units (tenant_id, id, kind),
  name VARCHAR(120) NOT NULL,
  source_ref VARCHAR(160) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  active BOOLEAN NOT NULL DEFAULT TRUE,
  PRIMARY KEY (tenant_id, id),
  UNIQUE KEY (tenant_id, source_ref),
  UNIQUE KEY (tenant_id, id, site_id),
  FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS check_ins (
  tenant_id BINARY(16) NOT NULL,
  id BINARY(16) NOT NULL,
  event_id BINARY(16) NOT NULL,
  source_identity VARCHAR(160) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  user_id BINARY(16) NOT NULL,
  device_id BINARY(16) NULL,
  door_id BINARY(16) NULL,
  site_id BINARY(16) NULL,
  site_kind VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'site',
  CHECK (site_kind = 'site'),
  FOREIGN KEY (tenant_id, site_id, site_kind) REFERENCES org_units (tenant_id, id, kind),
  source ENUM('door','agent','manual') NOT NULL,
  direction ENUM('in','out') NOT NULL,
  occurred_at DATETIME(6) NOT NULL,
  latitude DECIMAL(9,6) NULL,
  longitude DECIMAL(9,6) NULL,
  accuracy_m DECIMAL(12,3) NULL,
  body_hash BINARY(32) NOT NULL,
  PRIMARY KEY (tenant_id, id),
  UNIQUE KEY (tenant_id, source, source_identity, event_id),
  KEY (tenant_id, user_id, occurred_at, id),
  FOREIGN KEY (tenant_id, user_id) REFERENCES users (tenant_id, id),
  FOREIGN KEY (tenant_id, device_id) REFERENCES devices (tenant_id, id),
  FOREIGN KEY (tenant_id, door_id, site_id) REFERENCES doors (tenant_id, id, site_id),
  CHECK ((latitude IS NULL AND longitude IS NULL AND accuracy_m IS NULL) OR (latitude IS NOT NULL AND longitude IS NOT NULL AND accuracy_m IS NOT NULL AND latitude BETWEEN -90 AND 90 AND longitude BETWEEN -180 AND 180 AND accuracy_m >= 0)),
  CHECK ((source = 'door' AND door_id IS NOT NULL AND site_id IS NOT NULL AND device_id IS NULL) OR (source = 'agent' AND device_id IS NOT NULL AND door_id IS NULL) OR (source = 'manual' AND door_id IS NULL AND device_id IS NULL)),
  FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS security_reports (
  tenant_id BINARY(16) NOT NULL,
  device_id BINARY(16) NOT NULL,
  event_id BINARY(16) NOT NULL,
  observed_at DATETIME(6) NOT NULL,
  report_hash BINARY(32) NOT NULL,
  controls JSON NOT NULL,
  PRIMARY KEY (tenant_id, device_id, event_id),
  FOREIGN KEY (tenant_id, device_id) REFERENCES devices (tenant_id, id),
  KEY (tenant_id, device_id, observed_at),
  CHECK (JSON_TYPE(controls) = 'ARRAY' AND JSON_LENGTH(controls) <= 100),
  FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS client_logs (
  tenant_id BINARY(16) NOT NULL,
  device_id BINARY(16) NOT NULL,
  event_id BINARY(16) NOT NULL,
  at DATETIME(6) NOT NULL,
  received_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  level ENUM('info','warn','error') NOT NULL,
  code VARCHAR(100) NOT NULL,
  component VARCHAR(80) NOT NULL,
  control_id VARCHAR(100) NULL,
  command_id BINARY(16) NULL,
  attempt SMALLINT UNSIGNED NULL,
  error_code VARCHAR(80) NULL,
  body_hash BINARY(32) NOT NULL,
  PRIMARY KEY (tenant_id, device_id, event_id),
  FOREIGN KEY (tenant_id, device_id) REFERENCES devices (tenant_id, id),
  FOREIGN KEY (tenant_id, command_id, device_id) REFERENCES device_command (tenant_id, id, device_id),
  KEY (tenant_id, device_id, at),
  KEY (received_at),
  CHECK (attempt IS NULL OR attempt <= 1000),
  FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

DELIMITER $$
CREATE EVENT IF NOT EXISTS maintain_episode_partitions
ON SCHEDULE EVERY 1 DAY STARTS CURRENT_TIMESTAMP + INTERVAL 1 DAY
ON COMPLETION PRESERVE ENABLE
DO
maintenance: BEGIN
  DECLARE maintenance_lock VARCHAR(64);
  DECLARE lock_acquired INT DEFAULT 0;
  DECLARE month_start DATE;
  DECLARE month_end DATE;
  DECLARE horizon DATE;
  DECLARE retention_days INT;
  DECLARE cutoff DATE;
  DECLARE oldest_partition VARCHAR(64);
  DECLARE EXIT HANDLER FOR SQLEXCEPTION
  BEGIN
    IF lock_acquired = 1 THEN
      DO RELEASE_LOCK(maintenance_lock);
    END IF;
    RESIGNAL;
  END;

  SET maintenance_lock = CONCAT('keeper_v4:', LEFT(SHA2(DATABASE(), 256), 48));
  SELECT GET_LOCK(maintenance_lock, 0) INTO lock_acquired;
  IF COALESCE(lock_acquired, 0) <> 1 THEN
    LEAVE maintenance;
  END IF;
  SET horizon = DATE_FORMAT(UTC_DATE(), '%Y-%m-01') + INTERVAL 13 MONTH;
  SELECT MAX(CAST(TRIM(BOTH '''' FROM partition_description) AS DATE)) INTO month_start
    FROM information_schema.partitions
    WHERE table_schema = DATABASE() AND table_name = 'episodes' AND partition_description <> 'MAXVALUE';
  WHILE month_start < horizon DO
    SET month_end = month_start + INTERVAL 1 MONTH;
    SET @episode_partition_sql = CONCAT('ALTER TABLE episodes REORGANIZE PARTITION p_future INTO (PARTITION p',
      DATE_FORMAT(month_start, '%Y%m'), ' VALUES LESS THAN (''', month_end,
      '''), PARTITION p_future VALUES LESS THAN (MAXVALUE))');
    PREPARE episode_partition_stmt FROM @episode_partition_sql;
    EXECUTE episode_partition_stmt;
    DEALLOCATE PREPARE episode_partition_stmt;
    SET month_start = month_end;
  END WHILE;

  -- Shared partitions must outlive every tenant's raw retention, including the default.
  SELECT COALESCE(MAX(COALESCE(r.raw_days, 90)), 90) INTO retention_days
    FROM tenants t LEFT JOIN retention_settings r ON r.tenant_id = t.tenant_id;
  SET cutoff = UTC_DATE() - INTERVAL retention_days DAY;
  partition_purge: LOOP
    SET oldest_partition = (
      SELECT partition_name FROM information_schema.partitions
      WHERE table_schema = DATABASE() AND table_name = 'episodes' AND partition_description <> 'MAXVALUE'
        AND CAST(TRIM(BOTH '''' FROM partition_description) AS DATE) <= cutoff
      ORDER BY partition_ordinal_position LIMIT 1
    );
    IF oldest_partition IS NULL THEN
      LEAVE partition_purge;
    END IF;
    -- Keep raw data until every affected local day has a current, finalized rollup.
    SET @episode_partition_sql = CONCAT(
      'SELECT EXISTS (SELECT 1 FROM episodes PARTITION (`', oldest_partition, '`) e ',
      'LEFT JOIN user_assignments a ON a.tenant_id = e.tenant_id AND a.id = e.user_assignment_id ',
      'LEFT JOIN schedules s ON s.tenant_id = a.tenant_id AND s.id = a.schedule_id ',
      'WHERE CONVERT_TZ(e.started_at, ''+00:00'', s.timezone) IS NULL OR ',
      '(SELECT COUNT(*) FROM rollup_days r WHERE r.tenant_id = e.tenant_id ',
      'AND r.day BETWEEN DATE(CONVERT_TZ(e.started_at, ''+00:00'', s.timezone)) ',
      'AND DATE(CONVERT_TZ(e.ended_at - INTERVAL 1 MICROSECOND, ''+00:00'', s.timezone)) ',
      'AND r.finalized_at IS NOT NULL AND r.dirty = 0 AND r.calculated_revision = r.input_revision) ',
      '<> DATEDIFF(CONVERT_TZ(e.ended_at - INTERVAL 1 MICROSECOND, ''+00:00'', s.timezone), ',
      'CONVERT_TZ(e.started_at, ''+00:00'', s.timezone)) + 1) INTO @episode_partition_blocked');
    PREPARE episode_partition_stmt FROM @episode_partition_sql;
    EXECUTE episode_partition_stmt;
    DEALLOCATE PREPARE episode_partition_stmt;
    IF @episode_partition_blocked THEN
      LEAVE partition_purge;
    END IF;
    SET @episode_partition_sql = CONCAT('ALTER TABLE episodes DROP PARTITION `', oldest_partition, '`');
    PREPARE episode_partition_stmt FROM @episode_partition_sql;
    EXECUTE episode_partition_stmt;
    DEALLOCATE PREPARE episode_partition_stmt;
  END LOOP;
  DO RELEASE_LOCK(maintenance_lock);
END$$
DELIMITER ;
