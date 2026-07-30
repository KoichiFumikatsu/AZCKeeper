-- Keeper 4 — licenciamiento por tiers.
--
-- Cuatro capas gobiernan un modulo, cada una responde una pregunta distinta:
--   catalogo (keeper_module)          -> existe el modulo?
--   tier     (keeper_tier + _module)  -> la firma tiene derecho? (comercial)
--   politica (keeper_policy_assignments) -> esta encendido? (operativo)
--   estado   (keeper_device_module_state) -> corre de verdad? (observado)
--
-- El backend recorta la politica efectiva contra el tier de la firma antes de
-- enviarla al cliente: un modulo que el tier no incluye se elimina aunque la
-- politica lo pida. Asi la licencia se hace cumplir en un solo punto y el
-- cliente nunca recibe -ni puede activar- un modulo que la firma no compro.

-- Catalogo: la version viva de keeper_module_catalog (que en el esquema 3
-- existia pero ningun codigo leia). code es clave natural: identificador estable
-- y legible que el cliente ya usa en su config.
CREATE TABLE keeper_module (
  code         VARCHAR(64) NOT NULL,
  label        VARCHAR(190) NOT NULL,
  category     ENUM('tracking','security','control','data') NOT NULL,
  is_sensitive TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = su consulta se audita (screenshots, location)',
  is_active    TINYINT(1) NOT NULL DEFAULT 1,
  sort_order   SMALLINT NOT NULL DEFAULT 0,
  PRIMARY KEY (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE keeper_tier (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  code       VARCHAR(64) NOT NULL,
  label      VARCHAR(190) NOT NULL,
  sort_order SMALLINT NOT NULL DEFAULT 0,
  is_active  TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uq_tier_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Que modulos incluye cada tier (muchos a muchos).
CREATE TABLE keeper_tier_module (
  tier_id     INT UNSIGNED NOT NULL,
  module_code VARCHAR(64) NOT NULL,
  PRIMARY KEY (tier_id, module_code),
  CONSTRAINT fk_tiermod_tier   FOREIGN KEY (tier_id)     REFERENCES keeper_tier (id)     ON DELETE CASCADE,
  CONSTRAINT fk_tiermod_module FOREIGN KEY (module_code) REFERENCES keeper_module (code) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- La firma referencia su tier vigente. SET NULL: borrar un tier no borra la firma;
-- una firma sin tier no tiene ningun modulo desbloqueado (fail-closed comercial).
-- El historial de cambios de tier va en keeper_audit_log (event_type='tier_change').
ALTER TABLE keeper_firmas
  ADD COLUMN tier_id INT UNSIGNED NULL AFTER nombre,
  ADD KEY ix_firmas_tier (tier_id),
  ADD CONSTRAINT fk_firma_tier FOREIGN KEY (tier_id) REFERENCES keeper_tier (id) ON DELETE SET NULL;
