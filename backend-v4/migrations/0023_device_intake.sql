-- Alta de equipos a escala (docs/architecture/v4-alta-equipos.md).
-- Registro PROPIO de equipos esperados: el cruce automatico nunca depende de un sistema externo.

CREATE TABLE IF NOT EXISTS expected_devices (
  tenant_id BINARY(16) NOT NULL,
  id BINARY(16) NOT NULL,
  user_id BINARY(16) NOT NULL,
  asset_code VARCHAR(40) NULL,
  serial_number VARCHAR(120) NULL,
  status ENUM('pending','enrolled','cancelled') NOT NULL DEFAULT 'pending',
  source ENUM('manual','csv','api','connector') NOT NULL DEFAULT 'manual',
  device_id BINARY(16) NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  version BIGINT UNSIGNED NOT NULL DEFAULT 1,
  -- Unicidad solo entre los pendientes: un equipo dado de baja libera su serie/placa para otro registro.
  pending_serial VARCHAR(120) GENERATED ALWAYS AS (IF(status='pending', serial_number, NULL)) STORED,
  pending_asset VARCHAR(40) GENERATED ALWAYS AS (IF(status='pending', asset_code, NULL)) STORED,
  PRIMARY KEY (tenant_id, id),
  UNIQUE KEY (tenant_id, pending_serial),
  UNIQUE KEY (tenant_id, pending_asset),
  KEY (tenant_id, status, created_at),
  KEY (tenant_id, serial_number),
  FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id),
  FOREIGN KEY (tenant_id, user_id) REFERENCES users (tenant_id, id),
  FOREIGN KEY (tenant_id, device_id) REFERENCES devices (tenant_id, id),
  CHECK (asset_code IS NOT NULL OR serial_number IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Scope de la API externa para que un inventario/ERP cargue equipos esperados.
INSERT INTO scopes (code, description)
SELECT 'expected-devices:write', 'Carga de equipos esperados'
WHERE NOT EXISTS (SELECT 1 FROM scopes WHERE code = 'expected-devices:write');

-- Clave de alta de la empresa: va en installation.json del paquete generico. Solo se guarda el hash.
CREATE TABLE IF NOT EXISTS enrollment_keys (
  tenant_id BINARY(16) NOT NULL,
  id BINARY(16) NOT NULL,
  key_hash BINARY(32) NOT NULL,
  hint VARCHAR(8) NOT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  revoked_at DATETIME(6) NULL,
  PRIMARY KEY (tenant_id, id),
  UNIQUE KEY (key_hash),
  KEY (tenant_id, revoked_at),
  FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS enrollment_settings (
  tenant_id BINARY(16) NOT NULL,
  auto_approve_serial_match BOOLEAN NOT NULL DEFAULT TRUE,
  self_identify BOOLEAN NOT NULL DEFAULT FALSE,
  self_identify_auto_confirm BOOLEAN NOT NULL DEFAULT FALSE,
  version BIGINT UNSIGNED NOT NULL DEFAULT 1,
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (tenant_id),
  FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Solicitud de alta de un equipo que se presento con la clave de la empresa. Idempotente por huella de clave publica.
CREATE TABLE IF NOT EXISTS enrollment_requests (
  tenant_id BINARY(16) NOT NULL,
  id BINARY(16) NOT NULL,
  public_key_thumbprint BINARY(32) NOT NULL,
  serial_number VARCHAR(120) NULL,
  hostname VARCHAR(120) NOT NULL,
  agent_version VARCHAR(40) NOT NULL,
  claimed_document VARCHAR(40) NULL,
  status ENUM('pending','approved','rejected','enrolled') NOT NULL DEFAULT 'pending',
  match_kind ENUM('none','serial','document','manual') NOT NULL DEFAULT 'none',
  alert VARCHAR(80) NULL,
  user_id BINARY(16) NULL,
  suggested_user_id BINARY(16) NULL,
  asset_code VARCHAR(40) NULL,
  expected_device_id BINARY(16) NULL,
  enrollment_id BINARY(16) NULL,
  device_id BINARY(16) NULL,
  decided_by BINARY(16) NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  last_seen_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  decided_at DATETIME(6) NULL,
  PRIMARY KEY (tenant_id, id),
  UNIQUE KEY (tenant_id, public_key_thumbprint),
  KEY (tenant_id, status, created_at),
  KEY (tenant_id, enrollment_id),
  FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id),
  FOREIGN KEY (tenant_id, user_id) REFERENCES users (tenant_id, id),
  FOREIGN KEY (tenant_id, suggested_user_id) REFERENCES users (tenant_id, id),
  CHECK (status NOT IN ('approved','enrolled') OR user_id IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Al final: es la unica sentencia no idempotente; si algo anterior falla, reintentar el archivo es seguro.
ALTER TABLE devices ADD COLUMN asset_code VARCHAR(40) NULL AFTER hostname;
