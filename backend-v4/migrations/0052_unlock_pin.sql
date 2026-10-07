CREATE TABLE IF NOT EXISTS tenant_unlock_pin (
  tenant_id BINARY(16) NOT NULL PRIMARY KEY,
  pin_enc VARBINARY(128) NOT NULL,
  salt BINARY(32) NOT NULL,
  verifier BINARY(32) NOT NULL,
  revision BIGINT UNSIGNED NOT NULL DEFAULT 1,
  updated_by BINARY(16) NOT NULL,
  updated_at DATETIME(6) NOT NULL,
  CONSTRAINT unlock_pin_tenant_fk FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
