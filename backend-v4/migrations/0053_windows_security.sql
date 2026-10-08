CREATE TABLE IF NOT EXISTS tenant_windows_security (
  tenant_id BINARY(16) NOT NULL PRIMARY KEY,
  disable_smart_app_control BOOLEAN NOT NULL DEFAULT FALSE,
  updated_by BINARY(16) NOT NULL,
  updated_at DATETIME(6) NOT NULL,
  CONSTRAINT windows_security_tenant_fk FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
