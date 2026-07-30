# Keeper 4 — Esquema de datos · Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Construir el esquema de base de datos de Keeper 4 en el entorno de desarrollo, corrigiendo por diseño los defectos estructurales del esquema actual y dejando lista la base sobre la que se construye la vista de procesos.

**Architecture:** Base de datos nueva desde cero en el entorno DEV existente. Se conservan los nombres `keeper_*` donde el modelo no cambia. Tres correcciones estructurales gobiernan el diseño: identidad interna separada del identificador externo (multi-tenant), detalle de episodios separado del agregado diario (rendimiento), y banderas de cobertura obligatorias (para que "no medido" no pueda parecer "medido con buen resultado").

**Tech Stack:** MySQL 8.0.40 en hosting compartido cPanel, sin variables tuneables. Acceso por SSH (`ssh keeper-hosting`) al entorno `devkeep.azclegal.com`.

**Spec:** `docs/superpowers/specs/2026-07-29-keeper4-datos-y-vista-procesos.md`

## Global Constraints

- **Entorno: `devkeep.azclegal.com` + base `pipezafra_keepdev`.** Es el entorno de **desarrollo** de Keeper 4. La base de producción de Keeper 4 se decidirá al desplegar; este plan no la toca.
- **Producción (`keep.azclegal.com` / `pipezafra_keep`) NO se toca en ningún paso de este plan.** Keeper 3 sigue vivo ahí.
- MySQL 8.0.40. `innodb_buffer_pool_size` y demás variables **no son tuneables**.
- **Charset y collation: `utf8mb4` / `utf8mb4_general_ci` en todas las tablas.** Es lo que usan 27 de las 31 tablas actuales; mezclar collations produce `Illegal mix of collations` en los JOIN por texto.
- **Límite de procesos del hosting:** comandos SSH simples, uno por conexión. Los comandos compuestos con heredoc producen `bash: fork: Resource temporarily unavailable`.
- **El PHP CLI del hosting es 5.3.29** aunque la web sirva PHP 8. No correr scripts PHP modernos allí.
- Rama de trabajo: `feature/modulo-seguridad`. Un commit por tarea.
- Las migraciones viven en `AZCKeeper_Client/Web/migrations/keeper4/` — carpeta nueva, para no mezclarlas con las del esquema 3.

## Patrón de acceso a la base (usar en todas las tareas)

```bash
SSH_ASKPASS=~/.ssh/askpass.sh SSH_ASKPASS_REQUIRE=force DISPLAY=:0 ssh keeper-hosting \
  'cd ~/www/devkeep.azclegal.com && MYSQL_PWD=$(grep -E "^DB_PASS=" .env|head -1|cut -d= -f2-) \
   mysql -h $(grep -E "^DB_HOST=" .env|head -1|cut -d= -f2-) -u pipezafra_keepdev pipezafra_keepdev \
   -e "<SQL>"' < /dev/null
```

Para aplicar un archivo: subirlo con `scp` y redirigirlo con `< archivo.sql` en lugar de `-e`.

---

## File Structure

| Archivo | Responsabilidad | Tarea |
|---|---|---|
| `migrations/keeper4/00_drop_legacy.sql` | Vaciar el esquema 3 del entorno DEV | 1 |
| `migrations/keeper4/01_identidad.sql` | Personas, equipos, organización, multi-tenant | 2 |
| `migrations/keeper4/02_actividad.sql` | Episodios, rollup diario, resumen con cobertura | 3 |
| `migrations/keeper4/03_operacion.sql` | Políticas, estado de módulos, seguridad | 4 |
| `migrations/keeper4/04_panel.sql` | Cuentas admin, roles, ajustes, auditoría con actor | 5 |
| `migrations/keeper4/05_seed.sql` | Datos mínimos de arranque | 6 |
| `migrations/keeper4/README.md` | Orden de aplicación y qué cambia respecto al 3 | 6 |

---

## Task 1: Preparar el entorno

**Files:**
- Create: `AZCKeeper_Client/Web/migrations/keeper4/00_drop_legacy.sql`

**Interfaces:**
- Produces: base `pipezafra_keepdev` vacía, lista para el esquema nuevo.

- [ ] **Step 1: Inventariar lo que hay antes de borrar**

```bash
SSH_ASKPASS=~/.ssh/askpass.sh SSH_ASKPASS_REQUIRE=force DISPLAY=:0 ssh keeper-hosting \
  'cd ~/www/devkeep.azclegal.com && MYSQL_PWD=$(grep -E "^DB_PASS=" .env|head -1|cut -d= -f2-) \
   mysql -N -h $(grep -E "^DB_HOST=" .env|head -1|cut -d= -f2-) -u pipezafra_keepdev pipezafra_keepdev \
   -e "SELECT table_name FROM information_schema.tables WHERE table_schema=DATABASE() ORDER BY table_name;"' < /dev/null
```

Anotar el listado en el reporte. Son 32 tablas del esquema de Keeper 3 más `keeper_security_state`.

- [ ] **Step 2: Escribir el script de limpieza**

Crear `AZCKeeper_Client/Web/migrations/keeper4/00_drop_legacy.sql`:

```sql
-- Keeper 4 — vaciado del entorno DEV antes de crear el esquema nuevo.
--
-- SOLO PARA DEV (pipezafra_keepdev). NUNCA ejecutar contra pipezafra_keep.
-- Keeper 4 arranca con datos desde cero; Keeper 3 queda intacto en su propia base
-- como historial de solo lectura.

SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS
  keeper_activity_day,
  keeper_admin_accounts,
  keeper_admin_sessions,
  keeper_app_classifications,
  keeper_areas,
  keeper_audit_log,
  keeper_cargos,
  keeper_client_log,
  keeper_client_releases,
  keeper_daily_metrics,
  keeper_data_sources,
  keeper_device_locks,
  keeper_devices,
  keeper_dual_job_alerts,
  keeper_events,
  keeper_firmas,
  keeper_focus_daily,
  keeper_handshake_log,
  keeper_install_coverage_notes,
  keeper_module_catalog,
  keeper_panel_roles,
  keeper_panel_settings,
  keeper_policy_assignments,
  keeper_security_state,
  keeper_sedes,
  keeper_sessions,
  keeper_sociedades,
  keeper_suspicious_apps,
  keeper_user_assignments,
  keeper_users,
  keeper_window_episode,
  keeper_work_schedules;

SET FOREIGN_KEY_CHECKS = 1;
```

- [ ] **Step 3: Aplicar y verificar que la base quedó vacía**

Subir el archivo y aplicarlo. Después:

```bash
SSH_ASKPASS=~/.ssh/askpass.sh SSH_ASKPASS_REQUIRE=force DISPLAY=:0 ssh keeper-hosting \
  'cd ~/www/devkeep.azclegal.com && MYSQL_PWD=$(grep -E "^DB_PASS=" .env|head -1|cut -d= -f2-) \
   mysql -N -h $(grep -E "^DB_HOST=" .env|head -1|cut -d= -f2-) -u pipezafra_keepdev pipezafra_keepdev \
   -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE();"' < /dev/null
```

Esperado: `0`

- [ ] **Step 4: Commit**

```bash
git add AZCKeeper_Client/Web/migrations/keeper4/00_drop_legacy.sql
git commit -m "feat(k4): script de vaciado del entorno DEV para el esquema nuevo

Keeper 4 arranca con datos desde cero. Solo aplica a pipezafra_keepdev;
produccion (pipezafra_keep) queda intacta como historial."
```

---

## Task 2: Identidad, organización y multi-tenant

**Files:**
- Create: `AZCKeeper_Client/Web/migrations/keeper4/01_identidad.sql`

**Interfaces:**
- Produces: `keeper_users`, `keeper_devices`, `keeper_firmas`, `keeper_areas`, `keeper_cargos`, `keeper_sedes`, `keeper_sociedades`, `keeper_user_assignments`, `keeper_source`, `keeper_external_ref`, `keeper_sessions`.
- La Tarea 3 referencia `keeper_users.id` y `keeper_devices.id` con FK.

- [ ] **Step 1: Escribir el esquema de identidad**

Crear `AZCKeeper_Client/Web/migrations/keeper4/01_identidad.sql`:

```sql
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
  KEY ix_firmas_activa (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE keeper_sociedades (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  nombre       VARCHAR(190) NOT NULL,
  is_active    TINYINT(1) NOT NULL DEFAULT 1,
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE keeper_areas (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  nombre       VARCHAR(190) NOT NULL,
  is_active    TINYINT(1) NOT NULL DEFAULT 1,
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE keeper_cargos (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  nombre       VARCHAR(190) NOT NULL,
  is_active    TINYINT(1) NOT NULL DEFAULT 1,
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE keeper_sedes (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  nombre       VARCHAR(190) NOT NULL,
  is_active    TINYINT(1) NOT NULL DEFAULT 1,
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id)
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
  KEY ix_assign_scope (firma_id, sede_id, area_id, cargo_id),
  CONSTRAINT fk_assign_user FOREIGN KEY (user_id) REFERENCES keeper_users (id) ON DELETE CASCADE
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
  CONSTRAINT fk_session_user FOREIGN KEY (user_id) REFERENCES keeper_users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
```

- [ ] **Step 2: Aplicar y verificar**

Subir y aplicar. Verificar que se crearon las 11 tablas y que `keeper_external_ref` tiene el UNIQUE:

```bash
... -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE(); SHOW INDEX FROM keeper_external_ref WHERE Key_name='uq_extref';"
```

Esperado: `11` tablas, y el índice `uq_extref` presente con tres columnas.

- [ ] **Step 3: Verificar que las FK funcionan**

```bash
... -e "INSERT INTO keeper_users (display_name) VALUES ('prueba'); INSERT INTO keeper_devices (user_id, device_guid) VALUES (LAST_INSERT_ID(), '11111111-1111-4111-8111-111111111111'); SELECT COUNT(*) FROM keeper_devices; DELETE FROM keeper_users WHERE display_name='prueba'; SELECT COUNT(*) AS debe_ser_cero FROM keeper_devices;"
```

Esperado: `1` y luego `0` — el `ON DELETE CASCADE` funciona.

- [ ] **Step 4: Commit**

```bash
git add AZCKeeper_Client/Web/migrations/keeper4/01_identidad.sql
git commit -m "feat(k4): esquema de identidad, organizacion y multi-tenant

keeper_external_ref separa identidad interna de identificador externo. En el
esquema 3 el id legacy se escribia directo en las asignaciones y se leia como
id de Keeper: funcionaba solo porque el seed preservo los ids, y con dos
clientes distintos es incorrecto desde el primer dia.

keeper_source gana key_version para poder rotar APP_KEY sin dejar ilegible lo
ya cifrado."
```

---

## Task 3: Actividad, episodios y cobertura

Es el núcleo del plan: sostiene la vista de procesos.

**Files:**
- Create: `AZCKeeper_Client/Web/migrations/keeper4/02_actividad.sql`

**Interfaces:**
- Consumes: `keeper_users.id`, `keeper_devices.id` de la Tarea 2.
- Produces: `keeper_episode` (particionada), `keeper_episode_daily`, `keeper_day_summary`, `keeper_focus_daily`.

- [ ] **Step 1: Escribir el esquema de actividad**

Crear `AZCKeeper_Client/Web/migrations/keeper4/02_actividad.sql`:

```sql
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
```

- [ ] **Step 2: Aplicar y verificar la partición**

```bash
... -e "SELECT partition_name, partition_description FROM information_schema.partitions WHERE table_schema=DATABASE() AND table_name='keeper_episode' ORDER BY partition_ordinal_position;"
```

Esperado: 7 particiones, de `p2026_08` a `pmax`.

- [ ] **Step 3: Verificar que los índices sirven a las consultas de la vista**

Insertar una fila de prueba y comprobar el plan de las dos consultas que sostienen la vista:

```bash
... -e "INSERT INTO keeper_users (display_name) VALUES ('qa'); SET @u = LAST_INSERT_ID(); INSERT INTO keeper_devices (user_id, device_guid) VALUES (@u, '22222222-2222-4222-8222-222222222222'); SET @d = LAST_INSERT_ID(); INSERT INTO keeper_episode (user_id, device_id, day_date, start_at, end_at, duration_seconds, process_name) VALUES (@u, @d, '2026-08-15', '2026-08-15 09:00:00', '2026-08-15 09:05:00', 300, 'chrome.exe'); EXPLAIN SELECT * FROM keeper_episode WHERE user_id=@u AND day_date BETWEEN '2026-08-01' AND '2026-08-31' ORDER BY start_at\G"
```

Esperado: `key: ix_ep_user_day_start` y **sin** `Using filesort` — el índice ya entrega el orden.

Luego el agregado:

```bash
... -e "EXPLAIN SELECT process_name, SUM(duration_seconds) FROM keeper_episode WHERE day_date BETWEEN '2026-08-01' AND '2026-08-31' GROUP BY process_name\G"
```

Esperado: `key: ix_ep_day_proc` y `Extra` con `Using index` — es covering, la suma se resuelve sin tocar la tabla.

- [ ] **Step 4: Limpiar los datos de prueba**

```bash
... -e "DELETE FROM keeper_users WHERE display_name='qa'; SELECT COUNT(*) AS episodios_restantes FROM keeper_episode;"
```

Esperado: `0` en episodios — ojo, `keeper_episode` no tiene FK por la partición, así que hay que borrarlos explícitamente si quedaron. Verificar y borrar a mano si es necesario.

- [ ] **Step 5: Commit**

```bash
git add AZCKeeper_Client/Web/migrations/keeper4/02_actividad.sql
git commit -m "feat(k4): esquema de actividad con cobertura explicita

Detalle particionado por mes separado del agregado diario. ix_ep_user_day_start
sirve la vista de procesos sin filesort; ix_ep_day_proc es covering para los
agregados que en el esquema 3 hacian escaneo completo de 6.9M filas.

Sin GREATEST en el resumen diario: era un trinquete que nunca bajaba y volvio
imborrable el doble seed del cliente. La idempotencia va por clave unica.

Banderas activity/window/call_tracked obligatorias, y focus_score NULLABLE:
sin cobertura debe ser NULL, nunca un numero. En el esquema 3 un equipo con
WindowTracking apagado daba focus 55 y 90% de productividad."
```

---

## Task 4: Operación — políticas, módulos y seguridad

**Files:**
- Create: `AZCKeeper_Client/Web/migrations/keeper4/03_operacion.sql`

**Interfaces:**
- Consumes: `keeper_users.id`, `keeper_devices.id`.
- Produces: `keeper_policy_assignments`, `keeper_device_module_state`, `keeper_security_state`, `keeper_work_schedules`, `keeper_client_releases`, `keeper_client_log`.

- [ ] **Step 1: Escribir el esquema de operación**

Crear `AZCKeeper_Client/Web/migrations/keeper4/03_operacion.sql`:

```sql
-- Keeper 4 — operacion: politicas, estado real de modulos, seguridad.

CREATE TABLE keeper_policy_assignments (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  scope       ENUM('global','user','device') NOT NULL,
  user_id     BIGINT UNSIGNED NULL,
  device_id   BIGINT UNSIGNED NULL,
  version     INT UNSIGNED NOT NULL DEFAULT 1,
  priority    INT NOT NULL DEFAULT 0,
  is_active   TINYINT(1) NOT NULL DEFAULT 1,
  policy_json JSON NOT NULL,
  updated_by  BIGINT UNSIGNED NULL COMMENT 'keeper_admin_accounts.id',
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_policy_scope (scope, is_active, priority),
  KEY ix_policy_user (user_id, is_active),
  KEY ix_policy_device (device_id, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Eco del estado REAL de los modulos en cada equipo, no de la politica recibida.
-- Es lo que permite distinguir tres estados que en el 3 eran indistinguibles:
-- apagado a proposito, encendido y reportando, encendido y sin reportar.
CREATE TABLE keeper_device_module_state (
  device_id   BIGINT UNSIGNED NOT NULL,
  module_code VARCHAR(64) NOT NULL,
  is_running  TINYINT(1) NOT NULL,
  reported_at DATETIME NOT NULL,
  detail_json JSON NULL,
  PRIMARY KEY (device_id, module_code),
  KEY ix_dms_reported (reported_at),
  CONSTRAINT fk_dms_device FOREIGN KEY (device_id) REFERENCES keeper_devices (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Estado de controles de seguridad observados en el equipo (Modulo de Seguridad).
CREATE TABLE keeper_security_state (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id       BIGINT UNSIGNED NOT NULL,
  device_id     BIGINT UNSIGNED NOT NULL,
  reported_at   DATETIME NOT NULL,
  agent_present TINYINT(1) NOT NULL DEFAULT 0,
  controls_json LONGTEXT NOT NULL,
  controls_hash CHAR(64) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_security_state_device (device_id),
  KEY ix_security_state_reported (reported_at),
  KEY ix_security_state_user (user_id),
  CONSTRAINT fk_secstate_device FOREIGN KEY (device_id) REFERENCES keeper_devices (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE keeper_work_schedules (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id           BIGINT UNSIGNED NULL COMMENT 'NULL = horario global',
  work_start_time   TIME NOT NULL DEFAULT '07:00:00',
  work_end_time     TIME NOT NULL DEFAULT '19:00:00',
  lunch_start_time  TIME NOT NULL DEFAULT '12:00:00',
  lunch_end_time    TIME NOT NULL DEFAULT '13:00:00',
  applicable_days   VARCHAR(20) NOT NULL DEFAULT '1,2,3,4,5',
  timezone          VARCHAR(64) NOT NULL DEFAULT 'America/Bogota',
  is_active         TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  KEY ix_ws_user (user_id, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE keeper_client_releases (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  version      VARCHAR(32) NOT NULL,
  download_url VARCHAR(512) NOT NULL,
  size_bytes   BIGINT UNSIGNED NULL,
  is_active    TINYINT(1) NOT NULL DEFAULT 0,
  is_beta      TINYINT(1) NOT NULL DEFAULT 0,
  notes        TEXT NULL,
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_release_version (version),
  KEY ix_release_active (is_active, is_beta)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE keeper_client_log (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id     BIGINT UNSIGNED NULL,
  device_id   BIGINT UNSIGNED NULL,
  level       ENUM('debug','info','warn','error') NOT NULL DEFAULT 'info',
  source      VARCHAR(64) NOT NULL DEFAULT 'other',
  message     VARCHAR(1024) NOT NULL,
  meta_json   JSON NULL,
  logged_at   DATETIME NOT NULL,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_clog_device_time (device_id, logged_at),
  KEY ix_clog_level_time (level, logged_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
```

- [ ] **Step 2: Aplicar y verificar**

```bash
... -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE();"
```

Esperado: `21` tablas acumuladas (11 de identidad + 4 de actividad + 6 de operación).

- [ ] **Step 3: Commit**

```bash
git add AZCKeeper_Client/Web/migrations/keeper4/03_operacion.sql
git commit -m "feat(k4): esquema de operacion con eco de estado de modulos

keeper_device_module_state reporta que modulos corren DE VERDAD en cada equipo,
no la politica recibida. Es lo que permite distinguir apagado a proposito de
encendido sin reportar, que en el esquema 3 eran indistinguibles.

No se migran keeper_module_catalog, keeper_device_locks, keeper_events,
keeper_daily_metrics ni keeper_handshake_log: ningun codigo vivo las lee."
```

---

## Task 5: Panel y auditoría con actor

**Files:**
- Create: `AZCKeeper_Client/Web/migrations/keeper4/04_panel.sql`

**Interfaces:**
- Produces: `keeper_admin_accounts`, `keeper_admin_sessions`, `keeper_panel_roles`, `keeper_panel_settings`, `keeper_audit_log`.

- [ ] **Step 1: Escribir el esquema de panel y auditoría**

Crear `AZCKeeper_Client/Web/migrations/keeper4/04_panel.sql`:

```sql
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
```

- [ ] **Step 2: Aplicar y verificar el total**

```bash
... -e "SELECT COUNT(*) AS total FROM information_schema.tables WHERE table_schema=DATABASE(); SHOW COLUMNS FROM keeper_audit_log LIKE 'admin_id';"
```

Esperado: `26` tablas y la columna `admin_id` presente.

- [ ] **Step 3: Commit**

```bash
git add AZCKeeper_Client/Web/migrations/keeper4/04_panel.sql
git commit -m "feat(k4): esquema de panel y auditoria con actor

keeper_audit_log gana admin_id: en el esquema 3 la tabla tenia sujeto pero no
actor, asi que podia decir a quien se le hizo algo pero nunca quien lo hizo. Sin
esa columna la bitacora del Modulo de Seguridad no puede responder 'quien otorgo
esta excepcion'.

event_category separa acciones administrativas, importaciones y accesos a datos
sensibles: las consultas a window_title se auditan por secreto profesional."
```

---

## Task 6: Datos de arranque y documentación

**Files:**
- Create: `AZCKeeper_Client/Web/migrations/keeper4/05_seed.sql`
- Create: `AZCKeeper_Client/Web/migrations/keeper4/README.md`

**Interfaces:**
- Consumes: todo el esquema anterior.
- Produces: base utilizable con una cuenta admin y una política global activa.

- [ ] **Step 1: Escribir el seed**

Crear `AZCKeeper_Client/Web/migrations/keeper4/05_seed.sql`. **Sin política global activa el handshake devuelve HTTP 500 y el equipo deja de reportar por completo**, así que este seed no es opcional:

```sql
-- Keeper 4 — datos minimos de arranque.

-- Politica global. Sin una fila activa con scope='global' el handshake responde 500
-- y el equipo deja de reportar, no solo de recibir politicas.
INSERT INTO keeper_policy_assignments (scope, version, is_active, policy_json) VALUES
('global', 1, 1, JSON_OBJECT(
  'modules', JSON_OBJECT(
    'enableActivityTracking', TRUE,
    'enableWindowTracking',   TRUE,
    'enableCallTracking',     TRUE,
    'enableBlocking',         FALSE,
    'enableUpdateManager',    TRUE,
    'enableDebugWindow',      FALSE
  ),
  'blocking',    JSON_OBJECT('enableDeviceLock', FALSE),
  'webBlocking', JSON_OBJECT('enabled', FALSE, 'syncIntervalSeconds', 300, 'domains', JSON_ARRAY())
));

-- Horario laboral global.
INSERT INTO keeper_work_schedules (user_id, is_active) VALUES (NULL, 1);

-- Roles del panel. El catalogo de modulos vive en UN solo sitio (el codigo);
-- esta tabla guarda solo los permisos por rol.
INSERT INTO keeper_panel_roles (role_code, label, permissions_json, is_system) VALUES
('superadmin', 'Superadministrador', JSON_OBJECT('all', TRUE), 1),
('admin',      'Administrador',      JSON_OBJECT(), 1),
('viewer',     'Consulta',           JSON_OBJECT(), 1);
```

- [ ] **Step 2: Aplicar y verificar que la política global existe**

```bash
... -e "SELECT scope, version, is_active, JSON_EXTRACT(policy_json, '$.modules.enableWindowTracking') AS window_on FROM keeper_policy_assignments WHERE scope='global';"
```

Esperado: una fila, `is_active = 1`, `window_on = true`.

- [ ] **Step 3: Escribir el README de las migraciones**

Crear `AZCKeeper_Client/Web/migrations/keeper4/README.md`:

```markdown
# Migraciones de Keeper 4

Orden de aplicación:

1. `00_drop_legacy.sql` — **solo en DEV**. Vacía el esquema de Keeper 3.
2. `01_identidad.sql` — personas, equipos, organización, multi-tenant
3. `02_actividad.sql` — episodios, rollup diario, resumen con cobertura
4. `03_operacion.sql` — políticas, estado de módulos, seguridad
5. `04_panel.sql` — cuentas admin, roles, ajustes, auditoría
6. `05_seed.sql` — datos mínimos de arranque

## Qué cambia respecto al esquema 3

| Cambio | Motivo |
|---|---|
| `keeper_external_ref` | Identidad interna separada del id externo. Sin esto el multi-tenant es incorrecto desde el segundo cliente |
| `keeper_episode` particionada | Purga por `DROP PARTITION` en vez de `DELETE` masivo |
| `keeper_episode_daily` | Los agregados dejan de escanear la tabla completa |
| `keeper_day_summary` sin `GREATEST` | Era un trinquete que volvió imborrable el doble seed |
| Banderas `*_tracked` | "No medido" no puede parecer "medido con buen resultado" |
| `focus_score` NULLABLE | Sin cobertura debe ser NULL, nunca un número |
| `keeper_device_module_state` | Eco del estado real, no de la política recibida |
| `keeper_audit_log.admin_id` | La bitácora ya puede responder quién, no solo a quién |
| `keeper_source.key_version` | Permite rotar `APP_KEY` sin perder lo cifrado |
| Sin `app_name` ni `call_app_hint` | Duplicado y derivable, en la tabla más grande |

## Tablas del esquema 3 que NO se migran

`keeper_module_catalog`, `keeper_device_locks`, `keeper_events`, `keeper_daily_metrics`,
`keeper_handshake_log`. Ningún código vivo las lee ni las escribe.

## Mantenimiento de particiones

`keeper_episode` está particionada por mes hasta 2027-01 más `pmax`. Antes de que se
agote hay que añadir particiones nuevas y, según la ventana de retención acordada,
eliminar las más antiguas con `ALTER TABLE keeper_episode DROP PARTITION pAAAA_MM`.
```

- [ ] **Step 4: Verificación integral**

```bash
... -e "SELECT COUNT(*) AS tablas FROM information_schema.tables WHERE table_schema=DATABASE(); SELECT COUNT(*) AS collations_incorrectas FROM information_schema.tables WHERE table_schema=DATABASE() AND table_collation <> 'utf8mb4_general_ci';"
```

Esperado: `26` tablas y `0` collations incorrectas.

- [ ] **Step 5: Commit**

```bash
git add AZCKeeper_Client/Web/migrations/keeper4/
git commit -m "feat(k4): seed de arranque y documentacion de migraciones

Sin politica global activa el handshake responde 500 y el equipo deja de
reportar por completo, asi que el seed no es opcional."
```

---

## Criterio de salida

- [ ] `pipezafra_keepdev` tiene **26 tablas** y ninguna con collation distinta de `utf8mb4_general_ci`
- [ ] `keeper_episode` tiene 7 particiones y `EXPLAIN` de la consulta de la vista usa `ix_ep_user_day_start` **sin filesort**
- [ ] `EXPLAIN` del agregado por proceso usa `ix_ep_day_proc` con `Using index`
- [ ] `keeper_external_ref` tiene el UNIQUE `(source_id, entity_type, external_id)`
- [ ] `keeper_audit_log` tiene `admin_id` indexado
- [ ] `keeper_focus_daily.focus_score` es NULLABLE
- [ ] Existe una política global activa
- [ ] **Producción (`pipezafra_keep`) intacta:** ningún paso de este plan la tocó

Cumplido esto, el siguiente spec es el de la API de Keeper 4 (endpoints y repos sobre este esquema) y el de la vista de procesos.
