-- Keeper 4 — idempotencia de episodios.
--
-- Un cliente que reintenta un batch tras un timeout de red (el server pudo haber
-- commiteado) re-insertaba el detalle y DUPLICABA el rollup diario, que alimenta la
-- vista de procesos y el doble empleo. Clave unica natural: un equipo no puede tener
-- dos episodios del mismo proceso que arranquen en el mismo segundo. Incluye day_date
-- porque la tabla esta particionada por esa columna (MySQL exige que el unique la
-- contenga). Con esto el INSERT IGNORE del EpisodeRepo descarta duplicados exactos y
-- el rollup solo suma lo realmente insertado.

ALTER TABLE keeper_episode
  ADD UNIQUE KEY uq_ep_natural (device_id, day_date, start_at, process_name);
