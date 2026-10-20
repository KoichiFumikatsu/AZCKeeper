SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci;
SET time_zone = '+00:00';

CREATE TABLE location_reclassification (
  tenant_id BINARY(16) NOT NULL PRIMARY KEY,
  until_at DATETIME(6) NOT NULL,
  cursor_at DATETIME(6) NULL,
  cursor_device BINARY(16) NULL,
  cursor_id BINARY(16) NULL,
  FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
