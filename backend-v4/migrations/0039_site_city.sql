-- Ciudad de cada sede (Koichi 2026-10-02): Tequendama -> Cali, 808 -> Medellin, oficina de Centroamerica -> Tegucigalpa.
-- Segmenta personas por pais > ciudad > sede. Texto libre; la comparacion ignora mayusculas y tildes (collation ai_ci).
ALTER TABLE org_units
  ADD COLUMN city VARCHAR(80) NULL AFTER country_code,
  ADD CONSTRAINT org_units_city CHECK (city IS NULL OR (kind = 'site' AND CHAR_LENGTH(TRIM(city)) > 0));
