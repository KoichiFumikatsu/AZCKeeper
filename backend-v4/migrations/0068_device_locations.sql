SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci;
SET time_zone = '+00:00';

ALTER TABLE devices
  ADD COLUMN lost_at DATETIME(6) NULL,
  ADD COLUMN lost_by BINARY(16) NULL,
  ADD COLUMN lost_reason VARCHAR(500) NULL;

CREATE TABLE site_location_settings (
  tenant_id BINARY(16) NOT NULL,
  site_id BINARY(16) NOT NULL,
  public_ips JSON NOT NULL,
  wifi JSON NOT NULL,
  latitude DOUBLE NULL,
  longitude DOUBLE NULL,
  radius_m DOUBLE NULL,
  version BIGINT UNSIGNED NOT NULL DEFAULT 1,
  PRIMARY KEY (tenant_id,site_id),
  FOREIGN KEY (tenant_id,site_id) REFERENCES org_units (tenant_id,id),
  CHECK (JSON_TYPE(public_ips)='ARRAY' AND JSON_TYPE(wifi)='ARRAY'),
  CHECK ((latitude IS NULL AND longitude IS NULL AND radius_m IS NULL) OR
    (latitude BETWEEN -90 AND 90 AND longitude BETWEEN -180 AND 180 AND radius_m>0))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE device_locations (
  tenant_id BINARY(16) NOT NULL,
  id BINARY(16) NOT NULL,
  device_id BINARY(16) NOT NULL,
  user_id BINARY(16) NOT NULL,
  at DATETIME(6) NOT NULL,
  received_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  latitude DOUBLE NULL,
  longitude DOUBLE NULL,
  accuracy_m DOUBLE NULL,
  network_type ENUM('wifi','cable','unknown') NOT NULL,
  wifi_ssid VARCHAR(128) NULL,
  wifi_bssid VARCHAR(17) CHARACTER SET ascii COLLATE ascii_bin NULL,
  public_ip VARCHAR(45) CHARACTER SET ascii COLLATE ascii_bin NULL,
  ip_at_capture BOOLEAN NOT NULL DEFAULT FALSE,
  result ENUM('en_sede','fuera','sin_dato') NOT NULL,
  site_id BINARY(16) NULL,
  matched_by ENUM('red','geocerca') NULL,
  in_work_hours BOOLEAN NOT NULL,
  body_hash BINARY(32) NOT NULL,
  PRIMARY KEY (tenant_id,device_id,id),
  FOREIGN KEY (tenant_id,device_id) REFERENCES devices (tenant_id,id),
  FOREIGN KEY (tenant_id,user_id) REFERENCES users (tenant_id,id),
  FOREIGN KEY (tenant_id,site_id) REFERENCES org_units (tenant_id,id),
  KEY (tenant_id,user_id,at,id),
  KEY (tenant_id,device_id,at,id),
  KEY (tenant_id,at,device_id),
  CHECK ((latitude IS NULL AND longitude IS NULL AND accuracy_m IS NULL) OR
    (latitude BETWEEN -90 AND 90 AND longitude BETWEEN -180 AND 180 AND accuracy_m>=0))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
