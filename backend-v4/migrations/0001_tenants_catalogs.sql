-- AZCKeeper v4 / MySQL >= 8.0.30. Global catalogs are the only business tables without tenant_id.

SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci;

SET time_zone = '+00:00';

CREATE TABLE IF NOT EXISTS tenants (
  tenant_id BINARY(16) NOT NULL,
  name VARCHAR(120) NOT NULL,
  status ENUM('active','suspended') NOT NULL DEFAULT 'active',
  timezone VARCHAR(64) NOT NULL DEFAULT 'America/Bogota',
  rbac_self_management BOOLEAN NOT NULL DEFAULT FALSE,
  auth_version BIGINT UNSIGNED NOT NULL DEFAULT 1,
  policy_version BIGINT UNSIGNED NOT NULL DEFAULT 1,
  version BIGINT UNSIGNED NOT NULL DEFAULT 1,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (tenant_id),
  CHECK (rbac_self_management IN (0,1)),
  CHECK (version > 0 AND auth_version > 0 AND policy_version > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS branding (
  tenant_id BINARY(16) NOT NULL,
  display_name VARCHAR(120) NOT NULL,
  logo_url VARCHAR(2048) NULL,
  primary_color CHAR(7) NOT NULL DEFAULT '#003A5D',
  accent_color CHAR(7) NOT NULL DEFAULT '#BE1622',
  PRIMARY KEY (tenant_id),
  FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS permissions (
  code VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  label VARCHAR(160) NOT NULL,
  resource VARCHAR(80) NOT NULL,
  action VARCHAR(80) NOT NULL,
  delegable BOOLEAN NOT NULL DEFAULT TRUE,
  platform_only BOOLEAN NOT NULL DEFAULT FALSE,
  version BIGINT UNSIGNED NOT NULL DEFAULT 1,
  PRIMARY KEY (code),
  CHECK (delegable IN (0,1) AND platform_only IN (0,1)),
  CHECK (platform_only = 0 OR delegable = 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS role_templates (
  seed_key VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  name VARCHAR(120) NOT NULL,
  scope_kind ENUM('tenant','area','site','self') NOT NULL,
  platform_only BOOLEAN NOT NULL DEFAULT FALSE,
  version BIGINT UNSIGNED NOT NULL DEFAULT 1,
  PRIMARY KEY (seed_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS role_template_permissions (
  seed_key VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  permission_code VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  PRIMARY KEY (seed_key, permission_code),
  FOREIGN KEY (seed_key) REFERENCES role_templates (seed_key),
  FOREIGN KEY (permission_code) REFERENCES permissions (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS scopes (
  code VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  description VARCHAR(255) NOT NULL,
  PRIMARY KEY (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS modules (
  code VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  name VARCHAR(120) NOT NULL,
  active BOOLEAN NOT NULL DEFAULT TRUE,
  PRIMARY KEY (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

