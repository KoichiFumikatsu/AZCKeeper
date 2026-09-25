-- Catalog creation times support bounded cursor reads; compilation requests survive HTTP failures.
ALTER TABLE org_units ADD COLUMN created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6), ADD KEY admin_page (tenant_id,created_at,id);
ALTER TABLE schedules ADD COLUMN created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6), ADD KEY admin_page (tenant_id,created_at,id);
ALTER TABLE roles ADD COLUMN created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6), ADD KEY admin_page (tenant_id,created_at,id);
ALTER TABLE policy_documents ADD COLUMN created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6), ADD KEY admin_page (tenant_id,created_at,id);
ALTER TABLE tier ADD COLUMN created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6), ADD KEY admin_page (tenant_id,created_at,id);
ALTER TABLE devices ADD COLUMN created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6), ADD KEY admin_page (tenant_id,created_at,id);
CREATE TABLE admin_policy_recompiles (
  tenant_id BINARY(16) NOT NULL PRIMARY KEY,
  revision BIGINT UNSIGNED NOT NULL DEFAULT 1,
  requested_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  FOREIGN KEY (tenant_id) REFERENCES tenants(tenant_id)
) ENGINE=InnoDB;
