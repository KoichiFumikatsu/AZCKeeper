-- Keeper 4 — inactividad reportada por el equipo, para el semaforo de presencia.
--
-- El cliente reporta sus segundos de inactividad (WinIdleMonitor) en cada handshake. Junto
-- con last_seen_at (que ya se actualiza en el handshake) el panel calcula la presencia por
-- persona: activa / ausente (idle alto) / desconectada (last_seen viejo) / sin keeper (sin
-- equipo). Es una instantanea del ultimo handshake (~5 min), suficiente para un semaforo.
ALTER TABLE keeper_devices
  ADD COLUMN last_idle_seconds INT NULL COMMENT 'idle reportado en el ultimo handshake' AFTER last_seen_at;
