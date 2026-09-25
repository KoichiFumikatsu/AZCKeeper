-- La restriccion de 0016 exigia que el desglose sumara exactamente <= al total.
-- Contra los datos reales de K3 (21.182 dias) eso resulto demasiado estricto:
--
--   72,1%  cuadran exacto
--   13,5%  el desglose suma menos que el total
--   14,2%  exceden entre 1 y 5 segundos   <- deriva normal
--    0,2%  exceden mas de 60 segundos     <- filas realmente corruptas
--
-- La deriva de pocos segundos es inevitable: K3 incrementa el total y el contador de la
-- franja en caminos de codigo distintos, a 1 Hz y con tipos distintos (int vs decimal).
-- Exigir igualdad exacta rechazaba una de cada siete filas buenas.
--
-- Se tolera hasta 60 segundos de exceso. Las filas que se pasan de ahi NO se corrigen ni
-- se recortan: se quedan con el desglose en NULL (desconocido), porque recortarlas seria
-- inventar un reparto horario que nadie midio.

ALTER TABLE day_summary
  DROP CONSTRAINT day_summary_active_split,
  DROP CONSTRAINT day_summary_idle_split;

ALTER TABLE day_summary
  ADD CONSTRAINT day_summary_active_split CHECK (
    work_hours_active_seconds IS NULL OR lunch_active_seconds IS NULL OR after_hours_active_seconds IS NULL
    OR work_hours_active_seconds + lunch_active_seconds + after_hours_active_seconds <= active_seconds + 60),
  ADD CONSTRAINT day_summary_idle_split CHECK (
    work_hours_idle_seconds IS NULL OR lunch_idle_seconds IS NULL OR after_hours_idle_seconds IS NULL
    OR work_hours_idle_seconds + lunch_idle_seconds + after_hours_idle_seconds <= idle_seconds + 60);
