-- Organization, memberships and tenant-local roles. No implicit global scope.

SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci;

SET time_zone = '+00:00';

CREATE TABLE IF NOT EXISTS org_units (
  tenant_id BINARY(16) NOT NULL,
  id BINARY(16) NOT NULL,
  kind VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  name VARCHAR(120) NOT NULL,
  parent_id BINARY(16) NULL,
  active BOOLEAN NOT NULL DEFAULT TRUE,
  version BIGINT UNSIGNED NOT NULL DEFAULT 1,
  PRIMARY KEY (tenant_id, id),
  UNIQUE KEY (tenant_id, id, kind),
  KEY (tenant_id, kind, active, name),
  FOREIGN KEY (tenant_id, parent_id) REFERENCES org_units (tenant_id, id),
  CHECK (kind IN ('firm','site','area','position')),
  CHECK (parent_id IS NULL OR parent_id <> id),
  FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS sociedades (
  tenant_id BINARY(16) NOT NULL,
  id BINARY(16) NOT NULL,
  name VARCHAR(120) NOT NULL,
  external_ref VARCHAR(160) NULL,
  active BOOLEAN NOT NULL DEFAULT TRUE,
  firm_id BINARY(16) NOT NULL,
  firm_kind VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'firm',
  CHECK (firm_kind = 'firm'),
  FOREIGN KEY (tenant_id, firm_id, firm_kind) REFERENCES org_units (tenant_id, id, kind),
  PRIMARY KEY (tenant_id, id),
  UNIQUE KEY (tenant_id, external_ref),
  FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS schedules (
  tenant_id BINARY(16) NOT NULL,
  id BINARY(16) NOT NULL,
  name VARCHAR(120) NOT NULL,
  timezone VARCHAR(64) NOT NULL,
  start_local TIME NOT NULL,
  end_local TIME NOT NULL,
  version BIGINT UNSIGNED NOT NULL DEFAULT 1,
  PRIMARY KEY (tenant_id, id),
  CHECK (start_local >= '00:00:00' AND start_local < '24:00:00' AND end_local >= '00:00:00' AND end_local < '24:00:00'),
  FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS schedule_days (
  tenant_id BINARY(16) NOT NULL,
  schedule_id BINARY(16) NOT NULL,
  weekday TINYINT UNSIGNED NOT NULL,
  PRIMARY KEY (tenant_id, schedule_id, weekday),
  FOREIGN KEY (tenant_id, schedule_id) REFERENCES schedules (tenant_id, id),
  CHECK (weekday BETWEEN 1 AND 7),
  FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS users (
  tenant_id BINARY(16) NOT NULL,
  id BINARY(16) NOT NULL,
  display_name VARCHAR(160) NOT NULL,
  email VARCHAR(254) NULL,
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  panel_login_enabled BOOLEAN NOT NULL DEFAULT FALSE,
  auth_version BIGINT UNSIGNED NOT NULL DEFAULT 1,
  version BIGINT UNSIGNED NOT NULL DEFAULT 1,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  firm_id BINARY(16) NOT NULL,
  firm_kind VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'firm',
  CHECK (firm_kind = 'firm'),
  FOREIGN KEY (tenant_id, firm_id, firm_kind) REFERENCES org_units (tenant_id, id, kind),
  site_id BINARY(16) NOT NULL,
  site_kind VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'site',
  CHECK (site_kind = 'site'),
  FOREIGN KEY (tenant_id, site_id, site_kind) REFERENCES org_units (tenant_id, id, kind),
  area_id BINARY(16) NOT NULL,
  area_kind VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'area',
  CHECK (area_kind = 'area'),
  FOREIGN KEY (tenant_id, area_id, area_kind) REFERENCES org_units (tenant_id, id, kind),
  position_id BINARY(16) NOT NULL,
  position_kind VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'position',
  CHECK (position_kind = 'position'),
  FOREIGN KEY (tenant_id, position_id, position_kind) REFERENCES org_units (tenant_id, id, kind),
  schedule_id BINARY(16) NOT NULL,
  sociedad_id BINARY(16) NULL,
  PRIMARY KEY (tenant_id, id),
  UNIQUE KEY (tenant_id, email),
  KEY (tenant_id, status, display_name, id),
  KEY (tenant_id, area_id, site_id, status, id),
  FOREIGN KEY (tenant_id, schedule_id) REFERENCES schedules (tenant_id, id),
  FOREIGN KEY (tenant_id, sociedad_id) REFERENCES sociedades (tenant_id, id),
  CHECK (panel_login_enabled IN (0,1)),
  FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS user_external_refs (
  tenant_id BINARY(16) NOT NULL,
  user_id BINARY(16) NOT NULL,
  source VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  external_ref VARCHAR(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  PRIMARY KEY (tenant_id, source, external_ref),
  UNIQUE KEY (tenant_id, user_id, source),
  FOREIGN KEY (tenant_id, user_id) REFERENCES users (tenant_id, id),
  FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS user_assignments (
  tenant_id BINARY(16) NOT NULL,
  id BINARY(16) NOT NULL,
  user_id BINARY(16) NOT NULL,
  firm_id BINARY(16) NOT NULL,
  firm_kind VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'firm',
  CHECK (firm_kind = 'firm'),
  FOREIGN KEY (tenant_id, firm_id, firm_kind) REFERENCES org_units (tenant_id, id, kind),
  site_id BINARY(16) NOT NULL,
  site_kind VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'site',
  CHECK (site_kind = 'site'),
  FOREIGN KEY (tenant_id, site_id, site_kind) REFERENCES org_units (tenant_id, id, kind),
  area_id BINARY(16) NOT NULL,
  area_kind VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'area',
  CHECK (area_kind = 'area'),
  FOREIGN KEY (tenant_id, area_id, area_kind) REFERENCES org_units (tenant_id, id, kind),
  position_id BINARY(16) NOT NULL,
  position_kind VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'position',
  CHECK (position_kind = 'position'),
  FOREIGN KEY (tenant_id, position_id, position_kind) REFERENCES org_units (tenant_id, id, kind),
  schedule_id BINARY(16) NOT NULL,
  sociedad_id BINARY(16) NULL,
  starts_at DATETIME(6) NOT NULL,
  ends_at DATETIME(6) NULL,
  open_user_id BINARY(16) GENERATED ALWAYS AS (IF(ends_at IS NULL,user_id,NULL)) STORED,
  PRIMARY KEY (tenant_id, id),
  UNIQUE KEY (tenant_id, id, user_id),
  UNIQUE KEY (tenant_id, open_user_id),
  KEY (tenant_id, user_id, starts_at, ends_at),
  KEY (tenant_id, area_id, site_id, starts_at),
  FOREIGN KEY (tenant_id, user_id) REFERENCES users (tenant_id, id),
  FOREIGN KEY (tenant_id, schedule_id) REFERENCES schedules (tenant_id, id),
  FOREIGN KEY (tenant_id, sociedad_id) REFERENCES sociedades (tenant_id, id),
  CHECK (ends_at IS NULL OR ends_at > starts_at),
  FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS roles (
  tenant_id BINARY(16) NOT NULL,
  id BINARY(16) NOT NULL,
  name VARCHAR(120) NOT NULL,
  seed_key VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NULL,
  seed_version BIGINT UNSIGNED NULL,
  cloned_from_id BINARY(16) NULL,
  scope_kind ENUM('tenant','area','site','self') NOT NULL,
  active BOOLEAN NOT NULL DEFAULT TRUE,
  version BIGINT UNSIGNED NOT NULL DEFAULT 1,
  PRIMARY KEY (tenant_id, id),
  UNIQUE KEY (tenant_id, name),
  UNIQUE KEY (tenant_id, id, scope_kind),
  FOREIGN KEY (tenant_id, cloned_from_id) REFERENCES roles (tenant_id, id),
  FOREIGN KEY (seed_key) REFERENCES role_templates (seed_key),
  CHECK (seed_key IS NULL OR seed_key <> 'super_admin' OR tenant_id = 0x00000000000040008000000000000001),
  FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS role_permissions (
  tenant_id BINARY(16) NOT NULL,
  role_id BINARY(16) NOT NULL,
  permission_code VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  PRIMARY KEY (tenant_id, role_id, permission_code),
  FOREIGN KEY (tenant_id, role_id) REFERENCES roles (tenant_id, id),
  FOREIGN KEY (permission_code) REFERENCES permissions (code),
  FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS role_area_scopes (
  tenant_id BINARY(16) NOT NULL,
  role_id BINARY(16) NOT NULL,
  scope_kind ENUM('tenant','area','site','self') NOT NULL DEFAULT 'area',
  area_id BINARY(16) NOT NULL,
  area_kind VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'area',
  CHECK (area_kind = 'area'),
  FOREIGN KEY (tenant_id, area_id, area_kind) REFERENCES org_units (tenant_id, id, kind),
  PRIMARY KEY (tenant_id, role_id, area_id),
  FOREIGN KEY (tenant_id, role_id, scope_kind) REFERENCES roles (tenant_id, id, scope_kind),
  CHECK (scope_kind = 'area'),
  FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS role_site_scopes (
  tenant_id BINARY(16) NOT NULL,
  role_id BINARY(16) NOT NULL,
  scope_kind ENUM('tenant','area','site','self') NOT NULL DEFAULT 'site',
  site_id BINARY(16) NOT NULL,
  site_kind VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'site',
  CHECK (site_kind = 'site'),
  FOREIGN KEY (tenant_id, site_id, site_kind) REFERENCES org_units (tenant_id, id, kind),
  PRIMARY KEY (tenant_id, role_id, site_id),
  FOREIGN KEY (tenant_id, role_id, scope_kind) REFERENCES roles (tenant_id, id, scope_kind),
  CHECK (scope_kind = 'site'),
  FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS user_roles (
  tenant_id BINARY(16) NOT NULL,
  user_id BINARY(16) NOT NULL,
  role_id BINARY(16) NOT NULL,
  PRIMARY KEY (tenant_id, user_id, role_id),
  FOREIGN KEY (tenant_id, user_id) REFERENCES users (tenant_id, id),
  FOREIGN KEY (tenant_id, role_id) REFERENCES roles (tenant_id, id),
  FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS admin_accounts (
  tenant_id BINARY(16) NOT NULL,
  id BINARY(16) NOT NULL,
  user_id BINARY(16) NULL,
  email VARCHAR(254) NOT NULL,
  password_hash VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  is_platform_admin BOOLEAN NOT NULL DEFAULT FALSE,
  active BOOLEAN NOT NULL DEFAULT TRUE,
  auth_version BIGINT UNSIGNED NOT NULL DEFAULT 1,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (tenant_id, id),
  UNIQUE KEY (tenant_id, email),
  UNIQUE KEY (tenant_id, user_id),
  KEY (email, tenant_id),
  FOREIGN KEY (tenant_id, user_id) REFERENCES users (tenant_id, id),
  CHECK ((is_platform_admin = 1 AND tenant_id = 0x00000000000040008000000000000001 AND user_id IS NULL) OR (is_platform_admin = 0 AND tenant_id <> 0x00000000000040008000000000000001 AND user_id IS NOT NULL)),
  FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS admin_sessions (
  tenant_id BINARY(16) NOT NULL,
  id BINARY(16) NOT NULL,
  admin_id BINARY(16) NOT NULL,
  token_hash BINARY(32) NOT NULL,
  csrf_hash BINARY(32) NOT NULL,
  auth_version BIGINT UNSIGNED NOT NULL,
  created_at DATETIME(6) NOT NULL,
  last_seen_at DATETIME(6) NOT NULL,
  expires_at DATETIME(6) NOT NULL,
  idle_expires_at DATETIME(6) NOT NULL,
  reauthenticated_at DATETIME(6) NULL,
  revoked_at DATETIME(6) NULL,
  PRIMARY KEY (tenant_id, id),
  UNIQUE KEY (tenant_id, token_hash),
  KEY (token_hash, tenant_id),
  KEY (expires_at),
  KEY (idle_expires_at),
  FOREIGN KEY (tenant_id, admin_id) REFERENCES admin_accounts (tenant_id, id),
  CHECK (expires_at > created_at AND expires_at <= created_at + INTERVAL 8 HOUR),
  CHECK (last_seen_at >= created_at AND last_seen_at <= expires_at),
  CHECK (idle_expires_at > last_seen_at AND idle_expires_at <= last_seen_at + INTERVAL 30 MINUTE AND idle_expires_at <= expires_at),
  FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS admin_pre_sessions (
  tenant_id BINARY(16) NOT NULL DEFAULT 0x00000000000040008000000000000001,
  id BINARY(16) NOT NULL,
  token_hash BINARY(32) NOT NULL,
  csrf_hash BINARY(32) NOT NULL,
  created_at DATETIME(6) NOT NULL,
  expires_at DATETIME(6) NOT NULL,
  consumed_at DATETIME(6) NULL,
  PRIMARY KEY (tenant_id, id),
  UNIQUE KEY (tenant_id, token_hash),
  KEY (expires_at),
  FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id),
  CHECK (tenant_id = 0x00000000000040008000000000000001),
  CHECK (expires_at > created_at AND expires_at <= created_at + INTERVAL 5 MINUTE)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
