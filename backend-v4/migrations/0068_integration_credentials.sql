-- Credenciales de la API externa desde el panel (Koichi, 2026-10-20): Integraciones (API) crea, rota y revoca API keys y
-- clientes OAuth de una empresa (GET/POST /integrations, POST /integrations/{id}/rotate, DELETE /integrations/{id}).
-- Hasta ahora solo las pruebas insertaban integraciones. Columnas nuevas de integrations:
--   revoked_at   cuando se revoco (active pasa a FALSE y auth_version sube: ExternalAuth deja de aceptar la credencial y
--                los access tokens OAuth ya emitidos en la siguiente peticion).
--   created_by   principal de quien la creo (para la tabla del panel); el owner_admin_id de 0003 no sirve para la
--                plataforma: su FK exige una cuenta de la misma empresa.
--   last_used_at ultima peticion autenticada (ExternalAuth la escribe como mucho una vez por minuto).
-- Repetible: DDL condicional por information_schema.

SET @sql = IF(EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE()
    AND table_name = 'integrations' AND column_name = 'revoked_at'),
  'DO 0',
  'ALTER TABLE integrations ADD COLUMN revoked_at DATETIME(6) NULL AFTER expires_at');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = IF(EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE()
    AND table_name = 'integrations' AND column_name = 'last_used_at'),
  'DO 0',
  'ALTER TABLE integrations ADD COLUMN last_used_at DATETIME(6) NULL AFTER revoked_at');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = IF(EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE()
    AND table_name = 'integrations' AND column_name = 'created_by'),
  'DO 0',
  'ALTER TABLE integrations ADD COLUMN created_by BINARY(16) NULL AFTER owner_admin_id, ADD CONSTRAINT integrations_created_by FOREIGN KEY (tenant_id, created_by) REFERENCES principals (tenant_id, id)');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
