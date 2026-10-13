-- Un mismo proceso puede tener dos categorias el mismo dia: el navegador con un sitio reconocido (site:Facebook, poco
-- productivo) y sin sitio (chrome, su categoria propia). ProductivityWorker agrupa por proceso y categoria, pero la clave
-- de episode_daily no incluia la categoria: el INSERT del dia fallaba por clave duplicada y el calculo de TODO el dia del
-- tenant quedaba detenido (devkeep, 2026-10-13 desde 15:40 UTC, primer dia con chrome con y sin sitio en un mismo equipo).
-- Solo ProductivityWorker escribe en esta tabla y la recalcula entera por dia: ampliar la clave no deja filas huerfanas.

ALTER TABLE episode_daily
  DROP PRIMARY KEY,
  ADD PRIMARY KEY (tenant_id, day, user_id, user_assignment_id, device_id, process_name, category);
