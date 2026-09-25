CREATE TABLE holidays (
  tenant_id BINARY(16) NOT NULL, id BINARY(16) NOT NULL, day DATE NOT NULL, name VARCHAR(160) NOT NULL,
  PRIMARY KEY (tenant_id,id), KEY (tenant_id,day), FOREIGN KEY (tenant_id) REFERENCES tenants(tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
CREATE TABLE holiday_society_links (
  tenant_id BINARY(16) NOT NULL, holiday_id BINARY(16) NOT NULL, society_id BINARY(16) NOT NULL,
  PRIMARY KEY (tenant_id,holiday_id,society_id),
  FOREIGN KEY (tenant_id,holiday_id) REFERENCES holidays(tenant_id,id) ON DELETE CASCADE,
  FOREIGN KEY (tenant_id,society_id) REFERENCES sociedades(tenant_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
