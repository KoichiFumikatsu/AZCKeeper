# Pantallas K3 en el panel v4 real

Fecha: 2026-10-13. Rama: `feature/keeper-v4`. Base: `3e6019f`.

Implementados Inicio, Personas, Ficha, Productividad, Equipos, Doble empleo y Cobertura, además del control de versiones por anillo. Los cambios están en `backend-v4/public`, no en el prototipo. Sin push, SSH ni despliegue.

**Cierre pendiente por restricciones del entorno:** no fue posible crear commits ni capturas de navegador. `.git` está montado como solo lectura: `git add` falla creando `.git/index.lock` con `Permission denied`. Playwright encuentra su módulo y Chromium, pero el lanzamiento devuelve `spawn EPERM`. No se ha validado visualmente el resultado ni se presentan capturas ficticias como evidencia.

## Paridad por pantalla

«Igual» significa contenido o función recuperada; no igualdad píxel a píxel. Se mantienen el fondo claro, la paleta índigo y los componentes reales de `assets/k3.css`, que es la hoja cargada por ambos layouts. La comparación funcional se hizo contra los PHP de K3 y el inventario de paridad; la comparación visual queda pendiente.

| Pantalla / ruta | Igual: elementos recuperados | Parcial o distinto | Falta respecto al inventario completo de K3 |
|---|---|---|---|
| Inicio `/` | Hoy / 7 días / Mes / rango; personas en línea, ausentes y desconectadas; horas de hoy y del mes; primer ingreso hoy; productividad, focus e inactividad; personal y equipos; top 5; apps; enlace a Productividad | Conexión hasta 5 min, ausente entre 5 y 15; umbral explícito de v4. Roster de 20 con enlace al directorio completo. Top por tiempo en uso, no exclusivamente laboral. Tendencia en lugar de donut/gauge | Desglose de franjas en Inicio, banner de alertas pendientes, donut y gauge de K3 |
| Personas `/usuarios.php` | «Tu personal activo»; estado de conexión; primer ingreso, tiempo, productividad y focus de hoy; filtro de conexión; búsqueda por nombre/correo/cédula/cargo/empresa; filtros de empresa/sede/área; paginación; acceso a actividad | Se conserva tabla y gestión administrativa existente. Estados calculados al consultar, sin sondeo automático. La situación laboral sigue separada de la conexión | Tarjetas individuales de K3; no son necesarias para las columnas solicitadas |
| Ficha `/miembro.php?id=…` | Actividad como pestaña inicial para cuentas con reportes; Hoy / 7 días / Mes / rango; resumen mensual; horario laboral/almuerzo/fuera de horario/llamadas; en uso/inactivo/bloqueado/simulado; constancia disponible; apps en resumen; CSV diario con primer ingreso y último evento | Dispositivo, versión y conexión visibles dentro del resumen de actividad. CSV en subpestaña Actividad. Franjas desconocidas se conservan como `null` / «Sin dato». Focus conserva la fórmula v4. Promedio mensual por día con actividad | Detalle de episodios/títulos de ventanas y CSV de episodios; gauge cualitativo de focus; banner individual de alertas |
| Productividad `/reportes.php` | Consulta inicial visible; atajo Hoy; ranking individual ordenable por persona/área/productividad/focus/constancia/trabajo profundo/cambios/días; ranking por área; ocho semanas; CSV de ranking | Área actual, no reconstrucción de movimientos históricos. Tendencia = ocho bloques de siete días hasta la fecha final, promedio de días medidos. Constancia solo cuando existe en `focus_daily`; el cálculo nativo actual de v4 deja ese campo nulo | Selector Firma/Área, flechas de variación y edición de pesos de focus |
| Equipos `/equipos.php` | Total/activos/obsoletos/bajas; búsqueda por equipo/persona/versión; filtros de estado y anillo; gráfica de versiones; selección por equipo o todos los filtrados; cambio por lote; alerta de pausados y motivo | Activo reciente: hasta 30 días, igual que K3; versiones excluyen obsoletos y bajas. Lote de PATCH individuales con resultado de éxitos/fallos; no transacción global | Sin pendientes de los requisitos obligatorios de esta pantalla; revisión visual pendiente |
| Ficha de equipo `/equipo.php?id=…` | «Actualizaciones: Automáticas / Primero (pruebas) / Pausadas»; edición para plataforma; motivo visible | Se conserva el resto de la ficha v4. Se añadió la ruta al router local, que antes respondía 404 | Sin pendiente funcional del control de anillos |
| Versiones `/admin/releases.php` | Publicar una versión registrada en pruebas y ampliarla después a stable, por empresa y porcentaje | Usa releases previamente registradas y firmadas; conserva el flujo de registro de releases y mudanzas existente | No se añade carga/firma de binarios desde esta pantalla |
| Doble empleo `/doble-empleo.php` | Persona por nombre; período; filtros pendiente/revisada y prioridad; marcar revisada; auditoría y alcance | La revisión es una marca, no un dictamen laboral. Se conserva evidencia y notas existentes | Resultado productivo/improductivo, editor de notas de revisión, filtro por tipo y KPIs de prioridad de K3 |
| Cobertura `/cobertura.php` | KPIs por motivo sobre todo el alcance filtrado, recorriendo todas las páginas; filtro por sede; notas y exenciones existentes | Usa motivos de v4: instalado, sin equipo, pendiente de enrolar, sin señal reciente, exento | Motivo «versión obsoleta» y comparación con la última release; buscador específico de esta pantalla |

Sedes, Solicitudes pendientes y Políticas se leyeron como contexto y no se modificaron: no formaban parte de las pantallas a construir de este encargo.

## API y datos

| Ruta | Cambio | Seguridad / comportamiento |
|---|---|---|
| `GET /dashboard` | Implementado y habilitado en el exportador de rutas | `reportes.ver`, empresa de sesión, mismos filtros/alcances que reportes. Hoy por defecto en zona de empresa; `from` incluido / `to` excluido; máximo 31 días. 404 al filtrar una persona ajena. Roster de 20; contadores sobre todas las personas activas del alcance |
| `GET /users/{id}/activity` | Expone nueve campos de franjas, diarios y totales; constancia y días con actividad en totales | Conserva alcance y 404 de persona ajena. Sin inventar valores: si falta una franja medida, queda nula |
| `GET /reports/people` | Totales de franjas, `constancy_pct`, `days_with_activity`; admite `user_id` en el contrato | Se reutiliza en Personas y rankings. La autorización sigue en el backend |
| `PATCH /devices/{id}` | Admite `release_ring` y `release_pause_reason` | **Solo plataforma** para estos campos; conserva permisos anteriores para otros cambios. `If-Match` obligatorio. Pausado exige motivo no vacío, máximo 500 caracteres; reanudar limpia el motivo. Auditoría por equipo |
| `GET /devices/{id}` y listado de equipos | Devuelven anillo y motivo | Alcance existente de equipos/personas |
| `POST /release-deployments` | Contrato y UI limitan publicación a `pruebas` o `stable` | Conserva autorización e idempotencia. La misma versión puede tener despliegues en ambos anillos |
| Descarga/sync de releases del agente | `Resources::release()` devuelve `null` para `pausado` | Bloquea tanto la oferta en sync como la descarga por ID, incluso si existe un despliegue antiguo para un anillo llamado pausado. El equipo puede seguir sincronizando |
| `PATCH /reports/dual-job-alerts/{id}` | Nuevo: `{ "reviewed": true }`, respuesta 204 | `cumplimiento.editar`, CSRF, alcance propio/equipo/todo, 404 fuera del alcance o empresa; auditoría. No permite reabrir silenciosamente |
| `GET /reports/dual-job-alerts` | Filtros `reviewed=0/1` y `severity` | Se combinan con el alcance y el período existentes |
| `GET /devices/install-coverage` | Filtro `site_id` | Sede del tenant y alcance autorizado; se combina con el alcance de personas |

No se implementó `GET /users/{id}/dashboard`: no fue necesario. La ficha usa `/activity`, apps y datos existentes, con sus permisos actuales.

El Dashboard comparte esquema con la API externa. Las propiedades nuevas se declararon opcionales para no romper sus respuestas. `data_through` queda nulo en el tablero nuevo: no se afirma una marca de consolidación que la consulta no calcula. `generated_at` sí fecha la consulta.

La conexión representa la última señal de un equipo activo, no actividad humana. Las llamadas ya están incluidas en el tiempo en uso; la entrada simulada está incluida en inactivo. No se vuelven a sumar.

## Migración y archivos para un despliegue posterior

**No ejecutado en ningún servidor.** La migración se aplicó únicamente en las bases temporales del smoke.

- `backend-v4/migrations/0058_device_release_pause.sql`: agrega `devices.release_pause_reason VARCHAR(500) NULL`. Conserva los anillos actuales.
- Backend: `src/ActivityReports.php`, `src/AdminApi.php`, `src/OperationsApi.php`, `src/Resources.php` y `config/contract.json`.
- Panel: `public/index.php`, `public/assets/app.js`, `coordinator.js`, `k3.js`, `operations.js`, **nuevo** `fleet.js` y `k3.css`.
- Contrato fuente: `docs/architecture/openapi-v4.yaml`; exportador: `backend-v4/tests/export-contract.py`.
- Generados: `client-v4/src/Keeper.Shared/Policy/Policy.g.cs` y `Protocol/Protocol.g.cs`. Solo cambió el hash del contrato; no se necesita una nueva versión del agente para estas pantallas o anillos.

Aplicar la migración antes de instalar el backend que lee la nueva columna. En una futura puesta en producción deben ir juntos backend, contrato exportado y JS. Las mudanzas firmadas de servidor existentes no se modificaron; la pausa bloquea versiones, incluida la actualización que un agente antiguo pudiera necesitar para mudarse.

## Verificación

| Comprobación | Resultado |
|---|---|
| MySQL aislado, migraciones, replay y smoke completo | **SMOKE PASS: 3817 comprobaciones**, código de salida 0. Incluye agregación del tablero con varios equipos de la misma persona. Procesos y bases temporales eliminados |
| API nueva y ampliaciones | Pruebas de alcance propio/equipo/todo, 404 fuera de alcance/tenant, falta de permiso, rangos, sin datos, franjas nulas y medidas, revisión persistente/auditada |
| Anillos | Motivo obligatorio/blanco, enum inválido, permisos, conflicto de versión, publicación en pruebas, promoción a stable, pausa de sync/descarga, reanudación y auditoría |
| Contrato | Exportación correcta: **152 rutas / 239 esquemas**; `npm run generate` y `npm run check` correctos |
| JavaScript | `node --check --input-type=module` correcto para los cinco módulos modificados/nuevos |
| PHP | `php -l` correcto en los archivos nuevos/modificados de implementación y pruebas nuevas |
| .NET | **592 Agent + 154 Bootstrapper**, 0 fallos, 0 omitidas. Sin cambios en `packages.lock.json` |
| DOM del panel real | **9 vistas correctas**; HTML servido por PHP, módulos reales, respuestas API sintéticas. Verifica renderizado, recuperación de un rango inválido, contenido del CSV diario, pausa, publicación en pruebas/stable y revisión de alerta |
| Navegador / capturas | **Bloqueado**: Chromium `spawn EPERM`. No hay capturas generadas ni validación de reflow, CSS o teclado nativo |
| Git | **0 commits**: `.git/index.lock` no se puede crear por permisos del entorno. Sin push |

La primera invocación .NET con servidores reutilizados quedó sin salida y se canceló. La ejecución que terminó correctamente fue:

```powershell
dotnet test client-v4/Keeper.sln --no-restore --disable-build-servers -m:1 -v:minimal
```

El smoke se ejecutó desde `backend-v4` con los binarios de Laragon:

```powershell
$env:PATH = 'C:\laragon\bin\mysql\mysql-8.4.3-winx64\bin;C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64;' + $env:PATH
powershell -NoProfile -File tests/mysql-smoke.ps1 -DebugResponses
```

Se corrigió una prueba antigua de archivado de empresas en `tests/group-structure.php`: asumía que todas las empresas cabían en la primera página. Las nuevas fixtures hicieron visible esa suposición; ahora recorre los cursores sin debilitar la comprobación de archivadas.

Los tests nuevos están en `k3-dashboard.php`, `k3-updates.php`, `k3-dom.cjs`, `k3-panel.cjs` y `k3-panel-fixture.cjs`. Las franjas y métricas se verifican también en `locked-system.php`.

## Reproducir DOM y capturas pendientes

En una terminal, desde `backend-v4`:

```powershell
php -S 127.0.0.1:8780 -t public public/index.php
```

Desde la raíz del repo:

```powershell
$env:JSDOM_MODULE = 'C:/Users/FumiWork/proyectos/portal-azc/node_modules/jsdom'
node --experimental-vm-modules backend-v4/tests/k3-dom.cjs

$env:KEEPER_PLAYWRIGHT_MODULE = 'C:/Users/FumiWork/AppData/Local/Temp/claude/C--Users-FumiWork/940c289c-56f1-42c7-9403-51f24a16b3c1/scratchpad/hs/node_modules/playwright'
node backend-v4/tests/k3-panel.cjs
```

El harness de Playwright está preparado para generar `inicio.png`, `personas.png`, `ficha.png`, `productividad.png`, `equipos.png`, `equipo.png`, `versiones.png`, `doble.png` y `cobertura.png` en `docs/design/2026-10-13-capturas-k3/`. **Son nombres previstos, no capturas existentes.** Los datos están identificados como prueba. Comparar después con K3; la prueba DOM no sustituye esa comparación.

## Commits pendientes

No se intentó eludir la restricción de `.git`. Todos los cambios quedan en el árbol de trabajo. `artifacts/` y `graphify-out/` ya existían sin seguimiento al inicio y no se modificaron.

Separación lógica solicitada para una sesión con escritura en Git:

1. `Recupera Inicio de K3 con tablero y alcance de reportes`.
2. `Muestra actividad y conexión de hoy en Personas`.
3. `Completa la ficha con franjas, resumen mensual y CSV diario`.
4. `Añade rankings y tendencia de ocho semanas a Productividad`.
5. `Completa Equipos y controla actualizaciones por anillo` (incluye migración, ficha y publicación en Versiones).
6. `Permite filtrar y marcar revisadas las alertas de doble empleo`.
7. `Añade indicadores de cobertura y filtro por sede`.

Hay archivos compartidos entre pantallas: requieren selección de cambios, no un `git add` global por cada mensaje. El trailer pedido para cada commit es:

```text
Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
```

Pendiente para dar el encargo por cerrado: revisar capturas reales y crear los commits por pantalla. Sin autorización nueva de despliegue ni push.
