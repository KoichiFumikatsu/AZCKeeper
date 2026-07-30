# Keeper 4 — Tiers, licenciamiento y features nuevas

**Fecha:** 2026-07-30
**Rama:** `feature/modulo-seguridad`
**Extiende:** `2026-07-29-keeper4-datos-y-vista-procesos.md`
**Motivo:** Keeper pasa de herramienta interna a **servicio que se vende por tiers**. Cada tier desbloquea
módulos, y se agregan cuatro capacidades nuevas: screenshots, apagado remoto, diagnóstico de red bajo
demanda y ubicación.

---

## 1. El modelo de cuatro capas

Un módulo de Keeper está gobernado por cuatro capas independientes. Confundir cualquier par de ellas es
el error que hay que evitar en la esqueletación.

| Capa | Pregunta que responde | Dónde vive |
|---|---|---|
| **Catálogo** | ¿Este módulo existe en el producto? | `keeper_module` |
| **Tier** | ¿La firma tiene derecho a usarlo? (comercial) | `keeper_tier` + `keeper_tier_module` |
| **Política** | ¿Está encendido para este equipo/persona? (operativo) | `keeper_policy_assignments` |
| **Estado** | ¿Está corriendo de verdad en la máquina? (observado) | `keeper_device_module_state` |

**La regla de composición:** al resolver la política efectiva de un equipo, el backend la **recorta contra
el tier de la firma** a la que está asignado el agente. Un módulo que el tier no incluye se elimina de la
política efectiva antes de enviarla al cliente, aunque la política lo pida.

Cadena de resolución en el handshake:

```
device → keeper_user_assignments.firma_id → keeper_firmas.tier_id
       → keeper_tier_module (módulos permitidos)
       → ∩ con la política efectiva (global → user → device)
       → effectiveConfig que recibe el cliente
```

Por qué separadas y no una sola cosa:

- **Vender ≠ encender.** Una firma compra el tier Pro pero puede tener screenshots apagado por política en
  ciertos equipos. El tier es el derecho; la política es el uso.
- **Hacer cumplir la venta.** Si la política pudiera encender lo que el tier no incluye, no habría forma
  de garantizar que una firma solo use lo que pagó. El recorte server-side lo garantiza en un punto.
- **El cliente no decide.** El cliente recibe la política ya recortada; no conoce tiers. Un cliente
  comprometido no puede "activarse" un módulo que no tiene licencia, porque nunca le llega en la config.

---

## 2. Catálogo de módulos

`keeper_module` es la versión viva de `keeper_module_catalog` (que en el esquema 3 existía pero ningún
código leía). Una fila por cada módulo que el producto ofrece:

```sql
CREATE TABLE keeper_module (
  code         VARCHAR(64) NOT NULL,   -- 'activityTracking', 'screenshots', ...
  label        VARCHAR(190) NOT NULL,
  category     ENUM('tracking','security','control','data') NOT NULL,
  is_sensitive TINYINT(1) NOT NULL DEFAULT 0,  -- 1 = su consulta se audita (screenshots, location)
  is_active    TINYINT(1) NOT NULL DEFAULT 1,
  sort_order   SMALLINT NOT NULL DEFAULT 0,
  PRIMARY KEY (code)
);
```

`code` es clave natural, no `id` autoincremental: los módulos se referencian por un identificador estable
y legible (`'screenshots'`), y el cliente ya usa esos nombres en su config.

`is_sensitive` marca los módulos cuya **consulta** es un evento auditable por sí mismo: screenshots y
location capturan contenido protegido, así que ver esos datos deja rastro en `keeper_audit_log` con
categoría `data_access` (definida en la Tarea 5).

Módulos iniciales del catálogo:

| code | categoría | sensible |
|---|---|---|
| `activityTracking` | tracking | no |
| `windowTracking` | tracking | no |
| `callTracking` | tracking | no |
| `processView` | tracking | no |
| `webBlocking` | control | no |
| `deviceLock` | control | no |
| `security` | security | no |
| `remoteShutdown` | control | no |
| `networkDiagnostic` | control | no |
| `screenshots` | data | **sí** |
| `location` | data | **sí** |

---

## 3. Tiers

```sql
CREATE TABLE keeper_tier (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  code       VARCHAR(64) NOT NULL,
  label      VARCHAR(190) NOT NULL,
  sort_order SMALLINT NOT NULL DEFAULT 0,
  is_active  TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uq_tier_code (code)
);

CREATE TABLE keeper_tier_module (
  tier_id     INT UNSIGNED NOT NULL,
  module_code VARCHAR(64) NOT NULL,
  PRIMARY KEY (tier_id, module_code),
  CONSTRAINT fk_tiermod_tier   FOREIGN KEY (tier_id)     REFERENCES keeper_tier (id)    ON DELETE CASCADE,
  CONSTRAINT fk_tiermod_module FOREIGN KEY (module_code) REFERENCES keeper_module (code) ON DELETE CASCADE
);
```

La firma referencia su tier vigente:

```sql
ALTER TABLE keeper_firmas
  ADD COLUMN tier_id INT UNSIGNED NULL AFTER nombre,
  ADD KEY ix_firmas_tier (tier_id),
  ADD CONSTRAINT fk_firma_tier FOREIGN KEY (tier_id) REFERENCES keeper_tier (id) ON DELETE SET NULL;
```

**Decisión: el tier es feature gating puro, no asientos.** El número de agentes que una firma puede
monitorear no depende del tier (decisión de Koichi, 2026-07-30). Si más adelante se quiere cobrar por
volumen, se agrega una columna `seat_limit` al tier — es aditivo y no cambia el modelo.

**El tier vigente es un solo valor por firma.** No se modela historial de tiers en tabla propia: los
cambios de tier (upgrade/downgrade) se registran en `keeper_audit_log` con `event_type='tier_change'`.
Eso responde "cuándo cambió y quién lo cambió" sin una tabla de vigencias que casi nunca se consulta.

Tiers iniciales (ajustables — es una propuesta, no un contrato):

| Tier | Módulos incluidos |
|---|---|
| `basico` | activityTracking, windowTracking, callTracking, processView |
| `pro` | básico + webBlocking, deviceLock, security, networkDiagnostic, remoteShutdown |
| `enterprise` | pro + screenshots, location |

Los dos módulos sensibles quedan en el tier más alto, lo que alinea el gating comercial con el gating
legal: nadie los tiene por defecto.

---

## 4. Comandos: apagado remoto y diagnóstico de red

Apagado remoto y diagnóstico de red **no son telemetría**: son **órdenes que van del servidor al equipo**,
lo contrario al flujo normal de Keeper. El sistema no tiene hoy un mecanismo para eso. Se agrega una cola
de comandos, que sirve a ambos y a cualquier acción remota futura.

```sql
CREATE TABLE keeper_device_command (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  device_id    BIGINT UNSIGNED NOT NULL,
  command_type VARCHAR(64) NOT NULL,   -- 'shutdown', 'network_diag', 'screenshot_now'
  params_json  JSON NULL,
  status       ENUM('pending','sent','acked','done','failed','expired','canceled')
                 NOT NULL DEFAULT 'pending',
  created_by   BIGINT UNSIGNED NULL,   -- admin_id: el actor, para auditoria
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  sent_at      DATETIME NULL,
  completed_at DATETIME NULL,
  result_json  JSON NULL,              -- resultado del diagnostico, o error del apagado
  expires_at   DATETIME NULL,          -- un apagado sin ejecutar caduca, no se acumula
  PRIMARY KEY (id),
  KEY ix_cmd_device_status (device_id, status),
  KEY ix_cmd_created (created_at),
  CONSTRAINT fk_cmd_device FOREIGN KEY (device_id) REFERENCES keeper_devices (id) ON DELETE CASCADE
);
```

**Flujo:** el panel encola un comando (`pending`) → el cliente lo recoge en el handshake y lo marca `sent`
→ lo ejecuta → reporta resultado y pasa a `done`/`failed`. El `result_json` del `network_diag` trae ping,
DNS, ruta y velocidad; el supervisor lo ve en segundos, que es el "tiempo real bajo demanda" que se
decidió (no un stream persistente, inviable en el hosting compartido).

**`expires_at` es importante para el apagado:** una orden de apagar que el equipo no recogió porque estaba
offline **no debe ejecutarse tres días después** cuando el usuario por fin encienda. Caduca.

**El diagnóstico de red no lleva tabla propia.** Su resultado vive en `result_json` del comando. Si más
adelante se quiere tendencia histórica de red por equipo, se agrega una tabla; hoy sería sobre-ingeniería
para un caso bajo demanda.

---

## 5. Datos sensibles: screenshots y ubicación

Ambos módulos están en el tier `enterprise` y marcados `is_sensitive`. Su **consulta** se audita.

### 5.1 Screenshots

Respeta la decisión de arquitectura del 2026-06-09: **el blob va a object storage, la BD solo guarda
metadata + hash**. A 1000 equipos, las capturas son TB/año; blobs en MySQL revientan disco, backups y
replicación del hosting.

```sql
CREATE TABLE keeper_screenshot (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id      BIGINT UNSIGNED NOT NULL,
  device_id    BIGINT UNSIGNED NOT NULL,
  captured_at  DATETIME NOT NULL,      -- hora local del equipo
  received_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  object_key   VARCHAR(512) NOT NULL,  -- clave en object storage; el PNG/JPG NO va aqui
  sha256       CHAR(64) NOT NULL,      -- tamper-evidence: prueba que el blob no se altero
  size_bytes   INT UNSIGNED NULL,
  trigger_type ENUM('scheduled','event','on_demand') NOT NULL DEFAULT 'event',
  command_id   BIGINT UNSIGNED NULL,   -- si vino de un comando on_demand
  PRIMARY KEY (id),
  KEY ix_ss_user_time (user_id, captured_at),
  KEY ix_ss_device_time (device_id, captured_at),
  CONSTRAINT fk_ss_device FOREIGN KEY (device_id) REFERENCES keeper_devices (id) ON DELETE CASCADE
);
```

Retención 90 días (decisión previa). El blob se purga del object storage por su cuenta; la fila de
metadata se puede purgar con un job por `captured_at`.

> **Pendiente de infra:** dónde vive el object storage. El candidato de la nota del 2026-06-09 es el
> Nextcloud del box `cloud.azclegal.com` (2,2 TB libres). No se decide en este spec.

### 5.2 Ubicación

```sql
CREATE TABLE keeper_location (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id     BIGINT UNSIGNED NOT NULL,
  device_id   BIGINT UNSIGNED NOT NULL,
  captured_at DATETIME NOT NULL,
  latitude    DECIMAL(9,6) NOT NULL,   -- ~0.1 m de precision, suficiente y compacto
  longitude   DECIMAL(9,6) NOT NULL,
  accuracy_m  INT UNSIGNED NULL,
  source      ENUM('gps','wifi','ip') NOT NULL DEFAULT 'ip',
  PRIMARY KEY (id),
  KEY ix_loc_user_time (user_id, captured_at),
  KEY ix_loc_device_time (device_id, captured_at),
  CONSTRAINT fk_loc_device FOREIGN KEY (device_id) REFERENCES keeper_devices (id) ON DELETE CASCADE
);
```

`source` importa para la interpretación: una ubicación por IP es aproximada a nivel ciudad; por GPS es
precisa. El panel debe mostrar la fuente para no dar por exacta una estimación por IP.

---

## 6. Nota legal — sube de nivel, no de grado

`window_title` ya rozaba secreto profesional. **Screenshots graban el contenido de los casos, no el
título; la ubicación es dato de intimidad.** La gerencia jurídica ya contempló estas features (Koichi,
2026-07-30), así que no bloquean. Pero el diseño incorpora tres cosas desde el inicio, no como añadido:

1. **Consulta auditada.** Ver una captura o una ubicación escribe una fila `data_access` en
   `keeper_audit_log` con el actor: quién vio qué de quién y cuándo.
2. **Permiso propio, separado de "ver el panel".** Acceder a datos sensibles es un permiso distinto,
   igual que se decidió para `window_title` completo en la vista de procesos.
3. **Responsable del tratamiento explícito.** Como los datos son de un empleado de AZC que presta servicio
   a una firma, el contrato con cada firma debe declarar quién es responsable y quién encargado. Es
   materia de los abogados; el sistema solo debe poder demostrar qué se capturó, cuándo y quién accedió.

---

## 7. Decisiones tomadas

| Decisión | Motivo |
|---|---|
| Tier = feature gating, no asientos | Decisión de Koichi. `seat_limit` es aditivo si hace falta |
| Recorte de política contra tier en el backend | Un solo punto donde se hace cumplir la licencia; el cliente no decide |
| `keeper_module.code` como clave natural | Identificador estable y legible; el cliente ya usa esos nombres |
| Cola de comandos para apagado y diagnóstico | Son órdenes servidor→equipo, no telemetría; el sistema no lo tenía |
| Diagnóstico de red bajo demanda, sin tabla propia | "Tiempo real" viable = a pedido; el stream persistente no lo aguanta el hosting |
| `expires_at` en comandos | Un apagado no ejecutado no debe dispararse días después |
| Screenshots: blob en object storage, BD solo metadata + sha256 | A 1000 equipos son TB/año; respeta la decisión del 2026-06-09 |
| Módulos sensibles solo en el tier alto | Alinea el gating comercial con el legal: nadie los tiene por defecto |
| Historial de tier en `keeper_audit_log`, no en tabla | Responde quién y cuándo sin una tabla de vigencias poco consultada |

---

## 8. Pendiente de confirmación

1. Los tres tiers y su reparto de módulos son una propuesta. ¿Cuáles son los tiers reales y qué incluye
   cada uno?
2. Dónde vive el object storage de las capturas (candidato: Nextcloud en `cloud.azclegal.com`).
3. Frecuencia y disparador de screenshots y de ubicación (programado, por evento, bajo demanda), que
   define carga y también el encuadre legal.
