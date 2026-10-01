-- Empresas archivadas (decision de Koichi 2026-10-01): ocultas en las listas y selectores salvo que superadmin pida
-- verlas, y de solo lectura: nadie las modifica hasta desarchivarlas. Sus equipos ya no se conectan (suspendidas).
ALTER TABLE tenants ADD COLUMN archived_at DATETIME(6) NULL;
-- Las tres que se archivaron a mano antes de existir la columna.
UPDATE tenants SET archived_at = UTC_TIMESTAMP(6) WHERE status = 'suspended' AND name LIKE '%(archivada)';
