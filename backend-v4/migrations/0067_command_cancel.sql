-- Cancelar una orden remota (Koichi, 2026-10-19): un reinicio con aviso de 5 min enviado a varios equipos no se podia
-- detener en uno. POST /devices/{id}/commands/{commandId}/cancel:
--   pending (el equipo aun no la recibio) -> status cancelled y cancelled_at; nunca se entrega.
--   delivered/running con cuenta regresiva -> cancel_requested_at; el sync la vuelve a entregar con ese campo a los
--     agentes >= 4.0.27, que detienen la cuenta y responden failed/cancelled_by_admin -> status cancelled.
-- status sigue en su ENUM (un cancel_requested aparte habria que tratarlo en cada filtro de ordenes abiertas). El
-- CHECK de 0003 exige result_code/completed_at NULL en cancelled: la hora de la cancelacion va en cancelled_at.
-- Quien y por que queda en audit_log (cancelDeviceCommand, reason). Repetible: DDL condicional por information_schema.

SET @sql = IF(EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE()
    AND table_name = 'device_command' AND column_name = 'cancel_requested_at'),
  'DO 0',
  'ALTER TABLE device_command ADD COLUMN cancel_requested_at DATETIME(6) NULL AFTER completed_at');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = IF(EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE()
    AND table_name = 'device_command' AND column_name = 'cancelled_at'),
  'DO 0',
  'ALTER TABLE device_command ADD COLUMN cancelled_at DATETIME(6) NULL AFTER cancel_requested_at');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
