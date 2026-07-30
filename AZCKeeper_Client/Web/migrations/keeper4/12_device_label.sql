-- Keeper 4 — nombre editable del equipo desde el panel.
--
-- El cliente reporta device_name = nombre de la maquina (Environment.MachineName) en cada
-- login, y ClientLogin hace device_name = COALESCE(:n, device_name): siempre lo pisa. Si un
-- admin quisiera renombrar el equipo editando device_name, el proximo login lo revertiria.
--
-- 'label' es el nombre AMIGABLE que pone el admin desde el panel; el cliente NUNCA lo toca.
-- El panel muestra COALESCE(label, device_name): la etiqueta del admin si existe, si no el
-- nombre real de la maquina. Asi el renombre se mantiene y ademas se conserva el nombre
-- tecnico original (util para IT).

ALTER TABLE keeper_devices
  ADD COLUMN label VARCHAR(190) NULL COMMENT 'nombre amigable puesto por el admin; el cliente no lo toca' AFTER device_name;
