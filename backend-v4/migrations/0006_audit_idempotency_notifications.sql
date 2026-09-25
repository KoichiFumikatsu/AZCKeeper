-- Explicit principals, immutable audit, idempotency and notification/webhook resources.

SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci;

SET time_zone = '+00:00';

CREATE TABLE IF NOT EXISTS principals (
  tenant_id BINARY(16) NOT NULL,
  id BINARY(16) NOT NULL,
  kind ENUM('admin','device','integration','system') NOT NULL,
  admin_tenant_id BINARY(16) NULL,
  admin_id BINARY(16) NULL,
  device_id BINARY(16) NULL,
  integration_id BINARY(16) NULL,
  PRIMARY KEY (tenant_id, id),
  UNIQUE KEY (tenant_id, id, kind),
  UNIQUE KEY (tenant_id, admin_tenant_id, admin_id),
  UNIQUE KEY (tenant_id, device_id),
  UNIQUE KEY (tenant_id, integration_id),
  FOREIGN KEY (admin_tenant_id, admin_id) REFERENCES admin_accounts (tenant_id, id),
  FOREIGN KEY (tenant_id, device_id) REFERENCES devices (tenant_id, id),
  FOREIGN KEY (tenant_id, integration_id) REFERENCES integrations (tenant_id, id),
  CHECK (admin_tenant_id IS NULL OR admin_tenant_id = tenant_id OR admin_tenant_id = 0x00000000000040008000000000000001),
  CHECK ((kind = 'admin' AND admin_id IS NOT NULL AND admin_tenant_id IS NOT NULL AND device_id IS NULL AND integration_id IS NULL) OR (kind = 'device' AND device_id IS NOT NULL AND admin_id IS NULL AND admin_tenant_id IS NULL AND integration_id IS NULL) OR (kind = 'integration' AND integration_id IS NOT NULL AND admin_id IS NULL AND admin_tenant_id IS NULL AND device_id IS NULL) OR (kind = 'system' AND admin_id IS NULL AND admin_tenant_id IS NULL AND device_id IS NULL AND integration_id IS NULL)),
  FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS audit_log (
  tenant_id BINARY(16) NOT NULL,
  id BINARY(16) NOT NULL,
  actor_id BINARY(16) NOT NULL,
  actor_type ENUM('admin','device','integration','system') NOT NULL,
  action VARCHAR(100) NOT NULL,
  resource_type VARCHAR(80) NOT NULL,
  resource_id BINARY(16) NOT NULL,
  at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  request_id BINARY(16) NOT NULL,
  outcome ENUM('allowed','denied','failed') NOT NULL,
  changed_fields JSON NOT NULL,
  reason VARCHAR(500) NULL,
  PRIMARY KEY (tenant_id, id),
  FOREIGN KEY (tenant_id, actor_id, actor_type) REFERENCES principals (tenant_id, id, kind),
  KEY (tenant_id, at, id),
  KEY (tenant_id, resource_type, resource_id, at),
  CHECK (JSON_TYPE(changed_fields) = 'ARRAY'),
  FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS idempotency_keys (
  tenant_id BINARY(16) NOT NULL,
  principal_id BINARY(16) NOT NULL,
  method VARCHAR(10) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  route_hash BINARY(32) NOT NULL,
  route VARCHAR(500) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  idempotency_key BINARY(16) NOT NULL,
  request_hash BINARY(32) NOT NULL,
  state ENUM('processing','completed') NOT NULL DEFAULT 'processing',
  response_status SMALLINT UNSIGNED NULL,
  response_ciphertext MEDIUMBLOB NULL,
  response_key_id VARCHAR(100) NULL,
  created_at DATETIME(6) NOT NULL,
  expires_at DATETIME(6) NOT NULL,
  PRIMARY KEY (tenant_id, principal_id, method, route_hash, idempotency_key),
  FOREIGN KEY (tenant_id, principal_id) REFERENCES principals (tenant_id, id),
  KEY (expires_at),
  CHECK (expires_at > created_at AND expires_at <= created_at + INTERVAL 25 HOUR),
  CHECK ((state = 'processing' AND response_status IS NULL AND response_ciphertext IS NULL AND response_key_id IS NULL) OR (state = 'completed' AND response_status BETWEEN 100 AND 599 AND response_ciphertext IS NOT NULL AND response_key_id IS NOT NULL)),
  FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS notifications (
  tenant_id BINARY(16) NOT NULL,
  id BINARY(16) NOT NULL,
  user_id BINARY(16) NULL,
  type VARCHAR(100) NOT NULL,
  resource_type VARCHAR(80) NOT NULL,
  resource_id BINARY(16) NOT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  title VARCHAR(160) NOT NULL,
  PRIMARY KEY (tenant_id, id),
  FOREIGN KEY (tenant_id, user_id) REFERENCES users (tenant_id, id),
  KEY (tenant_id, created_at, id),
  KEY (tenant_id, user_id, created_at, id),
  FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS webhooks (
  tenant_id BINARY(16) NOT NULL,
  id BINARY(16) NOT NULL,
  url VARCHAR(2048) NOT NULL,
  active BOOLEAN NOT NULL DEFAULT TRUE,
  key_id VARCHAR(100) NOT NULL,
  signing_secret_ciphertext BLOB NOT NULL,
  encryption_key_id VARCHAR(100) NOT NULL,
  PRIMARY KEY (tenant_id, id),
  CHECK (url LIKE 'https://%'),
  FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS webhook_events (
  tenant_id BINARY(16) NOT NULL,
  webhook_id BINARY(16) NOT NULL,
  event_type VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  PRIMARY KEY (tenant_id, webhook_id, event_type),
  FOREIGN KEY (tenant_id, webhook_id) REFERENCES webhooks (tenant_id, id),
  FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS webhook_scopes (
  tenant_id BINARY(16) NOT NULL,
  webhook_id BINARY(16) NOT NULL,
  scope_code VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  PRIMARY KEY (tenant_id, webhook_id, scope_code),
  FOREIGN KEY (tenant_id, webhook_id) REFERENCES webhooks (tenant_id, id),
  FOREIGN KEY (scope_code) REFERENCES scopes (code),
  FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS webhook_deliveries (
  tenant_id BINARY(16) NOT NULL,
  id BINARY(16) NOT NULL,
  webhook_id BINARY(16) NOT NULL,
  notification_id BINARY(16) NOT NULL,
  status ENUM('pending','delivered','failed') NOT NULL DEFAULT 'pending',
  attempts INT UNSIGNED NOT NULL DEFAULT 0,
  next_attempt_at DATETIME(6) NOT NULL,
  delivered_at DATETIME(6) NULL,
  PRIMARY KEY (tenant_id, id),
  UNIQUE KEY (tenant_id, webhook_id, notification_id),
  FOREIGN KEY (tenant_id, webhook_id) REFERENCES webhooks (tenant_id, id),
  FOREIGN KEY (tenant_id, notification_id) REFERENCES notifications (tenant_id, id),
  KEY (tenant_id, status, next_attempt_at),
  FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
