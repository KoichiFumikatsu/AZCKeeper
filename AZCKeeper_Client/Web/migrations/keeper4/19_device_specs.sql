-- Keeper 4 — especificaciones del equipo (para la tarjeta "Equipo" en la vista de procesos).
--
-- El cliente recoge las specs una vez al arrancar (SO/CPU/RAM/disco, nombre/modelo/serial,
-- IP/MAC, GPU/pantallas) y las envia en el primer handshake. Se guardan crudas en JSON: el
-- panel decide que mostrar (p.ej. IP/MAC solo a IT). No cambian a menudo -> se refrescan al
-- reiniciar/actualizar el equipo.
ALTER TABLE keeper_devices
  ADD COLUMN specs_json JSON NULL COMMENT 'specs del equipo reportadas por el cliente' AFTER last_idle_seconds,
  ADD COLUMN specs_at   DATETIME NULL COMMENT 'cuando se reportaron (UTC)' AFTER specs_json;
