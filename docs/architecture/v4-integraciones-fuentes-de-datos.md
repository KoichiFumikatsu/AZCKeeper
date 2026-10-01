# Keeper v4 — Fuentes de datos e integraciones (One, puertas, CSV, BD)

Estado: **especificación, sin implementar** · 2026-09-30 · Autor: Koichi + Claude

Este documento define cómo Keeper recibe y entrega datos de otros sistemas (AZC One, el sistema de puertas, archivos CSV, bases de datos de clientes) sin depender de ninguno. Es el contrato contra el que se construye la API de One: One puede implementarlo sin esperar a Keeper, y viceversa.

---

## 0. Decisiones (cerradas con Koichi, 2026-09-30)

1. **Independencia en tiempo de ejecución.** Keeper y One no comparten base de datos ni se llaman en el camino crítico del otro. Si uno cae, el otro sigue funcionando con su última información.
2. **Un dueño por dominio, por empresa.** Cada tipo de dato (sedes, personas, horarios…) tiene una sola fuente que manda en cada empresa. No hay "prioridad" ni mezcla de valores entre fuentes.
3. **Dirección por dominio, nunca bidireccional.** Un dominio entra a Keeper (`in`) o sale de Keeper (`out`). El mismo dominio no se escribe en ambos lados.
4. **Keeper publica un formato canónico.** Las fuentes se adaptan a él (One lo expone tal cual; un cliente con BD arma una vista con esas columnas). Keeper no contiene código específico de One ni de ningún cliente.
5. **Catálogos y eventos se tratan distinto.** Los catálogos (sedes, personas…) tienen dueño y se reconcilian. Los eventos (entradas por puerta, ausencias) tienen origen, solo se agregan y se deduplican por id.
6. **Las entradas a sede vienen del sistema de puertas**, no de One.
7. **"Permisos" son dos cosas y ambas entran:**
   - permisos de RR. HH. (vacaciones, licencias, incapacidades) → dominio `ausencias`, dueño One;
   - permisos del panel → **no se sincronizan directamente**. One envía cargo y jerarquía; Keeper los convierte en roles con **reglas propias** (§8).
8. **Un CSV hace dueño a Keeper.** Si el dato se carga por CSV o a mano, Keeper es el origen y se edita en Keeper.
9. **Una BD externa es solo de entrada y por vista.** Keeper nunca escribe en una BD ajena ni lee sus tablas internas.
10. **Persona en dos empresas (excepción):** en Keeper existe en **una sola** empresa, la principal. La elige un administrador cuando se detecta y se puede cambiar después (§5.6).
11. **Dueños del grupo (p. ej. el presidente de Grupo AZC, Bilingüe y LawyerDesk):** como persona monitoreada, una sola empresa; como observador, una **cuenta de grupo empresarial** con acceso a varias empresas, selector y tablero consolidado (§8.5). No es superadmin.
12. **Horarios distintos por día existen** (sábados, medio tiempo): Keeper extiende `schedules` para horas por día (§4.3).
13. **One reactiva el mismo registro al recontratar:** el id externo es estable de por vida.
14. **Personas previas que One no trae:** nunca se retiran solas; van a revisión (§5.3).
15. **Directivos y dueños se excluyen del monitoreo por defecto, con excepciones.** Nuevo campo de persona `monitoring` (`monitored` | `excluded`); los cargos marcados como directivos (regla en Keeper, como §8.3) dejan a la persona en `excluded`. Un equipo que intenta enrolarse a nombre de una persona excluida queda pendiente con alerta en Alta de equipos; un administrador puede hacer la excepción por persona.
16. **Frenos:** se mantienen los umbrales propuestos (5 personas / 5 % por vuelta, lista mínima del 50 %).
17. **El endpoint de entradas (`check-ins:batch`) se ofrece a cualquier empresa** con su propio sistema de acceso, con su llave de integración.

---

## 1. Principios de independencia

| Riesgo | Regla |
|---|---|
| Un cambio de esquema en One rompe Keeper | Keeper solo conoce el formato canónico (§4). One lo sirve desde su propia capa de API. |
| One cae o responde mal | La vuelta de sincronización falla, se registra y **no aplica nada**. Ninguna falla de fuente se interpreta como "todos se fueron". |
| Keeper cae | One no llama a Keeper en su flujo propio. El módulo Keeper dentro de One muestra "Keeper no disponible" (§9). |
| Una fuente envía datos absurdos | Frenos de seguridad (§5.4): bajas masivas, cédulas que cambian, listas vacías → bloqueo y aprobación manual. |
| Credenciales de terceros en Keeper | Cifradas (AES-256-GCM con la llave de Keeper, como los secretos de hardening y webhooks). Nunca se devuelven por la API. |

---

## 2. Dominios

### 2.1 Catálogo de dominios

| Dominio | Clase | Qué contiene | Entidad Keeper |
|---|---|---|---|
| `firmas` | catálogo | Firmas / sociedades cliente | `org_units` kind=firm |
| `sedes` | catálogo | Sedes físicas | `org_units` kind=site |
| `areas` | catálogo | Áreas (jerárquicas) | `org_units` kind=area |
| `cargos` | catálogo | Cargos | `org_units` kind=position |
| `horarios` | catálogo | Jornadas (zona, hora de entrada/salida, días) | `schedules`, `schedule_days` |
| `festivos` | catálogo | Días no laborables | `holidays` |
| `personas` | catálogo | Persona, cédula, correo, ubicación organizacional, horario, estado, fechas | `users`, `user_assignments` |
| `jerarquia` | catálogo | A quién reporta cada persona | `users.reports_to_user_id` (nuevo, §3) |
| `ausencias` | evento | Vacaciones, licencias, incapacidades, permisos | `absences` (nuevo) |
| `entradas` | evento | Paso por puerta de acceso | `check_ins` source=door |
| `roles_panel` | solo Keeper | Qué puede ver y hacer cada cuenta | `roles`, `user_roles`, `panel_role_rules` (nuevo) |
| `equipos`, `actividad`, `productividad`, `reglas` | solo Keeper | Lo que genera el agente | (existentes) |

`roles_panel`, `equipos`, `actividad`, `productividad` y `reglas` **nunca tienen dueño externo**. Pueden salir (`out`) pero no entrar.

### 2.2 Dueño por defecto según el tipo de fuente

| Fuente | Por defecto | Notas |
|---|---|---|
| Ninguna / manual | Keeper es dueño de todo | Estado actual |
| CSV | Keeper es dueño de lo importado | El CSV es una carga inicial o periódica; se edita en Keeper |
| API REST (One u otra) | La fuente es dueña de los dominios que expone | Configurable dominio por dominio |
| Vista SQL | Igual que REST, solo `in` | Lectura de una vista con usuario de solo lectura |
| Push (puertas) | Origen de eventos | Solo para eventos (§7) |

### 2.3 Configuración esperada para AZC

| Empresa Keeper | Fuente | Dominios de One (`in`) | Keeper es dueño de |
|---|---|---|---|
| Grupo AZC | One (`?empresa=grupo-azc`) | firmas, sedes, areas, cargos, horarios, festivos, personas, jerarquia, ausencias | roles_panel, equipos, reglas… |
| Bilingüe | One (`?empresa=bilingue`) | ídem | ídem |
| LawyerDesk | One (`?empresa=lawyerdesk`) | ídem | ídem |
| Cliente nuevo sin One | CSV o manual | — | todo |

Las **entradas** de las tres empresas de AZC vienen del sistema de puertas (§7).

---

## 3. Modelo de datos nuevo en Keeper

Todo por empresa (`tenant_id`), con las mismas convenciones de las migraciones actuales (BINARY(16), `version`, auditoría).

### 3.1 `data_sources`

Una fila por fuente configurada en una empresa.

| Columna | Tipo | Notas |
|---|---|---|
| `id` | uuid | |
| `name` | varchar(120) | "AZC One", "Puertas Palmetto/Tequendama", "CSV RR. HH." |
| `kind` | enum `rest`, `sql_view`, `csv`, `push` | |
| `config_ciphertext` | blob | URL base, filtro de empresa, credencial; cifrado. Para `sql_view`: DSN y nombre de la vista |
| `interval_minutes` | smallint | Consulta incremental (por defecto 10). `NULL` para `csv`/`push` |
| `full_reconcile_at` | time | Hora local de la vuelta completa diaria (por defecto 02:00) |
| `mode` | enum `simulate`, `live` | Toda fuente nueva arranca en `simulate` (§5.5) |
| `active` | bool | |
| `last_run_id`, `last_success_at` | | Para el panel de estado |

### 3.2 `data_domain_owners`

| Columna | Tipo | Notas |
|---|---|---|
| `domain` | enum (§2.1) | PK con tenant |
| `source_id` | uuid NULL | `NULL` = Keeper es dueño |
| `direction` | enum `in`, `out` | `out` solo para dominios de Keeper que otra fuente consume |

Restricción: un dominio con `direction=in` tiene exactamente un `source_id` no nulo. Los dominios "solo Keeper" rechazan `in`.

### 3.3 `entity_links` (vínculo con ids externos)

Generaliza `user_external_refs` a todas las entidades.

| Columna | Tipo | Notas |
|---|---|---|
| `entity_type` | enum `org_unit`, `schedule`, `holiday`, `user`, `absence`, `door` | |
| `entity_id` | uuid | Id Keeper |
| `source_id` | uuid | |
| `external_id` | varchar(160) | Id en la fuente (estable, nunca reutilizado) |
| `linked_at`, `link_method` | | `link_method`: `created` (Keeper la creó desde la fuente), `matched_document`, `matched_name`, `manual` |

Únicos: `(tenant, source, entity_type, external_id)` y `(tenant, entity_type, entity_id, source)`.

`user_external_refs` se conserva para `document` (cédula) y los vínculos de K3 (`k3`, `k3:cc`…). La cédula **no** es el id externo: sirve para el **primer** emparejamiento (§5.2).

### 3.4 `sync_runs` y `sync_run_items`

- `sync_runs`: `id`, `source_id`, `kind` (`incremental`, `full`, `manual`, `push_batch`), `mode` (`simulate`/`live`), `status` (`running`, `applied`, `blocked`, `failed`, `simulated`), `started_at`, `finished_at`, contadores por dominio (creados, cambiados, retirados, omitidos, con error), `blocked_reason`, `error_code`.
- `sync_run_items`: una fila por cambio o problema: `domain`, `external_id`, `entity_id`, `action` (`create`, `update`, `deactivate`, `skip`, `error`, `pending_approval`), `fields` (JSON con antes/después de los campos cambiados, **sin** datos sensibles completos: la cédula se enmascara salvo los 4 últimos dígitos), `message`.
- Retención: 90 días para items, 1 año para runs (configurable en la retención de la empresa).

### 3.5 Cambios a tablas existentes

- `users.reports_to_user_id` BINARY(16) NULL (FK a users de la misma empresa; sin ciclos, validado en el motor).
- `users.hired_on`, `users.terminated_on` DATE NULL.
- `org_units`: sin cambios (el vínculo va en `entity_links`).
- `doors.counts_for_presence` BOOLEAN (solo las puertas de acceso al edificio cuentan para presencia; refleja `doors_meta.access_point` del sistema de puertas).
- `user_roles.origin` enum `manual`, `rule` + `rule_id` NULL (§8.3).
- Nuevo `scope_kind` `team` en roles (§8.4).

### 3.6 `absences` (ausencias de RR. HH.)

| Columna | Tipo | Notas |
|---|---|---|
| `id`, `user_id` | uuid | |
| `kind` | enum `vacaciones`, `licencia_remunerada`, `licencia_no_remunerada`, `incapacidad`, `permiso`, `calamidad`, `otro` | |
| `starts_at`, `ends_at` | DATETIME(6) UTC | Ausencias de día completo: inicio a las 00:00 y fin a las 24:00 en la zona del horario de la persona |
| `all_day` | bool | |
| `status` | enum `approved`, `cancelled` | Solo `approved` tiene efecto; las solicitudes pendientes no se envían |
| `source_id`, `external_id` | | Idempotencia |

Efectos en Keeper:
- presencia/puntualidad: un día cubierto por una ausencia aprobada **no** cuenta como ausencia ni tardanza;
- productividad: esos días se excluyen de los promedios de la persona;
- reportes: se muestran como "Ausencia: vacaciones" en el calendario de la persona.

---

## 4. Formato canónico

JSON UTF-8. Fechas-hora en ISO 8601 UTC (`2026-09-30T13:05:00Z`); fechas en `YYYY-MM-DD`. Ids externos: cadenas opacas y estables (nunca se reutiliza un id de algo borrado).

### 4.1 Sobre de respuesta (catálogos)

```json
{
  "schema": "keeper.roster.v1",
  "company": "grupo-azc",
  "generated_at": "2026-09-30T13:00:00Z",
  "complete": true,
  "next_cursor": null,
  "items": [ ... ]
}
```

- `complete: true` solo en la lista **completa** (vuelta diaria o sin `updated_since`). **Solo con una lista completa Keeper puede retirar lo que no venga.**
- Con `updated_since`, `complete` es `false` y la ausencia de un elemento **no significa nada**. Las bajas incrementales se informan con `status: "inactive"` explícito.
- Paginación por cursor opaco. `complete` vale para el conjunto paginado completo: Keeper solo considera completa una lista si recorrió todas las páginas de la misma vuelta (mismo `generated_at`).

### 4.2 Unidad organizacional (`firmas`, `sedes`, `areas`, `cargos`)

```json
{ "external_id": "sede-17", "kind": "site", "name": "Palmetto Cali",
  "parent_external_id": null, "active": true, "updated_at": "2026-09-29T20:11:00Z" }
```

`kind` ∈ `firm`, `site`, `area`, `position`. Solo las áreas usan `parent_external_id`; si un área cuelga de una sede, el padre es la sede.

### 4.3 Horario

```json
{ "external_id": "jornada-l-s", "name": "L-V 8-5 y sábado 8-12", "timezone": "America/Bogota",
  "days": [
    { "weekday": 1, "start_local": "08:00", "end_local": "17:00", "lunch": { "start_local": "12:00", "end_local": "13:00" } },
    { "weekday": 2, "start_local": "08:00", "end_local": "17:00", "lunch": { "start_local": "12:00", "end_local": "13:00" } },
    { "weekday": 6, "start_local": "08:00", "end_local": "12:00", "lunch": null }
  ],
  "active": true, "updated_at": "..." }
```

`weekday`: 1 = lunes … 7 = domingo; los días que no aparecen no son laborables.

**Cambio en Keeper (decidido 2026-09-30: los horarios distintos por día sí existen):** hoy `schedules` tiene una sola franja (`start_local`/`end_local`) y `schedule_days` solo marca días. Se añaden `start_local`, `end_local`, `lunch_start_local` y `lunch_end_local` a `schedule_days`; las columnas de `schedules` quedan como valor por defecto para los días sin franja propia. Presencia, puntualidad y productividad pasan a leer la franja del día.

### 4.4 Festivo

```json
{ "external_id": "fest-2026-12-08", "day": "2026-12-08", "name": "Inmaculada Concepción", "active": true }
```

### 4.5 Persona

```json
{
  "external_id": "emp-1234",
  "document": "1144123456",
  "display_name": "Ana María Pérez Gómez",
  "email": "ana.perez@azclegal.com",
  "firm_external_id": "firma-3",
  "site_external_id": "sede-17",
  "area_external_id": "area-9",
  "position_external_id": "cargo-41",
  "schedule_external_id": "jornada-l-v",
  "reports_to_external_id": "emp-0042",
  "status": "active",
  "hired_on": "2024-02-01",
  "terminated_on": null,
  "updated_at": "2026-09-30T12:58:00Z"
}
```

- `document`: cédula u otro documento, solo dígitos y letras (Keeper la normaliza igual que hoy).
- `status: "inactive"` + `terminated_on` = retiro. Keeper **no borra personas**: las marca inactivas (lo que revoca sus equipos, §5.4).
- Las referencias (`*_external_id`) deben existir en la misma vuelta o en una anterior. Si no, la persona se omite con error `unknown_reference` y se reintenta en la siguiente vuelta.

### 4.6 Ausencia (evento)

```json
{ "external_id": "aus-889", "person_external_id": "emp-1234", "kind": "vacaciones",
  "starts_at": "2026-10-05T05:00:00Z", "ends_at": "2026-10-10T05:00:00Z", "all_day": true,
  "status": "approved", "updated_at": "..." }
```

Una ausencia cancelada se reenvía con `status: "cancelled"`; no desaparece en silencio.

### 4.7 Entrada por puerta (evento)

```json
{ "event_id": "palmetto-0.2.1-48213", "door_ref": "0.3.1", "document": "1144123456",
  "direction": "in", "occurred_at": "2026-09-30T12:21:07Z", "granted": true }
```

- `event_id`: único y estable en el sistema de puertas (controlador + índice de evento).
- `door_ref`: OID de la puerta en el sistema de puertas; Keeper la resuelve contra `doors.source_ref`.
- `document`: la cédula asociada a la tarjeta (`model_cards.cc`). El sistema de puertas **no** envía números de tarjeta a Keeper.
- Solo se envían eventos `granted: true` de puertas marcadas como acceso.

---

## 5. Motor de sincronización

### 5.1 Vuelta

1. **Leer** de la fuente (`rest`: GET paginado; `sql_view`: SELECT paginado sobre la vista; `csv`: archivo subido).
2. **Validar** cada elemento contra el formato canónico. Los inválidos se registran como `error` y no frenan a los demás.
3. **Resolver referencias** (§5.2).
4. **Calcular la diferencia** con lo que hay en Keeper para los dominios de los que esa fuente es dueña. **Los dominios de otro dueño se ignoran aunque vengan en la respuesta.**
5. **Aplicar los frenos** (§5.4).
6. **Aplicar** en transacciones de hasta 500 elementos, en orden de dependencia: `firmas` → `sedes` → `areas` → `cargos` → `horarios` → `festivos` → `personas` → `jerarquia` → reglas de rol (§8) → `ausencias`.
7. **Registrar** la vuelta (`sync_runs`/`sync_run_items`), auditar y recompilar las reglas de los equipos de las personas cambiadas (como hoy al editar una persona).

Concurrencia: una sola vuelta por fuente a la vez (bloqueo por `source_id`). Programación: el cron actual de Keeper (cada 10 min) lanza las vueltas incrementales vencidas y la completa diaria.

### 5.2 Emparejamiento (primer vínculo)

Para cada elemento sin `entity_links` con esa fuente:

| Entidad | Orden de búsqueda | Si hay coincidencia | Si no |
|---|---|---|---|
| Persona | 1) vínculo existente; 2) **cédula** (`document` o `k3:cc`) entre personas sin vínculo con esta fuente | Se vincula (`matched_document`) y se actualiza | Se crea |
| Unidad org. | 1) vínculo; 2) mismo `kind` y nombre normalizado (sin tildes, mayúsculas ni espacios dobles) | **Pendiente de aprobación** (`matched_name`) en la primera vuelta; luego automático | Se crea |
| Horario, festivo | 1) vínculo; 2) nombre normalizado | Igual que unidad org. | Se crea |

Una cédula que coincide con **dos** personas activas (no debería pasar, Keeper la hace única) → `error: document_ambiguous`, sin vincular.

### 5.3 Bloqueo de campos

Cuando un dominio tiene dueño externo:
- La API de administración rechaza crear o editar esas entidades con `409 managed_by_source` (detalle con el nombre de la fuente). Solo se permiten los campos de Keeper (por ejemplo, `panel_login_enabled`, roles manuales).
- El panel muestra "Gestionado por AZC One", oculta "Crear" y deshabilita los campos.
- Una persona creada en Keeper **antes** de conectar la fuente (a mano, por CSV o desde K3) y que la fuente no trae queda como **huérfana**: nunca se retira automáticamente. Aparece en la bandeja de aprobación y un administrador decide, una por una o en grupo: **vincular** con alguien de la fuente, **retirar** o **dejar** como persona local (decidido 2026-09-30).

### 5.4 Frenos de seguridad

Una vuelta pasa a `blocked` (nada se aplica) y avisa en el panel si:

| Freno | Umbral por defecto |
|---|---|
| Lista completa vacía o con menos del 50 % de las personas activas vinculadas | siempre |
| Retiros en una sola vuelta | más de `max(5, 5 % de las activas vinculadas)` |
| Cambio de cédula de una persona ya vinculada | siempre (por persona: ese elemento queda `pending_approval`, el resto se aplica) |
| Desactivación de una sede/área/cargo con personas activas | por elemento: `pending_approval` |
| Error de transporte, HTTP ≠ 200, JSON inválido, paginación incompleta | la vuelta es `failed` (no `blocked`), se reintenta en la siguiente |

Desbloquear requiere permiso `integraciones.aprobar` y queda auditado. Los umbrales se pueden cambiar por fuente.

### 5.5 Simulación

Toda fuente nueva arranca en `mode=simulate`: corre vueltas completas, calcula y muestra qué haría (altas, cambios, retiros, vínculos por cédula, vínculos por nombre a aprobar, huérfanas), pero **no escribe nada**. Pasa a `live` con un clic de quien tenga `integraciones.gestionar`, después de revisar al menos una vuelta.

### 5.6 Persona en dos empresas

One puede devolver a la misma persona (mismo `external_id` o misma cédula) en dos empresas. En Keeper, una persona y sus equipos pertenecen a **una** empresa.

- **Detección:** al sincronizar una empresa, si el `external_id` o la cédula ya está vinculado en otra empresa conectada a la misma fuente, el elemento queda `pending_approval` con motivo `multi_company`.
- **Elección:** la bandeja muestra "Esta persona está en Grupo AZC y LawyerDesk, ¿cuál es su empresa principal?". La elección se guarda en `person_primary_company` (fuente, `external_id`, empresa elegida, quién y cuándo) y **One no la pisa**: en las demás empresas esa persona se ignora desde entonces.
- **Cambio posterior:** botón "Mover a otra empresa" en la ficha de la persona (plataforma, o cuenta de grupo con `personas.mover`). Mueve a la persona con su historial (asignaciones, actividad, presencia, ausencias) a la otra empresa y re-mapea sede, área, cargo y horario a los equivalentes de la destino (si no hay equivalente vinculado, los elige quien mueve). Sus **equipos** reciben un comando de cambio de empresa y se enrolan en la destino sin reinstalar (comando nuevo del agente).
- Mientras nadie decida, la persona sigue solo en la empresa donde se creó primero.

---

## 6. Dirección `out`: lo que Keeper entrega

Los dominios de Keeper que otros consumen (productividad, presencia derivada, equipos, alertas):
- **Lectura por API externa** (`/ext/v1/...`, ya existe): la otra parte consulta cuando quiere.
- **Webhooks** (tabla `webhooks`, ya existe): avisos firmados de eventos puntuales (equipo asignado, alerta de doble empleo, resumen diario listo). El receptor debe ser idempotente; Keeper reintenta con espera creciente.

Keeper nunca escribe en la BD ni en la API de otro sistema para "empujar" un dominio `out`: publica y el otro consume.

---

## 7. Entradas por puerta

**Sentido: el sistema de puertas empuja a Keeper.** El servidor de puertas está en la LAN detrás de NAT; Keeper no puede llegar a él, pero él sí sale a HTTPS.

### 7.1 Endpoint en Keeper

`POST /ext/v1/check-ins:batch` con llave de integración con alcance `entradas.escribir`.

```json
{ "schema": "keeper.checkins.v1", "events": [ { ...§4.7... } ] }
```

- Hasta 500 eventos por llamada. Respuesta por evento: `accepted`, `duplicate` o `rejected` (con motivo).
- Idempotente por `(fuente, event_id)`: reenviar es seguro.
- Persona por `document`. Si la cédula no existe en Keeper, el evento va a **cuarentena** (`unmatched_check_ins`, 30 días) y se re-empareja cuando la persona aparezca (por ejemplo, después de la siguiente vuelta de One).
- Puerta por `door_ref`. Una puerta desconocida se registra en `doors` como inactiva y pendiente de asignar sede (se gestiona en el panel); sus eventos quedan en cuarentena hasta entonces.
- Solo las puertas con `counts_for_presence` alimentan la presencia.

### 7.2 Lado del sistema de puertas

- Un trabajo (extensión del `keeper-sync` actual) lee los eventos nuevos de `doors.db` cada minuto, filtra los `granted` de puertas de acceso y los envía en lotes.
- **Bandeja de salida** propia: si Keeper no responde, guarda la posición y reintenta. No pierde eventos ni bloquea al sistema de puertas.
- La cédula sale de `model_cards.cc`. Una tarjeta sin cédula no genera evento hacia Keeper (se cuenta y se reporta en el portal de puertas).
- Esto es **independiente** del flujo hacia `time_logs` del legacy: el sistema de puertas puede alimentar ambos.

---

## 8. Permisos

### 8.1 Permisos de RR. HH.

Dominio `ausencias` (§3.6, §4.6), dueño One. Sin efecto sobre accesos ni equipos.

### 8.2 Permisos del panel: por qué no se sincronizan

Si One escribiera roles de Keeper, un error o una intrusión en One podría dar a cualquiera acceso a toda una empresa en Keeper, incluidos los datos de actividad. Por eso **One envía hechos** (cargo, área, jerarquía) y **Keeper decide los accesos** con reglas que solo se administran en Keeper.

### 8.3 Reglas cargo → rol (`panel_role_rules`)

| Columna | Notas |
|---|---|
| `condition_kind` | `position` (tiene el cargo X), `manages` (alguien le reporta), `position_in_area` (cargo X en el área Y) |
| `condition_ids` | Cargo(s) y/o área(s) |
| `role_id` | Rol de la empresa que se asigna |
| `scope_from` | De dónde sale el alcance: `role` (el del rol tal cual), `own_area`, `own_site`, `team` (§8.4) |
| `active` | |

Comportamiento:
- Tras cada vuelta que toca `personas` o `jerarquia`, Keeper recalcula los roles derivados: añade los que cumplen la regla y **quita solo los que puso una regla** (`user_roles.origin='rule'`). Los roles manuales no se tocan.
- Topes:
  - una regla no puede asignar roles de plataforma ni permisos `platform_only`;
  - un rol usado en reglas debe tener la marca `assignable_by_rule` (se marca a propósito en Roles y permisos);
  - si una vuelta haría que más de 10 personas ganen un rol con `usuarios.editar` o `roles.gestionar`, se bloquea para aprobación.
- **Las reglas dan roles, no cuentas.** La cuenta del panel (correo + contraseña temporal) la sigue creando un administrador en Cuentas del panel; si la persona ya tiene cuenta, sus nuevos roles aplican al instante.

### 8.4 Alcance `team`

Nuevo `scope_kind`: la persona y quienes le reportan directa o indirectamente según `jerarquia`. Se resuelve en tiempo de consulta (como `area`/`site` hoy) para que un cambio de jefe aplique en la siguiente vuelta sin reescribir roles.

### 8.5 Cuentas de grupo empresarial (dueños y directivos de varias empresas)

Caso: el presidente es dueño de Grupo AZC, Bilingüe y LawyerDesk y debe ver las tres.

- **Grupo empresarial:** nueva entidad `company_groups` (nombre, empresas miembro), administrada por la plataforma.
- **Cuenta de grupo:** un tercer tipo de cuenta del panel, además de superadmin y de empresa. Un correo y una contraseña; en cada empresa del grupo tiene sus propios roles (p. ej. "Directivo: ve todo, no edita" en las tres, o editor solo en una). Los roles se asignan empresa por empresa en Cuentas del panel.
- **No es superadmin:** no ve versiones del agente, no crea empresas, no cambia la marca ni ve empresas fuera de su grupo.
- **Navegación (decidido 2026-09-30: ambas):**
  - selector de empresa arriba, limitado a las empresas de su grupo donde tiene roles;
  - **tablero "Grupo"** con los totales de las empresas sumadas (personas, equipos en línea, productividad, presencia, alertas), con desglose por empresa y enlace a cada una. Solo suma lo que sus roles le permiten ver en cada empresa.
- **Como persona monitoreada** (su propio PC), el dueño es una persona más en **una** empresa (§5.6). Puede tener cuenta de grupo sin ser persona monitoreada en ninguna.
- Seguridad: la API sigue validando empresa por empresa; el selector y el tablero son comodidad, no autorización.

---

## 9. Módulo Keeper dentro de One

One muestra información de Keeper (productividad, presencia, equipos de su gente) consultando la API externa de Keeper. Faltan dos piezas.

### 9.1 Consulta por id de One

One no guarda ids de Keeper. Los endpoints `/ext/v1/members/{id}/…` aceptan además `ref:{source}:{external_id}`, p. ej. `/ext/v1/members/ref:one:emp-1234/activity`, resuelto vía `entity_links`.

### 9.2 Quién ve qué: token por usuario

Hoy la API externa solo emite tokens **de integración** (OAuth `client_credentials`): un token ve todo lo que permiten los alcances de la integración, sin distinguir persona. Si One usara ese token, el filtro de "qué puede ver este usuario" quedaría solo en One: un error allí mostraría la productividad de toda la empresa a cualquiera.

Propuesta: **token en nombre de una persona** (OAuth 2.0 JWT Bearer, RFC 7523).
1. One firma con su llave privada una aserción corta (60 s): `iss=one`, `sub=emp-1234` (id externo), `aud=keeper-external-v1`, empresa.
2. Keeper la valida con la llave pública registrada en la integración de One, resuelve la persona por `entity_links` y emite un token de 5 minutos **con los permisos de esa persona en Keeper** (sus roles, incluidos los derivados por reglas).
3. Sin roles, la persona solo ve lo propio.

### 9.3 Aislamiento

One consulta con tiempo límite de 3 s. Si Keeper no responde, el módulo muestra "Keeper no disponible" y el resto de One sigue funcionando. Keeper no depende de One para servir estas consultas.

---

## 10. Pantallas en Keeper

**Integraciones › Fuentes de datos** (por empresa; permiso `integraciones.ver` / `.gestionar` / `.aprobar`):
- Lista de fuentes: tipo, estado de la última vuelta, última vuelta exitosa, próxima vuelta, modo (simulación o real). Botones "Sincronizar ahora" y "Simular".
- **Tabla dominio → dueño**, editable, con advertencia al cambiar un dominio de dueño ("los cambios hechos en Keeper dejarán de ser editables" / "Keeper pasará a ser dueño; la fuente dejará de actualizar esto").
- Historial de vueltas con detalle por elemento y filtros (errores, omitidos, pendientes).
- **Bandeja de aprobación:** vueltas bloqueadas, vínculos por nombre, cambios de cédula, desactivaciones con gente, huérfanas.
- Cuarentena de entradas (cédulas o puertas desconocidas).

En **Personas** y **Organización**: insignia "Gestionado por {fuente}" y campos bloqueados.

En **Roles y permisos**: sección "Asignación automática" con las reglas cargo → rol y vista previa de a quién aplicaría cada regla antes de guardarla.

---

## 11. Lo que construye One

1. `GET /api/keeper/v1/{dominio}?empresa={slug}[&updated_since=...][&cursor=...]` para `org-units`, `schedules`, `holidays`, `people`, `absences`, con el sobre de §4.1 y los elementos de §4.2-§4.6.
2. Autenticación por token Bearer por empresa (rotable). Solo lectura.
3. Ids externos estables, `updated_at` confiable (para el incremental) y `status: inactive` explícito en retiros y cancelaciones.
4. Para el módulo Keeper (§9): par de llaves para firmar aserciones; registrar la pública en Keeper.
5. Respetar §4: si One cambia sus tablas, su serializador sigue entregando `keeper.roster.v1`. Un cambio incompatible del formato es `v2` y se negocia; Keeper soporta `v1` y `v2` durante la transición.

---

## 12. Fases

| Fase | Contenido | Depende de |
|---|---|---|
| F1 | Modelo (`data_sources`, `data_domain_owners`, `entity_links`, `sync_runs`) + motor + frenos + simulación + conector CSV canónico + pantalla Fuentes de datos + bloqueo de campos | — |
| F2 | Conector REST + configuración AZC (3 empresas) | F1 + API de One (§11.1-3) |
| F3 | Entradas por puerta: endpoint batch, cuarentena, `counts_for_presence`, trabajo en el servidor de puertas | F1 (para cédulas vinculadas); puede ir en paralelo a F2 |
| F4 | Ausencias y su efecto en presencia/productividad | F2 |
| F5 | Jerarquía, alcance `team`, reglas cargo → rol | F2 |
| F5b | Grupos empresariales, cuentas de grupo, tablero consolidado (§8.5) | F1; no depende de One |
| F5c | Persona en dos empresas: detección, elección, "Mover a otra empresa" (§5.6) | F2 |
| F0 | Horarios distintos por día en Keeper (§4.3) | Ninguna; conviene antes de F2 |
| F6 | Módulo Keeper en One: consulta por `ref:`, aserciones JWT | F2, F5 |
| F7 | Conector de vista SQL | Cuando un cliente lo necesite |

La carga actual de AZC (K3 o CSV) puede hacerse antes de F2: al conectar One, el emparejamiento por cédula (§5.2) vincula a esas personas sin duplicarlas.

---

## 13. Preguntas abiertas

Todas resueltas el 2026-09-30 (decisiones 10 a 17). Se reabren con la primera simulación real contra One.

## 14. Estado de implementación

| Fase | Estado |
|---|---|
| F0 horarios por día | Hecho 2026-09-30: migración 0026, API (`day_hours`, almuerzo), calendario, política (franjas solo a agentes ≥ 4.0.13), agente 4.0.13, pantalla Horarios |
| F1 motor + fuentes | Hecho 2026-09-30: migración 0027 (`data_sources`, `data_domain_owners`, `entity_links`, `sync_runs`, `sync_items`), motor `DataSync` (REST canónico, simulación por SAVEPOINT, frenos, pendientes que recuerdan la respuesta), API `/data-sources`, `/data-domains`, `/sync-runs`, `/sync-items`, bloqueo `managed_by_source`, cron `config/sync-cron.php`, pantalla Fuentes de datos |
| F5b grupos empresariales | Hecho 2026-09-30: migración 0028 (`company_groups`, `company_group_members`, `admin_account_roles`, `admin_accounts.group_id`; se quita el CHECK de `principals` que impedía actuar en otra empresa, la validación pasa a `AdminAuth::context`), API `/company-groups`, cuentas de grupo en `/panel-accounts` (`group_id`, `group_roles`), sesión con `group.tenants`, selector de empresa y Tablero del grupo |
| F5 jerarquía, reglas y directivos | Hecho 2026-09-30: migración 0029 (`users.reports_to_user_id`, `users.monitoring_override`, alcance `team`, `roles.assignable_by_rule`, `panel_role_rules`, `user_roles.rule_id`, `monitoring_excluded_positions`, permiso `roles.reglas`), `RoleRules` (recalcula al cambiar personas, roles, reglas o tras sincronizar), jerarquía desde One, alta con alerta `person_excluded`, pantallas Roles (Asignación automática) y Personas (jefe, monitoreo, cargos excluidos) |
| F2 en adelante | Pendiente (F2 = conectar One cuando exista su API; el conector ya existe) |
