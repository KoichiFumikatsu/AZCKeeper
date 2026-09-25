-- Paridad de captura con K3.
-- K3 (keeper_activity_day) desglosaba la jornada en laboral / almuerzo / fuera de horario.
-- v4 recibia un unico total, de modo que el panel no podia distinguir si alguien estuvo
-- activo en su horario, en su almuerzo o de madrugada. Estas columnas reciben ese desglose.
-- Aditiva: NULL significa DESCONOCIDO (agente antiguo que no lo reporta), nunca cero.

ALTER TABLE day_summary
  ADD COLUMN work_hours_active_seconds BIGINT UNSIGNED NULL COMMENT 'K3: work_hours_active_seconds',
  ADD COLUMN work_hours_idle_seconds BIGINT UNSIGNED NULL COMMENT 'K3: work_hours_idle_seconds',
  ADD COLUMN lunch_active_seconds BIGINT UNSIGNED NULL COMMENT 'K3: lunch_active_seconds',
  ADD COLUMN lunch_idle_seconds BIGINT UNSIGNED NULL COMMENT 'K3: lunch_idle_seconds',
  ADD COLUMN after_hours_active_seconds BIGINT UNSIGNED NULL COMMENT 'K3: after_hours_active_seconds',
  ADD COLUMN after_hours_idle_seconds BIGINT UNSIGNED NULL COMMENT 'K3: after_hours_idle_seconds',
  ADD COLUMN sample_count BIGINT UNSIGNED NULL COMMENT 'Muestras reales que respaldan el dato. NO es samples_count de K3, que contaba envios HTTP',
  ADD COLUMN utc_offset_minutes SMALLINT NULL COMMENT 'Desfase local del equipo al cerrar el dia',
  ADD CONSTRAINT day_summary_utc_offset_range CHECK (utc_offset_minutes IS NULL OR utc_offset_minutes BETWEEN -840 AND 840);

-- El desglose no puede superar el total del que forma parte.
ALTER TABLE day_summary
  ADD CONSTRAINT day_summary_active_split CHECK (
    work_hours_active_seconds IS NULL OR after_hours_active_seconds IS NULL OR lunch_active_seconds IS NULL
    OR work_hours_active_seconds + lunch_active_seconds + after_hours_active_seconds <= active_seconds),
  ADD CONSTRAINT day_summary_idle_split CHECK (
    work_hours_idle_seconds IS NULL OR after_hours_idle_seconds IS NULL OR lunch_idle_seconds IS NULL
    OR work_hours_idle_seconds + lunch_idle_seconds + after_hours_idle_seconds <= idle_seconds);

-- K3 guardaba el nombre presentable aparte del ejecutable (app_name) y por que marco
-- el episodio como llamada (call_app_hint), que permite auditar los falsos positivos:
-- su deteccion por subcadena marcaba como llamada cualquier titulo que contuviera "call".
ALTER TABLE episodes
  ADD COLUMN app_name VARCHAR(190) NULL COMMENT 'K3: app_name',
  ADD COLUMN call_app_hint VARCHAR(190) NULL COMMENT 'K3: call_app_hint',
  ADD COLUMN time_category ENUM('work_hours','lunch','after_hours') NULL COMMENT 'Franja del episodio; el cliente ya la calculaba y se descartaba';

ALTER TABLE episode_daily
  ADD COLUMN app_name VARCHAR(190) NULL COMMENT 'Para agrupar el top de apps por producto y no por ejecutable',
  ADD COLUMN time_category ENUM('work_hours','lunch','after_hours') NULL;

CREATE INDEX episodes_time_category ON episodes (tenant_id, event_date, time_category);
