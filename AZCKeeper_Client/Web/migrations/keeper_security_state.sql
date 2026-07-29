-- Modulo de Seguridad — estado de controles reportado por cada equipo.
-- Una fila por dispositivo (estado ACTUAL, no historico): el reporte llega en
-- cada handshake y se hace UPSERT. El historico se agrega si se necesita, no antes.

CREATE TABLE IF NOT EXISTS keeper_security_state (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id        INT             NOT NULL,
  device_id      INT             NOT NULL,
  reported_at    DATETIME        NOT NULL,
  agent_present  TINYINT(1)      NOT NULL DEFAULT 0,
  controls_json  LONGTEXT        NOT NULL,
  controls_hash  CHAR(64)        NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_security_state_device (device_id),
  KEY ix_security_state_reported (reported_at),
  KEY ix_security_state_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
