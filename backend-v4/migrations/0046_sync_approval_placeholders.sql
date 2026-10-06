-- Frenos antes de la primera carga real desde One (revision one-keeper-changes-review.md, K5/I4 y M1).
-- 1) data_sources.approval_required: al asignar un dominio nuevo a una fuente, su siguiente vuelta real queda
--    `blocked` (domains_changed) hasta que alguien la apruebe (approveSyncRun). El cron nunca aplica solo una carga
--    nueva. Se limpia al aplicar una vuelta.
-- 2) org_units.placeholder / schedules.placeholder: "Sin firma", "Sin área", "Sin cargo", "Sin horario" (y "Sin sede", solo
--    para personas retiradas que la fuente trae sin sede) se reconocen
--    por esta marca, no por el nombre (una unidad real de One llamada "Sin área" no se confunde con el placeholder).
-- Repetible: cada columna se agrega solo si no existe (MySQL 8.4 no tiene ADD COLUMN IF NOT EXISTS).

SET @sql = IF(EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'data_sources' AND column_name = 'approval_required'),
  'DO 0', 'ALTER TABLE data_sources ADD COLUMN approval_required BOOLEAN NOT NULL DEFAULT FALSE AFTER mode');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = IF(EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'org_units' AND column_name = 'placeholder'),
  'DO 0', 'ALTER TABLE org_units ADD COLUMN placeholder BOOLEAN NOT NULL DEFAULT FALSE');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = IF(EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'schedules' AND column_name = 'placeholder'),
  'DO 0', 'ALTER TABLE schedules ADD COLUMN placeholder BOOLEAN NOT NULL DEFAULT FALSE');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Los placeholders ya creados (panel o sincronizacion) se marcan: mismo nombre y tipo, sin vinculo con ninguna fuente.
UPDATE org_units o SET o.placeholder = TRUE
  WHERE o.placeholder = FALSE
    AND ((o.kind = 'firm' AND o.name = 'Sin firma') OR (o.kind = 'area' AND o.name = 'Sin área') OR (o.kind = 'position' AND o.name = 'Sin cargo'))
    AND NOT EXISTS (SELECT 1 FROM entity_links l WHERE l.tenant_id = o.tenant_id AND l.entity_type = 'org_unit' AND l.entity_id = o.id);
UPDATE schedules s SET s.placeholder = TRUE
  WHERE s.placeholder = FALSE AND s.name = 'Sin horario'
    AND NOT EXISTS (SELECT 1 FROM schedule_days d WHERE d.tenant_id = s.tenant_id AND d.schedule_id = s.id)
    AND NOT EXISTS (SELECT 1 FROM entity_links l WHERE l.tenant_id = s.tenant_id AND l.entity_type = 'schedule' AND l.entity_id = s.id);
