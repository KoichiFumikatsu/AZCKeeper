-- Alta con placa: «Reiniciar con aviso para aplicarlo ya» (Koichi, 2026-10-17). Windows solo aplica el nombre nuevo
-- (rename_computer, result_code pending_restart) al reiniciar; si IT lo elige al aprobar, al enrolar se encola ademas un
-- reinicio con aviso de restart_notice_seconds (hoy 300). NULL = como antes: el nombre se aplica en el proximo reinicio.
-- Repetible: DDL condicional por information_schema. MySQL 8.4 y MariaDB 11.4.

SET @sql = IF(EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE()
    AND table_name = 'enrollment_requests' AND column_name = 'restart_notice_seconds'),
  'DO 0',
  'ALTER TABLE enrollment_requests ADD COLUMN restart_notice_seconds SMALLINT UNSIGNED NULL AFTER asset_code');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
