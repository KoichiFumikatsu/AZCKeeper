-- Migración: Agregar columna is_workday a keeper_activity_day
-- Propósito: Diferenciar días laborables (lunes-viernes) de fines de semana (sábado-domingo)
-- Fecha: 2026-02-04

ALTER TABLE keeper_activity_day
ADD COLUMN is_workday TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1=día laborable (lun-vie), 0=fin de semana (sáb-dom)'
AFTER after_hours_idle_seconds;

-- Índice para facilitar queries de "días laborados"
CREATE INDEX idx_is_workday ON keeper_activity_day(is_workday);

-- Actualizar registros existentes basándose en day_date
-- (1=domingo, 7=sábado en DAYOFWEEK de MySQL)
UPDATE keeper_activity_day
SET is_workday = CASE 
    WHEN DAYOFWEEK(day_date) IN (1, 7) THEN 0  -- domingo=1, sábado=7
    ELSE 1
END
WHERE is_workday = 1; -- solo actualizar los que tienen default

-- Verificación
SELECT 
    is_workday,
    COUNT(*) as total_days,
    MIN(day_date) as first_day,
    MAX(day_date) as last_day
FROM keeper_activity_day
GROUP BY is_workday;
