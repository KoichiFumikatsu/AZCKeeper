-- #3 Baja manual de dispositivos (devuelto/cambiado/reemplazado) con motivo y fecha.
-- La baja marca status='revoked' (ya excluido de vistas activas) + el motivo.
-- La "obsolescencia" (>30d sin reportar) se calcula por last_seen_at, sin columna.
ALTER TABLE keeper_devices
  ADD COLUMN decommission_reason ENUM('returned','changed','replaced','other') NULL DEFAULT NULL AFTER `status`,
  ADD COLUMN decommissioned_at TIMESTAMP NULL DEFAULT NULL AFTER decommission_reason;
