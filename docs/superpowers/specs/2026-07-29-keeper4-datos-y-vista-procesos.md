# Keeper 4 — Diseño de datos y vista de procesos

**Fecha:** 2026-07-29
**Rama:** `feature/modulo-seguridad`
**Precede a:** el plan de implementación de Keeper 4
**Depende de:** `2026-07-29-plan-maestro-saneamiento.md` (decisiones de encuadre §0)

Keeper 4 arranca con datos desde cero y Keeper 3 pasa a ser historial, así que el esquema se puede
diseñar bien en vez de parchear. Este documento define **la base de datos y el entregable de producto
prioritario** (la vista de "qué hace cada persona"), que son la misma decisión: la vista se alimenta de la
tabla más grande del sistema y no puede salir bien sobre el diseño actual.

Lo que este documento **no** cubre: el lifecycle de módulos del cliente, la consolidación del panel y el
Módulo de Seguridad. Cada uno tendrá su propio spec, apoyados en este.

---

## 1. Corte con Keeper 3

**Decisión: base de datos nueva. Keeper 3 queda congelada, intacta, en solo lectura.**

Razones, en orden de peso:

- **Rollback trivial.** Si algo sale mal, se apunta el `.env` de vuelta a la base anterior y el sistema
  vuelve a estar como estaba. Con tablas nuevas dentro de la misma base, revertir implica limpiar.
- **Cero riesgo de mezcla.** No hay forma de que una consulta olvidada lea datos del 3 y los presente como
  del 4.
- **El historial sigue disponible sin migrar nada.** Las consultas históricas se hacen contra la base
  vieja tal cual, sin export ni transformación.
- El hosting compartido ya permite crear bases desde cPanel (se hizo con `pipezafra_keepdev`), así que el
  costo operativo es un clic.

**Nombres de tabla: se conservan los actuales (`keeper_*`)** donde el diseño no cambia. Estar en una base
distinta ya desambigua, y renombrar todo obligaría a tocar código que no lo necesita. Solo cambian de
nombre las tablas cuyo modelo cambia de forma sustancial, para que un `SELECT` viejo falle en vez de
devolver algo con otra semántica.

> **Requiere confirmación de Koichi:** el nombre de la base y si el corte es por fecha (a partir de X, todo
> va al 4) o por despliegue (cada equipo empieza a escribir en el 4 cuando recibe el cliente nuevo). El
> segundo implica un período de escritura dividida y hay que decidir si eso es aceptable.

---

## 2. Identidad y multi-tenant

El sistema importa usuarios desde la base de datos del cliente (§0.5 del plan maestro). Eso obliga a
separar **identidad interna** de **identificador externo**, porque los espacios de IDs de dos clientes
distintos colisionan por definición: ambos pueden traer `area_id = 5` significando cosas diferentes.

### 2.1 Lo que se conserva

`keeper_firmas`, `keeper_areas`, `keeper_cargos`, `keeper_sedes` siguen siendo tablas separadas. Se
consideró unificarlas en una tabla genérica de unidad organizativa y **se descarta**: el modelo actual es
comprensible, el código lo asume en muchos sitios y el beneficio no compensa el churn.

### 2.2 Lo que cambia

**Tabla nueva de fuentes de datos**, sustituyendo a `keeper_data_sources` con custodia explícita:

```sql
CREATE TABLE keeper_source (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  firma_id      INT UNSIGNED NOT NULL,
  nombre        VARCHAR(190) NOT NULL,
  source_type   ENUM('manual','mysql','csv') NOT NULL DEFAULT 'manual',
  db_host       VARCHAR(190) NULL,
  db_port       SMALLINT UNSIGNED NULL,
  db_name       VARCHAR(190) NULL,
  db_user       VARCHAR(190) NULL,
  db_pass_enc   VARBINARY(512) NULL COMMENT 'AES-256-CBC; llave gestionada por CredentialVault',
  key_version   TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'para rotar APP_KEY sin perder lo cifrado',
  is_active     TINYINT(1) NOT NULL DEFAULT 1,
  last_import_at DATETIME NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_source_firma (firma_id, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
```

`key_version` es el cambio importante: hoy no hay forma de rotar `APP_KEY` sin dejar ilegible todo lo
cifrado. Con versión de llave, se puede rotar y re-cifrar de forma incremental.

**Tabla de correspondencia de identidad externa**, que es lo que hoy no existe y hace que el sistema
funcione por coincidencia:

```sql
CREATE TABLE keeper_external_ref (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  source_id     INT UNSIGNED NOT NULL,
  entity_type   ENUM('user','firma','area','cargo','sede') NOT NULL,
  external_id   VARCHAR(190) NOT NULL COMMENT 'el id tal como viene del cliente; VARCHAR para no asumir int',
  internal_id   BIGINT UNSIGNED NOT NULL,
  first_seen_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_seen_at  TIMESTAMP NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_extref (source_id, entity_type, external_id),
  KEY ix_extref_internal (entity_type, internal_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
```

Con esto, `keeper_user_assignments` guarda **siempre** identidad interna. La regla queda declarada en un
solo sitio y es verificable: ninguna columna `*_id` de asignación puede contener un identificador externo.
Eso elimina el fallo silencioso más peligroso del sistema actual — clasificar a la persona equivocada por
cargo o área, que es exactamente lo que decide quién necesita candado en el Módulo de Seguridad.

**Importación como flujo de primera clase**, con su propia bitácora: qué fuente, cuándo, cuántos registros
leídos, cuántos creados, cuántos actualizados, cuántos rechazados y por qué. Hoy `LegacySyncService` corre
en cada carga de página del panel sin dejar rastro de lo que hizo.

---

## 3. Actividad y procesos

El corazón del diseño y el sustrato de la prioridad de producto.

### 3.1 El problema a resolver

`keeper_window_episode` tiene hoy 6,9 millones de filas, crece ~66.700 por día con 256 equipos (~260.000
diarios proyectados a 1000), no tiene ningún índice que empiece por `day_date`, y no tiene retención.
`index.php` hace tres escaneos completos por carga. La vista de procesos consulta justamente por ahí.

La solución no es un índice más: es **separar el detalle del agregado**, y darle a cada uno la forma que su
consulta necesita.

### 3.2 Detalle: episodios

```sql
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
PARTITION BY RANGE COLUMNS(day_date) ( /* una partición por mes, gestionada por job */ );
```

Cambios respecto al actual y por qué:

- **`app_name` se elimina.** El endpoint actual escribe el mismo valor en `process_name` y `app_name`
  (`'app' => $processName`): es una columna duplicada en la tabla más grande del sistema.
- **`call_app_hint` se elimina.** Es `process_name` cuando `is_in_call` es verdadero; información derivable.
- **`ix_ep_user_day_start`** es el índice de la vista de procesos: filtra por persona y rango de fechas, y
  ordena por hora sin ordenación adicional.
- **`ix_ep_day_proc`** cubre los agregados globales (top apps, ocio) que hoy hacen escaneo completo. Es
  covering: incluye `duration_seconds`, así que la suma se resuelve en el índice.
- **Partición por mes** convierte la purga de retención en `DROP PARTITION` —instantáneo y sin bloqueo— en
  lugar de un `DELETE` de millones de filas sobre un hosting compartido que ya se cae por límite de
  procesos. Obliga a incluir `day_date` en la clave primaria, que es el precio de la partición en MySQL.
- **`process_name` pasa a `NOT NULL`.** Un episodio sin proceso no significa nada; hoy se permite y
  contamina los agregados.

> **Requiere confirmación de Koichi: la ventana de retención del detalle.** Con 1000 equipos son ~7,8
> millones de filas al mes. La recomendación es **6 meses de detalle** (unos 47 millones de filas, ~3 GB
> con índices) y agregado diario indefinido. Si el uso real de la vista es sobre días recientes, 3 meses
> es suficiente y deja la tabla en la mitad.

### 3.3 Agregado: rollup diario por proceso

```sql
CREATE TABLE keeper_episode_daily (
  user_id          BIGINT UNSIGNED NOT NULL,
  day_date         DATE NOT NULL,
  process_name     VARCHAR(190) NOT NULL,
  total_seconds    INT UNSIGNED NOT NULL DEFAULT 0,
  episode_count    INT UNSIGNED NOT NULL DEFAULT 0,
  call_seconds     INT UNSIGNED NOT NULL DEFAULT 0,
  first_start_at   DATETIME NULL,
  last_end_at      DATETIME NULL,
  PRIMARY KEY (user_id, day_date, process_name),
  KEY ix_epd_day_proc (day_date, process_name, total_seconds)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
```

Este rollup es el que responde todo lo agregado: top apps del período, ocio, primer ingreso, comparación
entre personas o sedes. Reduce el volumen de esas consultas de millones de filas a decenas por persona y
día. Se mantiene por el mismo job nocturno que ya existe para productividad, y **la vista de detalle nunca
lo consulta** — cada consulta va a la tabla con la forma que le corresponde.

### 3.4 Resumen diario y el fin del trinquete

`keeper_activity_day` se reemplaza por `keeper_day_summary`, con tres correcciones estructurales:

- **Sin `GREATEST`.** El UPSERT actual (`active_seconds = GREATEST(active_seconds, VALUES(...))`) es un
  trinquete monótono que nunca baja y que convirtió el doble seed en corrupción imborrable. En el diseño
  nuevo el cliente envía **el total del día**, el servidor lo **asigna**, y la idempotencia se resuelve con
  la clave única `(user_id, device_id, day_date)`. Reenviar el mismo total es inocuo; ya no hay suma.
- **Columnas de cobertura explícitas**, que es la corrección más importante de todo el documento:

```sql
  activity_tracked TINYINT(1) NOT NULL DEFAULT 0,
  window_tracked   TINYINT(1) NOT NULL DEFAULT 0,
  call_tracked     TINYINT(1) NOT NULL DEFAULT 0,
```

  Con esto, "no medido" **no puede** disfrazarse de "medido con buen resultado". Hoy un equipo con
  WindowTracking apagado produce focus 55 y productividad 90% —el mejor de la flota— porque los
  subpuntajes sin datos puntúan 100. Con la bandera, cualquier cálculo que dependa de episodios queda
  obligado a devolver "sin cobertura" en lugar de un número. La regla es verificable: **ninguna métrica se
  calcula sin su bandera de cobertura en verdadero.**
- **Unidades consistentes.** Hoy `active_seconds` es `int` y `work_hours_active_seconds` es
  `decimal(12,3)`, en la misma tabla y para lo mismo. Todo pasa a `INT UNSIGNED` de segundos.

`keeper_focus_daily` se conserva pero hereda las banderas de cobertura, y su cálculo se hace en un solo
sitio (§4.3 del plan maestro: `src/Panel/Metrics.php`) en lugar de las cuatro fórmulas actuales.

### 3.5 Eco de estado de módulos

Tabla nueva, la que resuelve "no se sabe si algo funciona":

```sql
CREATE TABLE keeper_device_module_state (
  device_id     BIGINT UNSIGNED NOT NULL,
  module_code   VARCHAR(64) NOT NULL,
  is_running    TINYINT(1) NOT NULL,
  reported_at   DATETIME NOT NULL,
  detail_json   JSON NULL,
  PRIMARY KEY (device_id, module_code),
  KEY ix_dms_reported (reported_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
```

El cliente reporta en cada handshake qué módulos tiene **realmente corriendo**, no qué política recibió.
Es el mismo patrón —ya construido y probado— de `keeper_security_state` con su latido, generalizado. Con
esta tabla el panel puede distinguir por fin tres estados que hoy son indistinguibles: *apagado a
propósito*, *encendido y reportando*, *encendido y sin reportar*.

### 3.6 Tablas que no se crean

`keeper_module_catalog`, `keeper_device_locks`, `keeper_events`, `keeper_daily_metrics` y
`keeper_handshake_log`: ninguna se lee ni se escribe desde código vivo. No se migran.

---

## 4. La vista de procesos

El entregable de producto prioritario: **qué hace cada persona**.

### 4.1 Requisitos, ya validados en uso

El visor entregado el 2026-07-10 se usó sobre 13.493 episodios reales y estableció lo que hace falta:
buscador de texto, filtros por franja horaria, proceso, usuario, equipo y llamada, estadísticas del
conjunto filtrado, ordenamiento, paginación y exportación a CSV. No hay que descubrir la funcionalidad.

Lo que cambia respecto al visor: pasa de HTML generado a página del panel, con datos en vivo, control de
acceso y auditoría.

### 4.2 Forma de la página

Una sola página, con el foco en una persona y un rango de fechas, y tres zonas:

1. **Resumen del período** — desde `keeper_episode_daily`: total por proceso, número de episodios,
   tiempo en llamada, primer y último evento. Es la respuesta a "en qué se le fue el tiempo".
2. **Línea de tiempo del día** — desde `keeper_episode`, resuelta por `ix_ep_user_day_start`: los
   episodios en orden, con duración, para ver la jornada como ocurrió.
3. **Detalle filtrable** — la tabla con buscador y filtros, paginada **en SQL**, con export del conjunto
   filtrado.

La consulta del detalle queda acotada por diseño: persona más rango de fechas siempre presentes, tope
duro al rango, y `LIMIT`/`OFFSET` reales. Es lo contrario de `user-dashboard.php:319`, que hoy trae ~7.800
filas sin límite y las embebe dos veces en la página.

### 4.3 Control de acceso a `window_title`

Este es el requisito de diseño que no es negociable, y viene del §11.1 del spec del módulo: los títulos de
ventana pueden contener nombres de casos, contrapartes y clientes de las firmas, o sea información
potencialmente amparada por secreto profesional.

Tres decisiones concretas:

- **Permiso propio.** Ver títulos completos es un permiso distinto de ver la vista. Un supervisor puede
  necesitar saber cuánto tiempo estuvo alguien en el CRM sin necesitar leer el título de cada ventana.
- **Enmascarado por rol, no ocultación de la fila.** Sin el permiso, la fila se muestra con el proceso y la
  duración, y el título aparece truncado o reemplazado. Ocultar la fila entera distorsionaría los totales.
- **Auditoría de acceso.** Cada consulta a la vista registra quién la hizo, sobre quién y con qué rango.
  No es burocracia: es lo que protege al responsable de TI, y es lo primero que pide un auditor cuando
  existe una capacidad de vigilancia.

> **Requiere confirmación de Koichi:** qué roles llevan el permiso de título completo, y si el enmascarado
> debe ser truncado (primeros N caracteres) o total.

### 4.4 Rendimiento esperado

| Consulta | Tabla | Índice | Filas leídas |
|---|---|---|---|
| Resumen del período (30 días) | `keeper_episode_daily` | PK | decenas |
| Línea de tiempo de un día | `keeper_episode` | `ix_ep_user_day_start` | ~260 |
| Detalle paginado | `keeper_episode` | `ix_ep_user_day_start` | tamaño de página |
| Top apps globales | `keeper_episode_daily` | `ix_epd_day_proc` | miles, covering |

Ninguna hace escaneo completo, y ninguna crece con el tamaño de la flota salvo la última, que crece con
personas y no con episodios.

---

## 5. Auditoría transversal

`keeper_audit_log` se rediseña con lo que hoy le falta:

- **`admin_id`** — el actor. Hoy la tabla tiene sujeto (`user_id`, `device_id`) pero no quién ejecutó la
  acción, así que no puede responder "quién otorgó esta excepción". Sin esto la bitácora del Módulo de
  Seguridad no funciona.
- **Categoría de evento indexada**, para separar acciones administrativas, importaciones y accesos a datos
  sensibles.
- **Uso obligatorio en acciones destructivas.** Hoy `AuditRepo::log()` se llama desde una sola página de
  22: no se auditan borrados de usuario, de equipo, de cuenta admin, cambios de rol ni de política.

---

## 6. Decisiones tomadas y descartadas

| Decisión | Motivo |
|---|---|
| Base de datos nueva, Keeper 3 congelado | Rollback trivial, cero mezcla, historial sin migrar |
| Conservar nombres `keeper_*` | La base distinta ya desambigua; renombrar obliga a tocar código sano |
| Cuatro tablas de organización, no una genérica | El modelo actual se entiende y el código lo asume; el churn no compensa |
| Partición por mes en episodios | Purga por `DROP PARTITION` en vez de `DELETE` masivo en hosting compartido |
| Rollup diario separado del detalle | Cada consulta a la tabla con su forma; elimina los escaneos completos |
| Eliminar `GREATEST` del resumen diario | Era un trinquete que volvió imborrable el doble seed |
| Banderas de cobertura obligatorias | "No medido" no puede parecer "medido con buen resultado" |
| `key_version` en credenciales cifradas | Hoy no se puede rotar `APP_KEY` sin perder lo cifrado |
| Eliminar `app_name` y `call_app_hint` | Duplicado y derivable, en la tabla más grande |

---

## 7. Pendiente de confirmación

1. Nombre de la base nueva, y si el corte es por fecha o por despliegue del cliente.
2. Ventana de retención del detalle de episodios (recomendado: 6 meses).
3. Qué roles pueden ver `window_title` completo, y si el enmascarado es truncado o total.
4. Cómo queda disponible el historial de Keeper 3: base congelada consultable, o export.
