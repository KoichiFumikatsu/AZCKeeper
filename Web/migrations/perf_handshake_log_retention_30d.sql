-- ============================================================
-- MIGRACIÓN: perf_handshake_log_retention_30d.sql
-- Fecha: 2026-02-26
-- Motivo: keeper_handshake_log no tiene purga. Con 200 clientes
--         haciendo handshake c/5min (~40 inserts/min) la tabla
--         crece ~57.600 filas/día con JSON completo almacenado.
--         En un año: >21 millones de filas sin valor operativo.
-- Impacto: Retiene solo los últimos 30 días. Reduce I/O en INSERTs
--          ya que MySQL mantiene índices más pequeños.
-- Prerequisito: event_scheduler debe estar activo.
--   Verificar: SHOW VARIABLES LIKE 'event_scheduler';
--   Activar:   SET GLOBAL event_scheduler = ON;
--              (o agregar event_scheduler=ON en my.ini)
-- ============================================================

-- Verificar que el scheduler esté activo antes de ejecutar
-- SHOW VARIABLES LIKE 'event_scheduler';

-- Crear evento de purga diario
CREATE EVENT IF NOT EXISTS purge_handshake_log
  ON SCHEDULE EVERY 1 DAY
  STARTS CURRENT_TIMESTAMP
  DO
    DELETE FROM keeper_handshake_log
    WHERE created_at < NOW() - INTERVAL 30 DAY;

-- Purga inicial (ejecutar una sola vez para limpiar el backlog existente)
-- Comentar si se quiere conservar el historial completo:
-- DELETE FROM keeper_handshake_log WHERE created_at < NOW() - INTERVAL 30 DAY;

-- Verificación post-migración:
-- SHOW EVENTS LIKE 'purge_handshake_log';
-- Debe mostrar el evento en estado ENABLED con intervalo = 1 DAY.
