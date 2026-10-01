-- Clasificacion de aplicaciones y sitios (decision de Koichi 2026-10-01):
-- productividad = uso en apps productivas / uso en ventanas; distraccion = uso en poco productivas / uso;
-- sin clasificar = uso en apps sin clasificar / uso; focus = tiempo productivo en bloques >= 25 min / tiempo productivo.
-- Un bloque sigue mientras la persona este en cualquier app productiva; se corta con una poco productiva o con
-- mas de 2 minutos sin uso. La clasificacion puede variar por area o cargo (p. ej. Facebook productivo en Mercadeo).

-- Sitios reconocidos dentro del navegador por el titulo de la ventana. El titulo NO se guarda: al recibir el episodio
-- solo se guarda la etiqueta del sitio (episodes.site). Las filas de la plataforma son la base para todas las empresas.
CREATE TABLE IF NOT EXISTS app_sites (
  tenant_id BINARY(16) NOT NULL,
  id BINARY(16) NOT NULL,
  name VARCHAR(80) NOT NULL,
  pattern VARCHAR(120) NOT NULL,
  active BOOLEAN NOT NULL DEFAULT TRUE,
  PRIMARY KEY (tenant_id, id),
  UNIQUE KEY app_sites_name (tenant_id, name),
  FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

ALTER TABLE episodes ADD COLUMN site VARCHAR(80) NULL AFTER process_name;

-- Clasificacion: la de la empresa manda sobre la base de la plataforma; la de un cargo o area manda sobre la empresa.
-- app_key = nombre de proceso (chrome, WINWORD) o "site:<sitio>" (site:Facebook).
ALTER TABLE app_classification ADD COLUMN label VARCHAR(120) NULL AFTER category;
CREATE TABLE IF NOT EXISTS app_classification_overrides (
  tenant_id BINARY(16) NOT NULL,
  id BINARY(16) NOT NULL,
  process_name VARCHAR(160) NOT NULL,
  category ENUM('productive','neutral','unproductive') NOT NULL,
  area_id BINARY(16) NULL,
  position_id BINARY(16) NULL,
  PRIMARY KEY (tenant_id, id),
  UNIQUE KEY app_override_target (tenant_id, process_name, area_id, position_id),
  FOREIGN KEY (tenant_id, area_id) REFERENCES org_units (tenant_id, id),
  FOREIGN KEY (tenant_id, position_id) REFERENCES org_units (tenant_id, id),
  CONSTRAINT app_override_one_target CHECK ((area_id IS NULL) <> (position_id IS NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Desglose del dia con la misma medicion (ventanas) para numerador y denominador.
ALTER TABLE day_summary
  ADD COLUMN window_active_seconds BIGINT UNSIGNED NULL,
  ADD COLUMN unproductive_seconds BIGINT UNSIGNED NULL,
  ADD COLUMN unclassified_seconds BIGINT UNSIGNED NULL,
  ADD COLUMN focus_block_seconds BIGINT UNSIGNED NULL;

INSERT INTO permissions (code, label, resource, action, delegable, platform_only)
SELECT 'actividad.clasificar', 'Clasificar aplicaciones y sitios (productiva, neutra, poco productiva) por empresa, área o cargo', 'actividad', 'clasificar', 1, 0
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE code = 'actividad.clasificar');
