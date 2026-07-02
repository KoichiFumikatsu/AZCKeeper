-- Corte de historial por firma: los firma-admins no ven actividad anterior a esta fecha.
-- NULL = sin corte (ve todo). Solo aplica a admins con firm_scope_id; superadmin ve todo.
ALTER TABLE keeper_firmas
  ADD COLUMN historial_desde DATE NULL DEFAULT NULL AFTER descripcion;
