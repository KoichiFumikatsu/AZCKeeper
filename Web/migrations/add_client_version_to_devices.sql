-- ============================================
-- Migración: Agregar campo client_version a keeper_devices
-- Fecha: 2024
-- Descripción: Soluciona el problema de versión no actualizada
-- ============================================

-- Agregar columna client_version
-- Nota: Si la columna ya existe, este script generará un error pero la base de datos no se verá afectada
ALTER TABLE `keeper_devices` 
ADD COLUMN `client_version` VARCHAR(20) NULL DEFAULT NULL AFTER `device_name`;

-- Índice opcional para búsquedas por versión (comentado por defecto)
-- CREATE INDEX idx_client_version ON keeper_devices(client_version);

-- ============================================
-- ROLLBACK (solo si es necesario revertir)
-- ============================================
-- ALTER TABLE `keeper_devices` DROP COLUMN `client_version`;
-- DROP INDEX idx_client_version ON keeper_devices;
