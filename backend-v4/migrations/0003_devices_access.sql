-- Device identity, enrollment, commands and tenant-bound integration credentials.

SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci;

SET time_zone = '+00:00';

CREATE TABLE IF NOT EXISTS devices (
  tenant_id BINARY(16) NOT NULL,
  id BINARY(16) NOT NULL,
  user_id BINARY(16) NOT NULL,
  hostname VARCHAR(120) NOT NULL,
  status ENUM('active','revoked','decommissioned') NOT NULL DEFAULT 'active',
  agent_version VARCHAR(40) NOT NULL,
  last_seen_at DATETIME(6) NULL,
  os_edition VARCHAR(100) NOT NULL,
  os_build VARCHAR(80) NULL,
  cpu VARCHAR(160) NOT NULL,
  ram_bytes BIGINT UNSIGNED NOT NULL,
  disk_bytes BIGINT UNSIGNED NULL,
  specs JSON NULL,
  encryption_state ENUM('reported_enabled','reported_disabled','unknown') NOT NULL DEFAULT 'unknown',
  capabilities JSON NOT NULL,
  policy_version BIGINT UNSIGNED NULL,
  auth_version BIGINT UNSIGNED NOT NULL DEFAULT 1,
  command_sequence BIGINT UNSIGNED NOT NULL DEFAULT 0,
  release_ring VARCHAR(80) NOT NULL DEFAULT 'stable',
  version BIGINT UNSIGNED NOT NULL DEFAULT 1,
  PRIMARY KEY (tenant_id, id),
  FOREIGN KEY (tenant_id, user_id) REFERENCES users (tenant_id, id),
  KEY (tenant_id, status, last_seen_at, id),
  KEY (tenant_id, user_id, id),
  CHECK (JSON_TYPE(capabilities) = 'ARRAY'),
  CHECK (policy_version IS NULL OR policy_version > 0),
  FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS device_assignments (
  tenant_id BINARY(16) NOT NULL,
  id BINARY(16) NOT NULL,
  device_id BINARY(16) NOT NULL,
  user_id BINARY(16) NOT NULL,
  starts_at DATETIME(6) NOT NULL,
  ends_at DATETIME(6) NULL,
  open_device_id BINARY(16) GENERATED ALWAYS AS (IF(ends_at IS NULL,device_id,NULL)) STORED,
  reason VARCHAR(500) NOT NULL,
  PRIMARY KEY (tenant_id, id),
  UNIQUE KEY (tenant_id, id, device_id, user_id),
  UNIQUE KEY (tenant_id, open_device_id),
  FOREIGN KEY (tenant_id, device_id) REFERENCES devices (tenant_id, id),
  FOREIGN KEY (tenant_id, user_id) REFERENCES users (tenant_id, id),
  KEY (tenant_id, device_id, starts_at),
  CHECK (ends_at IS NULL OR ends_at > starts_at),
  FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS device_keys (
  tenant_id BINARY(16) NOT NULL,
  id BINARY(16) NOT NULL,
  device_id BINARY(16) NOT NULL,
  thumbprint BINARY(32) NOT NULL,
  algorithm VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'ecdsa-p256-sha256',
  jwk_x BINARY(32) NOT NULL,
  jwk_y BINARY(32) NOT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  revoked_at DATETIME(6) NULL,
  PRIMARY KEY (tenant_id, id),
  UNIQUE KEY (tenant_id, thumbprint),
  UNIQUE KEY (tenant_id, id, device_id),
  FOREIGN KEY (tenant_id, device_id) REFERENCES devices (tenant_id, id),
  CHECK (algorithm = 'ecdsa-p256-sha256'),
  FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS enrollments (
  tenant_id BINARY(16) NOT NULL,
  id BINARY(16) NOT NULL,
  user_id BINARY(16) NOT NULL,
  device_id BINARY(16) NULL,
  public_key_thumbprint BINARY(32) NOT NULL,
  ticket_hash BINARY(32) NOT NULL,
  status ENUM('pending','consumed','revoked','expired') NOT NULL DEFAULT 'pending',
  reason VARCHAR(500) NOT NULL,
  created_at DATETIME(6) NOT NULL,
  expires_at DATETIME(6) NOT NULL,
  consumed_at DATETIME(6) NULL,
  PRIMARY KEY (tenant_id, id),
  UNIQUE KEY (tenant_id, ticket_hash),
  UNIQUE KEY (tenant_id, id, device_id),
  KEY (ticket_hash, tenant_id),
  KEY (tenant_id, status, expires_at),
  KEY (expires_at),
  FOREIGN KEY (tenant_id, user_id) REFERENCES users (tenant_id, id),
  FOREIGN KEY (tenant_id, device_id) REFERENCES devices (tenant_id, id),
  CHECK (expires_at > created_at AND expires_at <= created_at + INTERVAL 10 MINUTE),
  CHECK ((status = 'consumed') = (consumed_at IS NOT NULL)),
  FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS device_challenges (
  tenant_id BINARY(16) NOT NULL,
  id BINARY(16) NOT NULL,
  device_id BINARY(16) NULL,
  enrollment_id BINARY(16) NULL,
  nonce_hash BINARY(32) NOT NULL,
  created_at DATETIME(6) NOT NULL,
  expires_at DATETIME(6) NOT NULL,
  consumed_at DATETIME(6) NULL,
  PRIMARY KEY (tenant_id, id),
  UNIQUE KEY (tenant_id, nonce_hash),
  FOREIGN KEY (tenant_id, device_id) REFERENCES devices (tenant_id, id),
  FOREIGN KEY (tenant_id, enrollment_id) REFERENCES enrollments (tenant_id, id),
  KEY (expires_at),
  CHECK ((device_id IS NOT NULL) + (enrollment_id IS NOT NULL) = 1),
  CHECK (expires_at > created_at AND expires_at <= created_at + INTERVAL 60 SECOND),
  FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS device_sessions (
  tenant_id BINARY(16) NOT NULL,
  id BINARY(16) NOT NULL,
  device_id BINARY(16) NOT NULL,
  user_id BINARY(16) NOT NULL,
  assignment_id BINARY(16) NOT NULL,
  key_id BINARY(16) NOT NULL,
  token_hash BINARY(32) NOT NULL,
  device_auth_version BIGINT UNSIGNED NOT NULL,
  user_auth_version BIGINT UNSIGNED NOT NULL,
  tenant_auth_version BIGINT UNSIGNED NOT NULL,
  created_at DATETIME(6) NOT NULL,
  expires_at DATETIME(6) NOT NULL,
  revoked_at DATETIME(6) NULL,
  PRIMARY KEY (tenant_id, id),
  UNIQUE KEY (tenant_id, token_hash),
  KEY (token_hash, tenant_id),
  KEY (expires_at),
  FOREIGN KEY (tenant_id, assignment_id, device_id, user_id) REFERENCES device_assignments (tenant_id, id, device_id, user_id),
  FOREIGN KEY (tenant_id, key_id, device_id) REFERENCES device_keys (tenant_id, id, device_id),
  CHECK (expires_at > created_at AND expires_at <= created_at + INTERVAL 1 HOUR),
  FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS device_signature_nonces (
  tenant_id BINARY(16) NOT NULL,
  key_id BINARY(16) NOT NULL,
  nonce_hash BINARY(32) NOT NULL,
  created_at DATETIME(6) NOT NULL,
  expires_at DATETIME(6) NOT NULL,
  PRIMARY KEY (tenant_id, key_id, nonce_hash),
  FOREIGN KEY (tenant_id, key_id) REFERENCES device_keys (tenant_id, id),
  KEY (expires_at),
  CHECK (expires_at >= created_at + INTERVAL 120 SECOND AND expires_at <= created_at + INTERVAL 300 SECOND),
  FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS device_sync_state (
  tenant_id BINARY(16) NOT NULL,
  device_id BINARY(16) NOT NULL,
  enrollment_id BINARY(16) NOT NULL,
  last_sequence BIGINT UNSIGNED NOT NULL DEFAULT 0,
  updated_at DATETIME(6) NOT NULL,
  PRIMARY KEY (tenant_id, device_id, enrollment_id),
  FOREIGN KEY (tenant_id, device_id) REFERENCES devices (tenant_id, id),
  FOREIGN KEY (tenant_id, enrollment_id, device_id) REFERENCES enrollments (tenant_id, id, device_id),
  FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS device_command (
  tenant_id BINARY(16) NOT NULL,
  id BINARY(16) NOT NULL,
  device_id BINARY(16) NOT NULL,
  sequence BIGINT UNSIGNED NOT NULL,
  type ENUM('lock','unlock','restart','shutdown','wipe','refresh_policy') NOT NULL,
  status ENUM('pending','delivered','running','succeeded','failed','expired','cancelled') NOT NULL DEFAULT 'pending',
  reason VARCHAR(500) NOT NULL,
  created_at DATETIME(6) NOT NULL,
  expires_at DATETIME(6) NOT NULL,
  result_code VARCHAR(100) NULL,
  completed_at DATETIME(6) NULL,
  envelope_jws TEXT NULL,
  PRIMARY KEY (tenant_id, id),
  UNIQUE KEY (tenant_id, id, device_id),
  UNIQUE KEY (tenant_id, device_id, sequence),
  KEY (tenant_id, device_id, status, expires_at),
  FOREIGN KEY (tenant_id, device_id) REFERENCES devices (tenant_id, id),
  CHECK (expires_at > created_at AND sequence > 0),
  CHECK ((status IN ('succeeded','failed') AND result_code IS NOT NULL AND completed_at IS NOT NULL)
    OR (status NOT IN ('succeeded','failed') AND result_code IS NULL AND completed_at IS NULL)),
  FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS device_command_results (
  tenant_id BINARY(16) NOT NULL,
  device_id BINARY(16) NOT NULL,
  event_id BINARY(16) NOT NULL,
  command_id BINARY(16) NOT NULL,
  status ENUM('running','succeeded','failed') NOT NULL,
  at DATETIME(6) NOT NULL,
  code VARCHAR(100) NULL,
  body_hash BINARY(32) NOT NULL,
  PRIMARY KEY (tenant_id, device_id, event_id),
  FOREIGN KEY (tenant_id, command_id, device_id) REFERENCES device_command (tenant_id, id, device_id),
  FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS integrations (
  tenant_id BINARY(16) NOT NULL,
  id BINARY(16) NOT NULL,
  name VARCHAR(120) NOT NULL,
  owner_admin_id BINARY(16) NULL,
  auth_type ENUM('api_key','oauth2') NOT NULL,
  active BOOLEAN NOT NULL DEFAULT TRUE,
  auth_version BIGINT UNSIGNED NOT NULL DEFAULT 1,
  created_at DATETIME(6) NOT NULL,
  expires_at DATETIME(6) NOT NULL,
  PRIMARY KEY (tenant_id, id),
  UNIQUE KEY (tenant_id, id, auth_type),
  FOREIGN KEY (tenant_id, owner_admin_id) REFERENCES admin_accounts (tenant_id, id),
  CHECK (expires_at > created_at),
  FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS integration_scopes (
  tenant_id BINARY(16) NOT NULL,
  integration_id BINARY(16) NOT NULL,
  scope_code VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  PRIMARY KEY (tenant_id, integration_id, scope_code),
  FOREIGN KEY (tenant_id, integration_id) REFERENCES integrations (tenant_id, id),
  FOREIGN KEY (scope_code) REFERENCES scopes (code),
  FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS api_keys (
  tenant_id BINARY(16) NOT NULL,
  id BINARY(16) NOT NULL,
  integration_id BINARY(16) NOT NULL,
  auth_type ENUM('api_key','oauth2') NOT NULL DEFAULT 'api_key',
  public_prefix VARCHAR(160) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  secret_hash BINARY(32) NOT NULL,
  created_at DATETIME(6) NOT NULL,
  expires_at DATETIME(6) NOT NULL,
  revoked_at DATETIME(6) NULL,
  PRIMARY KEY (tenant_id, id),
  UNIQUE KEY (tenant_id, public_prefix),
  UNIQUE KEY (tenant_id, secret_hash),
  KEY (public_prefix, tenant_id),
  KEY (expires_at),
  FOREIGN KEY (tenant_id, integration_id, auth_type) REFERENCES integrations (tenant_id, id, auth_type),
  CHECK (auth_type = 'api_key'),
  CHECK (expires_at > created_at AND expires_at <= created_at + INTERVAL 90 DAY),
  FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS oauth_clients (
  tenant_id BINARY(16) NOT NULL,
  id BINARY(16) NOT NULL,
  integration_id BINARY(16) NOT NULL,
  auth_type ENUM('api_key','oauth2') NOT NULL DEFAULT 'oauth2',
  client_id VARCHAR(160) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  secret_hash BINARY(32) NOT NULL,
  created_at DATETIME(6) NOT NULL,
  expires_at DATETIME(6) NOT NULL,
  revoked_at DATETIME(6) NULL,
  PRIMARY KEY (tenant_id, id),
  UNIQUE KEY (tenant_id, client_id),
  UNIQUE KEY (tenant_id, id, integration_id),
  KEY (client_id, tenant_id),
  FOREIGN KEY (tenant_id, integration_id, auth_type) REFERENCES integrations (tenant_id, id, auth_type),
  CHECK (auth_type = 'oauth2'),
  CHECK (expires_at > created_at),
  FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS oauth_access_tokens (
  tenant_id BINARY(16) NOT NULL,
  id BINARY(16) NOT NULL,
  client_id BINARY(16) NOT NULL,
  integration_id BINARY(16) NOT NULL,
  token_hash BINARY(32) NOT NULL,
  auth_version BIGINT UNSIGNED NOT NULL,
  audience VARCHAR(80) NOT NULL DEFAULT 'keeper-external-v1',
  created_at DATETIME(6) NOT NULL,
  expires_at DATETIME(6) NOT NULL,
  revoked_at DATETIME(6) NULL,
  PRIMARY KEY (tenant_id, id),
  UNIQUE KEY (tenant_id, token_hash),
  UNIQUE KEY (tenant_id, id, integration_id),
  KEY (token_hash, tenant_id),
  KEY (expires_at),
  FOREIGN KEY (tenant_id, client_id, integration_id) REFERENCES oauth_clients (tenant_id, id, integration_id),
  CHECK (audience = 'keeper-external-v1'),
  CHECK (expires_at > created_at AND expires_at <= created_at + INTERVAL 15 MINUTE),
  FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS oauth_token_scopes (
  tenant_id BINARY(16) NOT NULL,
  token_id BINARY(16) NOT NULL,
  integration_id BINARY(16) NOT NULL,
  scope_code VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  PRIMARY KEY (tenant_id, token_id, scope_code),
  FOREIGN KEY (tenant_id, token_id, integration_id) REFERENCES oauth_access_tokens (tenant_id, id, integration_id),
  FOREIGN KEY (tenant_id, integration_id, scope_code) REFERENCES integration_scopes (tenant_id, integration_id, scope_code),
  FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
