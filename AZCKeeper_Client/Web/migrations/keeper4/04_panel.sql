-- Keeper 4 — panel y auditoria.

CREATE TABLE keeper_admin_accounts (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  email          VARCHAR(190) NOT NULL,
  display_name   VARCHAR(190) NULL,
  password_hash  VARCHAR(255) NOT NULL,
  panel_role     VARCHAR(64) NOT NULL DEFAULT 'viewer',
  firma_scope_id INT UNSIGNED NULL,
  area_scope_id  INT UNSIGNED NULL,
  sede_scope_id  INT UNSIGNED NULL,
  is_active      TINYINT(1) NOT NULL DEFAULT 1,
  created_by     BIGINT UNSIGNED NULL,
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_admin_email (email),
  KEY ix_admin_role (panel_role, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE keeper_admin_sessions (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  admin_id   BIGINT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL,
  issued_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at DATETIME NULL,
  revoked_at DATETIME NULL,
  ip         VARCHAR(45) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_adminsess_token (token_hash),
  KEY ix_adminsess_admin (admin_id, revoked_at),
  CONSTRAINT fk_adminsess_admin FOREIGN KEY (admin_id) REFERENCES keeper_admin_accounts (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE keeper_panel_roles (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  role_code    VARCHAR(64) NOT NULL,
  label        VARCHAR(190) NOT NULL,
  permissions_json JSON NOT NULL,
  is_system    TINYINT(1) NOT NULL DEFAULT 0,
  updated_by   BIGINT UNSIGNED NULL,
  updated_at   TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_role_code (role_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE keeper_panel_settings (
  setting_key   VARCHAR(128) NOT NULL,
  setting_value LONGTEXT NULL,
  updated_by    BIGINT UNSIGNED NULL,
  updated_at    TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Auditoria CON ACTOR. En el esquema 3 la tabla tenia sujeto (user_id, device_id)
-- pero no admin_id, asi que podia decir a quien pero nunca quien: la bitacora del
-- Modulo de Seguridad no podia responder 'quien otorgo esta excepcion'.
-- event_category separa acciones administrativas, importaciones y accesos a datos
-- sensibles (consultas a window_title), que se auditan por secreto profesional.
CREATE TABLE keeper_audit_log (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  admin_id       BIGINT UNSIGNED NULL COMMENT 'el ACTOR; NULL solo para eventos del sistema',
  user_id        BIGINT UNSIGNED NULL COMMENT 'el SUJETO',
  device_id      BIGINT UNSIGNED NULL,
  event_category ENUM('admin','import','security','data_access','system') NOT NULL DEFAULT 'admin',
  event_type     VARCHAR(64) NOT NULL,
  message        VARCHAR(512) NULL,
  meta_json      JSON NULL,
  ip             VARCHAR(45) NULL,
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_audit_actor (admin_id, created_at),
  KEY ix_audit_subject (user_id, created_at),
  KEY ix_audit_cat (event_category, event_type, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
