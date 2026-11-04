-- Vinculos de calendarios de la fuente; conserva los valores anteriores del ENUM en el mismo orden.
ALTER TABLE entity_links
  MODIFY COLUMN entity_type ENUM('org_unit','schedule','holiday','user','absence','door','holiday_calendar') NOT NULL;
