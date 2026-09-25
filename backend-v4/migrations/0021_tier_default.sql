-- Tier por defecto por tenant: al provisionar/enrolar un user se le asigna una suscripción
-- con este tier, de modo que las reglas de política (que exigen el módulo del tier activo)
-- apliquen sin intervención manual. La columna generada garantiza un único default por tenant.
ALTER TABLE tier
  ADD COLUMN is_default TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN default_key BINARY(16) AS (IF(is_default = 1, tenant_id, NULL)) VIRTUAL,
  ADD CONSTRAINT chk_tier_is_default CHECK (is_default IN (0, 1)),
  ADD UNIQUE KEY uq_tier_default (default_key);
