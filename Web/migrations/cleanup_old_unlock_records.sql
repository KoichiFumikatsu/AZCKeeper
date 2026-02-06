-- Limpieza de registros antiguos de keeper_device_locks
-- Marcar como inactivos los registros desbloqueados hace más de 7 días

UPDATE keeper_device_locks 
SET is_active = 0 
WHERE unlocked_at IS NOT NULL 
  AND unlocked_at < DATE_SUB(NOW(), INTERVAL 7 DAY)
  AND is_active = 1;

-- Opcional: Eliminar completamente registros muy antiguos (más de 30 días)
-- DELETE FROM keeper_device_locks 
-- WHERE unlocked_at < DATE_SUB(NOW(), INTERVAL 30 DAY);
