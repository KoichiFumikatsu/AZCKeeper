-- Keeper 4 — estado de cumplimiento del agente elevado.
--
-- El requisito de Koichi: "si o si, en el panel me muestre si entro con permisos de
-- admin o si fallo, porque ese podria ser un fallo silencioso". En Keeper 3 el
-- URLBlocklist fallo semanas en HKCU sin que nadie lo supiera. Para que el panel pueda
-- distinguir los tres estados por equipo con un simple WHERE (sin parsear JSON), la
-- elevacion y la capacidad de hacer cumplir pasan a ser columnas de primera clase.
--
--   agent_present=0                          -> gris  (no instalado / no reporta)
--   agent_present=1 AND agent_can_enforce=0  -> ROJO  (corre pero SIN privilegio: fallo visible)
--   agent_present=1 AND agent_can_enforce=1  -> verde (elevado y aplicando)
--
-- La query del panel para cazar fallos silenciosos es literalmente:
--   SELECT ... WHERE agent_present=1 AND agent_can_enforce=0
-- y para cazar agentes muertos/colgados (reporte viejo):
--   WHERE agent_report_at < NOW() - INTERVAL 15 MINUTE
--
-- NULL = todavia sin reporte del agente (distinto de 0 = reporto que no puede).

ALTER TABLE keeper_security_state
  ADD COLUMN agent_elevated       TINYINT(1)   NULL DEFAULT NULL COMMENT 'NULL=sin reporte; 1=proceso elevado; 0=sin privilegio' AFTER agent_present,
  ADD COLUMN agent_can_enforce    TINYINT(1)   NULL DEFAULT NULL COMMENT 'NULL=sin reporte; 1=auto-test paso, puede escribir HKLM; 0=no' AFTER agent_elevated,
  ADD COLUMN agent_self_test_error VARCHAR(255) NULL DEFAULT NULL COMMENT 'motivo del fallo del auto-test, si lo hubo' AFTER agent_can_enforce,
  ADD COLUMN agent_version        VARCHAR(32)  NULL DEFAULT NULL COMMENT 'version del binario del agente que reporto' AFTER agent_self_test_error,
  ADD COLUMN agent_report_at      DATETIME     NULL DEFAULT NULL COMMENT 'instante (UTC) en que el agente genero el reporte; para detectar agente colgado' AFTER agent_version,
  ADD COLUMN agent_applied_json   LONGTEXT     NULL DEFAULT NULL COMMENT 'controles que el agente aplico este ciclo' AFTER agent_report_at,
  ADD COLUMN agent_failed_json    LONGTEXT     NULL DEFAULT NULL COMMENT 'controles que fallaron, con motivo' AFTER agent_applied_json;

-- Indice para la vista "equipos con enforcement roto": presente pero no elevado.
CREATE INDEX ix_secstate_enforce ON keeper_security_state (agent_present, agent_can_enforce);
