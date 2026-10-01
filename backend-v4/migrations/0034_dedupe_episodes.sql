-- Limpieza 2026-10-01: agentes <= 4.0.13 repetian el mismo episodio (misma ventana, mismo inicio y fin) con ids
-- nuevos, hasta 31 veces. Se deja una copia por (equipo, proceso, inicio, fin) y se marcan esos dias para recalcular.
CREATE TEMPORARY TABLE episode_dupes ENGINE=InnoDB AS
  SELECT tenant_id, device_id, process_name, started_at, ended_at, MIN(event_id) keep_id, MIN(event_date) day
  FROM episodes GROUP BY tenant_id, device_id, process_name, started_at, ended_at HAVING COUNT(*) > 1;
DELETE e FROM episodes e JOIN episode_dupes d ON d.tenant_id = e.tenant_id AND d.device_id = e.device_id
  AND d.process_name = e.process_name AND d.started_at = e.started_at AND d.ended_at = e.ended_at AND e.event_id <> d.keep_id;
INSERT INTO rollup_days (tenant_id, day) SELECT DISTINCT tenant_id, day FROM episode_dupes
  ON DUPLICATE KEY UPDATE dirty = TRUE, input_revision = input_revision + 1, finalized_at = NULL;
DROP TEMPORARY TABLE episode_dupes;
