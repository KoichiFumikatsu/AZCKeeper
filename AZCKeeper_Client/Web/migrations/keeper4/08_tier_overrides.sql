-- Keeper 4 — gestion de tiers: interruptor global + override granular por firma.
--
-- El manejo de tiers es un modulo aparte del panel, parametrizable:
--   - Los tiers y su reparto de modulos se editan desde el panel (son datos:
--     keeper_tier / keeper_tier_module). El seed solo pone valores iniciales.
--   - Interruptor GLOBAL de enforcement (keeper_panel_settings): apagado = todos
--     los modulos activos para todos (modo interno AZC, sin gating); encendido =
--     se hace cumplir el tier de cada firma.
--   - Override GRANULAR por firma: conceder un modulo aunque el tier no lo incluya,
--     o revocarlo aunque si.
--
-- Modulos efectivos de una firma (con enforcement encendido):
--   ( modulos del tier  MENOS  los revocados de la firma )
--     MAS  los concedidos de la firma
-- Con enforcement apagado: todos los keeper_module activos.

CREATE TABLE keeper_firma_module_override (
  firma_id    INT UNSIGNED NOT NULL,
  module_code VARCHAR(64) NOT NULL,
  enabled     TINYINT(1) NOT NULL COMMENT '1 = conceder aunque el tier no lo incluya; 0 = revocar aunque si',
  note        VARCHAR(255) NULL COMMENT 'por que esta excepcion; util para auditoria',
  updated_by  BIGINT UNSIGNED NULL COMMENT 'admin_id que fijo el override',
  updated_at  TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (firma_id, module_code),
  CONSTRAINT fk_fmo_firma  FOREIGN KEY (firma_id)    REFERENCES keeper_firmas (id)   ON DELETE CASCADE,
  CONSTRAINT fk_fmo_module FOREIGN KEY (module_code) REFERENCES keeper_module (code) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Interruptor global de enforcement de tiers. '1' = hacer cumplir el tier por firma;
-- '0' = todos los modulos disponibles para todos (modo interno AZC). Idempotente.
INSERT INTO keeper_panel_settings (setting_key, setting_value)
VALUES ('tier_enforcement_enabled', '1')
ON DUPLICATE KEY UPDATE setting_key = setting_key;
