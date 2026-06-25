-- Fix 500 al "Crear usuario solo en Keeper" (users.php, action=create_user, employee_id=0).
-- La ruta manual inserta legacy_employee_id=NULL, pero la columna era NOT NULL:
--   SQLSTATE[23000] 1048 Column 'legacy_employee_id' cannot be null.
-- Se hace nullable. La UNIQUE key uk_keeper_users_legacy se conserva (MySQL permite
-- múltiples NULL), así los usuarios keeper-only conviven con los vinculados a legacy.
-- Aplicado en PROD (keep.azclegal.com / pipezafra_keep) 2026-06-25.
-- Backup previo: /home/kelsie/azc_backups/keeper_users_pre_legacy_nullable_20260625_151433.sql
ALTER TABLE keeper_users
  MODIFY COLUMN legacy_employee_id INT NULL;
