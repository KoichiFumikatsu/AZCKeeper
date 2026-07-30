-- Keeper 4 — identidad, organizacion y multi-tenant.
--
-- Cambio estructural respecto al esquema 3: la identidad interna esta separada del
-- identificador externo (keeper_external_ref). En el 3, LegacySyncService escribia el
-- id legacy directamente en keeper_user_assignments y las lecturas lo trataban como id
-- de Keeper; funcionaba solo porque el seed preservo los ids. Con dos clientes distintos
-- eso es incorrecto desde el primer dia: ambos pueden traer area_id=5 con significados
-- distintos.

CREATE TABLE keeper_firmas (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  nombre       VARCHAR(190) NOT NULL,
  is_active    TINYINT(1) NOT NULL DEFAULT 1,
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_firmas_nombre (nombre),
  KEY ix_firmas_activa (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE keeper_sociedades (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  nombre       VARCHAR(190) NOT NULL,
  is_active    TINYINT(1) NOT NULL DEFAULT 1,
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_sociedades_nombre (nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE keeper_areas (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  nombre       VARCHAR(190) NOT NULL,
  is_active    TINYINT(1) NOT NULL DEFAULT 1,
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_areas_nombre (nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE keeper_cargos (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  nombre       VARCHAR(190) NOT NULL,
  is_active    TINYINT(1) NOT NULL DEFAULT 1,
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_cargos_nombre (nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE keeper_sedes (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  nombre       VARCHAR(190) NOT NULL,
  is_active    TINYINT(1) NOT NULL DEFAULT 1,
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_sedes_nombre (nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Fuente de datos por firma: como se importan sus usuarios.
-- key_version permite rotar APP_KEY sin dejar ilegible lo ya cifrado, que hoy es imposible.
CREATE TABLE keeper_source (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  firma_id       INT UNSIGNED NOT NULL,
  nombre         VARCHAR(190) NOT NULL,
  source_type    ENUM('manual','mysql','csv') NOT NULL DEFAULT 'manual',
  db_host        VARCHAR(190) NULL,
  db_port        SMALLINT UNSIGNED NULL,
  db_name        VARCHAR(190) NULL,
  db_user        VARCHAR(190) NULL,
  db_pass_enc    VARBINARY(512) NULL COMMENT 'AES-256-CBC',
  key_version    TINYINT UNSIGNED NOT NULL DEFAULT 1,
  is_active      TINYINT(1) NOT NULL DEFAULT 1,
  last_import_at DATETIME NULL,
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_source_firma (firma_id, is_active),
  CONSTRAINT fk_source_firma FOREIGN KEY (firma_id) REFERENCES keeper_firmas (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE keeper_users (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  cc                VARCHAR(32) NULL,
  email             VARCHAR(190) NULL,
  display_name      VARCHAR(190) NULL,
  status            ENUM('active','inactive','pending') NOT NULL DEFAULT 'active',
  employment_status ENUM('active','retired') NOT NULL DEFAULT 'active',
  password_hash     VARCHAR(255) NULL,
  created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  -- La cedula es la clave natural: es lo que permite a la importacion decidir
  -- "esta persona ya existe, mapeala" en vez de duplicarla. UNIQUE admite varios
  -- NULL en MySQL, asi que sigue siendo opcional.
  UNIQUE KEY uq_users_cc (cc),
  KEY ix_users_status (status, employment_status),
  KEY ix_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Correspondencia origen + identificador externo -> identidad interna.
-- external_id es VARCHAR para no asumir que el cliente usa enteros.
CREATE TABLE keeper_external_ref (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  source_id     INT UNSIGNED NOT NULL,
  entity_type   ENUM('user','firma','area','cargo','sede','sociedad') NOT NULL,
  external_id   VARCHAR(190) NOT NULL,
  internal_id   BIGINT UNSIGNED NOT NULL,
  first_seen_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_seen_at  TIMESTAMP NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_extref (source_id, entity_type, external_id),
  KEY ix_extref_internal (entity_type, internal_id),
  CONSTRAINT fk_extref_source FOREIGN KEY (source_id) REFERENCES keeper_source (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Asignaciones: SIEMPRE identidad interna. Ningun *_id de esta tabla puede
-- contener un identificador externo; para eso esta keeper_external_ref.
CREATE TABLE keeper_user_assignments (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id        BIGINT UNSIGNED NOT NULL,
  firma_id       INT UNSIGNED NULL,
  sociedad_id    INT UNSIGNED NULL,
  area_id        INT UNSIGNED NULL,
  cargo_id       INT UNSIGNED NULL,
  sede_id        INT UNSIGNED NULL,
  is_manual      TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = fijado a mano, la importacion no lo pisa',
  updated_at     TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_assign_user (user_id),
  KEY ix_assign_scope (firma_id, sede_id, area_id, cargo_id, sociedad_id),
  CONSTRAINT fk_assign_user FOREIGN KEY (user_id) REFERENCES keeper_users (id) ON DELETE CASCADE,
  -- Las cinco FK siguientes son lo que hace VERIFICABLE la regla de arriba. Sin ellas,
  -- un bug de importacion podria escribir aqui un id externo y MySQL lo aceptaria en
  -- silencio: justo el fallo que keeper_external_ref existe para prevenir.
  -- SET NULL y no CASCADE: perder una dimension organizacional no debe borrar la
  -- asignacion completa de la persona.
  CONSTRAINT fk_assign_firma    FOREIGN KEY (firma_id)    REFERENCES keeper_firmas (id)     ON DELETE SET NULL,
  CONSTRAINT fk_assign_sociedad FOREIGN KEY (sociedad_id) REFERENCES keeper_sociedades (id) ON DELETE SET NULL,
  CONSTRAINT fk_assign_area     FOREIGN KEY (area_id)     REFERENCES keeper_areas (id)      ON DELETE SET NULL,
  CONSTRAINT fk_assign_cargo    FOREIGN KEY (cargo_id)    REFERENCES keeper_cargos (id)     ON DELETE SET NULL,
  CONSTRAINT fk_assign_sede     FOREIGN KEY (sede_id)     REFERENCES keeper_sedes (id)      ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE keeper_devices (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id             BIGINT UNSIGNED NOT NULL,
  device_guid         CHAR(36) NOT NULL,
  device_name         VARCHAR(190) NULL,
  client_version      VARCHAR(32) NULL,
  status              ENUM('active','revoked') NOT NULL DEFAULT 'active',
  decommission_reason VARCHAR(190) NULL,
  decommissioned_at   DATETIME NULL,
  last_seen_at        DATETIME NULL,
  created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_device_guid (device_guid),
  KEY ix_device_user (user_id, status),
  KEY ix_device_seen (last_seen_at),
  CONSTRAINT fk_device_user FOREIGN KEY (user_id) REFERENCES keeper_users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE keeper_sessions (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id      BIGINT UNSIGNED NOT NULL,
  device_id    BIGINT UNSIGNED NULL,
  token_hash   CHAR(64) NOT NULL,
  issued_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at   DATETIME NULL,
  revoked_at   DATETIME NULL,
  ip           VARCHAR(45) NULL,
  user_agent   VARCHAR(255) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_session_token (token_hash),
  KEY ix_session_user (user_id, revoked_at),
  KEY ix_session_device (device_id),
  CONSTRAINT fk_session_user FOREIGN KEY (user_id) REFERENCES keeper_users (id) ON DELETE CASCADE,
  -- SET NULL y no CASCADE: dar de baja un equipo no debe borrar el rastro de la sesion.
  -- Si se borra el usuario, fk_session_user ya arrastra la sesion por CASCADE.
  CONSTRAINT fk_session_device FOREIGN KEY (device_id) REFERENCES keeper_devices (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
