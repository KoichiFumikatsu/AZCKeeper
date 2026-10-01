-- Estructura del grupo (decision de Koichi 2026-10-01): Grupo AZC es UNA empresa en Keeper y dentro tiene
-- "empresas del grupo" (AZC Legal, BilingueLaw, LawyerDesk, Matiz LegalTech, el propio Grupo AZC). Comparten sedes;
-- las sedes y las empresas tienen pais; las sedes remotas son "Remoto - <pais>". Los festivos van en calendarios
-- (uno por pais o los que se quieran) y cada persona tiene su calendario, asignable en grupo como los horarios.

-- 1) Nuevo tipo de unidad: empresa del grupo. El CHECK de kind lo nombra el motor: se busca y se reemplaza.
SET @org_check = (SELECT c.constraint_name FROM information_schema.table_constraints c
  JOIN information_schema.check_constraints k ON k.constraint_schema = c.constraint_schema AND k.constraint_name = c.constraint_name
  WHERE c.table_schema = DATABASE() AND c.table_name = 'org_units' AND c.constraint_type = 'CHECK'
    AND k.check_clause LIKE '%position%' AND k.check_clause LIKE '%firm%' LIMIT 1);
SET @org_sql = IF(@org_check IS NULL, 'DO 0', CONCAT('ALTER TABLE org_units DROP CONSTRAINT `', @org_check, '`'));
PREPARE org_stmt FROM @org_sql;
EXECUTE org_stmt;
DEALLOCATE PREPARE org_stmt;
ALTER TABLE org_units
  ADD COLUMN country_code CHAR(2) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER parent_id,
  ADD CONSTRAINT org_units_kind CHECK (kind IN ('firm','site','area','position','company')),
  ADD CONSTRAINT org_units_country CHECK (country_code IS NULL OR (kind = 'site' AND country_code REGEXP '^[A-Z]{2}$'));

-- 2) Empresa del grupo de cada persona (y en su historial de asignaciones, para filtrar por periodo).
ALTER TABLE users
  ADD COLUMN company_id BINARY(16) NULL AFTER firm_kind,
  ADD COLUMN company_kind VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'company' AFTER company_id,
  ADD CONSTRAINT users_company FOREIGN KEY (tenant_id, company_id, company_kind) REFERENCES org_units (tenant_id, id, kind),
  ADD CONSTRAINT users_company_kind CHECK (company_kind = 'company');
ALTER TABLE user_assignments
  ADD COLUMN company_id BINARY(16) NULL AFTER firm_kind,
  ADD COLUMN company_kind VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'company' AFTER company_id,
  ADD CONSTRAINT user_assignments_company FOREIGN KEY (tenant_id, company_id, company_kind) REFERENCES org_units (tenant_id, id, kind),
  ADD CONSTRAINT user_assignments_company_kind CHECK (company_kind = 'company');

-- 3) Calendarios de festivos.
CREATE TABLE IF NOT EXISTS holiday_calendars (
  tenant_id BINARY(16) NOT NULL,
  id BINARY(16) NOT NULL,
  name VARCHAR(120) NOT NULL,
  country_code CHAR(2) CHARACTER SET ascii COLLATE ascii_bin NULL,
  is_default BOOLEAN NOT NULL DEFAULT FALSE,
  active BOOLEAN NOT NULL DEFAULT TRUE,
  version BIGINT UNSIGNED NOT NULL DEFAULT 1,
  PRIMARY KEY (tenant_id, id),
  UNIQUE KEY holiday_calendars_name (tenant_id, name),
  FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id),
  CONSTRAINT holiday_calendars_country CHECK (country_code IS NULL OR country_code REGEXP '^[A-Z]{2}$')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
ALTER TABLE holidays
  ADD COLUMN calendar_id BINARY(16) NULL,
  ADD CONSTRAINT holidays_calendar FOREIGN KEY (tenant_id, calendar_id) REFERENCES holiday_calendars (tenant_id, id) ON DELETE CASCADE;
ALTER TABLE users
  ADD COLUMN holiday_calendar_id BINARY(16) NULL,
  ADD CONSTRAINT users_holiday_calendar FOREIGN KEY (tenant_id, holiday_calendar_id) REFERENCES holiday_calendars (tenant_id, id);

-- 4) Los festivos que ya existian pasan a calendarios: los marcados para sociedades eran los de la oficina de EE. UU.;
-- los de toda la empresa, los de Colombia (calendario por defecto). Solo en empresas que ya tenian festivos.
INSERT INTO holiday_calendars (tenant_id, id, name, country_code, is_default)
  SELECT DISTINCT h.tenant_id, UNHEX(REPLACE(UUID(),'-','')), 'Colombia', 'CO', TRUE FROM holidays h
  WHERE NOT EXISTS (SELECT 1 FROM holiday_society_links l WHERE l.tenant_id = h.tenant_id AND l.holiday_id = h.id)
  GROUP BY h.tenant_id;
INSERT INTO holiday_calendars (tenant_id, id, name, country_code, is_default)
  SELECT h.tenant_id, UNHEX(REPLACE(UUID(),'-','')), 'Estados Unidos', 'US', FALSE FROM holidays h
  WHERE EXISTS (SELECT 1 FROM holiday_society_links l WHERE l.tenant_id = h.tenant_id AND l.holiday_id = h.id)
  GROUP BY h.tenant_id;
UPDATE holidays h JOIN holiday_calendars c ON c.tenant_id = h.tenant_id AND c.country_code = 'US'
  SET h.calendar_id = c.id
  WHERE EXISTS (SELECT 1 FROM holiday_society_links l WHERE l.tenant_id = h.tenant_id AND l.holiday_id = h.id);
UPDATE holidays h JOIN holiday_calendars c ON c.tenant_id = h.tenant_id AND c.country_code = 'CO'
  SET h.calendar_id = c.id
  WHERE h.calendar_id IS NULL;
DELETE l FROM holiday_society_links l JOIN holidays h ON h.tenant_id = l.tenant_id AND h.id = l.holiday_id WHERE h.calendar_id IS NOT NULL;
