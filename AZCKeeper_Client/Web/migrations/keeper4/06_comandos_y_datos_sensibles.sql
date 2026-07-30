-- Keeper 4 — cola de comandos y datos sensibles.
--
-- keeper_device_command es un canal servidor -> equipo, lo contrario al flujo
-- normal de Keeper (telemetria equipo -> servidor). Sirve al apagado remoto y al
-- diagnostico de red bajo demanda, y a cualquier accion remota futura.

CREATE TABLE keeper_device_command (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  device_id    BIGINT UNSIGNED NOT NULL,
  command_type VARCHAR(64) NOT NULL COMMENT 'shutdown | network_diag | screenshot_now',
  params_json  JSON NULL,
  status       ENUM('pending','sent','acked','done','failed','expired','canceled')
                 NOT NULL DEFAULT 'pending',
  created_by   BIGINT UNSIGNED NULL COMMENT 'admin_id: el actor, para auditoria',
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  sent_at      DATETIME NULL,
  completed_at DATETIME NULL,
  result_json  JSON NULL COMMENT 'resultado del diagnostico de red, o error del apagado',
  expires_at   DATETIME NULL COMMENT 'un apagado no recogido caduca; no se ejecuta dias despues',
  PRIMARY KEY (id),
  KEY ix_cmd_device_status (device_id, status),
  KEY ix_cmd_created (created_at),
  CONSTRAINT fk_cmd_device FOREIGN KEY (device_id) REFERENCES keeper_devices (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Screenshots: el blob va a object storage, la BD solo metadata + hash.
-- A 1000 equipos son TB/año; blobs en MySQL revientan disco/backups/replicacion.
-- Decision de arquitectura del 2026-06-09. Su CONSULTA se audita (data_access).
CREATE TABLE keeper_screenshot (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id      BIGINT UNSIGNED NOT NULL,
  device_id    BIGINT UNSIGNED NOT NULL,
  captured_at  DATETIME NOT NULL COMMENT 'hora local del equipo',
  received_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  object_key   VARCHAR(512) NOT NULL COMMENT 'clave en object storage; el PNG/JPG NO va en BD',
  sha256       CHAR(64) NOT NULL COMMENT 'tamper-evidence: prueba que el blob no se altero',
  size_bytes   INT UNSIGNED NULL,
  trigger_type ENUM('scheduled','event','on_demand') NOT NULL DEFAULT 'event',
  command_id   BIGINT UNSIGNED NULL COMMENT 'si vino de un comando on_demand',
  PRIMARY KEY (id),
  KEY ix_ss_user_time (user_id, captured_at),
  KEY ix_ss_device_time (device_id, captured_at),
  CONSTRAINT fk_ss_device FOREIGN KEY (device_id) REFERENCES keeper_devices (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Ubicacion. DECIMAL(9,6) da ~0.1 m, suficiente y compacto. source importa para
-- la interpretacion: por IP es aproximada a nivel ciudad, por GPS es precisa.
CREATE TABLE keeper_location (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id     BIGINT UNSIGNED NOT NULL,
  device_id   BIGINT UNSIGNED NOT NULL,
  captured_at DATETIME NOT NULL,
  latitude    DECIMAL(9,6) NOT NULL,
  longitude   DECIMAL(9,6) NOT NULL,
  accuracy_m  INT UNSIGNED NULL,
  source      ENUM('gps','wifi','ip') NOT NULL DEFAULT 'ip',
  PRIMARY KEY (id),
  KEY ix_loc_user_time (user_id, captured_at),
  KEY ix_loc_device_time (device_id, captured_at),
  CONSTRAINT fk_loc_device FOREIGN KEY (device_id) REFERENCES keeper_devices (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
