-- Keeper 4 — features de Keeper 3 que quedaron fuera del esquema inicial y se
-- traen rediseñadas (decisiones de Koichi, 2026-07-30, ver inventario de cobertura).
--
--   1. Deteccion de doble empleo (dual-job): entra rediseñada.
--   2. Cobertura de instalacion: entra corregida, atada a la identidad interna.
--   3. Enrolamiento: NO lleva tabla — se pliega sobre keeper_users(status='pending')
--      + keeper_audit_log (event_category='admin', event_type='enrollment_*').
--   4/5. UpdateManager/logging fuera del catalogo (infra, no se tarifica) y Discord
--      como config: son decisiones, sin cambio de esquema.

-- Clasificacion de aplicaciones. Fuente unica de "que es esta app":
--   - category alimenta el split productivo/ocio de la productividad. En el esquema 3
--     la lista de ocio vivia en keeper_panel_settings.leisure_apps y NUNCA se sembraba
--     -> la productividad no distinguia trabajo de ocio. Aqui es una tabla propia y el
--     seed la puebla.
--   - is_dual_job_signal marca apps que sugieren un segundo empleo (CRM de la
--     competencia, otras herramientas de fichaje, apps de trabajo remoto). Es un eje
--     distinto de la categoria: una app puede ser 'leisure' y ademas señal de doble
--     empleo, o 'productive' y aun asi sospechosa. Consolida las dos tablas separadas
--     del esquema 3 (app_classifications + suspicious_apps) sin conflar los ejes.
CREATE TABLE keeper_app_classification (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  match_type        ENUM('process','title_keyword') NOT NULL DEFAULT 'process',
  pattern           VARCHAR(190) NOT NULL,
  category          ENUM('productive','leisure','neutral') NOT NULL DEFAULT 'neutral',
  is_dual_job_signal TINYINT(1) NOT NULL DEFAULT 0,
  note              VARCHAR(255) NULL,
  updated_by        BIGINT UNSIGNED NULL COMMENT 'admin_id',
  updated_at        TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_appclass (match_type, pattern),
  KEY ix_appclass_cat (category),
  KEY ix_appclass_signal (is_dual_job_signal)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Alertas de doble empleo. Se recalculan desde keeper_episode/keeper_episode_daily
-- (que reemplazan a keeper_window_episode). evidence_json guarda los episodios/apps
-- que dispararon la alerta; status lleva el ciclo de revision.
CREATE TABLE keeper_dual_job_alert (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id       BIGINT UNSIGNED NOT NULL,
  device_id     BIGINT UNSIGNED NULL,
  day_date      DATE NOT NULL,
  alert_type    VARCHAR(64) NOT NULL COMMENT 'que disparo la alerta',
  evidence_json JSON NULL,
  status        ENUM('open','reviewed','dismissed','confirmed') NOT NULL DEFAULT 'open',
  reviewed_by   BIGINT UNSIGNED NULL COMMENT 'admin_id que reviso',
  reviewed_at   DATETIME NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_dja (user_id, day_date, alert_type),
  KEY ix_dja_status (status, day_date),
  CONSTRAINT fk_dja_user FOREIGN KEY (user_id) REFERENCES keeper_users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Cobertura de instalacion: notas y exentos por persona. En el esquema 3 se clavaba
-- por legacy_employee_id para sobrevivir a empleados no enrolados; en K4 los usuarios
-- entran por import ANTES del enrolamiento del equipo, asi que ya existen como
-- keeper_users y la clave por user_id es correcta.
CREATE TABLE keeper_coverage_note (
  user_id    BIGINT UNSIGNED NOT NULL,
  is_exempt  TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'arranca en 0: solo se ve lo accionable',
  note       VARCHAR(512) NULL,
  updated_by BIGINT UNSIGNED NULL COMMENT 'admin_id',
  updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id),
  CONSTRAINT fk_covnote_user FOREIGN KEY (user_id) REFERENCES keeper_users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Seed inicial de clasificacion de apps: arregla el bug de "ocio nunca sembrado".
-- Lista minima y ajustable desde el panel; el punto es que la productividad tenga
-- con que distinguir desde el dia uno.
INSERT INTO keeper_app_classification (match_type, pattern, category, is_dual_job_signal) VALUES
  ('process', 'chrome.exe',       'neutral',    0),
  ('process', 'msedge.exe',       'neutral',    0),
  ('process', 'outlook.exe',      'productive', 0),
  ('process', 'winword.exe',      'productive', 0),
  ('process', 'excel.exe',        'productive', 0),
  ('process', 'teams.exe',        'productive', 0),
  ('process', 'netflix',          'leisure',    0),
  ('process', 'spotify.exe',      'leisure',    0),
  ('process', 'steam.exe',        'leisure',    0),
  ('title_keyword', 'youtube',    'leisure',    0),
  ('title_keyword', 'facebook',   'leisure',    0),
  ('title_keyword', 'whatsapp',   'leisure',    0);
