-- Borrar unidades (Koichi 2026-10-02): la unidad desaparece del panel; si el historial (asignaciones pasadas, entradas
-- por puerta) la menciona, se conserva como eliminada para que ese historial siga valido. Si nunca se uso, se borra.
ALTER TABLE org_units ADD COLUMN deleted_at DATETIME(6) NULL;
