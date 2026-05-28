-- Agrega columnas device_status y day_summary_json a keeper_devices.
-- Requerido por ClientHandshake::computeDeviceStatus() para guardar
-- el estado calculado y evitar queries adicionales en el panel admin.
--
-- Ejecutar en la base de datos de producción:
--   mysql -u <user> -p <dbname> < add_device_status_columns.sql

ALTER TABLE `keeper_devices`
  ADD COLUMN `device_status` ENUM('active', 'away', 'inactive') NOT NULL DEFAULT 'inactive' AFTER `status`,
  ADD COLUMN `day_summary_json` JSON DEFAULT NULL AFTER `device_status`;

ALTER TABLE `keeper_devices`
  ADD INDEX `ix_keeper_devices_device_status` (`device_status`);
