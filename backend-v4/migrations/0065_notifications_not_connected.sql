-- Notificaciones: resumen del dia «N sin conectarse hoy» (Koichi, 2026-10-16). Personas activas con jornada hoy (sin
-- festivo ni ausencia) que pasada su hora de entrada + margen de puntualidad aun no tienen actividad. Se cachea junto con
-- las llegadas tarde (mismo calculo de /reports/presence) y se guarda por cuenta cuantas vio al abrir la lista.
-- Repetible: DDL condicional por information_schema. MySQL 8.4 y MariaDB 11.4.

SET @sql = IF(EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE()
    AND table_name = 'panel_notification_state' AND column_name = 'digest_not_connected'),
  'DO 0',
  'ALTER TABLE panel_notification_state ADD COLUMN digest_not_connected INT UNSIGNED NULL AFTER digest_late');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = IF(EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE()
    AND table_name = 'panel_notification_state' AND column_name = 'seen_not_connected'),
  'DO 0',
  'ALTER TABLE panel_notification_state ADD COLUMN seen_not_connected INT UNSIGNED NOT NULL DEFAULT 0 AFTER seen_absent');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
