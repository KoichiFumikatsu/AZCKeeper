# Arquitectura del panel Keeper

Fecha: 2026-10-13. Rama: `feature/keeper-v4`. Base del árbol: `8c571d3`, posterior al panel `0fa8dd3`.

Cambios en el panel PHP real y su API. Sin commits, push, SSH ni despliegue. Se revisaron las capturas anteriores de Inicio, Personas y Ficha; no se generaron capturas nuevas.

## Implementación

| Pantalla | Resultado |
|---|---|
| Inicio | Activos/total de trabajadores, sedes activas/total, llegadas a tiempo/tarde/temprano/sin actividad; productividad y focus de hoy con consulta cada 60 segundos mientras la página está visible. Se conserva el selector de período para los demás informes. Problemas accionables con enlaces a persona, equipo o Salud. |
| Personas | Período Hoy/7 días/Mes/rango, última actividad en ese período, tiempo trabajado, Ver y Editar por fila. Edición en lote conservada. |
| Editar persona | Estado laboral: contratado, retirado, licencia, vacaciones e incapacidad. Retirar inactiva y revoca equipos, sesiones y órdenes igual que el retiro existente. Campos gestionados por una fuente deshabilitados e identificados por origen; actualización individual desde la fuente. Contraseña elegida para cuentas existentes, con reautenticación y cambio obligatorio al entrar; enlace para crear cuenta cuando no existe. |
| Ficha / Actividad | Ventanas agrupadas por app/sitio, uso, tiempo al frente sin uso y visitas; orden ascendente/descendente por las cinco columnas. Cronología por día, inicio/fin y app/sitio, paginada. Ninguno de los dos endpoints selecciona ni devuelve títulos. |
| Ficha / Controles | Equipos con versión, última conexión, K3, Smart App Control y controles declarados. Actualizaciones por anillo y acciones remotas compartidas con la ficha de equipo: bloquear, desbloquear, reiniciar, apagar y borrar. Borrado conserva nombre escrito y reautenticación; las acciones respetan los permisos existentes. |
| Horarios | Margen de puntualidad de empresa, inicialmente 15 minutos, y selector de horario predeterminado. Respaldo tanto para horario nulo como para el marcador «Sin horario», sin reescribir asignaciones. Se aplica al calendario, cálculo y horario enviado al agente. |
| Administración | Menú Principal / Administración / Plataforma, ocultación por permisos y Plataforma solo para superadmin. Administradores reúne cuentas, roles y un interruptor «Puede entrar al Portal» por persona de la empresa seleccionada. El interruptor solo guarda el permiso futuro; no habilita autenticación de Portal ni acceso al panel. |
| Salud del servidor | Estado del cálculo, última ejecución y mensaje del último fallo, recortado a 1.000 caracteres y conservado en base de datos. Se limpia tras una ejecución correcta. |

Conexión significa señal del agente: en línea hasta 5 minutos, ausente hasta 15 y desconectado después. El refresco cada minuto consulta los agregados disponibles; no acelera el cron ni convierte la señal del agente en prueba de actividad humana.

El margen incluye ambos límites: con entrada a las 08:00 y margen 15, 07:45–08:15 cuenta a tiempo; 07:44:59 es temprano y 08:15:01 es tarde. Cambiar los ajustes encola exactamente 30 días locales, incluido hoy. El histórico de márgenes permite conservar la clasificación anterior fuera de ese intervalo; el histórico anterior a esta migración conserva la regla antigua sin margen. Los agregados importados reciben la actualización de jornada correspondiente sin sustituir su actividad por datos vacíos.

## API

| Ruta | Autorización y comportamiento |
|---|---|
| `GET /dashboard/problems` | `reportes.ver`, alcance de personas. Problemas de equipos intersectados además con `equipos.ver`; cumplimiento con `cumplimiento.ver`. Fallo del cálculo solo para plataforma. |
| `GET /users/{id}/windows?from&to` | `reportes.ver`; propio/equipo/filtros/todo, 404 fuera de alcance o empresa. Máximo 31 días, fin excluido, retención de episodios. Duraciones recortadas proporcionalmente a los límites locales del período. Solo app/sitio y tres medidas. |
| `GET /users/{id}/timeline?day&limit&offset` | Mismo alcance y retención; orden cronológico estable, segmentos recortados al día local. Páginas de hasta 500 filas. Sin títulos. |
| `GET /schedules/settings` | `horarios.ver`, toda la empresa. Margen y horario predeterminado. |
| `PUT /schedules/settings` | `horarios.editar`, toda la empresa, CSRF y auditoría. Margen 0–180, horario del mismo tenant y distinto del marcador vacío. Cola de recálculo y recompilación de políticas. |
| `POST /users/{id}/refresh-source` | `usuarios.editar`, toda la empresa, persona del alcance vinculada a una fuente activa dueña de alguno de sus dominios. Cuerpo `source_id`; auditoría y registro de sincronización. Respeta simulación y aprobación pendiente de dominios; solo modifica campos de esa fuente. |
| `PUT /users/{id}/portal-access` | `usuarios.editar`, toda la empresa, autogestión de accesos contratada o plataforma. Cuerpo booleano `portal_login_enabled`; CSRF y auditoría. |
| `GET /dashboard` | Añade sedes activas y total. Sedes reales activas del alcance; con alcance completo incluye sedes sin personas. |
| `GET /users`, `GET/PATCH /users/{id}`, `POST /users` | Estado laboral; lecturas incluyen permiso de Portal, horario efectivo y dueños de dominios sin credenciales de integración. Retiro mantiene la invalidación existente. |
| `POST /panel-accounts/{id}/reset-password` | Conserva el uso anterior sin cuerpo y añade contraseña elegida opcional, 12–128 caracteres. Mantiene autorización, delegación, reautenticación y revocación de sesiones. |
| `GET /tenants/server-health` | Añade `cron_last_error`; mantiene acceso de plataforma. |

Contrato fuente: `docs/architecture/openapi-v4.yaml`. Exportación con `backend-v4/tests/export-contract.py`; generación y comprobación de `client-v4/tools`. No se editó manualmente el contrato JSON generado.

## Decisiones basadas en el modelo real

- «Sin horario» es un registro `placeholder`, además del posible valor nulo. El respaldo reconoce ese indicador, no el nombre visible.
- La migración selecciona el horario existente por zona `America/Bogota` y sus cinco jornadas efectivas: L–J 08:00–17:30, V 08:00–17:00. Contempla días que heredan las horas generales. No usa UUID ni nombre fijo; si no hay coincidencia, el administrador debe elegirlo en Horarios.
- Las ausencias solo tienen estado aprobada/cancelada. No se inventó una bandeja de aprobación.
- La revisión de apps sospechosas pertenece a las alertas de cumplimiento generadas a partir de las detecciones. El feed usa esas alertas pendientes; una detección no tiene una revisión independiente. La revisión sigue en Doble empleo.
- El conector de origen disponible pagina una nómina y no ofrece lectura individual. La actualización individual lee esa nómina y aplica exclusivamente la persona vinculada; no retira personas ausentes, no importa catálogos y no adelanta el cursor global. Referencias nuevas o cambios que requieren revisión se conservan para la sincronización administrada, sin aplicar parcialmente la ficha.
- Las horas trabajadas y la última vez en uso de Personas corresponden al período elegido; no son hora de login ni última conexión.

## Migración y despliegue posterior

Nueva `backend-v4/migrations/0059_panel_architecture.sql`: margen y horario predeterminado en `tenants`, historial `punctuality_margins`, `employment_status` y `portal_login_enabled` en `users`, `last_error` en `productivity_cron_status`. Inicializa los retirados, selecciona el horario coincidente y encola 30 días; marca las políticas para recompilar cuando hay predeterminado. La purga de empresa anula su referencia al horario antes de eliminarlo.

Para un despliegue posterior, aplicar 0059 antes del código y publicar juntos:

- `backend-v4/src/`: `ActivityCalendar.php`, `ActivityReports.php`, `AdminApi.php`, `DataSync.php`, `OperationsApi.php`, `PanelAccounts.php`, **nuevo** `PanelActivity.php`, `PolicyCompiler.php`, `ProductivityWorker.php`, `Request.php`, **nuevo** `ScheduleSettings.php`, `TenantPurge.php`.
- `backend-v4/config/contract.json`.
- `backend-v4/public/assets/`: `app.js`, `coordinator.js`, **nuevo** `device-actions.js`, `k3.js`, `nav-access.js`; `public/cuentas.php` y `public/partials/sidebar.php`.

`device-actions.js` extrae la lógica remota existente de `app.js`; ambos accesos usan el mismo diálogo y envío. Los import maps ya descubren automáticamente los módulos JS. No hace falta un binario nuevo del agente: los dos C# generados solo cambian el hash del contrato.

## Verificación

| Comprobación | Resultado |
|---|---|
| Smoke PHP con MySQL 8.4 aislado | **3.967 comprobaciones, PASS**. Procesos y directorio de datos de prueba eliminados por el runner. |
| .NET | **592 Agent + 154 Bootstrapper**, sin fallos ni omisiones. `packages.lock.json` sin cambios. |
| DOM del panel real con API simulada | **13 vistas, PASS**. Incluye orden de ventanas, cronología, controles, confirmación escrita de borrado, margen, Portal y estado laboral. |
| Contrato | Exportación, generación y comprobación correctas: **158 rutas y 249 esquemas**. |
| Sintaxis | `php -l` en los PHP modificados/nuevos y `node --check` en los JS/CJS modificados/nuevos: correctos. |
| Árbol | `git diff --check` correcto. Cambios sin commit; sin push ni despliegue. |

El smoke cubre alcance propio/equipo/todo, aislamiento entre empresas y 404, privacidad de ventanas y cronología, límites de puntualidad al segundo, respaldo del horario, recálculo de 30 días, conservación histórica, retiro y revocación, exclusión de retirados en llegadas de hoy, permiso de Portal sin habilitar el panel, actualización individual desde fuentes con propiedad parcial y persistencia del fallo del cálculo.

Comandos ejecutados desde la raíz, con PHP y MySQL de Laragon en `PATH`:

```powershell
powershell -NoProfile -File backend-v4/tests/mysql-smoke.ps1 -DebugResponses
python backend-v4/tests/export-contract.py
npm --prefix client-v4/tools run generate
npm --prefix client-v4/tools run check
dotnet test client-v4/Keeper.sln --no-restore --disable-build-servers -m:1 -v:minimal
node --experimental-vm-modules backend-v4/tests/k3-dom.cjs
```

El ensayo DOM usa el panel servido en `http://127.0.0.1:8780` y JSDOM ya instalado en la máquina; no sustituye la revisión visual. No se ejecutó Chromium, conforme al encargo. `tests/k3-panel.cjs` queda preparado para capturar 16 pantallas, incluidas ventanas, línea de tiempo, controles, edición, Horarios, Administradores y Salud, en `docs/design/2026-10-13-capturas-arquitectura/`. Para tomar las capturas, servir `backend-v4/public` con PHP en ese puerto y ejecutar `node backend-v4/tests/k3-panel.cjs`; `KEEPER_PLAYWRIGHT_MODULE` permite indicar la instalación de Playwright disponible. Se conservan las capturas anteriores.

La política de ejecución rechazó el comando final que cerraba el servidor PHP de pruebas (PID 9456, puerto 8780) y eliminaba los temporales de `.validation/`, sin detallar la causa. Ambos quedan pendientes de limpieza local; los logs incluyen `panel-smoke-final.log`, `panel-dotnet.log` y `panel-dom.log`. No incluir `.validation/` en los commits. El runner sí eliminó su MySQL aislado. Los directorios preexistentes `artifacts/` y `graphify-out/` no se modificaron.

## Grupos para commits posteriores

Los archivos compartidos necesitan selección de fragmentos, no añadir el archivo completo en cada grupo.

1. **Horarios y estado laboral:** 0059; `ScheduleSettings.php`, `ActivityCalendar.php`, `ActivityReports.php`, `ProductivityWorker.php`, `PolicyCompiler.php`, `TenantPurge.php`; partes de `AdminApi.php`, `k3.js` y contrato; `tests/panel-architecture.php` y registro en `tests/smoke.php`.
2. **Inicio y problemas:** `PanelActivity.php`, partes de `ActivityReports.php`, `AdminApi.php`, `coordinator.js` y contrato; `tests/k3-dashboard.php`.
3. **Personas y edición desde origen:** `DataSync.php`, `PanelAccounts.php`, `Request.php`; partes de `AdminApi.php`, `k3.js` y contrato; `tests/data-sync.php`, `tests/panel-accounts.php`.
4. **Ficha, ventanas y controles:** `device-actions.js`, extracción en `app.js`; partes de `PanelActivity.php`, `coordinator.js`, `AdminApi.php` y contrato.
5. **Navegación y Portal:** `sidebar.php`, `cuentas.php`, `nav-access.js`; partes de `k3.js`, `AdminApi.php` y contrato.
6. **Salud del cálculo:** partes de `ProductivityWorker.php`, `OperationsApi.php`, `k3.js` y contrato.
7. **Evidencia y generados:** `tests/k3-panel-fixture.cjs`, `tests/k3-panel.cjs`, `tests/k3-dom.cjs`, este reporte y los archivos generados de contrato/C# correspondientes al conjunto final.

Sin dudas de producto pendientes para lo implementado. La validación visual en Chromium queda para el usuario, como establece el encargo.
