-- Puente One (docs/architecture/v4-puente-one-legacy.md §6.1 K1): nuevo dominio "empresas" (empresas del grupo,
-- org_units kind=company). Una fuente puede ser duena de el y asignar la empresa de cada persona (users.company_id).
-- Se agrega al final del ENUM (sin reordenar los valores existentes). Repetirlo deja la columna igual.
ALTER TABLE data_domain_owners
  MODIFY COLUMN domain ENUM('firmas','sedes','areas','cargos','horarios','festivos','personas','jerarquia','ausencias','empresas') NOT NULL;
