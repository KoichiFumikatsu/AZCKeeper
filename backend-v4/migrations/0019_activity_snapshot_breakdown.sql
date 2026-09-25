-- El cliente ya envia el desglose horario (H1) y el servidor lo descartaba: activity_snapshots
-- solo guardaba activo e inactivo. Aqui recibe el resto de lo que el contrato transporta.
--
-- NULL significa DESCONOCIDO, no cero: un agente antiguo que no reporta el desglose no debe
-- aparecer con una jornada de cero segundos.

ALTER TABLE activity_snapshots
  ADD COLUMN call_seconds BIGINT UNSIGNED NULL,
  ADD COLUMN work_hours_active_seconds BIGINT UNSIGNED NULL,
  ADD COLUMN work_hours_idle_seconds BIGINT UNSIGNED NULL,
  ADD COLUMN lunch_active_seconds BIGINT UNSIGNED NULL,
  ADD COLUMN lunch_idle_seconds BIGINT UNSIGNED NULL,
  ADD COLUMN after_hours_active_seconds BIGINT UNSIGNED NULL,
  ADD COLUMN after_hours_idle_seconds BIGINT UNSIGNED NULL,
  ADD COLUMN first_activity_at DATETIME(6) NULL COMMENT 'Primera actividad real, no el primer envio',
  ADD COLUMN last_activity_at DATETIME(6) NULL,
  ADD COLUMN sample_count BIGINT UNSIGNED NULL COMMENT 'Muestras tomadas; NO son los envios HTTP que contaba K3',
  ADD COLUMN utc_offset_minutes SMALLINT NULL,
  -- Las llamadas son un subconjunto del tiempo activo. En los datos reales de K3 esto se
  -- violaba en el 17,4% de los dias, con un maximo de 184 horas de llamada en un dia de 24.
  ADD CONSTRAINT activity_call_subset CHECK (call_seconds IS NULL OR call_seconds <= active_seconds),
  ADD CONSTRAINT activity_active_split CHECK (
    work_hours_active_seconds IS NULL OR lunch_active_seconds IS NULL OR after_hours_active_seconds IS NULL
    OR work_hours_active_seconds + lunch_active_seconds + after_hours_active_seconds <= active_seconds + 60),
  ADD CONSTRAINT activity_idle_split CHECK (
    work_hours_idle_seconds IS NULL OR lunch_idle_seconds IS NULL OR after_hours_idle_seconds IS NULL
    OR work_hours_idle_seconds + lunch_idle_seconds + after_hours_idle_seconds <= idle_seconds + 60),
  ADD CONSTRAINT activity_first_before_last CHECK (
    first_activity_at IS NULL OR last_activity_at IS NULL OR first_activity_at <= last_activity_at);
