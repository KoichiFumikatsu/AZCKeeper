-- MySQL >= 8.0.30: repeatable triggers; application authorization still mandatory.

SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci;

SET time_zone = '+00:00';

CREATE TABLE IF NOT EXISTS assignment_intervals (
  tenant_id BINARY(16) NOT NULL,
  kind ENUM('user_assignments','device_assignments','subscriptions') NOT NULL,
  id BINARY(16) NOT NULL,
  user_id BINARY(16) NULL,
  device_id BINARY(16) NULL,
  owner_id BINARY(16) GENERATED ALWAYS AS (COALESCE(user_id, device_id)) STORED,
  starts_at DATETIME(6) NOT NULL,
  ends_at DATETIME(6) NULL,
  cancelled BOOLEAN NOT NULL DEFAULT FALSE,
  PRIMARY KEY (tenant_id, kind, id),
  KEY (tenant_id, kind, owner_id, starts_at, ends_at),
  FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id),
  FOREIGN KEY (tenant_id, user_id) REFERENCES users (tenant_id, id),
  FOREIGN KEY (tenant_id, device_id) REFERENCES devices (tenant_id, id),
  CHECK ((kind = 'device_assignments' AND device_id IS NOT NULL AND user_id IS NULL)
    OR (kind IN ('user_assignments','subscriptions') AND user_id IS NOT NULL AND device_id IS NULL)),
  CHECK (ends_at IS NULL OR ends_at > starts_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

DELIMITER $$

CREATE TRIGGER IF NOT EXISTS episodes_immutable BEFORE UPDATE ON episodes FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'episodes is immutable';
END$$

CREATE TRIGGER IF NOT EXISTS episode_ingest_keys_immutable BEFORE UPDATE ON episode_ingest_keys FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'episode_ingest_keys is immutable';
END$$

CREATE TRIGGER IF NOT EXISTS policy_versions_immutable BEFORE UPDATE ON policy_versions FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'policy_versions is immutable';
END$$

CREATE TRIGGER IF NOT EXISTS policy_rules_immutable BEFORE UPDATE ON policy_rules FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'policy_rules is immutable';
END$$

CREATE TRIGGER IF NOT EXISTS effective_policies_immutable BEFORE UPDATE ON effective_policies FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'effective_policies is immutable';
END$$

CREATE TRIGGER IF NOT EXISTS client_releases_immutable BEFORE UPDATE ON client_releases FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'client_releases is immutable';
END$$

CREATE TRIGGER IF NOT EXISTS audit_log_immutable BEFORE UPDATE ON audit_log FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_log is immutable';
END$$

CREATE TRIGGER IF NOT EXISTS audit_log_append_only BEFORE DELETE ON audit_log FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_log is append-only; archive via privileged maintenance';
END$$

CREATE TRIGGER IF NOT EXISTS client_releases_immutable_delete BEFORE DELETE ON client_releases FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Release manifests are immutable; disable deployment instead';
END$$

CREATE TRIGGER IF NOT EXISTS episodes_tenant_dedupe BEFORE INSERT ON episodes FOR EACH ROW
BEGIN
  DECLARE allowed_days INT DEFAULT NULL;
  DECLARE valid_from DATETIME(6);
  DECLARE valid_until DATETIME(6);
  SELECT late_arrival_days INTO allowed_days FROM retention_settings
    WHERE tenant_id = NEW.tenant_id LOCK IN SHARE MODE;
  IF allowed_days IS NULL OR NEW.started_at < UTC_TIMESTAMP(6) - INTERVAL allowed_days DAY
     OR NEW.ended_at > UTC_TIMESTAMP(6) + INTERVAL 5 MINUTE THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'event_too_old, future event or retention settings missing';
  END IF;
  SELECT starts_at, ends_at INTO valid_from, valid_until FROM device_assignments
    WHERE tenant_id = NEW.tenant_id AND id = NEW.device_assignment_id
      AND device_id = NEW.device_id AND user_id = NEW.user_id LOCK IN SHARE MODE;
  IF valid_from IS NULL OR NEW.started_at < valid_from OR
     (valid_until IS NOT NULL AND NEW.ended_at > valid_until) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Event outside device assignment';
  END IF;
  SET valid_from = NULL;
  SET valid_until = NULL;
  SELECT starts_at, ends_at INTO valid_from, valid_until FROM user_assignments
    WHERE tenant_id = NEW.tenant_id AND id = NEW.user_assignment_id
      AND user_id = NEW.user_id LOCK IN SHARE MODE;
  IF valid_from IS NULL OR NEW.started_at < valid_from OR
     (valid_until IS NOT NULL AND NEW.ended_at > valid_until) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Event outside user assignment';
  END IF;
  INSERT INTO episode_ingest_keys
    (tenant_id, device_id, event_id, user_id, device_assignment_id,
     user_assignment_id, event_date, body_hash, received_at)
  VALUES
    (NEW.tenant_id, NEW.device_id, NEW.event_id, NEW.user_id, NEW.device_assignment_id,
     NEW.user_assignment_id, NEW.event_date, NEW.body_hash, NEW.received_at);
END$$

CREATE TRIGGER IF NOT EXISTS episode_keys_delete_guard BEFORE DELETE ON episode_ingest_keys FOR EACH ROW
BEGIN
  DECLARE raw_event BINARY(16) DEFAULT NULL;
  DECLARE allowed_days INT DEFAULT NULL;
  SELECT late_arrival_days INTO allowed_days FROM retention_settings
    WHERE tenant_id = OLD.tenant_id LOCK IN SHARE MODE;
  SELECT event_id INTO raw_event FROM episodes WHERE tenant_id = OLD.tenant_id
    AND device_id = OLD.device_id AND event_id = OLD.event_id AND event_date = OLD.event_date LOCK IN SHARE MODE;
  IF raw_event IS NOT NULL OR allowed_days IS NULL OR OLD.event_date >= UTC_DATE() - INTERVAL allowed_days DAY THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Purge raw episode before its dedupe key';
  END IF;
END$$

CREATE TRIGGER IF NOT EXISTS user_assignments_no_overlap_insert BEFORE INSERT ON user_assignments FOR EACH ROW
BEGIN
  DECLARE locked_owner BINARY(16);
  DECLARE overlapping BINARY(16) DEFAULT NULL;
  DECLARE CONTINUE HANDLER FOR NOT FOUND SET overlapping = NULL;
  
  SELECT id INTO locked_owner FROM users
    WHERE tenant_id = NEW.tenant_id AND id = NEW.user_id FOR UPDATE;
  SELECT id INTO overlapping FROM assignment_intervals
    WHERE tenant_id = NEW.tenant_id AND kind = 'user_assignments' AND owner_id = NEW.user_id
      
      
      AND (ends_at IS NULL OR NEW.starts_at < ends_at)
      AND (NEW.ends_at IS NULL OR starts_at < NEW.ends_at)
    LIMIT 1 FOR UPDATE;
  IF overlapping IS NOT NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Overlapping validity intervals';
  END IF;
  INSERT INTO assignment_intervals (tenant_id, kind, id, user_id, device_id, starts_at, ends_at, cancelled)
  VALUES (NEW.tenant_id, 'user_assignments', NEW.id, NEW.user_id, NULL, NEW.starts_at, NEW.ends_at, FALSE);
END$$

CREATE TRIGGER IF NOT EXISTS user_assignments_no_overlap_update BEFORE UPDATE ON user_assignments FOR EACH ROW
BEGIN
  DECLARE locked_owner BINARY(16);
  DECLARE overlapping BINARY(16) DEFAULT NULL;
  DECLARE CONTINUE HANDLER FOR NOT FOUND SET overlapping = NULL;
  IF NEW.tenant_id <> OLD.tenant_id OR NEW.id <> OLD.id OR NEW.user_id <> OLD.user_id THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Assignment identity is immutable'; END IF;
  SELECT id INTO locked_owner FROM users
    WHERE tenant_id = NEW.tenant_id AND id = NEW.user_id FOR UPDATE;
  SELECT id INTO overlapping FROM assignment_intervals
    WHERE tenant_id = NEW.tenant_id AND kind = 'user_assignments' AND owner_id = NEW.user_id
      AND id <> OLD.id
      
      AND (ends_at IS NULL OR NEW.starts_at < ends_at)
      AND (NEW.ends_at IS NULL OR starts_at < NEW.ends_at)
    LIMIT 1 FOR UPDATE;
  IF overlapping IS NOT NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Overlapping validity intervals';
  END IF;
  UPDATE assignment_intervals SET starts_at = NEW.starts_at, ends_at = NEW.ends_at,
    cancelled = FALSE
    WHERE tenant_id = NEW.tenant_id AND kind = 'user_assignments' AND id = NEW.id;
END$$

CREATE TRIGGER IF NOT EXISTS device_assignments_no_overlap_insert BEFORE INSERT ON device_assignments FOR EACH ROW
BEGIN
  DECLARE locked_owner BINARY(16);
  DECLARE overlapping BINARY(16) DEFAULT NULL;
  DECLARE CONTINUE HANDLER FOR NOT FOUND SET overlapping = NULL;
  
  SELECT id INTO locked_owner FROM devices
    WHERE tenant_id = NEW.tenant_id AND id = NEW.device_id FOR UPDATE;
  SELECT id INTO overlapping FROM assignment_intervals
    WHERE tenant_id = NEW.tenant_id AND kind = 'device_assignments' AND owner_id = NEW.device_id
      
      
      AND (ends_at IS NULL OR NEW.starts_at < ends_at)
      AND (NEW.ends_at IS NULL OR starts_at < NEW.ends_at)
    LIMIT 1 FOR UPDATE;
  IF overlapping IS NOT NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Overlapping validity intervals';
  END IF;
  INSERT INTO assignment_intervals (tenant_id, kind, id, user_id, device_id, starts_at, ends_at, cancelled)
  VALUES (NEW.tenant_id, 'device_assignments', NEW.id, NULL, NEW.device_id, NEW.starts_at, NEW.ends_at, FALSE);
END$$

CREATE TRIGGER IF NOT EXISTS device_assignments_no_overlap_update BEFORE UPDATE ON device_assignments FOR EACH ROW
BEGIN
  DECLARE locked_owner BINARY(16);
  DECLARE overlapping BINARY(16) DEFAULT NULL;
  DECLARE CONTINUE HANDLER FOR NOT FOUND SET overlapping = NULL;
  IF NEW.tenant_id <> OLD.tenant_id OR NEW.id <> OLD.id OR NEW.device_id <> OLD.device_id THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Assignment identity is immutable'; END IF;
  SELECT id INTO locked_owner FROM devices
    WHERE tenant_id = NEW.tenant_id AND id = NEW.device_id FOR UPDATE;
  SELECT id INTO overlapping FROM assignment_intervals
    WHERE tenant_id = NEW.tenant_id AND kind = 'device_assignments' AND owner_id = NEW.device_id
      AND id <> OLD.id
      
      AND (ends_at IS NULL OR NEW.starts_at < ends_at)
      AND (NEW.ends_at IS NULL OR starts_at < NEW.ends_at)
    LIMIT 1 FOR UPDATE;
  IF overlapping IS NOT NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Overlapping validity intervals';
  END IF;
  UPDATE assignment_intervals SET starts_at = NEW.starts_at, ends_at = NEW.ends_at,
    cancelled = FALSE
    WHERE tenant_id = NEW.tenant_id AND kind = 'device_assignments' AND id = NEW.id;
END$$

CREATE TRIGGER IF NOT EXISTS subscriptions_no_overlap_insert BEFORE INSERT ON subscriptions FOR EACH ROW
BEGIN
  DECLARE locked_owner BINARY(16);
  DECLARE overlapping BINARY(16) DEFAULT NULL;
  DECLARE CONTINUE HANDLER FOR NOT FOUND SET overlapping = NULL;
  
  SELECT id INTO locked_owner FROM users
    WHERE tenant_id = NEW.tenant_id AND id = NEW.user_id FOR UPDATE;
  SELECT id INTO overlapping FROM assignment_intervals
    WHERE tenant_id = NEW.tenant_id AND kind = 'subscriptions' AND owner_id = NEW.user_id
      
      AND cancelled = FALSE AND NEW.status <> 'cancelled'
      AND (ends_at IS NULL OR NEW.starts_at < ends_at)
      AND (NEW.ends_at IS NULL OR starts_at < NEW.ends_at)
    LIMIT 1 FOR UPDATE;
  IF overlapping IS NOT NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Overlapping validity intervals';
  END IF;
  INSERT INTO assignment_intervals (tenant_id, kind, id, user_id, device_id, starts_at, ends_at, cancelled)
  VALUES (NEW.tenant_id, 'subscriptions', NEW.id, NEW.user_id, NULL, NEW.starts_at, NEW.ends_at, NEW.status = 'cancelled');
END$$

CREATE TRIGGER IF NOT EXISTS subscriptions_no_overlap_update BEFORE UPDATE ON subscriptions FOR EACH ROW
BEGIN
  DECLARE locked_owner BINARY(16);
  DECLARE overlapping BINARY(16) DEFAULT NULL;
  DECLARE CONTINUE HANDLER FOR NOT FOUND SET overlapping = NULL;
  IF NEW.tenant_id <> OLD.tenant_id OR NEW.id <> OLD.id OR NEW.user_id <> OLD.user_id THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Assignment identity is immutable'; END IF;
  SELECT id INTO locked_owner FROM users
    WHERE tenant_id = NEW.tenant_id AND id = NEW.user_id FOR UPDATE;
  SELECT id INTO overlapping FROM assignment_intervals
    WHERE tenant_id = NEW.tenant_id AND kind = 'subscriptions' AND owner_id = NEW.user_id
      AND id <> OLD.id
      AND cancelled = FALSE AND NEW.status <> 'cancelled'
      AND (ends_at IS NULL OR NEW.starts_at < ends_at)
      AND (NEW.ends_at IS NULL OR starts_at < NEW.ends_at)
    LIMIT 1 FOR UPDATE;
  IF overlapping IS NOT NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Overlapping validity intervals';
  END IF;
  UPDATE assignment_intervals SET starts_at = NEW.starts_at, ends_at = NEW.ends_at,
    cancelled = NEW.status = 'cancelled'
    WHERE tenant_id = NEW.tenant_id AND kind = 'subscriptions' AND id = NEW.id;
END$$

CREATE TRIGGER IF NOT EXISTS role_permissions_platform_insert BEFORE INSERT ON role_permissions FOR EACH ROW
BEGIN
  IF NEW.tenant_id <> 0x00000000000040008000000000000001 AND EXISTS (
    SELECT 1 FROM permissions WHERE code = NEW.permission_code AND platform_only = TRUE
  ) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Platform permission cannot belong to a tenant role';
  END IF;
END$$

CREATE TRIGGER IF NOT EXISTS role_permissions_platform_update BEFORE UPDATE ON role_permissions FOR EACH ROW
BEGIN
  IF NEW.tenant_id <> 0x00000000000040008000000000000001 AND EXISTS (
    SELECT 1 FROM permissions WHERE code = NEW.permission_code AND platform_only = TRUE
  ) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Platform permission cannot belong to a tenant role';
  END IF;
END$$

CREATE TRIGGER IF NOT EXISTS snapshot_monotonic BEFORE UPDATE ON activity_snapshots FOR EACH ROW
BEGIN
  IF NEW.tenant_id <> OLD.tenant_id OR NEW.device_id <> OLD.device_id OR NEW.day <> OLD.day
    OR NEW.sequence < OLD.sequence OR
    (NEW.sequence = OLD.sequence AND (NEW.body_hash <> OLD.body_hash OR NEW.snapshot_id <> OLD.snapshot_id
      OR NEW.active_seconds <> OLD.active_seconds OR NEW.idle_seconds <> OLD.idle_seconds
      OR NEW.user_id <> OLD.user_id OR NEW.device_assignment_id <> OLD.device_assignment_id)) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Stale or conflicting activity snapshot';
  END IF;
END$$

CREATE TRIGGER IF NOT EXISTS user_assignments_interval_delete AFTER DELETE ON user_assignments FOR EACH ROW
BEGIN
  DELETE FROM assignment_intervals WHERE tenant_id = OLD.tenant_id AND kind = 'user_assignments' AND id = OLD.id;
END$$

CREATE TRIGGER IF NOT EXISTS device_assignments_interval_delete AFTER DELETE ON device_assignments FOR EACH ROW
BEGIN
  DELETE FROM assignment_intervals WHERE tenant_id = OLD.tenant_id AND kind = 'device_assignments' AND id = OLD.id;
END$$

CREATE TRIGGER IF NOT EXISTS subscriptions_interval_delete AFTER DELETE ON subscriptions FOR EACH ROW
BEGIN
  DELETE FROM assignment_intervals WHERE tenant_id = OLD.tenant_id AND kind = 'subscriptions' AND id = OLD.id;
END$$

DELIMITER ;
