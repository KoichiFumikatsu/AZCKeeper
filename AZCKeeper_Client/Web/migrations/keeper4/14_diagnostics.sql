-- Keeper 4 — diagnostico en vivo por persona.
--
-- Pedido de Koichi: poder ver, de una persona concreta y en (casi) tiempo real, el
-- comportamiento ESPERADO vs el REAL de cada modulo, el flujo de logs, la actividad en
-- crudo y la salud de red — todo desde el panel, sin tocar el equipo.
--
-- El hosting compartido no da streaming ni WebSockets, asi que "tiempo real" = sondeo:
-- mientras una persona esta marcada, su cliente sube un snapshot cada ~4s y el panel se
-- auto-refresca. Para no repetir el write-amp que ya golpeo dos veces (keeper_window_episode
-- y keeper_client_log), esto es EFIMERO: retencion 2h + auto-apagado del flag a las 4h.

-- El flag por persona. Una fila = una persona con diagnostico activo. Sin fila (o expirada)
-- = apagado. El auto-apagado es implicito: handshake y panel tratan una sesion vencida como
-- ausente; el cron nocturno purga las vencidas.
CREATE TABLE keeper_diagnostic_session (
  user_id     BIGINT UNSIGNED NOT NULL,
  enabled_by  BIGINT UNSIGNED NULL COMMENT 'admin_id que lo encendio',
  started_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at  DATETIME NOT NULL COMMENT 'auto-apagado; started_at + 4h por defecto',
  PRIMARY KEY (user_id),
  KEY ix_diag_expires (expires_at),
  CONSTRAINT fk_diag_user FOREIGN KEY (user_id) REFERENCES keeper_users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Los snapshots. Efimeros (retencion 2h). Se guarda historia CORTA para poder rebobinar
-- los ultimos minutos, no infinita. SIN FK a users/devices: es telemetria desechable y no
-- debe encarecer el INSERT ni arrastrar cascadas. payload lleva los 4 bloques en JSON.
CREATE TABLE keeper_diagnostic_snapshot (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id     BIGINT UNSIGNED NOT NULL,
  device_id   BIGINT UNSIGNED NOT NULL,
  captured_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'llegada al servidor (UTC)',
  client_ts   DATETIME NULL COMMENT 'reloj del cliente al capturar',
  payload     JSON NOT NULL COMMENT 'modules[], logs[], activity, net',
  PRIMARY KEY (id),
  KEY ix_diagsnap_user (user_id, id),
  KEY ix_diagsnap_purge (captured_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
