-- Agente 4.0.24 (decision del dueño 2026-10-09): el tiempo con el equipo bloqueado (pantalla de bloqueo de Windows o
-- bloqueo de Keeper) no es "en uso" ni "inactivo": es "Bloqueado". En produccion la pantalla de bloqueo de Keeper
-- estuvo 3,5 h delante contando como en uso.
--
-- locked_seconds: fuera de active_seconds e idle_seconds (active + idle + locked <= dia). Franjas como las de activo e
-- inactivo. NULL = DESCONOCIDO (agente anterior a 4.0.24), nunca cero.
ALTER TABLE activity_snapshots
  ADD COLUMN locked_seconds BIGINT UNSIGNED NULL,
  ADD COLUMN work_hours_locked_seconds BIGINT UNSIGNED NULL,
  ADD COLUMN lunch_locked_seconds BIGINT UNSIGNED NULL,
  ADD COLUMN after_hours_locked_seconds BIGINT UNSIGNED NULL;

ALTER TABLE day_summary
  ADD COLUMN locked_seconds BIGINT UNSIGNED NULL,
  ADD COLUMN work_hours_locked_seconds BIGINT UNSIGNED NULL,
  ADD COLUMN lunch_locked_seconds BIGINT UNSIGNED NULL,
  ADD COLUMN after_hours_locked_seconds BIGINT UNSIGNED NULL;

-- Categoria "system" (Sistema): las ventanas de Keeper y de la pantalla de bloqueo no son productivas ni neutras; quedan
-- fuera de productividad, distraccion, sin clasificar, focus, rachas y cambios de contexto. Solo la base de la
-- plataforma la usa; las excepciones por area/cargo no la admiten (su enum no cambia).
ALTER TABLE app_classification
  MODIFY COLUMN category ENUM('productive','neutral','unproductive','unclassified','system') NOT NULL;

-- LockApp ya estaba como neutra en la base (0033): pasa a sistema.
INSERT INTO app_classification (tenant_id,id,process_name,category,label) VALUES
  (0x00000000000040008000000000000001, UNHEX(MD5('app:keeper.session')), 'Keeper.Session', 'system', 'Keeper (sesión)'),
  (0x00000000000040008000000000000001, UNHEX(MD5('app:keeper.agent')), 'Keeper.Agent', 'system', 'Keeper (agente)'),
  (0x00000000000040008000000000000001, UNHEX(MD5('app:keeper.bootstrapper')), 'Keeper.Bootstrapper', 'system', 'Keeper (instalador)'),
  (0x00000000000040008000000000000001, UNHEX(MD5('app:lockapp')), 'LockApp', 'system', 'Pantalla de bloqueo de Windows'),
  (0x00000000000040008000000000000001, UNHEX(MD5('app:logonui')), 'LogonUI', 'system', 'Inicio de sesión de Windows')
ON DUPLICATE KEY UPDATE category = 'system', label = VALUES(label), version = version + 1;

-- Recalcular los ultimos 30 dias con la categoria nueva (mismo alcance que un cambio de clasificacion).
UPDATE rollup_days SET dirty = TRUE, input_revision = input_revision + 1, finalized_at = NULL
WHERE day >= UTC_DATE() - INTERVAL 30 DAY;
