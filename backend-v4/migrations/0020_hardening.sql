CREATE TABLE IF NOT EXISTS tenant_hardening_settings (
  tenant_id BINARY(16) NOT NULL,
  admin_name VARCHAR(20) NOT NULL DEFAULT 'azcadmin',
  shared_password_enc BLOB NULL,
  hardening_mode ENUM('auto','panel') NOT NULL DEFAULT 'panel',
  deny_network_logon BOOLEAN NOT NULL DEFAULT TRUE,
  updated_by BINARY(16) NULL,
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (tenant_id),
  FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id),
  FOREIGN KEY (tenant_id, updated_by) REFERENCES principals (tenant_id, id),
  CHECK (deny_network_logon IN (0,1)),
  CHECK (shared_password_enc IS NULL OR OCTET_LENGTH(shared_password_enc) >= 41)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS device_hardening_status (
  device_id BINARY(16) NOT NULL,
  tenant_id BINARY(16) NOT NULL,
  state ENUM('none','pending','hardened','recovery_required','panel_wait') NOT NULL DEFAULT 'none',
  last_step VARCHAR(100) NULL,
  detail VARCHAR(1000) NULL,
  hardened_at DATETIME(6) NULL,
  reported_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (tenant_id, device_id),
  KEY (device_id, tenant_id),
  KEY (tenant_id, state, reported_at),
  FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id),
  FOREIGN KEY (tenant_id, device_id) REFERENCES devices (tenant_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

ALTER TABLE device_command MODIFY COLUMN type
  ENUM('lock','unlock','restart','shutdown','wipe','refresh_policy','harden','unharden') NOT NULL;

INSERT INTO permissions (code,label,resource,action,delegable,platform_only)
SELECT 'hardening.gestionar','Gestionar endurecimiento','hardening','gestionar',1,0
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE code='hardening.gestionar');

INSERT INTO role_template_permissions (seed_key,permission_code)
SELECT seed_key,'hardening.gestionar' FROM role_templates t
WHERE seed_key IN ('super_admin','it') AND NOT EXISTS (
  SELECT 1 FROM role_template_permissions p WHERE p.seed_key=t.seed_key AND p.permission_code='hardening.gestionar'
);

INSERT INTO role_permissions (tenant_id,role_id,permission_code)
SELECT r.tenant_id,r.id,'hardening.gestionar' FROM roles r
WHERE r.seed_key IN ('super_admin','it') AND NOT EXISTS (
  SELECT 1 FROM role_permissions p WHERE p.tenant_id=r.tenant_id AND p.role_id=r.id AND p.permission_code='hardening.gestionar'
);
