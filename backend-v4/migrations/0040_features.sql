-- Funciones vendibles por cliente (docs/architecture/v4-clientes-funciones-accesos.md, fase 1). El catalogo vive en
-- src/Features.php; aqui solo las elecciones. Paquetes = atajos de la plataforma. Apagar una funcion no borra datos.

CREATE TABLE IF NOT EXISTS feature_packages (
  tenant_id BINARY(16) NOT NULL,
  id BINARY(16) NOT NULL,
  name VARCHAR(80) NOT NULL,
  version BIGINT UNSIGNED NOT NULL DEFAULT 1,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (tenant_id, id),
  UNIQUE KEY feature_packages_name (tenant_id, name),
  FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id),
  CONSTRAINT feature_packages_platform CHECK (tenant_id = 0x00000000000040008000000000000001)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS feature_package_items (
  tenant_id BINARY(16) NOT NULL,
  package_id BINARY(16) NOT NULL,
  feature_code VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  PRIMARY KEY (tenant_id, package_id, feature_code),
  FOREIGN KEY (tenant_id, package_id) REFERENCES feature_packages (tenant_id, id) ON DELETE CASCADE,
  FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id),
  CONSTRAINT feature_package_items_platform CHECK (tenant_id = 0x00000000000040008000000000000001)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS tenant_features (
  tenant_id BINARY(16) NOT NULL,
  feature_code VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  enabled_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (tenant_id, feature_code),
  FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

ALTER TABLE tenants
  ADD COLUMN feature_package_tenant_id BINARY(16) NULL,
  ADD COLUMN feature_package_id BINARY(16) NULL,
  ADD CONSTRAINT tenants_feature_package FOREIGN KEY (feature_package_tenant_id, feature_package_id) REFERENCES feature_packages (tenant_id, id),
  ADD CONSTRAINT tenants_feature_package_pair CHECK ((feature_package_id IS NULL) = (feature_package_tenant_id IS NULL));

-- Clientes existentes (Grupo AZC y demas): todas las funciones encendidas, para no apagarles nada sin querer.
INSERT IGNORE INTO tenant_features (tenant_id, feature_code)
  SELECT t.tenant_id, f.code FROM tenants t
  CROSS JOIN (SELECT 'productividad' code UNION ALL SELECT 'titulos' UNION ALL SELECT 'clasificacion' UNION ALL SELECT 'presencia'
    UNION ALL SELECT 'festivos' UNION ALL SELECT 'ausencias' UNION ALL SELECT 'puertas' UNION ALL SELECT 'ubicacion'
    UNION ALL SELECT 'web' UNION ALL SELECT 'usb' UNION ALL SELECT 'descargas' UNION ALL SELECT 'instalaciones'
    UNION ALL SELECT 'comandos' UNION ALL SELECT 'borrado' UNION ALL SELECT 'endurecimiento' UNION ALL SELECT 'cumplimiento'
    UNION ALL SELECT 'cobertura' UNION ALL SELECT 'autogestion_personas' UNION ALL SELECT 'autogestion_accesos' UNION ALL SELECT 'auditoria'
    UNION ALL SELECT 'api' UNION ALL SELECT 'one' UNION ALL SELECT 'webhooks') f
  WHERE t.tenant_id <> 0x00000000000040008000000000000001 AND t.deleted_at IS NULL;
