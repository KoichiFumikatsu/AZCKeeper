# Supervisor — implementación de tareas 8 a 12

Fecha: 2026-10-30. Worktree: `C:\Users\FumiWork\Documents\AZCKeeper-supervisor`. Rama: `panel-supervisor`. HEAD: `b1a28bf`.

## Estado y procedencia

El worktree ya contenía una implementación sin commitear de las tareas 8–12 y un reporte anterior. Se revisó esa implementación, se corrigieron las discrepancias con la decisión 5 y la ausencia de protección en la UI, y se ejecutaron nuevamente las pruebas indicadas. Este reporte sustituye la interpretación anterior que equiparaba Supervisor con `coordinacion`; esa interpretación queda descartada.

No se hicieron commits, despliegues, accesos remotos, cambios en servidores, migraciones nuevas ni cambios en `client-v4/`. Las bases y los procesos HTTP usados pertenecen exclusivamente al arnés local aislado. Las tablas de roles que crean los tests son fixtures desechables, no datos ni reglas de configuración de clientes.

| Tarea | Resultado |
|---|---|
| 8 | Dos páginas PHP, layout independiente con marca, CSP, import map versionado y `k3.css`; enlace sujeto a `usuarios.ver`; tres caminos del login con destino resuelto en el servidor. |
| 9 | Lista multicliente con búsqueda/filtros en servidor, tres KPIs, cobertura, tendencia, estados de conexión, orden, cursores anterior/siguiente, URL y retorno seguro. Carga, vacío, error y acceso denegado diferenciados. |
| 10 | Ficha diaria con Hoy/Ayer/fecha, margen configurado, jornada/almuerzo/ausencias, actividad/productividad, alertas, usos y cronología paginada. Equipos actuales separados de los datos históricos. Protección y bloqueos solo cuando el servidor los autoriza. |
| 11 | Matriz all/self/team/filters y multicliente, regresiones de entrada, permisos y dependencias de lectura. Sin inventar permisos por navegar a Supervisor. |
| 12 | Fixtures 30/14, 300/142 y 300/600 personas/equipos, tres clientes y 14 días; medición HTTP, consultas, memoria y EXPLAIN. Presupuestos cumplidos en la ejecución específica. |

## Decisiones aplicadas

1. **Supervisor se determina por jerarquía, nunca por rol.** `MyAccess.supervisor_home` es verdadero para plataforma o para una persona activa que tenga al menos un reporte activo en `users.reports_to_user_id` dentro del cliente actual. La consulta usa la misma condición de actividad y JOIN por cliente que `RoleRules::matching('manages')`, limitada a la persona autenticada. Un contexto de grupo ajeno sin `user_id` no inventa una identidad local. No se ejecuta `RoleRules::apply` ni se modifican grants al leer.
2. **La entrada no concede acceso.** La decisión 5 prevalece también si faltan permisos: el jefe llega a Supervisor y la API puede devolver 403; la página muestra acceso no disponible y retorno al panel actual. El enlace en el panel depende exclusivamente de `usuarios.ver`. IT, RRHH, Administración y cualquier rol sin reportes conservan `/`; si tienen reportes activos entran a Supervisor. Se prueban roles con `seed_key` conocido y personalizado, con y sin reportes, reportes inactivos y retiro del último reporte.
3. **Protección sin huecos.** Si el servidor omite protección, no aparece «Qué tiene bloqueado», no se pide `/rules`, no se muestra un estado ficticio ni se reserva una tercera columna en equipos. También se elimina la mención de protección del aviso de actualidad en esa ficha. Plataforma/IT mantienen los tres estados y sus causas. La lista conserva seis columnas y tres KPIs, sin filtro, orden ni KPI de protección.
4. **Margen real.** Se usa el margen que entrega el servidor, configurado por cliente, con fallback de 15 minutos ya resuelto en las tareas 1–7. No se introduce un segundo cálculo en JS.
5. **Estilo real.** `backend-v4/public/assets/styles.css` no existe. Se reutiliza `k3.css`, como confirma el plan; todos los estilos nuevos quedan bajo `.supervisor`. No se importan scripts/datos de la maqueta ni se añaden IA o acciones de escritura.
6. **Fechas y datos faltantes.** Se valida fecha real/no futura usando la zona del cliente. Equipos y reglas siguen siendo actuales. No se inventan desconexiones históricas, clasificación productiva de apps o intervalos de inactividad. Un `null` se muestra como ausencia de dato, no como cero ni No protegido.
7. **Cambios compartidos necesarios.** `api.js` solo permite omitir el tenant para el GET exacto del agregado. `loadAccess()` conserva consumidores anteriores y cachea por tenant. `Application.php` excluye de la recompilación posterior los GET nuevos y sus dependencias `getMe`, `getMyAccess`, `getUserWindows`, `getUserTimeline`, `getUserRules`: consultar la vista no aplica políticas pendientes. Este último cambio afecta el efecto posterior de esas lecturas compartidas; sus autorizaciones y DTO se conservan. Por ello se ejecuta también la suite integral.
8. **Rutas existentes.** Se conserva el registro de `/proteccion.php` que ya estaba en los cambios recibidos, necesario para servir la página existente mediante el router del arnés. No se modifica su UI ni su semántica de cuatro estados. `/`, `/index.php` y `/admin/` no redirigen a Supervisor; evitan bucles al volver al panel.
9. **Capacidad.** No se añade ningún índice: las mediciones cumplen el presupuesto. La instrumentación reside exclusivamente en los tests. No se amplía la retención ni se crea historial de conexiones.

## Archivos del cambio final respecto a HEAD

| Archivo | Función |
|---|---|
| `backend-v4/public/supervisor.php` | Entrada de lista. |
| `backend-v4/public/supervisor-persona.php` | Entrada de ficha diaria. |
| `backend-v4/public/partials/layout-supervisor.php` | Shell, marca, CSP, CSS y módulo independiente; sin datos personales en HTML. |
| `backend-v4/public/assets/supervisor.js` | Peticiones, filtros, fechas, paginación, reintentos y descarte de respuestas atrasadas. |
| `backend-v4/public/assets/supervisor-view.js` | Renderizado seguro mediante nodos/texto, métricas, equipos, alertas, reglas y retorno permitido. |
| `backend-v4/public/assets/supervisor.css` | Distribución escritorio/móvil, con dos columnas de equipo cuando no hay protección. |
| `backend-v4/public/assets/api.js` | Excepción acotada de tenant para el agregado y lectura del tenant seleccionado. |
| `backend-v4/public/assets/app.js` | Destino de sesión existente, login nuevo y cambio de contraseña. |
| `backend-v4/public/assets/nav-access.js` | Permisos de navegación, caché por tenant y destino del login. |
| `backend-v4/public/partials/sidebar.php` | Enlace Supervisor. |
| `backend-v4/public/index.php` | Registro de las dos páginas nuevas y de la ruta existente de protección. |
| `backend-v4/src/AdminApi.php` | `supervisor_home` calculado con reportes activos, sin depender del rol. |
| `backend-v4/src/Application.php` | Lecturas de Supervisor y dependencias sin recompilación de políticas. |
| `docs/architecture/openapi-v4.yaml` | Campo opcional `MyAccess.supervisor_home` y semántica documentada. |
| `backend-v4/config/contract.json` | Regenerado con el exportador; no editado manualmente. |
| `backend-v4/tests/supervisor.php` | Matriz, entrada por jerarquía, HTTP de páginas y lecturas sin mutaciones funcionales. |
| `backend-v4/tests/supervisor-capacity.php` | Fixtures, tiempos HTTP, conteo PDO y EXPLAIN. |
| `backend-v4/tests/supervisor-panel-fixture.cjs` | DTO de prueba, nombres hostiles e identidades compuestas. |
| `backend-v4/tests/supervisor-panel.cjs` | DOM/controladores, navegación y las 12 combinaciones de los tres caminos de login con cuatro condiciones de entrada. |
| `backend-v4/tests/supervisor-browser.cjs` | Pruebas Playwright para ejecución posterior por el caller. |
| Este reporte | Evidencia y pendientes. |

`docs/superpowers/plans/2026-10-30-panel-supervisor.md` ya estaba modificado al recibir el worktree: contiene la decisión 5. Se conservó sin editarlo. Los hooks de `smoke.php` y `mysql-smoke.ps1 -SupervisorOnly` ya existían y se reutilizaron. No se cambian `SupervisorReadModel`, `SupervisorSignals`, `SupervisorProtection`, `AdminAccess` ni los algoritmos de productividad.

## Pruebas ejecutadas en esta sesión

### Fallos reproducidos antes de corregir

Se ampliaron las pruebas sobre la implementación recibida y se observaron estos fallos reales:

```text
AssertionError [ERR_ASSERTION]: ... /Qué tiene bloqueado|Sin confirmar|protección/i
FAIL: login follows active reports, independent of role custom
```

La primera ejecución DOM y la primera ejecución `-SupervisorOnly` terminaron con código 1. El arnés eliminó su base/procesos al finalizar. Logs: [DOM RED](../../../backend-v4/.validation/supervisor-current-red-panel.log), [jerarquía RED](../../../backend-v4/.validation/supervisor-current-red-entry.log).

### Contrato, unitarias, DOM y sintaxis

```powershell
python backend-v4/tests/export-contract.py
php backend-v4/tests/supervisor-unit.php
php backend-v4/tests/supervisor.php
node backend-v4/tests/supervisor-panel.cjs
node backend-v4/tests/access-summary.test.mjs
node backend-v4/tests/error-message.test.mjs
```

Salida real resumida, todos con código 0:

```text
Exported 187 paths and 320 schemas
SUPERVISOR UNIT PASS: 45 assertions
PASS supervisor contracts
SUPERVISOR CONTRACT PASS: 11 assertions
SUPERVISOR PANEL PASS
access-summary: tests 10, pass 10, fail 0
error-message: tests 4, pass 4, fail 0
```

`php backend-v4/tests/supervisor.php` sin BD ejecuta únicamente los contratos; la integración se ejecutó con el arnés. `php -l` pasó en los nueve PHP nuevos/modificados; `node --check` pasó en los cinco módulos JS nuevos/modificados y el test de navegador. Node emite la advertencia de módulos VM experimentales; no es un fallo. [Salida completa estática](../../../backend-v4/.validation/supervisor-current-static.log).

Se comparó el JSON exportado con `HEAD` mediante Python: las rutas y todos los schemas previos son idénticos al retirar únicamente la nueva propiedad opcional. El otro cambio de raíz es `source_sha256`. El YAML conserva LF.

```text
PASS contract compatibility: same paths/schemas except optional MyAccess.supervisor_home
```

`git diff --check` pasó sin errores de whitespace; Git solo avisa de su posible conversión futura LF/CRLF.

```powershell
$env:KEEPER_PANEL_ORIGIN='http://127.0.0.1:18084'
node --experimental-vm-modules backend-v4/tests/k3-dom.cjs
```

```text
PASS panel DOM: 13 vistas, ventanas, cronología, controles, margen, Portal y estado laboral; sin validación visual.
```

Ejecutado con el HTTP del arnés local y las respuestas simuladas propias del test. Código 0. [Log del panel anterior](../../../backend-v4/.validation/supervisor-current-k3-dom.log).

### MySQL específico

```powershell
powershell -NoProfile -File backend-v4/tests/mysql-smoke.ps1 -SupervisorOnly
```

```text
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
SUPERVISOR SMOKE PASS: 555 assertions
Isolated MySQL processes and datadir removed.
```

Código 0. [Salida completa específica](../../../backend-v4/.validation/supervisor-current-specific.log).

La matriz comprueba all/self/team transitivo con ciclo/filtros AND/filtros vacíos, grupos con permisos distintos por destino, IDs repetidos entre clientes, URL ajenas, historia fuera del alcance, cursores alterados/vencidos/revocados, sesión inválida, clave temporal, clientes archivados/inactivos/eliminados y ausencia de rutas nuevas en Portal. Las huellas de tablas verifican que los GET no cambian políticas, planes, grants, órdenes, revisión de alertas ni asignaciones; la renovación de sesión sigue siendo infraestructura normal.

### Regresión integral

```powershell
powershell -NoProfile -File backend-v4/tests/mysql-smoke.ps1 -DebugResponses
```

```text
PASS protection api (59 assertions)
PASS effective rules api (51 assertions)
PASS supervisor capacity budgets
PASS legacy k3 (40 assertions)
PASS productivity short transactions (51 assertions)
PASS device auth locks (88 assertions)
SMOKE PASS: 6018 assertions
Isolated MySQL processes and datadir removed.
```

Código 0. [Salida integral completa](../../../backend-v4/.validation/supervisor-current-full.log). Incluye los módulos anteriores de actividad/productividad, presencia, protección, reglas, autenticación, API externa, Portal, planes, permisos, órdenes y concurrencia. No se relajaron sus expectativas. Todos los cambios funcionales finales quedaron cubiertos; después solo se completó este reporte.

## Capacidad: medición específica real

Windows; PHP 8.3.30; MySQL Community 8.4.3; CPU `Intel64 Family 6 Model 186 Stepping 3, GenuineIntel`; 12 procesadores lógicos; buffer pool InnoDB 64 MiB. Tres clientes, 14 días, profundidad de alcance constante. El último escenario contiene 300 personas, 600 equipos y 8.400 episodios.

Cada operación usa una petición de calentamiento y 20 muestras HTTP. p95 corresponde al percentil de rango más cercano. Se excluye del tiempo la preparación de fixtures/migraciones.

| Personas/equipos | Operación | p50 ms | p95 ms | Máximo bytes HTTP | Consultas modelo | Pico PHP modelo |
|---|---|---:|---:|---:|---:|---:|
| 30/14 | Lista | 293,08 | 323,86 | 53.171 | 197 | 30 MiB |
| 30/14 | Ficha | 150,91 | 162,75 | 3.146 | 63 | 30 MiB |
| 300/142 | Lista | 829,84 | 1.028,71 | 85.743 | 197 | 34 MiB |
| 300/142 | Ficha | 141,31 | 165,84 | 3.146 | 63 | 34 MiB |
| 300/600 | Lista | 514,33 | 761,18 | 87.241 | 197 | 34 MiB |
| 300/600 | Ficha | 105,18 | 126,63 | 3.770 | 63 | 34 MiB |

Con 300/600, p95 de ventanas **79,87 ms**, cronología **67,03 ms**, reglas **121,07 ms**, lista filtrada **455,83 ms**, permisos parciales **1.057,12 ms**. Cada una tiene otras 20 muestras.

197/63 es el conteo PDO del modelo de lectura, no el de toda la autenticación HTTP. La memoria es el pico del proceso PHP que invoca directamente el modelo, con fixtures cargadas; no se instrumentó el pico del proceso HTTP. Los tiempos y bytes sí corresponden a peticiones HTTP reales. No hay consultas adicionales por fila al multiplicar personas/equipos; el agregado no lee episodios crudos.

EXPLAIN ejecuta los SQL reales capturados, con sus parámetros en memoria; el log imprime SQL con placeholders y planes sin los valores. Roster usa `PRIMARY` y JOINs `eq_ref`; alertas usan índices por cliente; equipos usan `tenant_id_2`, reportes/políticas sus claves existentes. Las subconsultas de últimos reportes/políticas muestran `Using filesort`; no se oculta ese coste. El conteo constante no significa que el trabajo interno de la BD sea constante.

Se cumple lista p95 ≤2 s, ficha ≤1 s y página de 50 ≤250 KiB en esta fixture/máquina. No es una garantía sobre producción o volúmenes mayores. No se añadió migración ni índice.

La repetición dentro de la suite integral también pasó: con 300/142, lista p95 **1.043,53 ms** y ficha **184,85 ms**; con 300/600, lista **962,41 ms** y ficha **182,29 ms**, máximo **87.241 bytes** para la lista. Se conservaron **197/63 consultas** y el pico del modelo fue **42 MiB** con la suite completa cargada. Los resultados completos están en el log integral.

## Para el caller

1. **Ejecutar navegador fuera del bloqueo de Chromium.** No se intentó abrir Chromium en esta sesión: el encargo informa `spawn EPERM`. Las pruebas están escritas; no hay capturas nuevas ni validación visual afirmada.

   ```powershell
   $env:KEEPER_PLAYWRIGHT_MODULE='C:/Users/FumiWork/proyectos/portal-azc/node_modules/playwright'
   node backend-v4/tests/supervisor-browser.cjs
   ```

   Inicia PHP local en `127.0.0.1:18086`, simula la API y bloquea solicitudes externas. Cubre entrada por jerarquía simulada sin depender del rol, sesión existente, cambio de clave, enlace desde el panel, Administrar, búsqueda→ficha→Ayer→volver, paginación, errores, detalle restringido, XSS, escritorio/móvil y foco de teclado. Capturas previstas: `backend-v4/.validation/supervisor-browser/`. La jerarquía real se prueba en MySQL, no en los mocks del navegador.
2. **Revisión visual de tarea 13:** 1366×768 y 375 px, nombres largos, teclado, lectura de estados, errores y ausencia de huecos sin protección. Las pruebas DOM no sustituyen esa revisión. Ajustar si el navegador detecta defectos.
3. **MariaDB no ejecutado.** Las pruebas de esta sesión usan MySQL 8.4.3.
4. **Revisión/commit/despliegue en otra fase.** Todos los cambios permanecen sin commit; no se preparó ni ejecutó publicación. Los logs están en `.validation`, excluido de Git; este reporte conserva los resultados relevantes.
