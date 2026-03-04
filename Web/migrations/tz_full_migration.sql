-- ============================================================
-- MIGRACIÓN DE PRUEBA: tz_full_migration.sql
-- Fecha: 2026-02-26
--
-- INSTRUCCIONES:
--   1. Probar en: pipezafra_inventario_db
--   2. Verificar resultados con las queries de validación al final
--   3. Si pasa: ejecutar idéntico en pipezafra_soporte_db
--
-- CUBRE:
--   FASE 2 → Índice keeper_sessions + retención handshake_log
--   FASE 3 → Timezone: columnas en keeper_devices,
--             migración de timestamps históricos a UTC,
--             y activación de UTC en sesión MySQL
--
-- PREREQUISITOS:
--   - Hacer backup antes de ejecutar en producción (soporte_db)
--   - Verificar timezone del servidor MySQL antes del paso 3.3:
--       SELECT @@global.time_zone, @@session.time_zone;
--   - Colombia = UTC-5 → los datos históricos necesitan +5 horas para ser UTC
--
-- REVERSIÓN DE EMERGENCIA:
--   Si falla: los pasos ADD COLUMN e ADD INDEX son seguros de revertir:
--     ALTER TABLE keeper_sessions DROP INDEX IF EXISTS idx_token_hash;
--     ALTER TABLE keeper_devices DROP COLUMN IF EXISTS iana_timezone;
--     ALTER TABLE keeper_devices DROP COLUMN IF EXISTS tz_offset_minutes;
--     ALTER TABLE keeper_window_episode DROP COLUMN IF EXISTS tz_offset_minutes;
--   La migración de datos (step 3.3) es irreversible sin backup.
-- ============================================================

SET time_zone = '+00:00';   -- asegura que esta sesión use UTC

-- ============================================================
-- PASO 2.1 — ÍNDICE keeper_sessions.token_hash  [OMITIDO]
-- ✅ Ya existe en producción: uk_keeper_sessions_tokenhash (UNIQUE)
--    y idx_sessions_token_expires (compuesto token_hash+expires_at).
--    Ejecutar el ALTER generaría error de duplicado. No es necesario.
-- ============================================================

-- ALTER TABLE `keeper_sessions`
--   ADD INDEX `idx_token_hash` (`token_hash`);   ← YA EXISTE

-- ============================================================
-- PASO 2.2 — RETENCIÓN keeper_handshake_log  [FASE 2]
-- Borra filas >30 días automáticamente (EVENT diario).
-- Prerequisito: event_scheduler = ON
--   Verificar: SHOW VARIABLES LIKE 'event_scheduler';
--   Activar si está OFF: SET GLOBAL event_scheduler = ON;
-- ============================================================

CREATE EVENT IF NOT EXISTS `purge_handshake_log`
  ON SCHEDULE EVERY 1 DAY
  STARTS CURRENT_TIMESTAMP
  DO
    DELETE FROM `keeper_handshake_log`
    WHERE `created_at` < NOW() - INTERVAL 30 DAY;

-- ============================================================
-- PASO 3.1 — COLUMNAS TIMEZONE en keeper_devices  [FASE 3]
-- Almacena la zona horaria del equipo detectada por el cliente.
-- Se rellena en el próximo handshake de cada dispositivo.
-- Seguro: additive, no modifica datos existentes.
-- ============================================================

ALTER TABLE `keeper_devices`
  ADD COLUMN `iana_timezone`    VARCHAR(64)  NULL COMMENT 'IANA timezone del equipo. Ej: America/Bogota' AFTER `device_name`,
  ADD COLUMN `tz_offset_minutes` SMALLINT   NULL COMMENT 'Offset UTC en minutos. Ej: UTC-5 = -300'      AFTER `iana_timezone`;

-- ============================================================
-- PASO 3.2 — COLUMNA OFFSET en keeper_window_episode  [FASE 3]
-- Permite saber con qué offset fue registrado cada episodio.
-- Las filas migradas en 3.3 tendrán tz_offset_minutes = -300 (Colombia).
-- Los nuevos registros del cliente actualizado envían el offset.
-- Seguro: additive.
-- ============================================================

ALTER TABLE `keeper_window_episode`
  ADD COLUMN `tz_offset_minutes` SMALLINT NULL COMMENT 'Offset UTC en minutos al momento del registro' AFTER `day_date`;

-- ============================================================
-- PASO 3.3 — MIGRAR TIMESTAMPS HISTÓRICOS A UTC  [FASE 3]
-- ⚠️  ÚNICO PASO DESTRUCTIVO — REQUIERE BACKUP PREVIO
--
-- Contexto:
--   - El cliente enviaba start_at/end_at en hora LOCAL (Colombia = UTC-5).
--   - Para convertir local → UTC: sumar 5 horas (restar los -300 minutos).
--   - Confirmado por datos: start_at=17:30 con created_at=22:30 (5h de diferencia).
--
-- Scope: solo filas anteriores al deploy de este fix (2026-02-27).
-- Filas futuras llegan ya en UTC desde el cliente actualizado.
-- ============================================================

UPDATE `keeper_window_episode`
SET
  `start_at`           = DATE_ADD(`start_at`, INTERVAL 5 HOUR),
  `end_at`             = DATE_ADD(`end_at`,   INTERVAL 5 HOUR),
  `tz_offset_minutes`  = -300   -- Colombia UTC-5 (confirmar si hay otros TZ)
WHERE
  `start_at` < '2026-02-27 00:00:00';  -- solo datos históricos pre-fix

-- ============================================================
-- PASO 3.4 — PRE-RELLENAR TIMEZONE EN DISPOSITIVOS CONOCIDOS
-- Los dispositivos en inventario_db son de Colombia (UTC-5).
-- Los dispositivos de producción se actualizan con el próximo handshake.
-- Opcional: si se conoce la timezone de cada device, setearla aquí.
-- ============================================================

UPDATE `keeper_devices`
SET
  `iana_timezone`    = 'America/Bogota',
  `tz_offset_minutes` = -300
WHERE `iana_timezone` IS NULL;

-- ============================================================
-- VALIDACIONES POST-MIGRACIÓN
-- Ejecutar para confirmar que todo aplicó correctamente.
-- ============================================================

-- V1: Confirmar índice creado
SELECT
  INDEX_NAME,
  COLUMN_NAME,
  NON_UNIQUE
FROM information_schema.STATISTICS
WHERE TABLE_NAME = 'keeper_sessions'
  AND INDEX_NAME = 'idx_token_hash';
-- Resultado esperado: 1 fila con INDEX_NAME='idx_token_hash', NON_UNIQUE=1

-- V2: Confirmar columnas en keeper_devices
SHOW COLUMNS FROM `keeper_devices` LIKE '%tz%';
-- Resultado esperado: tz_offset_minutes y iana_timezone visibles

-- V3: Confirmar columna en keeper_window_episode
SHOW COLUMNS FROM `keeper_window_episode` LIKE 'tz_offset_minutes';

-- V4: Verificar migración de timestamps (comparar start_at vs created_at)
-- Después de migrar, start_at debería estar en UTC (aprox igual a created_at).
SELECT
  id,
  start_at,
  created_at,
  TIMESTAMPDIFF(MINUTE, start_at, created_at) AS diff_minutes,
  tz_offset_minutes
FROM `keeper_window_episode`
ORDER BY id
LIMIT 10;
-- Resultado esperado: diff_minutes ≈ 0 (antes era ~300 = 5 horas de diferencia)

-- V5: Confirmar que el EVENT existe
SHOW EVENTS LIKE 'purge_handshake_log';

-- V6: EXPLAIN para confirmar que el índice se usa en auth
EXPLAIN
SELECT token_hash, user_id, device_id, expires_at, revoked_at
FROM keeper_sessions
WHERE token_hash = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'
LIMIT 1;
-- Resultado esperado: key = idx_token_hash (no NULL)

-- V7: Verificar keeper_devices actualizados
SELECT id, device_name, iana_timezone, tz_offset_minutes
FROM keeper_devices;

-- ============================================================
-- FIN DE MIGRACIÓN
-- Si todas las validaciones pasan en pipezafra_inventario_db:
--   → Ejecutar este mismo archivo en pipezafra_soporte_db
-- ============================================================
