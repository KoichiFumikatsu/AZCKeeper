# Encargo: las pantallas de K3, con el estilo de v4, en el panel real

Fecha: 2026-10-13. Repo: C:\Users\FumiWork\Documents\AZCKeeper, rama `feature/keeper-v4`. Idioma de la interfaz y commits: español.

## Por qué
El dueño (Koichi) pidió hace semanas que el panel v4 use las PANTALLAS de K3 (orden, contenido, KPIs, tablas, filtros, fichas) con el ESTILO de v4. Se hizo un prototipo estático (`docs/design/2026-09-29-panel-k3-v4/`) y se adoptó la navegación de K3 (`backend-v4/public/partials/layout-k3.php`, `assets/k3.js`), pero el CONTENIDO de las pantallas sigue siendo el de v4: a día de hoy él no ve el cambio. Un inventario (scratchpad, copiado en este repo como `docs/design/2026-10-13-paridad-k3-v4.md`) midió: de 65 elementos que K3 mostraba a un supervisor, 30 faltan y 15 son parciales.

## Fuentes que debes leer
- K3 (lo que hay que reproducir): `AZCKeeper_Client/Web/public/admin/*.php` (index, users, user-dashboard, productivity, devices, sedes-dashboard, dual-job-alerts, install-coverage, pending-users, policies) y sus partials/JS embebidos.
- Paridad pantalla por pantalla y top 15 de brechas: `docs/design/2026-10-13-paridad-k3-v4.md`.
- Panel v4 vivo: `backend-v4/public/*.php`, `backend-v4/public/assets/{k3.js,app.js,coordinator.js,...}`, estilos `assets/styles.css`. API: `docs/architecture/openapi-v4.yaml` (catálogo legible: `docs/design/2026-10-13-catalogo-api-v4.md`).
- OJO: `GET /dashboard` y `GET /users/{id}/dashboard` están en el contrato pero NO implementados (404). `coordinator.js` tiene una rama `users` con métricas que es código muerto (Personas usa `k3.js::people()`).

## Qué construir (por pantalla, en este orden, un commit por pantalla)
1. **Inicio** = Dashboard de K3: KPIs (en línea / ausente / desconectado ahora, horas de hoy, productividad, focus), «Personal activo ahora» (persona y equipo), primer ingreso de hoy, selector Hoy / 7 días / Mes / rango. Implementa `GET /dashboard` en el backend (alcance del usuario, mismo patrón que `/reports/*`) siguiendo el esquema del contrato.
2. **Personas** = Usuarios de K3 («Tu personal activo»): tarjetas o tabla con estado en vivo, primer ingreso de hoy, tiempo de hoy, % productivo, focus, filtros por empresa/sede/área y buscador. Reutiliza `/reports/people` + `/reports/presence` (o lo que implementes en 1).
3. **Ficha de persona** = user-dashboard de K3: período libre, resumen del mes, desglose horario laboral / almuerzo / fuera de horario / llamadas (el agente ya lo envía en el snapshot: exponerlo en `/users/{id}/activity` si falta), en uso / inactivo / bloqueado / simulado, top de apps, CSV diario con primer ingreso y último evento. Implementa `GET /users/{id}/dashboard` si lo necesitas.
4. **Productividad** = productivity.php de K3: ranking individual ordenable (área, productividad, focus, constancia, trabajo profundo, cambios, días), ranking por área, tendencia de 8 semanas.
5. **Equipos** = devices.php de K3: KPIs total / activos / obsoletos / dados de baja, buscador, filtro de estado, gráfica de versiones. **Y control de actualizaciones por equipo** (nuevo, ver abajo).
6. Si alcanza: Doble empleo usable (persona por nombre, filtros, marcar revisada) y Cobertura (KPIs por motivo, filtro por sede).

## Control de actualizaciones por equipo (obligatorio, dentro del punto 5)
- Hoy: `devices.release_ring` (todos 'stable') y `release_deployments` por anillo (`backend-v4/src/Resources.php::release()`); no hay forma de cambiar el anillo de un equipo.
- Construir: `PATCH /devices/{id}` (o ruta propia) acepta `release_ring` ∈ {`stable`, `pruebas`, `pausado`} con permiso de plataforma o uno nuevo `equipos.actualizaciones`; cambio por lote desde la lista; auditoría. `pausado` exige motivo (texto) y queda visible en la lista; el panel muestra una alerta con los equipos pausados (porque un equipo pausado no recibe versiones, incluida la de mudanza a producción).
- En la ficha del equipo: «Actualizaciones: Automáticas / Primero (pruebas) / Pausadas».
- En la página de versiones (releases): poder publicar una versión solo al anillo `pruebas` y luego ampliarla a `stable` (`POST /release-deployments` ya existe; verifica que acepte otro anillo).
- Contrato: editar SOLO `docs/architecture/openapi-v4.yaml`, luego `python backend-v4/tests/export-contract.py` y `cd client-v4/tools && npm run generate && npm run check`.

## Reglas de estilo y UX (del dueño)
Fondo siempre claro, paleta índigo de v4, nada dark/neón; «que un tonto pueda usarla sin perderse»: acción primaria clara por pantalla, estados vacíos y de error explícitos, confirmación escrita para acciones destructivas. Etiquetas en español llano. Los textos de K3 son la referencia de qué se muestra; el aspecto es el de v4.

## Pruebas obligatorias
- PHP: desde backend-v4 `export PATH="$(ls -d /c/laragon/bin/mysql/mysql-8.4.3*)/bin:$(ls -d /c/laragon/bin/php/php-8.3.30*):$PATH" && powershell -NoProfile -File tests/mysql-smoke.ps1 -DebugResponses` (línea base SMOKE PASS 3702). Añade pruebas para cada endpoint nuevo (alcance propio/equipo/todo, 404 fuera de alcance) y para el cambio de anillo.
- `node --check --input-type=module < archivo.js` en cada JS tocado.
- Si cambias C#: `dotnet test client-v4/Keeper.sln` (base Agent 592, Bootstrapper 154) y luego `git checkout -- '**/packages.lock.json'`.
- Panel en navegador: hay harness Playwright en `C:\Users\FumiWork\AppData\Local\Temp\claude\C--Users-FumiWork\940c289c-56f1-42c7-9403-51f24a16b3c1\scratchpad\hs\node_modules\playwright`; servir con `php -S 127.0.0.1:8780 -t public public/index.php` desde backend-v4. Captura cada pantalla nueva y compárala con la de K3.

## Reglas
- No desplegar, no SSH, no push. Commits por pantalla, en español, con línea final `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.
- Reporte en `docs/design/2026-10-13-reporte-pantallas-k3.md`: por pantalla, qué elementos de K3 quedaron (igual/parcial/falta), endpoints nuevos, migraciones, archivos a desplegar, capturas.
