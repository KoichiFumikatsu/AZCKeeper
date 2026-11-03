# Apertura de rutas de supervisor al Portal

Fecha: 2026-11-03. Worktree: `C:\Users\FumiWork\Documents\AZCKeeper-supervisor`, rama `panel-supervisor`, base `17ec7f0`.

## Cambios

| Archivo | Cambio |
|---|---|
| `backend-v4/src/Portal.php` | Añade únicamente `GET /reports/supervisor`, `GET /users/{id}/supervisor-summary`, `GET /organization`, `GET /schedules/holidays` y `GET /schedules/holiday-calendars` a `ROUTES`. |
| `backend-v4/src/AdminApi.php` | En `listSchedules`, exclusivamente por Portal y sin alcance completo, consulta los horarios efectivos de las personas autorizadas por `AdminAccess::usersSql`. |
| `docs/architecture/openapi-v4.yaml` | Añade `portalBearer` a las cinco lecturas, retira «sin acceso Portal», documenta selección de cliente y horarios; corrige la descripción del catálogo de calendarios según el handler real. |
| `backend-v4/config/contract.json` | Regenerado desde OpenAPI con `python backend-v4/tests/export-contract.py`. |
| `backend-v4/tests/portal.php` | Actualiza la expectativa de horario compartido y verifica 404 para las rutas expresamente excluidas. |
| `backend-v4/tests/supervisor.php` | Actualiza contrato/autenticación y añade pruebas HTTP con tokens de Portal, alcances, horarios, protección, permisos por sección, cursores, recompilación pendiente y CORS. |
| Este reporte | Decisiones, límites, evidencia de pruebas y archivos necesarios para desplegar posteriormente. |

`smoke.php` ya carga `portal.php` y `supervisor.php`. `supervisorTests()` ejecuta las nuevas pruebas tanto en `-SupervisorOnly` como en la suite completa; no fue necesario cambiar el arnés ni sus puntos de entrada.

No hay commits, despliegues, accesos a servidores externos, cambios de migraciones ni modificaciones de `client-v4/`. Las pruebas levantan y eliminan su propia base MySQL y servidor PHP en localhost.

## Decisiones y límites conservados

### Misma operación y autorización

`Request` normaliza `/portal/v1` a la plantilla del contrato y aplica la lista blanca. `Application::admin()` recibe el contexto de `Portal::context()` y ejecuta el mismo `AdminApi` y `SupervisorReadModel` que `/v1`.

La protección se construye solamente para plataforma o un rol activo con `seed_key=it`; no depende del nombre visible del rol. El supervisor ordinario no recibe claves `protection`, ni siquiera un valor nulo, tanto en el listado como en la ficha y sus equipos. Se mantienen las compuertas por permiso y función contratada de cada sección.

`self` conserva solo la propia persona; `team` incluye al titular y su equipo transitivo; área y sede son listas de un alcance `filters`, no tipos independientes. Los filtros compuestos mantienen sus intersecciones y `filters` vacío no expone personas ni horarios. Persona ajena: 404; filtro de área ajeno: 403, igual que `/v1`.

### Horarios

El SQL selecciona los horarios de `users` dentro del alcance, restringe ambos lados al mismo tenant y usa una subconsulta `IN` para no duplicar horarios compartidos. Resuelve un horario provisional al predeterminado del tenant cuando existe; sin predeterminado conserva el provisional. El predeterminado no se añade por sí solo: debe corresponder a una persona visible.

Se conservan la paginación y el DTO existentes. `self` mantiene su resultado anterior y `all` conserva el catálogo completo. Por `/v1`, `GET /schedules` sigue exigiendo alcance completo. No se amplían escrituras ni lecturas individuales de horarios.

### Catálogos: discrepancia de la referencia con el código

| Lectura | Regla real conservada en ambas puertas |
|---|---|
| `/organization` | `organizacion.ver`, cualquier alcance; catálogo del tenant. |
| `/schedules/holidays` | `festivos.ver` **y alcance completo** (`OperationsApi::catalog`). Con self/team/filters responde 403. Abrir la puerta no elimina esta restricción. |
| `/schedules/holiday-calendars` | `festivos.ver`, cualquier alcance: el listado retorna antes de `requireFull()` en `HolidayCalendars`. Sus contadores `people` y `holidays` son del calendario del tenant, no del equipo visible. |

La descripción anterior de OpenAPI para calendarios decía genéricamente que los catálogos exigían alcance completo. Se corrigió para describir el comportamiento existente; no se cambió el handler ni sus permisos. Los contadores de catálogo no equivalen al listado de personas supervisadas.

### Plataforma, grupos y sesión

- La ficha y los catálogos de plataforma seleccionan tenant con `X-Tenant-ID`. Las pruebas llaman las cinco rutas nuevas con token de plataforma y esta cabecera.
- **El Overview es un consolidado:** `SupervisorReadModel::overview()` selecciona con `?tenant_id=<uuid>`, no con `X-Tenant-ID`. Se conserva la semántica de `/v1`; para mostrar solo un cliente, el Portal debe enviar ese query. La prueba de plataforma usa query y cabecera coincidentes. No se convierte el Overview en un listado limitado por cabecera.
- Portal valida cualquier `X-Tenant-ID` recibido: una persona no puede elegir otro tenant. Esa validación ya era más estricta que la del Overview del panel, que ignora su cabecera residual. No se relaja.
- `Portal::context()` establece `group_id=null`. Una persona con una cuenta de grupo en el panel no obtiene acceso multicliente por token: solo su tenant. No se añade soporte para cuentas de grupo sin persona. Plataforma conserva sus contextos autorizados; el read model sigue excluyendo tenants archivados, suspendidos o eliminados del supervisor.
- No se encontró dependencia de cookies o CSRF en los handlers de supervisor. Los cursores usan `session_id`; Portal ya lo rellena con `portal_tokens.family_id`. Las pruebas verifican paginación sin cookie, rechazo entre familias y entre Portal/panel, y continuidad tras refresh dentro de la misma familia.

### Protección y «Qué tiene bloqueado»

Los tres estados (`protected`, `unconfirmed`, `unprotected`) y sus razones están disponibles en las nuevas respuestas solo para IT/plataforma, sujetos a las mismas disponibilidades de sección.

**La lista detallada «Qué tiene bloqueado» no forma parte de `supervisor-summary`.** El panel la carga con una segunda petición a `/users/{id}/rules`, condicionada por `sections.protection` y `sections.rules` (`supervisor.js`, `supervisor-view.js`). Esa ruta no está en las cinco autorizadas: se mantiene 404 en Portal, incluso para plataforma, y se verifica en las pruebas. El Portal puede mostrar el estado de protección, pero necesitaría otra autorización y un contrato apropiado para obtener esa lista detallada. No se abre la ruta ni se incrusta ese dato en el DTO.

### Lecturas sin recompilación y CORS

La exclusión de `getSupervisorOverview` y `getSupervisorPersonSummary` está en `Application::admin()`, compartido por las dos puertas; no depende del prefijo HTTP. No fue necesario modificar `Application.php`. Se insertó una recompilación pendiente en la prueba: ambas GET por Portal conservaron la cola y las políticas compiladas.

No cambia `Portal::cors()`. Las cinco nuevas rutas se prueban con preflight sin autenticación, origen autorizado, origen ajeno y error 401 con CORS. Se conservan `GET, POST`, `Authorization, Content-Type, X-Tenant-ID`, ausencia de credenciales CORS y ausencia de CORS en `/v1`.

`dual-job-alerts`, `data-sources`, `sync-runs`, `app-classification`, `person-plans` y `person-plans/available` siguen fuera de la lista blanca y responden 404 con token válido.

## Contrato

Se ejecutó:

```text
python backend-v4/tests/export-contract.py
Exported 187 paths and 320 schemas
```

Se buscó `portal_openapi` y `keeper-portal-openapi` en todo el worktree, incluidos archivos ocultos, excluyendo `.git` y `client-v4`. Sin coincidencias. También se revisaron los archivos de contrato/Portal de `tools` y `backend-v4/tests`: existe el exportador general y el probador `tools/portal-api-tester`, pero no un extractor independiente del contrato Portal. No se generaron artefactos en el repositorio externo de LawyerDesk.

## Verificación

Entorno real: PHP 8.3.30, MySQL 8.4.3, Windows. Las salidas siguientes se capturaron con `Tee-Object`; los logs completos locales están en `backend-v4/.validation/portal-routes/` (directorio ignorado por Git).

Primera ejecución de `-SupervisorOnly`: salida 1 por una expectativa incorrecta de la prueba nueva; suponía que ambos catálogos de festivos exigían alcance completo:

```text
FAIL: portal catalog retains full scope gate HTTP=200 code=none
```

Se inspeccionó `HolidayCalendars::dispatch()`, se corrigió la expectativa a 200 para calendarios y se repitió. No se cambió el handler para forzar la expectativa.

Las tres ejecuciones finales terminaron con codigo de salida **0**. Extractos literales de stdout (se omiten listados repetidos de migraciones y EXPLAIN):

```text
php backend-v4/tests/supervisor-unit.php
SUPERVISOR UNIT PASS: 45 assertions
```

Log completo local: `backend-v4/.validation/portal-routes/unit.log`.

```text
powershell -NoProfile -File backend-v4/tests/mysql-smoke.ps1 -SupervisorOnly
TRANSPORT PASS: 10 assertions
Isolated MySQL ready: 8.4.3
Verified 64 checks; fixtures rolled back. MySQL 8.4.3
PASS supervisor contracts
PASS supervisor Portal routes, scopes, schedules, protection, cursors and CORS
PASS supervisor entry, page shells and read-only dependencies
PASS supervisor contexts
PASS supervisor activity batches
PASS supervisor API, device batches and pagination
PASS supervisor scope, historical data, IT and section permissions
PASS supervisor 120 people / three clients / independent permissions
PASS supervisor calendar, overnight, absences, timezone and retention
PASS supervisor trend: equivalent closed days and common coverage
PASS supervisor bounded queries 197/197/197
PASS supervisor capacity budgets
SUPERVISOR SMOKE PASS: 851 assertions
Isolated MySQL processes and datadir removed.
```

Log completo local: `backend-v4/.validation/portal-routes/supervisor-2.log`.

```text
powershell -NoProfile -File backend-v4/tests/mysql-smoke.ps1
TRANSPORT PASS: 10 assertions
Isolated MySQL ready: 8.4.3
Verified 64 checks; fixtures rolled back. MySQL 8.4.3
PASS portal (148 assertions)
PASS access scope (19 assertions)
PASS access evaluation (50 assertions)
PASS access cases (86 assertions)
PASS access delegation (37 assertions)
PASS protection api (59 assertions)
PASS supervisor Portal routes, scopes, schedules, protection, cursors and CORS
PASS supervisor bounded queries 197/197/197
PASS supervisor capacity budgets
PASS productivity short transactions (51 assertions)
PASS device auth locks (88 assertions)
SMOKE PASS: 6320 assertions
Isolated MySQL processes and datadir removed.
```

Log completo local: `backend-v4/.validation/portal-routes/full.log`.

Mediciones reales del caso de 300 personas / 600 equipos dentro de la suite completa:

```text
SUPERVISOR CAPACITY {"people":300,"devices":600,"days":14,"endpoint":"overview","requests":20,"p50_ms":617.89,"p95_ms":781.17,"max_bytes":87236,"queries":197,"peak_bytes":41943040,"model_ms":500.02,"bytes":87236}
SUPERVISOR CAPACITY {"people":300,"devices":600,"days":14,"endpoint":"summary","requests":20,"p50_ms":185.8,"p95_ms":204.44,"max_bytes":3770,"queries":63,"peak_bytes":41943040,"model_ms":101.71,"bytes":3770}
```

Comprobaciones adicionales completadas:

```text
No syntax errors detected in backend-v4/src/Portal.php
No syntax errors detected in backend-v4/src/AdminApi.php
No syntax errors detected in backend-v4/tests/portal.php
No syntax errors detected in backend-v4/tests/supervisor.php
PASS supervisor contracts
SUPERVISOR CONTRACT PASS: 11 assertions
Contract source_sha256 matches OpenAPI
```

`git diff --check`: salida 0, sin errores de whitespace. Git solo advierte la normalización LF/CRLF configurada para los archivos editados.

## Archivos para un despliegue posterior

Sobre una instalación que ya tenga el backend de `panel-supervisor` en `17ec7f0`, este cambio requiere actualizar conjuntamente:

1. `backend-v4/src/Portal.php`.
2. `backend-v4/src/AdminApi.php`.
3. `backend-v4/config/contract.json` regenerado.

El YAML, las pruebas y este reporte deben acompañar la versión en el repositorio; el runtime PHP consume `contract.json`. **No hay nuevas migraciones ni cambios de schema.** Si el destino todavía no tiene la implementación de supervisor de esta rama, estos tres archivos no bastan: primero debe incluirse esa implementación y sus dependencias de la base `17ec7f0`.

Para consumir desde navegador se mantiene la configuración existente de orígenes autorizados; las personas necesitan cuenta activa, acceso Portal habilitado y los permisos/alcance indicados. Plataforma usa su token y la selección de cliente descrita arriba. No se modificó ninguna configuración externa ni se ejecutó el despliegue.
