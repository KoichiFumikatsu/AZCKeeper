-- ============================================================
-- Migration: Productividad, Focus Score y Detección Doble Empleo
-- Fecha: 2026-03-26
-- Descripción: 3 tablas nuevas + settings para el sistema de
--   productividad avanzada, focus score 0-100 y alertas de
--   posible doble empleo.
-- ============================================================

-- 1. Métricas de foco calculadas server-side por usuario/día
CREATE TABLE IF NOT EXISTS `keeper_focus_daily` (
  `id`                          BIGINT       NOT NULL AUTO_INCREMENT,
  `user_id`                     BIGINT       NOT NULL,
  `device_id`                   BIGINT       NOT NULL,
  `day_date`                    DATE         NOT NULL,

  -- Métricas de productividad
  `context_switches`            INT          NOT NULL DEFAULT 0   COMMENT 'Cambios de app en horario laboral (excl. micro <5s)',
  `deep_work_seconds`           INT          NOT NULL DEFAULT 0   COMMENT 'Segundos efectivos de deep work (validados con ratio actividad)',
  `deep_work_sessions`          INT          NOT NULL DEFAULT 0   COMMENT 'Cantidad de bloques de deep work (≥umbral)',
  `distraction_seconds`         INT          NOT NULL DEFAULT 0   COMMENT 'Segundos en apps/ventanas de ocio en horario laboral',
  `longest_focus_streak_seconds` INT         NOT NULL DEFAULT 0   COMMENT 'Racha más larga de foco continuo sin switch',

  -- Scores
  `focus_score`                 TINYINT UNSIGNED NOT NULL DEFAULT 0  COMMENT 'Score compuesto 0-100',
  `productivity_pct`            TINYINT UNSIGNED NOT NULL DEFAULT 0  COMMENT 'Productividad % (work_active - distraction) / total',
  `constancy_pct`               TINYINT UNSIGNED NOT NULL DEFAULT 0  COMMENT '% de bloques 30min con actividad',

  -- Puntualidad
  `first_activity_time`         TIME         DEFAULT NULL         COMMENT 'Hora del primer episodio del día',
  `scheduled_start`             TIME         DEFAULT NULL         COMMENT 'Hora programada de inicio (del schedule)',
  `punctuality_minutes`         SMALLINT     NOT NULL DEFAULT 0   COMMENT 'Minutos de diferencia (+temprano, -tarde)',

  `created_at`                  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`                  TIMESTAMP    NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_focus_daily_user_device_day` (`user_id`, `device_id`, `day_date`),
  KEY `ix_focus_daily_user_day` (`user_id`, `day_date`),
  KEY `ix_focus_daily_day` (`day_date`),
  KEY `ix_focus_daily_score` (`day_date`, `focus_score`),

  CONSTRAINT `fk_focus_daily_user`   FOREIGN KEY (`user_id`)   REFERENCES `keeper_users`   (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_focus_daily_device` FOREIGN KEY (`device_id`) REFERENCES `keeper_devices` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Métricas diarias de foco y productividad calculadas por cron nocturno';


-- 2. Alertas de posible doble empleo
CREATE TABLE IF NOT EXISTS `keeper_dual_job_alerts` (
  `id`                BIGINT       NOT NULL AUTO_INCREMENT,
  `user_id`           BIGINT       NOT NULL,
  `day_date`          DATE         NOT NULL            COMMENT 'Día de referencia de la alerta',

  `alert_type`        ENUM('after_hours_pattern','foreign_app','remote_desktop','suspicious_idle')
                                   NOT NULL             COMMENT 'Tipo de señal detectada',
  `severity`          ENUM('low','medium','high')
                                   NOT NULL DEFAULT 'low',
  `evidence_json`     JSON         DEFAULT NULL         COMMENT 'Datos que sustentan la alerta',

  `is_reviewed`       TINYINT(1)   NOT NULL DEFAULT 0,
  `reviewed_by`       BIGINT       DEFAULT NULL         COMMENT 'keeper_admin_accounts.id',
  `reviewed_at`       DATETIME     DEFAULT NULL,
  `notes`             TEXT         DEFAULT NULL         COMMENT 'Notas del admin al revisar',

  `created_at`        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),
  KEY `ix_dual_job_user_day`  (`user_id`, `day_date`),
  KEY `ix_dual_job_type`      (`alert_type`, `severity`),
  KEY `ix_dual_job_pending`   (`is_reviewed`, `severity`),
  KEY `ix_dual_job_day`       (`day_date`),

  CONSTRAINT `fk_dual_job_user` FOREIGN KEY (`user_id`) REFERENCES `keeper_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Alertas de posible doble empleo para revisión humana';


-- 3. Lista configurable de apps sospechosas
CREATE TABLE IF NOT EXISTS `keeper_suspicious_apps` (
  `id`              INT          NOT NULL AUTO_INCREMENT,
  `app_pattern`     VARCHAR(190) NOT NULL              COMMENT 'Patrón de process_name o window_title a matchear',
  `category`        ENUM('remote_desktop','foreign_vpn','foreign_workspace','vm')
                                 NOT NULL               COMMENT 'Categoría de la app sospechosa',
  `description`     VARCHAR(255) DEFAULT NULL,
  `is_active`       TINYINT(1)   NOT NULL DEFAULT 1,
  `created_at`      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_suspicious_app_pattern` (`app_pattern`),
  KEY `ix_suspicious_active` (`is_active`, `category`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Lista configurable de apps sospechosas para detección de doble empleo';


-- Seed de apps sospechosas conocidas
INSERT IGNORE INTO `keeper_suspicious_apps` (`app_pattern`, `category`, `description`) VALUES
  ('teamviewer',            'remote_desktop',    'TeamViewer — control remoto'),
  ('anydesk',               'remote_desktop',    'AnyDesk — escritorio remoto'),
  ('parsec',                'remote_desktop',    'Parsec — conexión remota de bajo latencia'),
  ('rustdesk',              'remote_desktop',    'RustDesk — escritorio remoto open source'),
  ('chrome remote desktop', 'remote_desktop',    'Chrome Remote Desktop'),
  ('remotedesktop',         'remote_desktop',    'Windows Remote Desktop (mstsc)'),
  ('mstsc',                 'remote_desktop',    'Microsoft Remote Desktop Client'),
  ('virtualbox',            'vm',                'Oracle VirtualBox'),
  ('vmware',                'vm',                'VMware Workstation/Player'),
  ('vmplayer',              'vm',                'VMware Player'),
  ('hyper-v',               'vm',                'Microsoft Hyper-V'),
  ('qemu',                  'vm',                'QEMU — emulador de máquina virtual');


-- 4. Settings de productividad en keeper_panel_settings
INSERT IGNORE INTO `keeper_panel_settings` (`setting_key`, `setting_value`) VALUES
  ('productivity.deep_work_threshold_minutes', '25'),
  ('productivity.focus_weights', '{"context_switches":20,"deep_work":25,"distraction":20,"punctuality":15,"constancy":20}'),
  ('productivity.enabled', 'true'),
  ('dual_job.after_hours_threshold_days', '5'),
  ('dual_job.after_hours_min_seconds', '3600'),
  ('dual_job.enabled', 'true');
