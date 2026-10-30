# Implementación de Supervisor — tareas 1 a 7

Fecha: 2026-10-30. Worktree: `C:\Users\FumiWork\Documents\AZCKeeper-supervisor`. Rama: `panel-supervisor`.

## Alcance implementado

| Tarea | Resultado |
|---|---|
| 1. Contratos y arnés | Dos GET exclusivos de sesión del panel, schemas anidados cerrados, exportación YAML → JSON, pruebas de contrato sin BD y switch `-SupervisorOnly` integrado también en la suite normal. |
| 2. Protección | Evaluador puro de tres estados, precedencia de fallos, límite inclusivo de 24 horas, reportes futuros, reglas pendientes, reasignación y agregación sobre todos los equipos activos. |
| 3. Señales | Conexión 300/900 segundos, llegada con margen del cliente, cobertura, P/J ponderado, cuatro tipos de alertas, deduplicación y orden total independiente de protección. |
| 4. Contextos | Plataforma, cliente propio y clientes de grupo autorizados; permisos y funciones resueltos por destino. El header residual no selecciona el universo del agregado. |
| 5. Actividad | `ActivityReports::readRows()` con filtros explícitos, alcance actual e histórico, calendario y evidencia de medición. Tendencia de días cerrados equivalentes con cohorte común. DTOs anteriores conservados. |
| 6. Equipos y cumplimiento | Lecturas por lotes de equipos activos, controles, políticas, planes efectivos y alertas. Calendario nocturno, almuerzo, ausencias y falta de conexión histórica. Evidencia proyectada sin JSON crudo. |
| 7. API y paginación | KPIs antes del filtro de estado y de la página, búsqueda global, facets autorizados y cursores cifrados ligados a sesión, permisos, clientes candidatos, filtros, fecha, límite y secuencia ordenada. Páginas independientes de equipos y alertas. |

Se aplicó la sección 9: margen configurado con fallback existente de 15 minutos cuando no hay configuración; acceso base mediante `usuarios.ver` para cualquier rol; protección omitida del servidor para todos salvo plataforma y el rol IT activo (`roles.seed_key='it'`), que además requiere acceso a equipos. Supervisores conservan conexión y última señal de equipos. No se agregó protección a alertas, KPIs, filtros ni orden.

Endpoints:

```text
GET /v1/reports/supervisor
GET /v1/users/{id}/supervisor-summary
```

## Archivos cambiados

| Archivo | Cambio |
|---|---|
| `backend-v4/src/SupervisorProtection.php` | Nuevo evaluador puro y agregación por persona. |
| `backend-v4/src/SupervisorSignals.php` | Nuevas funciones puras de señales, ponderación, alertas y orden. |
| `backend-v4/src/SupervisorReadModel.php` | Nuevo modelo de lectura, autorización, lotes, DTOs, KPIs y cursores. |
| `backend-v4/src/ActivityReports.php` | Extracción de lectura explícita y hechos internos; adaptadores existentes sin cambiar contrato. |
| `backend-v4/src/AdminAuth.php` | Excepción exacta del header para el agregado y derivación de contextos candidatos. |
| `backend-v4/src/AdminApi.php` | Gate delegado exclusivamente para el agregado y dispatch de ambos GET con `Cache-Control: no-store`. |
| `backend-v4/src/Application.php` | Excluir los dos GET nuevos de la recompilación automática de políticas posteriores a la petición. |
| `docs/architecture/openapi-v4.yaml` | Rutas, parámetros y 30 schemas nuevos; archivo conservado en LF. |
| `backend-v4/config/contract.json` | Regenerado exclusivamente mediante `tests/export-contract.py`. |
| `backend-v4/tests/supervisor-unit.php` | Nuevas pruebas puras sin conexión a BD. |
| `backend-v4/tests/supervisor.php` | Contratos, fixtures aisladas, HTTP real, scopes, permisos, señales, calendario, tendencia y paginación. |
| `backend-v4/tests/smoke.php` | Registro en suite normal y ejecución selectiva. |
| `backend-v4/tests/mysql-smoke.ps1` | Switch y guardado/restauración de `KEEPER_TEST_SUPERVISOR_ONLY`. |
| Este reporte | Evidencia y límites de la implementación. |

El plan, `ENCARGO.md` y `REPORTE.md` ya estaban sin seguimiento al comenzar; no se modificaron. No se tocaron `client-v4/`, páginas, assets, login ni navegación. No hubo commits, despliegues, migraciones nuevas ni conexiones remotas. MySQL y HTTP se iniciaron exclusivamente como servicios efímeros del arnés en localhost; cada ejecución eliminó sus procesos y datadir.

## Pruebas ejecutadas y salidas

Entorno: Windows, PHP CLI 8.3.30, MySQL Community 8.4.3, Node 24.18.0, Python 3.12 y PyYAML local. Las bases eran `keeper_v4_agent_test` aisladas, nunca la configuración de producción.

### Contrato

```powershell
python backend-v4/tests/export-contract.py
php backend-v4/tests/supervisor.php
```

```text
Exported 187 paths and 320 schemas
PASS supervisor contracts
SUPERVISOR CONTRACT PASS: 11 assertions
```

Además se comparó el contrato contra `git show HEAD:backend-v4/config/contract.json`, incluyendo todas las rutas y schemas previos, no solo los del agente:

```text
PASS: all 185 existing routes, 290 schemas and shared parameters unchanged
PASS: OpenAPI uses LF
```

Las respuestas HTTP exitosas pasan por el `Validator` real desde `adminRequest()`. Se rechazan enums inventados, límites 0/101, fecha inválida/futura, filtros organizativos sin cliente, parámetro de protección no admitido y destinos ajenos. La prueba sin BD comprueba también que ambas rutas no están permitidas por `Portal::allowed()`.

### PHP lint y pruebas puras

Se ejecutó `php -l` sobre los diez PHP modificados o nuevos: los siete archivos de `src/` de la tabla anterior y `tests/supervisor.php`, `tests/supervisor-unit.php`, `tests/smoke.php`.

```text
No syntax errors detected in backend-v4/src/SupervisorProtection.php
No syntax errors detected in backend-v4/src/SupervisorSignals.php
No syntax errors detected in backend-v4/src/SupervisorReadModel.php
No syntax errors detected in backend-v4/src/ActivityReports.php
No syntax errors detected in backend-v4/src/AdminAuth.php
No syntax errors detected in backend-v4/src/AdminApi.php
No syntax errors detected in backend-v4/src/Application.php
No syntax errors detected in backend-v4/tests/supervisor.php
No syntax errors detected in backend-v4/tests/supervisor-unit.php
No syntax errors detected in backend-v4/tests/smoke.php
```

```powershell
php backend-v4/tests/supervisor-unit.php
```

```text
SUPERVISOR UNIT PASS: 45 assertions
```

Casos: 24 horas exactas y +1 segundo; fallo antiguo u offline; reporte futuro; reasignación; ausencia de plan/equipos; controles desconocidos; fallo de control excluido solo informativo; conexión 300/301/900/901; margen 0, 15 y 20; llegada exacta y +1 segundo; cero medido, falta de medición, J=0; ponderación y tope; deduplicación, severidad low, independencia de protección y exclusiones de jornada.

### MySQL e integración HTTP específica

```powershell
powershell -NoProfile -File backend-v4/tests/mysql-smoke.ps1 -SupervisorOnly -DebugResponses
```

Salida de la ejecución específica con los casos de tendencia y cursores:

```text
PASS migration collation: availability, explicit stderr fallback, actual table collations and original hashes
PASS supervisor contracts
PASS supervisor contexts
PASS supervisor activity batches
PASS supervisor API, device batches and pagination
PASS supervisor scope, historical data, IT and section permissions
PASS supervisor 120 people / three clients / independent permissions
PASS supervisor calendar, overnight, absences, timezone and retention
PASS supervisor trend: equivalent closed days and common coverage
SUPERVISOR SMOKE PASS: 172 assertions
Isolated MySQL processes and datadir removed.
```

Las dos aserciones finales adicionales sobre la lista blanca del Portal se ejecutaron en la prueba de contrato independiente (11 aserciones). Logs locales de integración: [supervisor-final.log](../../../backend-v4/.validation/supervisor-final.log).

Cobertura destacada:

- 120 personas en tres clientes, nombres e IDs repetidos entre clientes y un cuarto cliente ajeno. Alerta alta del tercero primero; cumplimiento sin permiso en el segundo no afecta orden ni KPI.
- Páginas completas sin repetidos ni omisiones; búsqueda fuera de la primera página; KPIs independientes de estado y límite; cursores alterados, de otros filtros, vencidos, de otra sesión, con secuencia cambiada o grant revocado.
- Alcances all, self, team transitivo con ciclo, filtros AND y filtros vacíos. Persona actual visible con historia de otra área; detalles históricos bloqueados.
- Protección omitida para supervisores, presente para IT/plataforma y evaluada sobre todos los equipos con `devices_limit=1`; revocados excluidos, versiones pendientes y recompilación que el GET no ejecuta.
- Falta de permiso de equipos da null/disponibilidad, sin inventar offline o No protegido. Falta de función de productividad no se sustituye por cero.
- Margen configurado, deduplicación foreign_app/detección, evidencia limitada, revisadas fuera de pendientes y fechas de cálculo por día.
- Jornada, almuerzo, ausencia parcial, festivo, turno iniciado ayer, almuerzo nocturno, Tokio y retención vencida. Sin conexión histórica explícitamente incompleta.
- Tendencia con P/J de ventanas equivalentes y solo medidos en ambos períodos; lunes sin días cerrados.
- Sesión requerida, cookie inválida, contraseña temporal, header residual y cliente revocado.

### Regresión completa

Primera ejecución completa:

```powershell
powershell -NoProfile -File backend-v4/tests/mysql-smoke.ps1 -DebugResponses
```

```text
ACTIVITY VOLUME PASS: 238 users, 298 devices, 43969 episodes, 17310/20756/20704 imported aggregates; 19.2s
PASS protection evaluate (23 assertions)
PASS protection api (59 assertions)
PASS device auth locks (88 assertions)
SMOKE PASS: 5607 assertions
Isolated MySQL processes and datadir removed.
```

Repetición sobre el backend final, en puertos locales distintos:

```powershell
powershell -NoProfile -File backend-v4/tests/mysql-smoke.ps1 -MySqlPort 13385 -HttpPort 18085 -DebugResponses
```

Salida final:

```text
PASS supervisor calendar, overnight, absences, timezone and retention
PASS device auth locks (88 assertions)
SMOKE PASS: 5621 assertions
Isolated MySQL processes and datadir removed.
```

Log: [supervisor-full-final.log](../../../backend-v4/.validation/supervisor-full-final.log). La suite completa se inició antes de añadir los últimos casos de cursores, tendencia y lista blanca del Portal a los tests; esos casos adicionales se verificaron después en las ejecuciones específica y de contrato indicadas arriba. El código funcional final sí está cubierto por esta repetición completa.

La suite incluye las regresiones existentes de actividad, presencia, productividad, autorizaciones, cuatro estados de protección anteriores, API externa, Portal, políticas, planes, retención, comandos y concurrencia. El volumen anterior es la prueba existente de actividad, **no** un benchmark del endpoint Supervisor.

### DOM existente

Con el servidor HTTP aislado del arnés todavía activo y el módulo JSDOM local ya instalado:

```powershell
$env:KEEPER_PANEL_ORIGIN='http://127.0.0.1:18084'
node --experimental-vm-modules backend-v4/tests/k3-dom.cjs
```

```text
PASS panel DOM: 13 vistas, ventanas, cronología, controles, margen, Portal y estado laboral; sin validación visual.
```

El intento inicial sin `--experimental-vm-modules` falló con `TypeError: vm.SourceTextModule is not a constructor`; se corrigió el comando, no el test ni el panel.

### Revisión del diff

```powershell
git diff --check
```

Resultado: sin errores de whitespace. Git emitió únicamente avisos de que su configuración podría convertir LF a CRLF en una futura operación; el YAML y su hash se comprobaron sobre los bytes actuales en LF.

```text
PASS: original contracts, LF/hash, client-v4 and existing panel unchanged
```

### Evidencia RED → GREEN

Las pruebas se escribieron y ejecutaron antes de implementar los bloques correspondientes. Fallos iniciales observados y resueltos:

```text
supervisor contract getSupervisorOverview
Class "Keeper\SupervisorProtection" not found
Class "Keeper\SupervisorReadModel" not found
Call to undefined method Keeper\ActivityReports::readRows()
FAIL: supervisor overview HTTP=404 code=resource_not_found
Expected "unconfirmed", got "unprotected"                [reporte futuro]
FAIL: unknown alert calculation time explicit
Expected ["all_confirmed","excluded_control_failed"], got ["all_confirmed"]
```

También se corrigieron las fixtures para respetar `group_tenant_id/group_id` y el CHECK real que prohíbe self/team en grants de grupo; no se relajó el esquema para hacer pasar el test. El arnés pasó progresivamente 10, 30, 84, 125, 148, 162, 167 y 172 aserciones conforme se añadieron casos.

## Pruebas que no pudieron completarse

Se intentaron estas seis pruebas:

```text
node backend-v4/tests/k3-panel.cjs
node backend-v4/tests/unlock-pin-panel.cjs
node backend-v4/tests/windows-security-panel.cjs
node backend-v4/tests/power-schedules-panel.cjs
node backend-v4/tests/location-panel.cjs
node backend-v4/tests/integrations-panel.cjs
```

Primero fallaron al resolver `playwright` desde este worktree. Se encontró una instalación existente y se reintentaron usando el mecanismo previsto por los tests:

```powershell
$env:KEEPER_PLAYWRIGHT_MODULE='C:/Users/FumiWork/proyectos/portal-azc/node_modules/playwright'
```

Los seis reintentos terminaron con código 1 antes de ejecutar las aserciones del navegador:

```text
browserType.launch: spawn EPERM
```

El entorno impide lanzar Chromium. No se instalaron dependencias, no se eludió el bloqueo y no se afirma validación visual. Los logs están en `backend-v4/.validation/<nombre-del-test>.log`.

MySQL sí estuvo disponible y todas las pruebas backend previstas se pudieron ejecutar. No se probó un motor MariaDB. `supervisor-panel.cjs` pertenece a la implementación de interfaz, tareas 8–10, y aún no existe. La revisión visual y el benchmark específico de capacidad de las tareas 12–13 quedan fuera de este encargo.

## Desviaciones y precisiones del plan

1. **Archivo adicional `Application.php`:** la implementación existente recompila políticas pendientes después de cualquier GET administrativo. Se comunicó el hallazgo antes de modificarlo. Se excluyen únicamente las dos operaciones nuevas para respetar la lectura funcional; las rutas anteriores mantienen su comportamiento.
2. **Margen multicliente:** `kpis.arrivals.margin_minutes` puede ser null si los clientes tienen márgenes distintos. `margins[]` conserva el margen por cliente; las filas llevan el propio. No se cambia `punctuality_margins` ni la semántica del reporte anterior.
3. **Frescura de alertas:** los DTOs nuevos de alertas añaden `calculated_at` y `data_through`, obtenidos del `rollup_days` de la fecha consultada. Si falta cálculo o sigue dirty, se marca cobertura incompleta; no se ejecuta el worker desde GET.
4. **Historia conservadora:** `raw_detail_available=false` ante cualquier asignación fuera de alcance que solape el día, incluso sin demostrar que tiene episodios. Es más restrictivo que buscar solo asignaciones con datos, evita leer episodios crudos y mantiene intactos los GET anteriores. Las alertas agregadas sin asignación trazable se omiten si hay historia fuera de alcance.
5. **Grupo self/team:** la BD ya los prohíbe mediante CHECK. El test comprueba esa restricción real y la ausencia de identidad local en el contexto extranjero; no introduce fixtures imposibles ni cambia el esquema.
6. **Control excluido fallido:** se conserva como motivo informativo separado sin empeorar el estado. Reportes con fecha futura no se aceptan como confirmación ni como fallo confiable.
7. **Pruebas de contrato:** `supervisorContractTests` admite ejecutarse sin BD para validar localmente rutas, schemas y Portal; la misma función sigue integrada en el harness MySQL.

No se añadieron índices ni se afirma cumplir los presupuestos p95 del plan sin la medición específica de la tarea 12. No se desarrollaron las páginas ni los cambios de entrada/navegación de las tareas siguientes.
