-- Eliminar empresas (decision de Koichi 2026-10-02) SIN tocar la auditoria. Una empresa archivada se puede eliminar:
-- se borran sus personas, equipos, actividad, organizacion, horarios, reglas... y queda solo un registro minimo
-- (tenants.deleted_at), invisible en todo el panel, al que sigue apuntando su auditoria, que no se mueve ni se borra.
-- Las identidades (principals) a las que apunta la auditoria se conservan "desvinculadas": sin equipo ni cuenta detras.

ALTER TABLE tenants ADD COLUMN deleted_at DATETIME(6) NULL;
ALTER TABLE principals ADD COLUMN detached BOOLEAN NOT NULL DEFAULT FALSE;

-- La regla de identidades (cada tipo con su referencia) admite ahora la identidad desvinculada. El nombre de la
-- restriccion lo pone el motor: se busca por su contenido.
SET @principal_check = (SELECT c.constraint_name FROM information_schema.table_constraints c
  JOIN information_schema.check_constraints k ON k.constraint_schema = c.constraint_schema AND k.constraint_name = c.constraint_name
  WHERE c.table_schema = DATABASE() AND c.table_name = 'principals' AND c.constraint_type = 'CHECK'
    AND k.check_clause LIKE '%integration_id%' AND k.check_clause LIKE '%device_id%' AND k.check_clause LIKE '%system%' LIMIT 1);
SET @principal_sql = IF(@principal_check IS NULL, 'DO 0', CONCAT('ALTER TABLE principals DROP CONSTRAINT `', @principal_check, '`'));
PREPARE principal_stmt FROM @principal_sql;
EXECUTE principal_stmt;
DEALLOCATE PREPARE principal_stmt;
ALTER TABLE principals ADD CONSTRAINT principals_kind_refs CHECK (
  (detached = TRUE AND admin_id IS NULL AND admin_tenant_id IS NULL AND device_id IS NULL AND integration_id IS NULL)
  OR (detached = FALSE AND (
    (kind = 'admin' AND admin_id IS NOT NULL AND admin_tenant_id IS NOT NULL AND device_id IS NULL AND integration_id IS NULL)
    OR (kind = 'device' AND device_id IS NOT NULL AND admin_id IS NULL AND admin_tenant_id IS NULL AND integration_id IS NULL)
    OR (kind = 'integration' AND integration_id IS NOT NULL AND admin_id IS NULL AND admin_tenant_id IS NULL AND device_id IS NULL)
    OR (kind = 'system' AND admin_id IS NULL AND admin_tenant_id IS NULL AND device_id IS NULL AND integration_id IS NULL))));

-- Llaves de deduplicacion de actividad: siguen protegidas, salvo las de una empresa en eliminacion (deleted_at puesto
-- dentro de la misma transaccion de TenantPurge).
DELIMITER $$

DROP TRIGGER IF EXISTS episode_keys_delete_guard$$

CREATE TRIGGER episode_keys_delete_guard BEFORE DELETE ON episode_ingest_keys FOR EACH ROW
BEGIN
  DECLARE raw_event BINARY(16) DEFAULT NULL;
  DECLARE allowed_days INT DEFAULT NULL;
  IF NOT EXISTS (SELECT 1 FROM tenants t WHERE t.tenant_id = OLD.tenant_id AND t.deleted_at IS NOT NULL AND t.archived_at IS NOT NULL) THEN
    SELECT late_arrival_days INTO allowed_days FROM retention_settings
      WHERE tenant_id = OLD.tenant_id LOCK IN SHARE MODE;
    SELECT event_id INTO raw_event FROM episodes WHERE tenant_id = OLD.tenant_id
      AND device_id = OLD.device_id AND event_id = OLD.event_id AND event_date = OLD.event_date LOCK IN SHARE MODE;
    IF raw_event IS NOT NULL OR allowed_days IS NULL OR OLD.event_date >= UTC_DATE() - INTERVAL allowed_days DAY THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Purge raw episode before its dedupe key';
    END IF;
  END IF;
END$$

DELIMITER ;
