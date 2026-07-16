-- keeper_client_log — logs reportados por el cliente Windows (Warn/Error + eventos de update).
--
-- Motivación (2026-07-16): 39 equipos quedaron atascados en 3.0.2.4 handshakeando
-- con normalidad. La causa era indiagnosticable desde el servidor porque el único
-- rastro vivía en %APPDATA%\AZCKeeper\Logs\ de cada equipo. Esta tabla es el
-- instrumento que faltaba.
--
-- CONTENCIÓN DE VOLUMEN (keeper_window_episode ya está en 6.87M filas):
--   - El cliente solo reporta Warn/Error y eventos de update, nunca Info general.
--   - Cada línea se envía UNA vez (el buffer se drena al confirmar el envío).
--   - El endpoint topa a 50 filas por request.
--   - Retención de 30 días, purgada por el cron nocturno (ClientLogRepo::purgeOlderThan).
--
-- `client_ts` es hora LOCAL del equipo (su reloj puede estar desfasado — hay equipos
-- con 4h y 7h de offset). `created_at` es UTC del servidor: usar ESE para ordenar.

CREATE TABLE IF NOT EXISTS `keeper_client_log` (
  `id`             bigint       NOT NULL AUTO_INCREMENT,
  `user_id`        bigint       DEFAULT NULL,
  `device_id`      bigint       DEFAULT NULL,
  `level`          enum('info','warn','error') NOT NULL,
  `source`         varchar(64)  NOT NULL,
  `message`        varchar(512) NOT NULL,
  `meta_json`      json         DEFAULT NULL,
  `client_version` varchar(20)  DEFAULT NULL,
  `client_ts`      datetime     DEFAULT NULL,
  `created_at`     timestamp    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_kcl_created`        (`created_at`),
  KEY `ix_kcl_device_created` (`device_id`, `created_at`),
  KEY `ix_kcl_user_created`   (`user_id`, `created_at`),
  KEY `ix_kcl_level_created`  (`level`, `created_at`),
  KEY `ix_kcl_source_created` (`source`, `created_at`),
  CONSTRAINT `fk_kcl_user`   FOREIGN KEY (`user_id`)   REFERENCES `keeper_users` (`id`)   ON DELETE SET NULL,
  CONSTRAINT `fk_kcl_device` FOREIGN KEY (`device_id`) REFERENCES `keeper_devices` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
