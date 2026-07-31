-- Keeper 4 — force_update y minimum_version en el feed de releases (paridad con Keeper 3).
--
-- En K3 el rollout se manejaba DESDE EL PANEL de releases: una release podia marcarse
-- force_update para que la flota auto-aplicara el salto (aunque el equipo no tuviera
-- auto-descarga). ClientVersion devolvia forceUpdate:false fijo porque la columna no existia;
-- con esto el panel recupera ese control.
--
--   force_update=1     -> el cliente aplica el update aunque autoDownload este apagado.
--   minimum_version    -> si el cliente esta por debajo, el update es CRITICO (se fuerza igual).

ALTER TABLE keeper_client_releases
  ADD COLUMN force_update    TINYINT(1)   NOT NULL DEFAULT 0 AFTER is_beta,
  ADD COLUMN minimum_version VARCHAR(32)  NULL            AFTER force_update;
