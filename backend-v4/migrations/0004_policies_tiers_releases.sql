-- Versioned policy documents, per-member entitlements, signed release manifests.

SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci;

SET time_zone = '+00:00';

CREATE TABLE IF NOT EXISTS policy_documents (
  tenant_id BINARY(16) NOT NULL,
  id BINARY(16) NOT NULL,
  name VARCHAR(120) NOT NULL,
  enabled BOOLEAN NOT NULL DEFAULT TRUE,
  version BIGINT UNSIGNED NOT NULL DEFAULT 1,
  PRIMARY KEY (tenant_id, id),
  CHECK (version > 0),
  FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS policy_versions (
  tenant_id BINARY(16) NOT NULL,
  policy_id BINARY(16) NOT NULL,
  policy_version BIGINT UNSIGNED NOT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  document JSON NOT NULL,
  content_hash BINARY(32) NOT NULL,
  PRIMARY KEY (tenant_id, policy_id, policy_version),
  FOREIGN KEY (tenant_id, policy_id) REFERENCES policy_documents (tenant_id, id),
  CHECK (policy_version > 0 AND JSON_TYPE(document) = 'OBJECT'),
  FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS policy_rules (
  tenant_id BINARY(16) NOT NULL,
  id BINARY(16) NOT NULL,
  policy_id BINARY(16) NOT NULL,
  policy_version BIGINT UNSIGNED NOT NULL,
  kind ENUM('web','download','installation','schedule','os','usb','encryption') NOT NULL,
  effect ENUM('allow','deny','require') NOT NULL,
  schedule_id BINARY(16) NULL,
  priority SMALLINT UNSIGNED NOT NULL,
  targets JSON NOT NULL,
  PRIMARY KEY (tenant_id, policy_id, policy_version, id),
  FOREIGN KEY (tenant_id, policy_id, policy_version) REFERENCES policy_versions (tenant_id, policy_id, policy_version),
  FOREIGN KEY (tenant_id, schedule_id) REFERENCES schedules (tenant_id, id),
  CHECK (priority <= 10000 AND JSON_TYPE(targets) = 'ARRAY' AND JSON_LENGTH(targets) <= 1000),
  FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS policy_assignments (
  tenant_id BINARY(16) NOT NULL,
  id BINARY(16) NOT NULL,
  policy_id BINARY(16) NOT NULL,
  policy_version BIGINT UNSIGNED NOT NULL,
  scope ENUM('global','tenant','area','site','user','device') NOT NULL,
  area_id BINARY(16) NULL,
  area_kind VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'area',
  site_id BINARY(16) NULL,
  site_kind VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'site',
  user_id BINARY(16) NULL,
  device_id BINARY(16) NULL,
  priority SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  enabled BOOLEAN NOT NULL DEFAULT TRUE,
  scope_target BINARY(16) GENERATED ALWAYS AS (COALESCE(area_id,site_id,user_id,device_id,tenant_id)) STORED,
  PRIMARY KEY (tenant_id, id),
  UNIQUE KEY (tenant_id, scope, scope_target, priority),
  FOREIGN KEY (tenant_id, policy_id, policy_version) REFERENCES policy_versions (tenant_id, policy_id, policy_version),
  FOREIGN KEY (tenant_id, area_id, area_kind) REFERENCES org_units (tenant_id, id, kind),
  FOREIGN KEY (tenant_id, site_id, site_kind) REFERENCES org_units (tenant_id, id, kind),
  FOREIGN KEY (tenant_id, user_id) REFERENCES users (tenant_id, id),
  FOREIGN KEY (tenant_id, device_id) REFERENCES devices (tenant_id, id),
  CHECK (area_kind = 'area' AND site_kind = 'site' AND priority <= 10000),
  CHECK ((scope IN ('global','tenant') AND area_id IS NULL AND site_id IS NULL AND user_id IS NULL AND device_id IS NULL) OR (scope = 'area' AND area_id IS NOT NULL AND site_id IS NULL AND user_id IS NULL AND device_id IS NULL) OR (scope = 'site' AND site_id IS NOT NULL AND area_id IS NULL AND user_id IS NULL AND device_id IS NULL) OR (scope = 'user' AND user_id IS NOT NULL AND area_id IS NULL AND site_id IS NULL AND device_id IS NULL) OR (scope = 'device' AND device_id IS NOT NULL AND area_id IS NULL AND site_id IS NULL AND user_id IS NULL)),
  CHECK (scope <> 'global' OR tenant_id = 0x00000000000040008000000000000001),
  FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS effective_policies (
  tenant_id BINARY(16) NOT NULL,
  device_id BINARY(16) NOT NULL,
  policy_version BIGINT UNSIGNED NOT NULL,
  compiler_version VARCHAR(80) NOT NULL,
  composition_hash BINARY(32) NOT NULL,
  document JSON NOT NULL,
  content_hash BINARY(32) NOT NULL,
  key_id VARCHAR(100) NULL,
  envelope_jws MEDIUMTEXT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (tenant_id, device_id, policy_version),
  FOREIGN KEY (tenant_id, device_id) REFERENCES devices (tenant_id, id),
  CHECK (policy_version > 0 AND JSON_TYPE(document) = 'OBJECT'),
  CHECK (OCTET_LENGTH(document) <= 1048576),
  CHECK ((key_id IS NULL) = (envelope_jws IS NULL)),
  FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS tier (
  tenant_id BINARY(16) NOT NULL,
  id BINARY(16) NOT NULL,
  name VARCHAR(80) NOT NULL,
  badge_label VARCHAR(120) NOT NULL,
  active BOOLEAN NOT NULL DEFAULT TRUE,
  version BIGINT UNSIGNED NOT NULL DEFAULT 1,
  PRIMARY KEY (tenant_id, id),
  UNIQUE KEY (tenant_id, name),
  FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS tier_module (
  tenant_id BINARY(16) NOT NULL,
  tier_id BINARY(16) NOT NULL,
  module_code VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  PRIMARY KEY (tenant_id, tier_id, module_code),
  FOREIGN KEY (tenant_id, tier_id) REFERENCES tier (tenant_id, id),
  FOREIGN KEY (module_code) REFERENCES modules (code),
  FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS firma_module_override (
  tenant_id BINARY(16) NOT NULL,
  firm_id BINARY(16) NOT NULL,
  firm_kind VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'firm',
  module_code VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  enabled BOOLEAN NOT NULL,
  reason VARCHAR(500) NOT NULL,
  PRIMARY KEY (tenant_id, firm_id, module_code),
  FOREIGN KEY (tenant_id, firm_id, firm_kind) REFERENCES org_units (tenant_id, id, kind),
  FOREIGN KEY (module_code) REFERENCES modules (code),
  CHECK (firm_kind = 'firm' AND enabled IN (0,1)),
  FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS subscriptions (
  tenant_id BINARY(16) NOT NULL,
  id BINARY(16) NOT NULL,
  user_id BINARY(16) NOT NULL,
  tier_id BINARY(16) NOT NULL,
  starts_at DATETIME(6) NOT NULL,
  ends_at DATETIME(6) NULL,
  status ENUM('active','scheduled','cancelled') NOT NULL DEFAULT 'active',
  version BIGINT UNSIGNED NOT NULL DEFAULT 1,
  open_user_id BINARY(16) GENERATED ALWAYS AS (IF(ends_at IS NULL AND status <> 'cancelled',user_id,NULL)) STORED,
  PRIMARY KEY (tenant_id, id),
  UNIQUE KEY (tenant_id, open_user_id),
  KEY (tenant_id, user_id, starts_at, ends_at),
  FOREIGN KEY (tenant_id, user_id) REFERENCES users (tenant_id, id),
  FOREIGN KEY (tenant_id, tier_id) REFERENCES tier (tenant_id, id),
  CHECK (ends_at IS NULL OR ends_at > starts_at),
  FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS client_releases (
  tenant_id BINARY(16) NOT NULL,
  id BINARY(16) NOT NULL,
  version VARCHAR(40) NOT NULL,
  channel VARCHAR(40) NOT NULL,
  sequence BIGINT UNSIGNED NOT NULL,
  min_agent_version VARCHAR(40) NOT NULL,
  architecture ENUM('x64','arm64') NOT NULL,
  artifact_url VARCHAR(2048) NOT NULL,
  size_bytes BIGINT UNSIGNED NOT NULL,
  sha256 BINARY(32) NOT NULL,
  key_id VARCHAR(100) NOT NULL,
  manifest_jws TEXT NOT NULL,
  published_at DATETIME(6) NOT NULL,
  PRIMARY KEY (tenant_id, id),
  UNIQUE KEY (tenant_id, channel, architecture, sequence),
  UNIQUE KEY (tenant_id, channel, architecture, version),
  CHECK (tenant_id = 0x00000000000040008000000000000001),
  CHECK (sequence > 0 AND size_bytes > 0 AND CHAR_LENGTH(key_id) > 0),
  CHECK (CHAR_LENGTH(manifest_jws) BETWEEN 16 AND 16384 AND artifact_url LIKE 'https://%'),
  FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS release_deployments (
  tenant_id BINARY(16) NOT NULL,
  release_tenant_id BINARY(16) NOT NULL DEFAULT 0x00000000000040008000000000000001,
  release_id BINARY(16) NOT NULL,
  ring VARCHAR(80) NOT NULL,
  percentage TINYINT UNSIGNED NOT NULL,
  enabled BOOLEAN NOT NULL DEFAULT FALSE,
  PRIMARY KEY (tenant_id, release_id, ring),
  FOREIGN KEY (release_tenant_id, release_id) REFERENCES client_releases (tenant_id, id),
  CHECK (release_tenant_id = 0x00000000000040008000000000000001 AND percentage <= 100),
  FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

