# Keeper v4 — Puente de solo lectura desde AZC One legacy

Estado: **diseño, sin implementar** · 2026-10-06 · Autor: Koichi + Claude

> **2026-10-20 (decisión de Koichi):** Keeper se alimenta en continuo desde One v1 hasta One v2. Se implementó el modo vivo de `tools/one-legacy-snapshot/serve.php` (README §3), sin vistas `kb_*`. Corre en el hosting de One (opción (a) de §4) y usa el usuario de BD de One, que tiene todos los permisos. Ambas cosas van contra la recomendación de §4 y §5.1. Lo contienen la sesión `READ ONLY`, la guarda de solo-SELECT y las columnas explícitas, pero no cubren el riesgo de §4(a): quien comprometa One puede reescribir el puente.

El puente es un servicio pequeño y **separado del código de One**. Lee la BD del One legacy (`pipezafra_soporte_db`) y sirve el formato canónico `keeper.roster.v1` para que el conector REST que ya tiene Keeper (`DataSync::restFetch`) sincronice Grupo AZC. Cuando exista el One nuevo, Keeper solo cambia la URL base. Para eso el One nuevo debe servir **los mismos endpoints y los mismos `external_id`** (§2.0).

Etiquetas de confianza: **[Seguro]** = verificado en el código, el esquema o el volcado del 2026-09-22; **[Probable]** = inferencia fuerte; **[Suposición]** = falta un dato.

> **Lo primero:** con el código actual de Keeper, la sincronización **no puede crear a la mayoría de las personas de One**. `DataSync::people()` exige que las cinco referencias (firma, sede, área, cargo y horario) resuelvan, porque usa `in_array(null, $refs, true)`. En One, **164 de 213 activos no tienen área** y 59 no tienen firma [Seguro]. Antes de conectar hay que hacer el cambio K2 (§6).

---

## 0. Hechos de partida (volcado 2026-09-22, solo conteos)

| Dato | Valor | Confianza |
|---|---|---|
| Personas en `employee` | 301: 213 activas, 87 `retirado` y 1 `candidato` | [Seguro] |
| Activas por `employee.company` | código 2 = 165, código 1 = 47, código 5 = 1. Los códigos 3 y 4 no tienen a nadie | [Seguro] |
| Nombres de los códigos de compañía | Fijos en el PHP de One: 1 AZC, 2 BilingueLaw, 3 LawyerDesk, 4 AZC Legal, 5 Matiz LegalTech. **No hay tabla** | [Seguro] |
| Retiro | Lo marca `employee.role IN ('retirado','candidato')`. `exit_status` vale 0 para todos y `final_status` es siempre NULL | [Seguro] |
| Sedes | 4: Tequendama Cali, Palmetto Cali, 808 Medellín y Granada. Granada tiene 6 activos, todos con país Honduras. Todos los activos tienen sede | [Seguro] |
| Áreas | 14, con jerarquía de 2 niveles (`padre_id`). **164 de 213 activos sin área** | [Seguro] |
| Cargos | 41. Todos los activos tienen `position_id` | [Seguro] |
| Firmas cliente (`firm`) | 43 filas. 39 referenciadas por empleados. **59 activos sin firma** | [Seguro] |
| Jefe directo | `employee.supervisor_id`: 50 activos lo tienen y solo hay 3 jefes distintos. La tabla `supervisores` tiene 2 filas | [Seguro] |
| Horarios | `employee_schedule` es por persona y por día, sin catálogo. 165 activos tienen filas y 48 no. Hay 13 patrones distintos; el más común es L-J 08:00-17:30 y V 08:00-17:00 (119 personas). **Sin almuerzo** | [Seguro] |
| Horario por defecto de One | Sin filas: L-V 08:00-16:30 (`admin/registros.php`, constantes `HORA_ENTRADA` y `HORA_SALIDA`) | [Seguro] |
| Exonerados de horario | 7 activos con `exonerado_horario=1` | [Seguro] |
| Ausencias | `permisos` tiene **1 fila** en total. No hay vacaciones ni incapacidades | [Seguro] |
| Festivos | **No existen** en One | [Seguro] |
| Documento | `employee.CC` es `varchar(20)` en el volcado, **no `int`** como dice `_meta/esquema.sql`, y es único. Activos: 198 de 10 dígitos, 6 de 13 (DNI de Honduras) y el resto de 7 u 8. Todos alfanuméricos, sin duplicados | [Seguro] |
| Correo corporativo | `employee.mail`: lo tienen todos los activos, sin duplicados ni formatos inválidos. Dominios: bilinguelaw.com y azc.com.co | [Seguro] |
| Fechas de ingreso y retiro | **No existen.** `created_at` es la fecha de alta del registro, no la de contratación | [Seguro] |

El esquema de referencia de este documento es el del **volcado 2026-09-22**. `_meta/esquema.sql` está desactualizado: tiene `CC int`.

---

## 1. Endpoints que sirve el puente (derivados de `DataSync.php`; el código manda sobre el doc)

### 1.1 Lo que hace el conector [Seguro, `DataSync::restFetch` y `pages`]

- **URL:** `rtrim(base_url,'/') . '/' . endpoint . '?' . query`. Endpoints fijos: `org-units`, `schedules`, `holidays`, `people` y `absences`. Keeper solo llama a los que cubren un dominio del que la fuente es dueña (`ENDPOINTS`):
  - `org-units` → firmas, sedes, areas, cargos (+ `empresas` tras K1);
  - `schedules` → horarios;
  - `holidays` → festivos;
  - `people` → personas, jerarquia;
  - `absences` → ausencias.
- **Query:** `empresa` (= `config.company`), `updated_since` y `cursor`. Los vacíos se omiten.
  - `updated_since` es el `generated_at` de la última vuelta **aplicada**, con formato `YYYY-MM-DDTHH:MM:SSZ`. **Se trunca a segundos**, así que el filtro del puente debe ser inclusivo (`>=`).
- **Cabeceras:** `Authorization: Bearer <token>`, `Accept: application/json` y `User-Agent: AZCKeeper-sync/1`.
- **Transporte:** solo HTTPS, **sin seguir redirecciones**, 10 s para conectar, 30 s en total, respuesta de 50 MB como máximo y JSON con profundidad de 32 como máximo.
- **Errores:** cualquier código distinto de 200 hace que la vuelta quede en `failed` y no se aplica nada. Por eso **el puente nunca responde 200 con una lista vacía ante un error**; en ese caso responde 5xx.
- **Sobre de respuesta:** `schema` debe ser `"keeper.roster.v1"`, `items` un array y `generated_at` un string ISO.
  - En una vuelta **full** (sin `updated_since`), cada página debe traer `complete: true` y **el mismo `generated_at`**. Si no, la vuelta falla con `incomplete_list` o `inconsistent_pages`.
  - `next_cursor` es `null` o un string de 1024 caracteres como máximo. El límite es de 500 páginas.
- **Vueltas:** el cron lanza la incremental cada `interval_minutes` (10 por defecto) y la full diaria a `full_reconcile_local` (02:00 por defecto). Solo corre si el tenant tiene el feature `one` (`DataSync::due`).

### 1.2 Contrato del puente

Base URL: `https://<host-del-puente>/api/keeper/v1`. Es la misma ruta que fija §11 de `v4-integraciones-fuentes-de-datos.md`, para que el One nuevo la herede.

| Método y ruta | Devuelve | Notas |
|---|---|---|
| `GET /org-units?empresa=grupo-azc[&updated_since][&cursor]` | Items `kind` = `company`, `firm`, `site`, `area`, `position` | Son unos 110 items. Siempre devuelve el catálogo entero, también en incremental, porque `sedes` y `cargos` no tienen `updated_at`. En incremental, `complete:false` |
| `GET /schedules?...` | Horarios sintetizados (§2.5) | Unos 13. Siempre el catálogo entero |
| `GET /holidays?...` | **404 `not_served`** | One no tiene festivos. Si alguien asigna `festivos` al puente por error, la vuelta falla de forma visible en vez de borrar nada |
| `GET /people?...` | Personas (§2.6) | En incremental filtra por `updated_at >= updated_since` e incluye retiros con `status:"inactive"` |
| `GET /absences?...` | Ausencias (§2.8) | Ventana: `fecha_fin` en los últimos 400 días o en el futuro. En incremental filtra por `actualizado_en` |
| `GET /health` | `{ok, db, generated_at, counts}` sin datos personales | Para monitoreo. Pide el mismo token |

- `empresa`: **un solo slug**, `grupo-azc`, que cubre los códigos de compañía 1 a 5. Desde la migración 0035, Grupo AZC es un solo tenant con empresas internas. La tabla §2.3 del doc de integraciones, con tres tenants, queda superada. Sin `empresa` responde 400; con un slug desconocido, 404.
- `generated_at`: es `UTC_TIMESTAMP()` **de MySQL**, tomado al empezar la primera página antes de leer. Se fija en el cursor para que todas las páginas lo repitan. Al tomarlo antes de leer, un cambio hecho durante la lectura entra en la vuelta siguiente; el solape es inofensivo porque Keeper es idempotente.
- `cursor`: base64url de `{"g":generated_at,"k":último_id,"s":since}` con paginación por clave (`id ASC`) y 500 items por página. Hoy todo cabe en una página, pero la paginación queda hecha.
- La sesión MySQL usa `SET time_zone = '+00:00'`, así los `TIMESTAMP` salen en UTC.
- **Freno propio del puente:** si `people` en una vuelta full trae menos de `MIN_ACTIVE` activos (configurable; 150 al inicio), responde 503 `sanity_failed`. Es una defensa además del freno de Keeper, que no actúa mientras la base de personas vinculadas sea 0.

---

## 2. Mapeo por dominio

### 2.0 `external_id`: estable de por vida (decisión 13)

| Entidad | `external_id` | Por qué |
|---|---|---|
| Empresa del grupo | `one-company-<slug>` (`azc-legal`, `bilinguelaw`, `lawyerdesk`, `matiz`, `grupo-azc`) | Va por empresa de Keeper, no por código de One: dos códigos pueden caer en la misma empresa (ver P1) |
| Firma | `one-firm-<firm.id>` | Clave primaria autoincremental; MySQL 8 no reutiliza ids |
| Sede | `one-sede-<sedes.id>` | No se usa `codigo` porque es editable |
| Área | `one-area-<areas.id>` | |
| Cargo | `one-cargo-<cargos.id>` | |
| Horario | `one-hor-<sha1(tz + patrón)[0..12]>` | One no tiene catálogo de horarios: el id es el patrón. Mismo patrón, mismo id |
| Persona | `one-emp-<employee.id>` | Al recontratar, One reactiva el mismo registro (`CC` es único). **No se usa la cédula como id** |
| Ausencia | `one-perm-<permisos.id>` | |

**Requisito para el One nuevo:** debe emitir exactamente estos ids para las entidades que migre del legacy, o los `entity_links` se rompen y Keeper ve "todos nuevos / todos ausentes". Este es el precio de "solo cambia la URL", y debe quedar escrito en el contrato del One nuevo.

### 2.1 Empresas (`empresas`, nuevo kind `company`; requiere K1)

| Fuente | Campo canónico | Regla |
|---|---|---|
| Mapa fijo del puente: código → slug y nombre | `external_id`, `name` | Los nombres son **idénticos a los de Keeper**: "AZC Legal", "BilingueLaw", "LawyerDesk", "Matiz LegalTech" y "Grupo AZC" |
| — | `active` | `true` para las 5 empresas del mapa |
| `employee.company` | `person.company_external_id` | Un código que no está en el mapa deja `null` y suma al contador `unmapped_company` en `/health` |

**Hueco:** One no tiene tabla de compañías y ningún código identifica al personal del holding Grupo AZC. Ver P1.

### 2.2 Firmas (`firmas`, kind `firm`)

| One | Canónico | Regla |
|---|---|---|
| `firm.id` | `external_id` | `one-firm-<id>` |
| `firm.name` | `name` | Recortado a 120 caracteres (Keeper rechaza más de 120) |
| — | `active` | `true`: `firm` no tiene columna de estado. Una firma borrada en One se desactiva en la vuelta full, y queda en pendiente si tiene gente |
| `firm.manager`, `firm.mail_manager` | **no se leen** | Son datos personales |

### 2.3 Sedes (`sedes`, kind `site`)

| One | Canónico | Regla |
|---|---|---|
| `sedes.id`, `sedes.nombre` | `external_id`, `name` | |
| `sedes.activa` | `active` | `COALESCE(activa,1)=1` |
| Mapa fijo del puente | `country_code`, `city` (**campos nuevos, K3**) | Tequendama Cali y Palmetto Cali → CO/Cali; 808 Medellín → CO/Medellín; Granada → HN/Tegucigalpa [Probable: los 6 de Granada tienen país Honduras y 5 tienen ciudad Tegucigalpa; confirmar en P1] |
| Mapa fijo | zona horaria (para horarios y ausencias) | CO → `America/Bogota`, HN → `America/Tegucigalpa` |

- **Normalización:** `employee.city` y `employee.country` son la residencia de la persona, en texto libre ("Medellín, Antioquia, Colombia", "cali"). **No se usan** para la sede.
- **Hueco:** One no tiene sedes remotas ("Remoto – Honduras").

### 2.4 Áreas (`areas`, kind `area`) y cargos (`cargos`, kind `position`)

| One | Canónico | Regla |
|---|---|---|
| `areas.id`, `areas.nombre` | `external_id`, `name` | |
| `areas.padre_id` | `parent_external_id` | `one-area-<padre_id>` o `null`. Keeper resuelve primero los padres |
| — | `active` | `true`: no hay estado. Lo borrado se desactiva en la vuelta full |
| `cargos.id`, `cargos.nombre` | `external_id`, `name` | Mismas reglas |

### 2.5 Horarios (`horarios`)

One guarda el horario por persona. El puente **agrupa a las personas por patrón**:

1. **Patrón** de una persona activa = los días con `activo=1`, ordenados, con forma `d=HH:MM-HH:MM`. Si `hora_entrada` u `hora_salida` es NULL en un día activo, se usa 08:00 o 16:30 respectivamente, que es lo que hace One.
2. **Sin filas** en `employee_schedule`: se usa el patrón por defecto de One, L-V 08:00-16:30.
3. **Con filas pero ningún día activo** (1 persona): en One no trabaja ningún día. No hay horario válido (Keeper rechaza `days` vacío), así que se envía `schedule_external_id: null` (requiere K2).
4. **Días inválidos** (entrada ≥ salida): se descartan y suman al contador `invalid_schedule_days`.
5. `timezone`: el de la sede de la persona. El id y el nombre dependen de la zona.
6. `name`: se genera del patrón, por ejemplo `L-J 08:00-17:30 · V 08:00-17:00 (CO)`; tiene 120 caracteres como máximo.
7. `lunch`: **hueco**, One no tiene almuerzo. Ver P4.
8. `active: true`. Keeper no desactiva horarios: un patrón que nadie usa queda sin asignar.

**Hueco:** `exonerado_horario` (7 personas) no tiene equivalente canónico. Se ignora.

### 2.6 Personas (`personas`)

Filtro: todos los registros de `employee` excepto `role='candidato'`. Un candidato no se envía nunca: si lo contratan, aparece como alta.

| One | Canónico | Regla |
|---|---|---|
| `employee.id` | `external_id` | `one-emp-<id>` |
| `CC` | `document` | Solo `[0-9A-Za-z]` y mayúsculas. Keeper además quita espacios, puntos y guiones. **No se antepone `type_CC`** (2 activos tienen tipo 3); la cédula plana coincide con la de K3 (`k3:cc`) |
| `first_Name second_Name first_LastName second_LastName` | `display_name` | `CONCAT_WS` sin vacíos, espacios colapsados, se respetan las mayúsculas originales. Máximo 160 caracteres |
| `mail` | `email` | `LOWER(TRIM())`. Si queda vacío o no es un correo válido, va `null`. **Nunca se usa `personal_mail`** |
| `company` | `company_external_id` (nuevo, K1) | Por el mapa de §2.1 |
| `id_firm` | `firm_external_id` | `null` si falta (59 activos; requiere K2) |
| `sede_id` | `site_external_id` | |
| `area_id` | `area_external_id` | `null` si falta (164 activos; requiere K2) |
| `position_id` | `position_external_id` | |
| Patrón (§2.5) | `schedule_external_id` | |
| `supervisor_id` | `reports_to_external_id` | Solo si el jefe existe, está activo y no es la misma persona. Si no, `null` |
| `role` | `status` | `empleado`, `administrador`, `nomina`, `talento_humano` o `it` → `active`. `retirado` o NULL → `inactive`, como el PHP de One, donde `role != 'retirado'` excluye NULL |
| — | `hired_on`, `terminated_on` | `null`: **hueco** en One. Keeper hoy ignora ambos |
| `GREATEST(employee.updated_at, MAX(employee_schedule.updated_at))` | `updated_at` | Así un cambio de horario dispara la vuelta incremental |

**Nunca se leen:** `password`, `pin`, `photo`, `birthdate`, `phone`, `address`, `personal_mail`, `tarjeta_acceso`, `activo_fijo`, `geo`, `dval`, `extension`, `last_status`, `id_phone`, `needs_phone` ni `locacion_id`.

### 2.7 Jerarquía (`jerarquia`)

Sale del mismo endpoint `people` (`reports_to_external_id`). Keeper procesa primero a todas las personas y después la jerarquía, así que un jefe creado en la misma vuelta ya resuelve.

**Hueco:** solo 50 activos tienen jefe. La tabla `supervisores` (coordinador, director o gerente por área) **no se usa** para inferir jefes; inventar jerarquía otorgaría alcance `team` a quien no corresponde.

### 2.8 Ausencias (`ausencias`)

| One `permisos` | Canónico | Regla |
|---|---|---|
| `id` | `external_id` | `one-perm-<id>` |
| `empleado_id` (guarda la **CC**, no el id) | `person_external_id` | `JOIN employee ON employee.CC = permisos.empleado_id` |
| `tipo_permiso` | `kind` | `remunerado` y `matrimonio` → `licencia_remunerada`; `no_remunerado` → `licencia_no_remunerada`; `por_hora` → **se excluye** (no hay hora de inicio); `trabajo_casa` → **se excluye** (no es ausencia) |
| `fecha_inicio`, `fecha_fin` | `starts_at`, `ends_at`, `all_day:true` | 00:00 local del primer día y 00:00 local del día siguiente al último, en la zona de la sede, convertidos a UTC |
| `estado_supervisor`, `estado_talento_humano` | `status` | `approved` si el supervisor aprobó y TH aprobó (o TH "no aplica" para BilingueLaw, código 2, que en BD queda `''` [Probable: `'no_aplica'` no está en el enum]). `cancelled` si alguno rechazó. Las solicitudes pendientes no se envían |

**Nunca se leen:** `motivo`, `notas_*`, `soporte_ruta` (ruta a un archivo subido), `empleado_nombre` ni `empleado_departamento`.

**Hueco grande:** One no registra vacaciones, incapacidades ni calamidades, y `permisos` tiene 1 fila. Ver P2.

### 2.9 Festivos (`festivos`)

**One no tiene festivos.** El puente no los sirve (404). Keeper sigue siendo dueño: hoy los calcula `HolidaySeeds` en calendarios por país. Además, `DataSync::holidays()` ignora `holiday_calendars`: inserta sin `calendar_id` y empareja por día sin importar el país, así que **no sirve todavía para un grupo con varios países**. Ver P3.

---

## 3. Vistas SQL (solo lectura, contra el esquema del volcado 2026-09-22)

Se crean en `pipezafra_soporte_db` con `SQL SECURITY DEFINER`; el definer es el usuario dueño de la BD de One. Son objetos nuevos que no cambian ninguna tabla ni el código de One. El puente solo consulta `kb_*`.

```sql
-- Sedes
CREATE OR REPLACE SQL SECURITY DEFINER VIEW kb_sites AS
SELECT s.id AS one_id, CONCAT('one-sede-', s.id) AS external_id, TRIM(s.nombre) AS name,
       (COALESCE(s.activa, 1) = 1) AS active
FROM sedes s;

-- Areas (jerarquicas)
CREATE OR REPLACE SQL SECURITY DEFINER VIEW kb_areas AS
SELECT a.id AS one_id, CONCAT('one-area-', a.id) AS external_id, TRIM(a.nombre) AS name,
       CASE WHEN a.padre_id IS NULL THEN NULL ELSE CONCAT('one-area-', a.padre_id) END AS parent_external_id,
       a.updated_at
FROM areas a;

-- Cargos
CREATE OR REPLACE SQL SECURITY DEFINER VIEW kb_positions AS
SELECT c.id AS one_id, CONCAT('one-cargo-', c.id) AS external_id, TRIM(c.nombre) AS name
FROM cargos c;

-- Firmas cliente (sin manager ni mail_manager)
CREATE OR REPLACE SQL SECURITY DEFINER VIEW kb_firms AS
SELECT f.id AS one_id, CONCAT('one-firm-', f.id) AS external_id, LEFT(TRIM(f.name), 120) AS name, f.updated_at
FROM firm f;

-- Codigos de compania en uso (para detectar codigos sin mapear; sin datos personales)
CREATE OR REPLACE SQL SECURITY DEFINER VIEW kb_company_codes AS
SELECT e.company AS company_code,
       SUM(e.role IN ('empleado','administrador','nomina','talento_humano','it')) AS active_people
FROM employee e GROUP BY e.company;

-- Personas (sin password, pin, foto, contacto personal, tarjeta ni fechas personales)
CREATE OR REPLACE SQL SECURITY DEFINER VIEW kb_people AS
SELECT e.id AS one_id,
       CONCAT('one-emp-', e.id) AS external_id,
       UPPER(REGEXP_REPLACE(e.CC, '[^0-9A-Za-z]', '')) AS document,
       e.type_CC AS document_type,
       TRIM(REGEXP_REPLACE(CONCAT_WS(' ', NULLIF(TRIM(e.first_Name), ''), NULLIF(TRIM(e.second_Name), ''),
                                          NULLIF(TRIM(e.first_LastName), ''), NULLIF(TRIM(e.second_LastName), '')),
                           '[[:space:]]+', ' ')) AS display_name,
       NULLIF(LOWER(TRIM(e.mail)), '') AS email,
       e.company AS company_code,
       CASE WHEN e.id_firm  IS NULL THEN NULL ELSE CONCAT('one-firm-',  e.id_firm)  END AS firm_external_id,
       CASE WHEN e.sede_id  IS NULL THEN NULL ELSE CONCAT('one-sede-',  e.sede_id)  END AS site_external_id,
       e.sede_id,
       CASE WHEN e.area_id  IS NULL THEN NULL ELSE CONCAT('one-area-',  e.area_id)  END AS area_external_id,
       CASE WHEN e.position_id IS NULL THEN NULL ELSE CONCAT('one-cargo-', e.position_id) END AS position_external_id,
       CASE WHEN b.id IS NOT NULL AND b.id <> e.id
                 AND b.role IN ('empleado','administrador','nomina','talento_humano','it')
            THEN CONCAT('one-emp-', b.id) END AS reports_to_external_id,
       CASE WHEN e.role IN ('empleado','administrador','nomina','talento_humano','it') THEN 'active' ELSE 'inactive' END AS status,
       GREATEST(COALESCE(e.updated_at, e.created_at), COALESCE(h.max_updated, e.updated_at, e.created_at)) AS updated_at
FROM employee e
LEFT JOIN employee b ON b.id = e.supervisor_id
LEFT JOIN (SELECT employee_id, MAX(updated_at) AS max_updated FROM employee_schedule GROUP BY employee_id) h
       ON h.employee_id = e.id
WHERE e.role IS NULL OR e.role <> 'candidato';

-- Patron de horario por persona activa (el puente aplica el defecto de One, la zona por sede y el hash)
CREATE OR REPLACE SQL SECURITY DEFINER VIEW kb_person_schedule AS
SELECT e.id AS one_id, e.sede_id,
       (SELECT COUNT(*) FROM employee_schedule x WHERE x.employee_id = e.id) AS rows_total,
       (SELECT GROUP_CONCAT(CONCAT(s.dia_semana, '=',
                                   TIME_FORMAT(COALESCE(s.hora_entrada, '08:00:00'), '%H:%i'), '-',
                                   TIME_FORMAT(COALESCE(s.hora_salida,  '16:30:00'), '%H:%i'))
                            ORDER BY s.dia_semana SEPARATOR ',')
          FROM employee_schedule s
         WHERE s.employee_id = e.id AND s.activo = 1 AND s.dia_semana BETWEEN 1 AND 7) AS pattern
FROM employee e
WHERE e.role IN ('empleado','administrador','nomina','talento_humano','it');

-- Ausencias de RR. HH. (sin motivo, notas ni soporte_ruta)
CREATE OR REPLACE SQL SECURITY DEFINER VIEW kb_absences AS
SELECT CONCAT('one-perm-', p.id) AS external_id,
       CONCAT('one-emp-', e.id) AS person_external_id,
       e.sede_id,
       CASE p.tipo_permiso WHEN 'no_remunerado' THEN 'licencia_no_remunerada' ELSE 'licencia_remunerada' END AS kind,
       p.fecha_inicio, p.fecha_fin,
       CASE WHEN p.estado_supervisor = 'rechazado' OR p.estado_talento_humano = 'rechazado' THEN 'cancelled'
            WHEN p.estado_supervisor = 'aprobado'
                 AND (p.estado_talento_humano = 'aprobado' OR (e.company = 2 AND COALESCE(p.estado_talento_humano, '') = ''))
            THEN 'approved' END AS status,
       COALESCE(p.actualizado_en, p.creado_en) AS updated_at
FROM permisos p
JOIN employee e ON e.CC = p.empleado_id
WHERE p.tipo_permiso IN ('no_remunerado', 'remunerado', 'matrimonio')
  AND p.fecha_fin >= CURRENT_DATE - INTERVAL 400 DAY;
```

Consultas del puente (todas con `WHERE external_id/one_id > :cursor ORDER BY one_id LIMIT 500`):

- `people` incremental: `... WHERE updated_at >= :since`.
- `absences`: `WHERE status IS NOT NULL [AND updated_at >= :since]`.

Índices: no hacen falta con unas 300 filas. Si `employee` crece mucho, el `GREATEST` impide usar índice y habrá que revisarlo.

---

## 4. Dónde corre

| Criterio | (a) En el hosting de One (server1872, subdominio aparte, BD local) | (b) En el server de Keeper (.234, 185.42.22.239) con MySQL remoto de solo lectura |
|---|---|---|
| Aislamiento ante el RCE de One | **Malo.** Las subidas de One ejecutan PHP (AUDITORIA 1.3) y `record_log.php` escribe fuera de su docroot (2.4). El atacante tendría el mismo usuario Unix que el puente: podría reescribir su código, robar el token y **servir datos falsos a Keeper** (altas con cédulas inventadas, retiros) | **Bueno.** Comprometer One da acceso a la BD, que ya es de One, pero no al código del puente ni al canal hacia Keeper |
| Exposición de credenciales | La credencial de BD viviría junto al `.env` de One, que ya estuvo expuesto (AUDITORIA 1.6) | Usuario de solo lectura, solo para la IP .234, guardado en un `.env` con permisos 600 en un usuario Hestia propio |
| CSF / LFD | Keeper consulta a server1872 por HTTPS cada 10 min. El CSF ya baneó la IP de la oficina (2026-07-08 y 2026-09-17): un baneo de .234 dejaría la sincronización en `failed`. No se pierden datos, pero hay riesgo operativo | La BD está en `mysql.server1872.mylogin.co`, un host distinto del web [Seguro]. Ya acepta conexiones remotas por IP permitida (Fumilinux tuvo acceso) [Seguro]. Unas 6 conexiones por hora |
| Operación | Panel custom, sin API; PHP CLI 5.3 por defecto y web PHP 8.0 (8.2 seleccionable). La regla es no dejar archivos sueltos en `~/www` | Ya corre Keeper con PHP 8.2 en Hestia; despliegue y logs bajo control propio |
| Latencia | La BD es local | Una consulta remota por endpoint, menos de 1 s con estos volúmenes [Probable] |
| Cifrado BD | No aplica (socket local) | **Debe ser TLS** (`REQUIRE SSL`). Si el MySQL del hosting no ofrece TLS, esta opción queda bloqueada (§5) |

**Recomendación: (b).** El riesgo que más pesa es que One está comprometible hoy. En (a), quien comprometa One controla lo que Keeper cree sobre la plantilla; en (b) solo puede leer lo que ya era suyo.

El puente va en un **usuario Hestia propio** (`onebridge`), con su vhost `onebridge.azclegal.com` (registro A directo a 185.42.22.239, nunca proxy por el hosting) y con `Require ip 185.42.22.239 127.0.0.1`: solo Keeper puede llamarlo. Keeper lo consume por HTTPS con certificado Let's Encrypt, porque el conector exige `https`.

---

## 5. Seguridad

1. **Usuario de BD:** `pipezafra_kbridge`, creado con `@'185.42.22.239'`, `REQUIRE SSL`, `MAX_USER_CONNECTIONS 2` y `MAX_QUERIES_PER_HOUR 2000`. Solo `GRANT SELECT` sobre las 8 vistas `kb_*`.
   - El panel del hosting no permite grants finos por SSH, así que **lo hace soporte de mylogin.co o el panel**. Hay que verificarlo; ver P5.
   - Plan B si no permiten grants sobre vistas: grants **por columna** (`GRANT SELECT (id, CC, first_Name, …) ON employee`), que dejan fuera `password` y `pin` igual que las vistas.
   - Sin plan B aceptable, **no se arranca** con un usuario con ALL.
2. **TLS a MySQL:** `PDO::MYSQL_ATTR_SSL_CA` con el CA del servidor y la huella del certificado fijada en la configuración. Antes de nada se prueba `SHOW SESSION STATUS LIKE 'Ssl_cipher'` desde .234. Si no hay TLS, el puente no se despliega en (b) y se reabre la decisión.
3. **Token Bearer:** 32 bytes aleatorios. El puente guarda solo su SHA-256 y compara en tiempo constante. Acepta **dos tokens a la vez** para poder rotar sin cortes. En Keeper el token queda cifrado (AES-256-GCM, `DataSync::seal`).
4. **HTTPS sin redirecciones:** `http` → 403, no 301 (el conector no sigue redirecciones). HSTS.
5. **Límites:** IP permitida en el vhost, 60 peticiones por minuto por token (contador en archivo, como `RATE_DIR` de Keeper) y `LIMIT` duro de 500 por página.
6. **Logs sin datos personales:** fecha, ruta, nombres de los parámetros (sin el valor de `updated_since`), estado HTTP, número de items, duración y los contadores `unmapped_company` e `invalid_schedule_days`. Nunca el cuerpo, la cabecera `Authorization`, cédulas, nombres ni correos. Rotación de 30 días.
7. **Nada de contraseñas ni archivos:** el puente no consulta tablas base; tampoco `files` ni `uploads/`, y no sirve archivos. No tiene endpoints de escritura: cualquier método distinto de GET → 405.
8. **Fallo cerrado:** cualquier excepción → 5xx con un código genérico. Nunca 200 con `items: []` ante un error.

---

## 6. Cambios en Keeper y primera sincronización

### 6.1 Cambios en Keeper (no se pueden evitar desde el puente)

| Id | Cambio | Por qué | Bloquea |
|---|---|---|---|
| **K1** | Dominio `empresas` (`DataSources::DOMAINS`, `DataSync::ORG_DOMAINS['company']='empresas'`, `ENDPOINTS['org-units']`) + kind canónico `company` + `person.company_external_id` → `users.company_id` y `user_assignments.company_id`, al crear y al actualizar. Si la fuente no es dueña de `empresas`, se conserva el valor local (como hoy) | `createPerson` no asigna empresa y `updatePerson` la copia del valor anterior | Sí, para separar por empresa |
| **K2** | Referencias opcionales en `people()`. Si una `*_external_id` viene `null` o no viene, se guarda NULL (o se conserva el valor local si la fuente no es dueña de ese dominio). `unknown_reference` solo aplica a un id **enviado** que no resuelve. Hoy `in_array(null, $refs, true)` lo rechaza todo, en contra de §4.5 del doc | 164 personas sin área, 59 sin firma, 1 sin horario | **Sí, crítico** |
| **K3** | `country_code` y `city` en la unidad canónica `site`, aplicados por `orgUnit()` (columnas de 0035 y 0039) | Sin país, una sede creada desde One cae en el calendario por defecto (CO) y los de Honduras tendrían festivos de Colombia | Sí, para HN |
| **K4** | Un correo que choca con `UNIQUE(tenant_id,email)` debe registrarse como item `error: email_in_use`, no abortar la vuelta | Hoy el `PDOException` no es `SyncFailure`: sale de `run()` sin registrar `sync_runs` | Sí, si una persona manual tiene el mismo correo que One y no se empareja por cédula |
| **K5** | Freno de **altas masivas**: `max_creations` por vuelta (por ejemplo, 20) o bloqueo `first_load` cuando la base vinculada es 0 y la vuelta crea más de N. Se desbloquea con la aprobación normal | Los frenos actuales solo cuentan retiros. La primera carga crearía unas 210 personas sin freno | **Hecho** (0046): `too_many_creations` con el mismo límite que los retiros (5 / 5 %; base 0 → 5), `too_many_org_changes` y `domains_changed` (dominio recién asignado). Ver §6.2 |
| K6 | (Más adelante) `festivos` por calendario o país en el canónico y en `holidays()` | Hoy ignora `holiday_calendars` | No mientras Keeper sea dueño de festivos |

Lo único configurable sin código: activar el feature `one` en el tenant Grupo AZC (lo exige `DataSync::due`).

### 6.2 Plan de la primera sincronización de Grupo AZC

Datos manuales que ya están en Keeper: 3 empresas, sedes y 3 personas (Koichi Fumikatsu, Daniel Moreno y Andres Zafra).

**Emparejamiento:**
- Las unidades organizacionales se emparejan por `kind` y nombre normalizado. El resultado queda **pendiente de aprobación** (`matched_name`).
- Las personas se emparejan por cédula (`document` o `k3:cc`) y se vinculan automáticamente.
- Una persona local que One no trae queda **huérfana**: va a revisión y nunca se retira sola.

**Pasos:**

0. **Antes** (Keeper todavía es dueño de todo):
   - Renombrar en Keeper las empresas locales con el nombre exacto que envía el puente (§2.1) y las sedes con el nombre exacto de One. Así el emparejamiento por nombre las encuentra y no se duplican.
   - Cargar la cédula de las 3 personas con el mismo valor que su `CC` en One.
   - Dejar su correo igual al `mail` de One, o vacío.
1. Desplegar K1 a K4 (y K5 si se aprueba) y el puente. Desde .234: `curl` a `/health` y a cada endpoint, y comparar conteos con §0. Deben salir unas 213 personas activas, 4 sedes, 14 áreas, 41 cargos, 43 firmas y 5 empresas.
2. Crear la fuente "AZC One" (arranca en `simulate`) con `company=grupo-azc`, frenos 5 / 5 % / 50 %, incremental cada 10 min y full a las 02:00.
3. **Etapa A, catálogos.** Asignar a la fuente solo `empresas`, `firmas`, `sedes`, `areas`, `cargos` y `horarios`. Al asignarlos, Keeper marca la fuente `approval_required` y borra su `updated_since` (siguiente vuelta completa).
   - Simular una vuelta full y revisar altas, `matched_name` y errores.
   - Pasar a `live`. La primera vuelta real (manual o del cron) queda `blocked` con `domains_changed`: revisar sus items y **aprobarla** (`POST /sync-runs/{id}/approve`, permiso de plataforma). Los pendientes de una **simulación** no se pueden resolver (`resolve` exige una vuelta `applied`); los de la vuelta aprobada sí.
   - Aprobar los `matched_name` de las empresas y sedes locales.
4. **Etapa B, personas.** Añadir `personas` y `jerarquia`. Keeper vuelve a marcar la fuente (`approval_required`) y su siguiente vuelta es completa: **el cron no puede aplicar la carga solo**, aunque la fuente siga en `live`.
   - Opcional: volver a `simulate` y correr una full. Saldrá `blocked` / `too_many_creations` con `simulate=true`: es la vista previa.
   - En cualquier caso la primera vuelta real queda `blocked` (`too_many_creations`: unas 210 altas activas + las retiradas, límite 5 con base 0; o `domains_changed`). Revisar sus items: 3 vínculos `matched_document`, unas 210 altas activas y las retiradas (`create` con motivo `inactive_in_source`), 0 retiros, huérfanas esperadas 0, `error` = 0 o explicado, y el reparto por empresa contra el de §0.
   - Si todo cuadra, **aprobar esa vuelta**: vuelve a leer el puente y aplica la carga completa. Después, las incrementales del cron siguen solas mientras no superen los frenos.
5. **Después:**
   - Comprobar la recompilación de políticas, que las reglas de cargo → rol y de directivos se apliquen, y que las personas creadas reciben el plan por defecto (`createPerson` crea una `subscription`; revisar el impacto en facturación).
   - Vigilar 48 h de incrementales y dos vueltas full.
6. **Ausencias** (`ausencias`) y **festivos**: según P2 y P3.
7. **Cómo deshacer:** desactivar la fuente y devolver los dominios a Keeper (`source_id=null`). Los datos se quedan y vuelven a ser editables. No se borra nada.

---

## 7. Preguntas abiertas para Koichi

| # | Pregunta | Recomendación |
|---|---|---|
| P1 | **Mapas fijos del puente.** El código 1 ("AZC", 47 activos), ¿es AZC Legal o el holding Grupo AZC? El código 4 ("AZC Legal") no tiene a nadie. ¿La sede "Granada" es la oficina de Tegucigalpa (HN)? | 1 y 4 → AZC Legal; 2 → BilingueLaw; 3 → LawyerDesk; 5 → Matiz LegalTech. Granada → HN/Tegucigalpa. El holding queda sin gente desde One hasta que One tenga un código para él |
| P2 | **Ausencias:** One solo tiene `permisos` (1 fila, sin vacaciones ni incapacidades). Si One es dueño de `ausencias`, Keeper no podrá registrarlas y las vacaciones contarán como faltas | No asignar `ausencias` a One hasta el One nuevo. Es la única excepción a "One única fuente" que pide la realidad de los datos. Si no se acepta, asignarlas y aceptar que el dominio queda prácticamente vacío |
| P3 | **Festivos:** One no tiene. ¿Keeper sigue como dueño (calendarios por país calculados)? | Sí. Son cálculo, no edición manual, así que no contradicen la decisión |
| P4 | **Almuerzo:** One no lo guarda, pero los horarios suman 42 h/semana solo si se descuenta 1 h por día | El puente añade 12:00-13:00 los días de 8 h o más de jornada (configurable en el puente) |
| P5 | **Acceso a la BD:** ¿autorizas pedir a mylogin.co el usuario `pipezafra_kbridge` (solo SELECT sobre vistas, solo desde 185.42.22.239, `REQUIRE SSL`) y crear las vistas `kb_*` en la BD de One? | Sí. Si no hay TLS o no dan grants finos, se para y se reevalúa; nada de usar el usuario de One |
| P6 | **Huecos de datos:** 164 activos sin área, 59 sin firma, 7 exonerados de horario, sin fechas de ingreso ni de retiro | Hacer K2 (aceptar nulos) y que Talento Humano complete las áreas en One. El puente no inventa áreas, y `exonerado_horario` queda fuera hasta definir su efecto en Keeper |

---

## 8. Tareas y criterios de aceptación

### Puente (repo propio, por ejemplo `azc-one-bridge`, PHP 8.2 sin framework)

| Id | Tarea | Aceptación |
|---|---|---|
| B1 | Crear las vistas `kb_*` (§3) en `pipezafra_soporte_db`, con respaldo previo del esquema | `SELECT COUNT(*)` por vista coincide con los conteos de §0. `SHOW CREATE VIEW` no menciona `password`, `pin`, `photo`, `soporte_ruta` ni `motivo` |
| B2 | Usuario `pipezafra_kbridge` con grants solo sobre `kb_*`, `REQUIRE SSL` y host 185.42.22.239 | Desde .234: `SELECT * FROM employee` → denegado. `SELECT * FROM kb_people` → OK. `Ssl_cipher` no vacío. Desde otra IP → conexión rechazada |
| B3 | Esqueleto HTTP: rutas §1.2, Bearer con 2 tokens en hash, 405 si no es GET, 404 en `holidays`, 400 o 404 en `empresa`, 5xx si falla | Pruebas: token inválido → 401; error de BD simulado → 503 (nunca 200); `http` → 403 sin redirección |
| B4 | Serializadores por endpoint con el sobre `keeper.roster.v1`, `generated_at` de MySQL fijado en el cursor y paginación por clave | Con un tamaño de página forzado a 50: todas las páginas con el mismo `generated_at` y `complete:true` en full, sin repetidos ni huecos. Con `updated_since`: `complete:false` y solo filas con `updated_at >=` |
| B5 | Horarios sintetizados (§2.5): defecto de One, zona por sede, hash estable y nombre generado | Mismo volcado → mismos ids en dos corridas. 13 patrones o menos. Persona sin filas → patrón L-V 08:00-16:30. Persona sin días activos → `schedule_external_id:null` |
| B6 | Mapas fijos en la configuración: compañía → slug y nombre, sede → país, ciudad y zona, `MIN_ACTIVE` | Un código sin mapear incrementa `unmapped_company` en `/health`. Una full con menos de `MIN_ACTIVE` activos → 503 `sanity_failed` |
| B7 | Logs sin datos personales y límite de peticiones | Buscar en los logs una cédula o un correo de prueba no da resultados. 61 peticiones en un minuto → 429 |
| B8 | Despliegue en el usuario Hestia `onebridge`: vhost con `Require ip`, Let's Encrypt, `.env` 600 fuera del docroot | `curl https://onebridge.azclegal.com/api/keeper/v1/health` desde .234 → 200; desde fuera → 403 |
| B9 | Prueba de contrato contra `DataSync`: correr `DataSync::run` con el `fetch` inyectado apuntando al puente sobre una BD de prueba restaurada del volcado | Vuelta `simulated` sin `invalid_envelope` ni `invalid_item`. Conteos esperados (con K1 a K3) |

### Keeper

| Id | Tarea | Aceptación |
|---|---|---|
| K1 | Dominio `empresas` + kind `company` + `company_external_id` | Prueba: una persona con empresa A pasa a B → `users.company_id` es B y se abre una nueva `user_assignments` con B. Sin dueño de `empresas`, se conserva |
| K2 | Referencias opcionales y respeto de dominios sin dueño | Una persona sin área se crea en el placeholder "Sin área" (las columnas de `users` son NOT NULL; se reconoce por `org_units.placeholder`, no por el nombre). Un id enviado sin vínculo → `unknown_reference`. Si `firmas` no es de la fuente, se conserva `firm_id` y se edita en el panel |
| K3 | `country_code` y `city` en `site` | La sede Granada queda con HN/Tegucigalpa y `HolidayCalendars::effective` asigna el calendario HN a su gente |
| K4 | Choque de correo → item `email_in_use` | Una vuelta con choque termina `applied` o `simulated` con 1 error y el resto aplicado |
| K5 | Freno de altas (`too_many_creations`) y de dominio recién asignado (`domains_changed`) | La primera vuelta live con más de 5 altas (o tras asignar un dominio) queda `blocked` y con aprobación se aplica. **Hecho** (0046, prueba `syncBrakesTests`) |
| K6 | Feature `one` en el tenant, fuente y dominios según §6.2 | `DataSync::due` incluye la fuente. El historial muestra las etapas A y B |
| K7 | Actualizar `v4-integraciones-fuentes-de-datos.md`: §2.3 (un tenant), §4.2 (`company`, `country_code`, `city`), §4.5 (`company_external_id`, referencias opcionales) | El documento coincide con el código |
