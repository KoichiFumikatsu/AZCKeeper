-- Keeper 4 — actividad y procesos.
--
-- Tres correcciones estructurales respecto al esquema 3:
--   1. Detalle (keeper_episode) separado del agregado (keeper_episode_daily). En el 3,
--      el panel agregaba sobre 6.9M filas sin ningun indice que empezara por day_date:
--      index.php hacia 3 escaneos completos por carga.
--   2. Sin GREATEST en el resumen diario. En el 3 era un trinquete que nunca bajaba y
--      convirtio el doble seed del cliente en corrupcion imborrable.
--   3. Banderas de cobertura obligatorias. En el 3, un equipo con WindowTracking apagado
--      producia focus 55 y productividad 90% -el mejor de la flota- porque los
--      subpuntajes sin datos puntuaban 100.

-- Detalle. Particionada por mes: la purga de retencion es DROP PARTITION (instantaneo)
-- en vez de DELETE de millones de filas en un hosting que ya se cae por limite de procesos.
-- La particion obliga a incluir day_date en la clave primaria.
-- NOTA: sin FK, porque MySQL no admite claves foraneas en tablas particionadas.
-- La integridad la garantiza el endpoint, que resuelve device y user antes de insertar.
CREATE TABLE keeper_episode (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id          BIGINT UNSIGNED NOT NULL,
  device_id        BIGINT UNSIGNED NOT NULL,
  day_date         DATE NOT NULL,
  start_at         DATETIME NOT NULL,
  end_at           DATETIME NOT NULL,
  duration_seconds INT UNSIGNED NOT NULL,
  process_name     VARCHAR(190) NOT NULL,
  window_title     VARCHAR(512) NULL,
  is_in_call       TINYINT(1) NOT NULL DEFAULT 0,
  created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id, day_date),
  KEY ix_ep_user_day_start (user_id, day_date, start_at),
  KEY ix_ep_day_proc (day_date, process_name, duration_seconds),
  KEY ix_ep_device_day (device_id, day_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
PARTITION BY RANGE COLUMNS(day_date) (
  PARTITION p2026_08 VALUES LESS THAN ('2026-09-01'),
  PARTITION p2026_09 VALUES LESS THAN ('2026-10-01'),
  PARTITION p2026_10 VALUES LESS THAN ('2026-11-01'),
  PARTITION p2026_11 VALUES LESS THAN ('2026-12-01'),
  PARTITION p2026_12 VALUES LESS THAN ('2027-01-01'),
  PARTITION p2027_01 VALUES LESS THAN ('2027-02-01'),
  PARTITION pmax     VALUES LESS THAN (MAXVALUE)
);

-- Agregado diario por proceso. Responde todo lo agregado (top apps, ocio, primer
-- ingreso) en decenas de filas por persona y dia, en vez de millones.
CREATE TABLE keeper_episode_daily (
  user_id        BIGINT UNSIGNED NOT NULL,
  day_date       DATE NOT NULL,
  process_name   VARCHAR(190) NOT NULL,
  total_seconds  INT UNSIGNED NOT NULL DEFAULT 0,
  episode_count  INT UNSIGNED NOT NULL DEFAULT 0,
  call_seconds   INT UNSIGNED NOT NULL DEFAULT 0,
  first_start_at DATETIME NULL,
  last_end_at    DATETIME NULL,
  PRIMARY KEY (user_id, day_date, process_name),
  KEY ix_epd_day_proc (day_date, process_name, total_seconds)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Resumen diario. Reemplaza keeper_activity_day.
-- Todos los segundos son INT UNSIGNED: en el 3 convivian int y decimal(12,3) para lo mismo.
-- Las tres banderas de cobertura son la correccion mas importante del esquema: ninguna
-- metrica puede calcularse sin su bandera en 1.
CREATE TABLE keeper_day_summary (
  id                         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id                    BIGINT UNSIGNED NOT NULL,
  device_id                  BIGINT UNSIGNED NOT NULL,
  day_date                   DATE NOT NULL,
  tz_offset_minutes          SMALLINT NOT NULL DEFAULT -300,
  is_workday                 TINYINT(1) NOT NULL DEFAULT 1,
  activity_tracked           TINYINT(1) NOT NULL DEFAULT 0,
  window_tracked             TINYINT(1) NOT NULL DEFAULT 0,
  call_tracked               TINYINT(1) NOT NULL DEFAULT 0,
  active_seconds             INT UNSIGNED NOT NULL DEFAULT 0,
  idle_seconds               INT UNSIGNED NOT NULL DEFAULT 0,
  call_seconds               INT UNSIGNED NOT NULL DEFAULT 0,
  work_active_seconds        INT UNSIGNED NOT NULL DEFAULT 0,
  work_idle_seconds          INT UNSIGNED NOT NULL DEFAULT 0,
  lunch_active_seconds       INT UNSIGNED NOT NULL DEFAULT 0,
  lunch_idle_seconds         INT UNSIGNED NOT NULL DEFAULT 0,
  after_hours_active_seconds INT UNSIGNED NOT NULL DEFAULT 0,
  after_hours_idle_seconds   INT UNSIGNED NOT NULL DEFAULT 0,
  first_event_at             DATETIME NULL,
  last_event_at              DATETIME NULL,
  created_at                 TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at                 TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_day_summary (user_id, device_id, day_date),
  KEY ix_ds_day (day_date),
  KEY ix_ds_user_day (user_id, day_date),
  CONSTRAINT fk_ds_user FOREIGN KEY (user_id) REFERENCES keeper_users (id) ON DELETE CASCADE,
  CONSTRAINT fk_ds_device FOREIGN KEY (device_id) REFERENCES keeper_devices (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Metricas derivadas. Hereda las banderas de cobertura del resumen del dia:
-- si window_tracked=0, focus_score DEBE ser NULL, no un numero.
CREATE TABLE keeper_focus_daily (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id             BIGINT UNSIGNED NOT NULL,
  day_date            DATE NOT NULL,
  window_tracked      TINYINT(1) NOT NULL DEFAULT 0,
  focus_score         TINYINT UNSIGNED NULL COMMENT 'NULL = sin cobertura, nunca 0 por defecto',
  productivity_pct    TINYINT UNSIGNED NULL,
  deep_work_seconds   INT UNSIGNED NOT NULL DEFAULT 0,
  distraction_seconds INT UNSIGNED NOT NULL DEFAULT 0,
  switch_count        INT UNSIGNED NOT NULL DEFAULT 0,
  computed_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_focus (user_id, day_date),
  KEY ix_focus_day (day_date, focus_score),
  CONSTRAINT fk_focus_user FOREIGN KEY (user_id) REFERENCES keeper_users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
