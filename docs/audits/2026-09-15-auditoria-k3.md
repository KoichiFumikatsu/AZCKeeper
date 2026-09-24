# Auditoría K3 de AZCKeeper — 15 de septiembre de 2026

## Base de evidencia y alcance

- Repositorio: `C:\Users\FumiWork\Documents\AZCKeeper`. Rama auditada: `feature/modulo-seguridad`; commit `931fd1283ad4f356af5a592b26909ea9bc50f368`.
- Alcance: cliente Windows K3, AZCKeeperUpdater, AZCKeeper.Tests y backend/panel `AZCKeeper_Client/Web/`. Excluidos los árboles K4 indicados por el solicitante y `Web/migrations/keeper4/`.
- Se utilizó `graphify query "<pregunta>"` desde la raíz para mapear dependencias, endpoints, timers y tablas, antes de contrastar el código. El grafo conserva clases PAC retiradas: sirve como índice, no como prueba de comportamiento ni de números de línea.
- **Referencia de las citas:** todo archivo:línea sin prefijo corresponde al commit auditado 931fd1283ad4f356af5a592b26909ea9bc50f368, consultable con git show COMMIT:ruta; no al número de línea del worktree de entrega. Al reanudar, el checkout estaba en feature/moazc-bridge, commit 1638bd493d884a0741e4131ee2d8089b5b2d9b76. El suplemento distingue esa revisión con prefijo 1638bd4:. En las secciones principales, «actual» describe la revisión base auditada. Las citas 4f6aea5: corresponden exclusivamente al commit histórico 4f6aea562c14c4b10d4b79f4d8ae8869cdba733d, consultado con git show, sin checkout. No había referencia master disponible.
- No se verificó equivalencia entre este checkout, archivos desplegados por scp y binarios instalados. **Código actual, código histórico y producción se distinguen expresamente.**
- Se aceptan como hechos facilitados por el solicitante: PHP 8.2, MySQL 8.0.40 remoto, docroot Web/public, hosting cPanel/CSF-LFD, una IP pública NAT dedicada por empresa/sede, baneo ya ocurrido, flota aproximada de 180 equipos y unos 6,9 millones de episodios. No se volvió a investigar esa premisa ni se consultó producción.
- No se leyeron secretos reales ni se ejecutaron migraciones, endpoints productivos, builds del cliente o updater. Este reporte es el único archivo creado/modificado.
- Verificación local efectuada en la fase anterior sobre la revisión auditada: **18 tests PHP aprobados, 0 fallos; 66 archivos PHP comprobados con php -l, 0 errores**. PHP CLI disponible: 8.3.30; no sustituye verificación en PHP 8.2/MySQL productivos. El runner prueba merge, saneamiento y contratos, no carga ni aislamiento (`AZCKeeper_Client/Web/tests/run.php:26`). No se ejecutaron tests .NET que generan bin/obj o estado local.
- Las tasas y tamaños son **modelos derivados del código**, no mediciones. Las recomendaciones y OpenAPI son propuestas, no funcionalidades existentes. Ausencias de archivos/call sites son resultados de búsquedas en el alcance, no afirmaciones sobre archivos externos al repo.

## 1. Resumen ejecutivo: top 10 por severidad

| # | Severidad | Hallazgo e impacto | Evidencia |
|---|---|---|---|
| 1 | **Crítico** | Reenrolamiento sin prueba de posesión: conocer el GUID de un dispositivo activo permite solicitar un bearer de su usuario. El límite por IP no corrige la autenticación. | `AZCKeeper_Client/Web/src/Endpoints/ClientReEnroll.php:35`, `AZCKeeper_Client/Web/src/Endpoints/ClientReEnroll.php:56`, `AZCKeeper_Client/Web/src/Endpoints/ClientReEnroll.php:103`, `AZCKeeper_Client/Web/src/Endpoints/ClientReEnroll.php:120`. |
| 2 | **Crítico** | No hay frontera de tenant uniforme: handshake reasigna dispositivos ajenos y varias operaciones administrativas consultan/mutan por ID sin comprobar empresa. | `AZCKeeper_Client/Web/src/Endpoints/ClientHandshake.php:77`; `AZCKeeper_Client/Web/public/admin/users.php:54`; `AZCKeeper_Client/Web/public/admin/devices.php:19`; `AZCKeeper_Client/Web/public/admin/admin_auth.php:150`. |
| 3 | **Crítico** | Updater descarga ZIP desde URL administrable y ejecuta el updater contenido en él sin comprobar firma ni hash contra una fuente confiable. Impacto potencial sobre la flota con privilegios del usuario. | `AZCKeeper_Client/Update/UpdateManager.cs:218`, `AZCKeeper_Client/Update/UpdateManager.cs:235`, `AZCKeeper_Client/Update/UpdateManager.cs:238`, `AZCKeeper_Client/Update/UpdateManager.cs:259`; `AZCKeeper_Client/Web/public/admin/releases.php:24`. |
| 4 | **Alto** | Amplificación HTTP: handshake reinicia comprobación de updates; defaults iniciales dan actividad cada 10 s y handshake efectivo cada 120 s; recuperación offline fragmenta lotes. Modelo activo: 4,4–9 solicitudes/min/equipo antes de logs y backlog. | `AZCKeeper_Client/Core/CoreService.cs:615`; `AZCKeeper_Client/Update/UpdateManager.cs:73`; `AZCKeeper_Client/Config/ConfigManager.cs:278`; `AZCKeeper_Client/Network/ApiClient.cs:1023`. |
| 5 | **Alto** | Revisiones divergentes: 931fd12 retiró PAC; checkout de entrega 1638bd4 lo conserva y añade bridge. Falta identificar el artefacto productivo. No se demostró request remoto por URL bloqueada. | `AZCKeeper_Client/Blocking/WebBlockingManager.cs:14`, `AZCKeeper_Client/Blocking/WebBlockingManager.cs:44`, `AZCKeeper_Client/Blocking/WebBlockingManager.cs:81`; contraste histórico en §5.4. |
| 6 | **Alto** | Instalación desde cero no reproducible ejecutando los SQL entregados: falta tabla de enrolamientos, hay ALTER redundantes, faltan seeds obligatorios y existe una migración destructiva legacy. | `AZCKeeper_Client/Web/src/Repos/PendingEnrollmentRepo.php:24`; `AZCKeeper_Client/Web/public/admin/pending-users.php:87`; `AZCKeeper_Client/Web/src/Endpoints/ClientHandshake.php:90`; `AZCKeeper_Client/Web/migrations/keeper_org_independence.sql:167`. |
| 7 | **Alto** | Revocación incompleta: login crea sesiones sin expiración; bearer no comprueba estado actual del usuario; inactivarlo no revoca sesiones de cliente. | `AZCKeeper_Client/Web/src/Endpoints/ClientLogin.php:153`; `AZCKeeper_Client/Web/src/Repos/SessionRepo.php:9`; `AZCKeeper_Client/Web/public/admin/users.php:212`. |
| 8 | **Alto** | Autorización administrativa/CSRF inconsistentes: acceso al módulo puede permitir acciones globales; force-handshake exige únicamente sesión administrativa. | `AZCKeeper_Client/Web/public/admin/policies.php:19`; `AZCKeeper_Client/Web/public/admin/policies_diagnostic_backup.php:25`; `AZCKeeper_Client/Web/src/Endpoints/ForceHandshake.php:10`; `AZCKeeper_Client/Web/public/admin/admin_auth.php:274`. |
| 9 | **Alto** | PIN de desbloqueo guardado en configuración y escrito en logs; redacción remota no lo contempla y panel de logs es global. | `AZCKeeper_Client/Config/ConfigManager.cs:416`; `AZCKeeper_Client/Core/CoreService.cs:551`, `AZCKeeper_Client/Core/CoreService.cs:561`; `AZCKeeper_Client/Web/src/Endpoints/ClientLogBatch.php:113`; `AZCKeeper_Client/Web/public/admin/logs.php:47`. |
| 10 | **Alto** | Crecimiento/cálculo sin control suficiente: episodios sin idempotencia ni retención operativa identificada; panel con lecturas completas y cron global de cinco minutos sin checkpoint. | `AZCKeeper_Client/Web/src/Endpoints/WindowEpisodeBatch.php:149`; `AZCKeeper_Client/Web/public/admin/user-dashboard.php:329`; `AZCKeeper_Client/Web/src/Endpoints/ProductivityCron.php:58`; `AZCKeeper_Client/Web/migrations/keeper_full_schema.sql:434`. |

**Prioridad de decisión:** corregir autenticación, aislamiento y actualización antes de abrir el sistema a empresas independientes; reducir tráfico antes de ampliar flota; consolidar esquema/despliegue antes de migrar infraestructura. Un VPS aumenta margen operativo, pero conserva esos defectos si solo se traslada el código.

## 2. Pregunta 1 — Organización del endpoint/API

### 2.1 Arquitectura, validación y errores

El front controller lee PATH_INFO/REQUEST_URI, normaliza /index.php, APP_BASE_URL y API_PREFIX (default /api), y despacha una tabla explícita con 404/405. No publica versión de API ni OPTIONS. Bootstrap importa manualmente repositorios, servicios y todos los endpoints. La separación nominal existe, pero handlers y páginas también contienen SQL, autenticación y lógica de negocio: reutilizar casos de uso para terceros exige separarlos (AZCKeeper_Client/Web/public/index.php:7, AZCKeeper_Client/Web/public/index.php:13, AZCKeeper_Client/Web/public/index.php:20, AZCKeeper_Client/Web/public/index.php:26, AZCKeeper_Client/Web/public/index.php:44, AZCKeeper_Client/Web/public/index.php:66; AZCKeeper_Client/Web/src/bootstrap.php:15, AZCKeeper_Client/Web/src/bootstrap.php:34; AZCKeeper_Client/Web/src/Endpoints/ActivityDay.php:104).

Http::jsonInput lee todo el cuerpo antes de los límites particulares y convierte vacío/JSON inválido en array vacío. No hay límite global de bytes ni validación central de Content-Type. El bearer admite Authorization, cabecera redirigida y X-Auth-Token. Hay validadores comunes de fechas, enteros, escape HTML y dominios, pero su uso no es uniforme (AZCKeeper_Client/Web/src/Http.php:19, AZCKeeper_Client/Web/src/Http.php:28; AZCKeeper_Client/Web/src/InputValidator.php:15, AZCKeeper_Client/Web/src/InputValidator.php:38, AZCKeeper_Client/Web/src/InputValidator.php:64, AZCKeeper_Client/Web/src/InputValidator.php:92).

**Alto — límites/errores heterogéneos:** no hay capturador global de Throwable alrededor del despacho; WindowEpisode y batch pueden devolver detalle SQL. El límite de actividad de 16 KiB se aplica después de deserializar. Logs limita registros/mensaje, pero no meta. Propuesta: límite webserver y aplicación, error uniforme con correlation ID, detalles solo en logs restringidos (AZCKeeper_Client/Web/public/index.php:66; AZCKeeper_Client/Web/src/Endpoints/WindowEpisode.php:125; AZCKeeper_Client/Web/src/Endpoints/WindowEpisodeBatch.php:176; AZCKeeper_Client/Web/src/Endpoints/ActivityDay.php:92; AZCKeeper_Client/Web/src/Endpoints/ClientLogBatch.php:32, AZCKeeper_Client/Web/src/Endpoints/ClientLogBatch.php:90).

### 2.2 Inventario completo del router API

Prefijo lógico /api, configurable; la URL física puede conservar /index.php/api. R = lectura; W = inserción, actualización o borrado. **B** = bearer validado mediante keeper_sessions JOIN keeper_users; todas las filas B añaden lectura de esas tablas. Tener device_id en sesión no equivale a verificar siempre el dispositivo exacto: varios handlers comparan su propietario con el usuario (AZCKeeper_Client/Web/src/AuthService.php:16; AZCKeeper_Client/Web/src/Repos/SessionRepo.php:9).

| Método/ruta | Auth/condición | Tablas adicionales R / W | Evidencia |
|---|---|---|---|
| GET /health | Pública; no comprueba BD | Ninguna | AZCKeeper_Client/Web/public/index.php:46; AZCKeeper_Client/Web/src/Endpoints/Health.php:7. |
| GET /client/version | Pública | R keeper_client_releases | AZCKeeper_Client/Web/public/index.php:48; AZCKeeper_Client/Web/src/Repos/ReleaseRepo.php:39. |
| GET /client/activity-day | B; dispositivo propio activo; 30/min por usuario | R keeper_devices, keeper_activity_day | AZCKeeper_Client/Web/public/index.php:47; AZCKeeper_Client/Web/src/Endpoints/ActivityDay.php:188, AZCKeeper_Client/Web/src/Endpoints/ActivityDay.php:210, AZCKeeper_Client/Web/src/Endpoints/ActivityDay.php:225. |
| POST /client/login | Sin sesión previa; CAZCKeeper_Client/contraseña, legacy y fallback Keeper | R employee (legacy). R/W keeper_users, keeper_devices, keeper_user_assignments, keeper_enrollment_requests. W keeper_sessions, keeper_audit_log | AZCKeeper_Client/Web/public/index.php:52; AZCKeeper_Client/Web/src/Endpoints/ClientLogin.php:31, AZCKeeper_Client/Web/src/Endpoints/ClientLogin.php:72, AZCKeeper_Client/Web/src/Endpoints/ClientLogin.php:130, AZCKeeper_Client/Web/src/Endpoints/ClientLogin.php:153; AZCKeeper_Client/Web/src/Repos/PendingEnrollmentRepo.php:43. |
| POST /client/handshake | B; crea GUID desconocido y puede reasignar existente | R/W keeper_devices. R keeper_policy_assignments, keeper_work_schedules | AZCKeeper_Client/Web/public/index.php:51; AZCKeeper_Client/Web/src/Endpoints/ClientHandshake.php:59, AZCKeeper_Client/Web/src/Endpoints/ClientHandshake.php:67, AZCKeeper_Client/Web/src/Endpoints/ClientHandshake.php:77, AZCKeeper_Client/Web/src/Endpoints/ClientHandshake.php:87, AZCKeeper_Client/Web/src/Endpoints/ClientHandshake.php:142. |
| POST /client/activity-day | B; dispositivo propio activo | R keeper_devices. R/W keeper_activity_day (upsert) | AZCKeeper_Client/Web/public/index.php:53; AZCKeeper_Client/Web/src/Endpoints/ActivityDay.php:77, AZCKeeper_Client/Web/src/Endpoints/ActivityDay.php:104. |
| POST /client/window-episode | B; dispositivo propio activo | R keeper_devices. W keeper_window_episode | AZCKeeper_Client/Web/public/index.php:54; AZCKeeper_Client/Web/src/Endpoints/WindowEpisode.php:76, AZCKeeper_Client/Web/src/Endpoints/WindowEpisode.php:98. |
| POST /client/window-episodes/batch | B; dispositivo propio activo; máximo 50 | R keeper_devices. W keeper_window_episode | AZCKeeper_Client/Web/public/index.php:55; AZCKeeper_Client/Web/src/Endpoints/WindowEpisodeBatch.php:32, AZCKeeper_Client/Web/src/Endpoints/WindowEpisodeBatch.php:43, AZCKeeper_Client/Web/src/Endpoints/WindowEpisodeBatch.php:149. |
| POST /client/logs | B; dispositivo propio activo; máximo 50 | R keeper_devices. W keeper_client_log | AZCKeeper_Client/Web/public/index.php:56; AZCKeeper_Client/Web/src/Endpoints/ClientLogBatch.php:36, AZCKeeper_Client/Web/src/Endpoints/ClientLogBatch.php:57, AZCKeeper_Client/Web/src/Endpoints/ClientLogBatch.php:105. |
| POST /client/device-lock/unlock | B; acepta cualquier PIN no vacío | Ninguna adicional: no compara contra PIN servidor ni escribe bloqueo | AZCKeeper_Client/Web/public/index.php:57; AZCKeeper_Client/Web/src/Endpoints/DeviceLock.php:65. |
| POST /client/event | Sin auth en stub; siempre 501 | Ninguna | AZCKeeper_Client/Web/public/index.php:58; AZCKeeper_Client/Web/src/Endpoints/EventIngest.php:7. |
| POST /client/force-handshake | Cookie keeper_admin_token; no permiso de acción/scope | R keeper_admin_sessions, keeper_admin_accounts, keeper_users. W keeper_policy_assignments globales | AZCKeeper_Client/Web/public/index.php:59; AZCKeeper_Client/Web/src/Endpoints/ForceHandshake.php:10, AZCKeeper_Client/Web/src/Endpoints/ForceHandshake.php:38. |
| POST /client/re-enroll | Sin credenciales; GUID, flag y 10/min/IP | R keeper_panel_settings, keeper_users. R/W keeper_devices, keeper_audit_log. W keeper_sessions | AZCKeeper_Client/Web/public/index.php:60; AZCKeeper_Client/Web/src/Endpoints/ClientReEnroll.php:35, AZCKeeper_Client/Web/src/Endpoints/ClientReEnroll.php:43, AZCKeeper_Client/Web/src/Endpoints/ClientReEnroll.php:65, AZCKeeper_Client/Web/src/Endpoints/ClientReEnroll.php:103. |
| POST /client/security/report | B; dispositivo propio activo; hasta 100 controles | R keeper_devices. R/W keeper_security_state | AZCKeeper_Client/Web/public/index.php:61; AZCKeeper_Client/Web/src/Endpoints/SecurityReport.php:44, AZCKeeper_Client/Web/src/Endpoints/SecurityReport.php:65; AZCKeeper_Client/Web/src/Repos/SecurityStateRepo.php:20. |
| POST /cron/productivity | CRON_API_KEY en X-Cron-Key o query; global | R keeper_panel_settings, keeper_users, keeper_activity_day, keeper_window_episode, keeper_work_schedules, keeper_suspicious_apps. R/W keeper_focus_daily, keeper_dual_job_alerts. W purga keeper_client_log | AZCKeeper_Client/Web/public/index.php:62; AZCKeeper_Client/Web/src/Endpoints/ProductivityCron.php:28, AZCKeeper_Client/Web/src/Endpoints/ProductivityCron.php:66, AZCKeeper_Client/Web/src/Endpoints/ProductivityCron.php:94, AZCKeeper_Client/Web/src/Endpoints/ProductivityCron.php:119; AZCKeeper_Client/Web/src/Services/ProductivityCalculator.php:139, AZCKeeper_Client/Web/src/Services/ProductivityCalculator.php:467; AZCKeeper_Client/Web/src/Services/DualJobDetector.php:99, AZCKeeper_Client/Web/src/Services/DualJobDetector.php:291. |

GET /cron/productivity no está en el router, aunque un comentario sugiera GET. DeviceLock::getStatus existe pero no tiene ruta. Force-handshake incrementa versiones; no empuja mensajes ni adelanta timers de los equipos (AZCKeeper_Client/Web/public/index.php:44; AZCKeeper_Client/Web/src/Endpoints/DeviceLock.php:15; AZCKeeper_Client/Web/src/Endpoints/ForceHandshake.php:38).

### 2.3 Inventario completo del panel PHP

Base /admin/ dentro del docroot declarado. Son scripts directos, fuera del router API.

**P, autenticación común:** cookie validada contra keeper_admin_sessions, keeper_admin_accounts y keeper_users; roles keeper_panel_roles, ajustes keeper_panel_settings y piso histórico keeper_firmas. Una visita también puede disparar sincronización global: R employee, keeper_users, keeper_user_assignments; W keeper_user_assignments, con throttle de sesión PHP. Por tanto GET autenticado puede escribir indirectamente. Aplica a todas las filas P (AZCKeeper_Client/Web/public/admin/admin_auth.php:39, AZCKeeper_Client/Web/public/admin/admin_auth.php:53, AZCKeeper_Client/Web/public/admin/admin_auth.php:63, AZCKeeper_Client/Web/public/admin/admin_auth.php:88; AZCKeeper_Client/Web/src/LegacySyncService.php:93, AZCKeeper_Client/Web/src/LegacySyncService.php:107, AZCKeeper_Client/Web/src/LegacySyncService.php:129, AZCKeeper_Client/Web/src/LegacySyncService.php:144).

**M:** guard de acceso al módulo del menú; **A:** canDo en acciones. Ninguna abreviatura implica por sí sola comprobación del tenant del objeto. Tablas siguientes adicionales a P; “catálogos organizativos” se concreta como keeper_sociedades, keeper_firmas, keeper_areas, keeper_cargos y keeper_sedes cuando se usa ese conjunto.

| Script / método y acciones | Auth | Tablas R/W y observaciones | Evidencia |
|---|---|---|---|
| login.php GET formulario / POST login | Pública; email/password, estado de cuenta/usuario | R keeper_users, keeper_admin_accounts; W keeper_admin_sessions, last_login de keeper_admin_accounts | AZCKeeper_Client/Web/public/admin/login.php:28, AZCKeeper_Client/Web/public/admin/login.php:37, AZCKeeper_Client/Web/public/admin/login.php:47; AZCKeeper_Client/Web/src/Repos/AdminAuthRepo.php:42. |
| logout.php GET accesible, sin guard de método | Cookie si existe | W revocación keeper_admin_sessions | AZCKeeper_Client/Web/public/admin/logout.php:12. |
| index.php GET | P; scope en lectura | R keeper_users, keeper_user_assignments, keeper_devices, keeper_activity_day, keeper_window_episode, keeper_focus_daily, keeper_dual_job_alerts | AZCKeeper_Client/Web/public/admin/index.php:46, AZCKeeper_Client/Web/public/admin/index.php:58, AZCKeeper_Client/Web/public/admin/index.php:142, AZCKeeper_Client/Web/public/admin/index.php:254. |
| users.php GET lista; GET ajax=search_employee/get_user; POST create_user/toggle_admin/edit_user/delete_user | P; A en POST; AJAX no hereda scope de lista | R employee, keeper_users, keeper_devices, keeper_user_assignments, keeper_sociedades, keeper_firmas, keeper_areas, keeper_cargos, keeper_activity_day, keeper_window_episode. W keeper_users, keeper_admin_accounts | AZCKeeper_Client/Web/public/admin/users.php:19, AZCKeeper_Client/Web/public/admin/users.php:54, AZCKeeper_Client/Web/public/admin/users.php:68, AZCKeeper_Client/Web/public/admin/users.php:154, AZCKeeper_Client/Web/public/admin/users.php:176, AZCKeeper_Client/Web/public/admin/users.php:212, AZCKeeper_Client/Web/public/admin/users.php:260, AZCKeeper_Client/Web/public/admin/users.php:363. |
| user-dashboard.php GET HTML/ajax=episodes/csv/daily_csv | P + canViewUser, que omite sede | R keeper_users, keeper_user_assignments, keeper_sociedades, keeper_firmas, keeper_areas, keeper_cargos, keeper_devices, keeper_activity_day, keeper_window_episode, keeper_focus_daily, keeper_dual_job_alerts, keeper_work_schedules, keeper_panel_settings | AZCKeeper_Client/Web/public/admin/user-dashboard.php:15, AZCKeeper_Client/Web/public/admin/user-dashboard.php:36, AZCKeeper_Client/Web/public/admin/user-dashboard.php:203, AZCKeeper_Client/Web/public/admin/user-dashboard.php:220, AZCKeeper_Client/Web/public/admin/user-dashboard.php:338, AZCKeeper_Client/Web/public/admin/user-dashboard.php:362, AZCKeeper_Client/Web/public/admin/user-dashboard.php:384, AZCKeeper_Client/Web/public/admin/user-dashboard.php:443. |
| devices.php GET; POST revoke/activate/decommission/delete | P+M+A; ID de mutación sin scope | R keeper_devices, keeper_users, keeper_user_assignments, keeper_sociedades, keeper_firmas, keeper_areas, keeper_sedes. W keeper_devices; efectos FK al borrar | AZCKeeper_Client/Web/public/admin/devices.php:7, AZCKeeper_Client/Web/public/admin/devices.php:19, AZCKeeper_Client/Web/public/admin/devices.php:28, AZCKeeper_Client/Web/public/admin/devices.php:37, AZCKeeper_Client/Web/public/admin/devices.php:48, AZCKeeper_Client/Web/public/admin/devices.php:100. |
| assignments.php GET; POST save/batch_assign/delete/reset_override | P+M+A; lista y mutación con alcance diferente | R keeper_users, employee, keeper_user_assignments y catálogos organizativos. W keeper_user_assignments | AZCKeeper_Client/Web/public/admin/assignments.php:15, AZCKeeper_Client/Web/public/admin/assignments.php:42, AZCKeeper_Client/Web/public/admin/assignments.php:80, AZCKeeper_Client/Web/public/admin/assignments.php:127, AZCKeeper_Client/Web/public/admin/assignments.php:139, AZCKeeper_Client/Web/public/admin/assignments.php:204, AZCKeeper_Client/Web/public/admin/assignments.php:227. |
| organization.php GET; GET ajax=test_connection; POST create/edit/delete por entidad | P+M+A | R/W catálogos organizativos, keeper_data_sources, keeper_user_assignments al desvincular. Prueba externa SELECT 1 | AZCKeeper_Client/Web/public/admin/organization.php:23, AZCKeeper_Client/Web/public/admin/organization.php:28, AZCKeeper_Client/Web/public/admin/organization.php:142, AZCKeeper_Client/Web/public/admin/organization.php:186, AZCKeeper_Client/Web/public/admin/organization.php:218. |
| policies.php GET; POST update_global/save_user_override/remove_override/batch_apply/batch_remove/force_push/save_global_schedule/save_user_schedule/remove_user_schedule/save_leisure_apps | P+M; sin canDo específico en handlers | R/W keeper_policy_assignments, keeper_work_schedules, keeper_panel_settings. R keeper_users, keeper_user_assignments, keeper_sociedades, keeper_firmas, keeper_areas, keeper_devices | AZCKeeper_Client/Web/public/admin/policies.php:11, AZCKeeper_Client/Web/public/admin/policies.php:19, AZCKeeper_Client/Web/public/admin/policies.php:26, AZCKeeper_Client/Web/public/admin/policies.php:83, AZCKeeper_Client/Web/public/admin/policies.php:123, AZCKeeper_Client/Web/public/admin/policies.php:131, AZCKeeper_Client/Web/public/admin/policies.php:184, AZCKeeper_Client/Web/public/admin/policies.php:250. |
| policies_diagnostic_backup.php GET; POST activate/deactivate/delete/create/update/force_push | P + superadmin; csrfOk=true | R/W keeper_policy_assignments; R keeper_users, keeper_devices | AZCKeeper_Client/Web/public/admin/policies_diagnostic_backup.php:11, AZCKeeper_Client/Web/public/admin/policies_diagnostic_backup.php:23, AZCKeeper_Client/Web/public/admin/policies_diagnostic_backup.php:25, AZCKeeper_Client/Web/public/admin/policies_diagnostic_backup.php:83, AZCKeeper_Client/Web/public/admin/policies_diagnostic_backup.php:135. |
| releases.php GET; POST create/update/toggle_active/delete | P+M+A | R/W keeper_client_releases | AZCKeeper_Client/Web/public/admin/releases.php:9, AZCKeeper_Client/Web/public/admin/releases.php:24, AZCKeeper_Client/Web/public/admin/releases.php:57, AZCKeeper_Client/Web/public/admin/releases.php:94, AZCKeeper_Client/Web/public/admin/releases.php:107, AZCKeeper_Client/Web/public/admin/releases.php:144. |
| admin-users.php GET; POST create/update/toggle/delete | P+M+A; checks de jerarquía del rol solicitado, no frontera completa de scope/objeto | R/W keeper_admin_accounts; W keeper_admin_sessions al borrar; R keeper_users, keeper_panel_roles, keeper_firmas, keeper_areas, keeper_sedes, keeper_sociedades | AZCKeeper_Client/Web/public/admin/admin-users.php:9, AZCKeeper_Client/Web/public/admin/admin-users.php:24, AZCKeeper_Client/Web/public/admin/admin-users.php:46, AZCKeeper_Client/Web/public/admin/admin-users.php:66, AZCKeeper_Client/Web/public/admin/admin-users.php:88, AZCKeeper_Client/Web/public/admin/admin-users.php:108, AZCKeeper_Client/Web/public/admin/admin-users.php:121. |
| roles.php GET; POST create/edit/delete | P+M; lógica de jerarquía, sin guard uniforme de acción | R/W keeper_panel_roles, keeper_panel_settings; R keeper_admin_accounts | AZCKeeper_Client/Web/public/admin/roles.php:12, AZCKeeper_Client/Web/public/admin/roles.php:130, AZCKeeper_Client/Web/public/admin/roles.php:161, AZCKeeper_Client/Web/public/admin/roles.php:206, AZCKeeper_Client/Web/public/admin/roles.php:262, AZCKeeper_Client/Web/public/admin/roles.php:276. |
| panel-settings.php GET; POST save_visibility | P+M | R/W keeper_panel_settings; R keeper_panel_roles | AZCKeeper_Client/Web/public/admin/panel-settings.php:9, AZCKeeper_Client/Web/public/admin/panel-settings.php:45, AZCKeeper_Client/Web/public/admin/panel-settings.php:58. |
| pending-users.php GET; POST approve/reject | P+M+A; lista global | R/W keeper_enrollment_requests; R/W keeper_users al aprobar; W keeper_audit_log | AZCKeeper_Client/Web/public/admin/pending-users.php:10, AZCKeeper_Client/Web/public/admin/pending-users.php:33, AZCKeeper_Client/Web/public/admin/pending-users.php:49, AZCKeeper_Client/Web/public/admin/pending-users.php:84; AZCKeeper_Client/Web/src/Repos/PendingEnrollmentRepo.php:131. |
| productivity.php GET; POST calculate/save_focus_weights | P+M; can_view habilita POST; sí valida CSRF | R keeper_users, keeper_user_assignments, keeper_firmas, keeper_areas, keeper_activity_day, keeper_window_episode, keeper_work_schedules, keeper_suspicious_apps; R/W keeper_focus_daily, keeper_dual_job_alerts, keeper_panel_settings | AZCKeeper_Client/Web/public/admin/productivity.php:6, AZCKeeper_Client/Web/public/admin/productivity.php:13, AZCKeeper_Client/Web/public/admin/productivity.php:29, AZCKeeper_Client/Web/public/admin/productivity.php:48, AZCKeeper_Client/Web/public/admin/productivity.php:77, AZCKeeper_Client/Web/public/admin/productivity.php:101. |
| dual-job-alerts.php GET; POST review/add_classification/delete_classification/toggle_classification | P+M+can_review; UPDATE review sin scope del objeto | R keeper_dual_job_alerts, keeper_users, keeper_user_assignments, keeper_firmas, keeper_areas, keeper_admin_accounts, keeper_window_episode. R/W keeper_app_classifications; W keeper_dual_job_alerts | AZCKeeper_Client/Web/public/admin/dual-job-alerts.php:6, AZCKeeper_Client/Web/public/admin/dual-job-alerts.php:18, AZCKeeper_Client/Web/public/admin/dual-job-alerts.php:33, AZCKeeper_Client/Web/public/admin/dual-job-alerts.php:43, AZCKeeper_Client/Web/public/admin/dual-job-alerts.php:49, AZCKeeper_Client/Web/public/admin/dual-job-alerts.php:192; AZCKeeper_Client/Web/src/Repos/ProductivityRepo.php:281. |
| install-coverage.php GET; POST update_note/toggle_exempt/update_threshold | P+M+A | R employee, keeper_users, keeper_devices, keeper_client_releases, keeper_firmas, keeper_areas, keeper_cargos, keeper_sedes; R/W keeper_install_coverage_notes, keeper_panel_settings | AZCKeeper_Client/Web/public/admin/install-coverage.php:15, AZCKeeper_Client/Web/public/admin/install-coverage.php:27, AZCKeeper_Client/Web/public/admin/install-coverage.php:54, AZCKeeper_Client/Web/public/admin/install-coverage.php:75, AZCKeeper_Client/Web/public/admin/install-coverage.php:136, AZCKeeper_Client/Web/public/admin/install-coverage.php:168. |
| logs.php GET logs/audit/filtros | P+M; consultas globales | R keeper_client_log, keeper_audit_log, keeper_devices, keeper_users | AZCKeeper_Client/Web/public/admin/logs.php:14, AZCKeeper_Client/Web/public/admin/logs.php:40, AZCKeeper_Client/Web/public/admin/logs.php:47, AZCKeeper_Client/Web/public/admin/logs.php:50, AZCKeeper_Client/Web/public/admin/logs.php:67. |
| sedes-dashboard.php GET lista/detalle | P+M; menú previsto para superadmin; queries globales | R keeper_sedes, keeper_users, keeper_user_assignments, keeper_devices, keeper_activity_day, keeper_window_episode | AZCKeeper_Client/Web/public/admin/sedes-dashboard.php:8, AZCKeeper_Client/Web/public/admin/sedes-dashboard.php:50, AZCKeeper_Client/Web/public/admin/sedes-dashboard.php:54, AZCKeeper_Client/Web/public/admin/sedes-dashboard.php:95, AZCKeeper_Client/Web/public/admin/sedes-dashboard.php:112. |
| server-health.php GET, refresco navegador 30 s | P+M | R information_schema.TABLES, SHOW STATUS/VARIABLES, keeper_sessions, keeper_devices; diagnóstico host | AZCKeeper_Client/Web/public/admin/server-health.php:9, AZCKeeper_Client/Web/public/admin/server-health.php:55, AZCKeeper_Client/Web/public/admin/server-health.php:110, AZCKeeper_Client/Web/public/admin/server-health.php:137, AZCKeeper_Client/Web/public/admin/server-health.php:144, AZCKeeper_Client/Web/public/admin/server-health.php:226. |

admin_auth.php y partials/layout_header.php/layout_footer.php también están bajo docroot, pero son includes, no casos de uso. Propuesta: denegar acceso HTTP directo manteniendo inclusión PHP. Header no autentica por sí mismo (AZCKeeper_Client/Web/public/admin/admin_auth.php:15; AZCKeeper_Client/Web/public/admin/partials/layout_header.php:1; AZCKeeper_Client/Web/public/admin/partials/layout_footer.php:5).

### 2.4 Deuda que afecta extensión

- **Alto:** separar autenticación, permiso de acción y alcance del objeto; el menú debe decidir presentación, no ser la única autorización (AZCKeeper_Client/Web/public/admin/admin_auth.php:133, AZCKeeper_Client/Web/public/admin/admin_auth.php:150, AZCKeeper_Client/Web/public/admin/admin_auth.php:179, AZCKeeper_Client/Web/public/admin/admin_auth.php:274).
- **Medio:** mover sync legacy de navegación GET a jobs explícitos por tenant, con checkpoint (AZCKeeper_Client/Web/public/admin/admin_auth.php:53; AZCKeeper_Client/Web/src/LegacySyncService.php:93).
- **Medio:** formalizar contratos/casing, errores e inserción parcial: batch puede responder 200 con descartes y el cliente considera éxito todo el lote (AZCKeeper_Client/Web/src/Endpoints/WindowEpisodeBatch.php:161; AZCKeeper_Client/Network/ApiClient.cs:749).
- **Bajo:** candidatos residuales sin consumidores K3 encontrados: HandshakeRepo, LegacyAuthRepo, DeviceLock::getStatus y stub EventIngest; confirmar antes de retirarlos (AZCKeeper_Client/Web/src/bootstrap.php:15; AZCKeeper_Client/Web/src/Repos/LegacyAuthRepo.php:13; AZCKeeper_Client/Web/src/Endpoints/DeviceLock.php:15; AZCKeeper_Client/Web/src/Endpoints/EventIngest.php:7).


## 3. Pregunta 2 — Portabilidad a VPS/Docker

### 3.1 Dependencias y configuración real

No se encontró dependencia de una API de cPanel: los handlers usan PHP/PDO/archivos. No se encontró Composer, Docker ni .htaccess propio en el árbol web auditado. Esa ausencia no describe archivos externos instalados en el hosting. Bootstrap manual y router requieren proporcionar configuración web explícita (AZCKeeper_Client/Web/src/bootstrap.php:4; AZCKeeper_Client/Web/public/index.php:10; AZCKeeper_Client/Web/src/Db.php:106).

| Componente | Necesidad / evidencia |
|---|---|
| PHP | Mantener PHP 8.2 como objetivo inicial y verificar ahí; lint local 8.3 no prueba todo el runtime (AZCKeeper_Client/Web/tests/run.php:26). |
| Extensiones | PDO/pdo_mysql, mbstring y openssl; JSON, session, hash, filter y fechas. Evidencia: AZCKeeper_Client/Web/src/Db.php:106, AZCKeeper_Client/Web/src/Db.php:241; AZCKeeper_Client/Web/src/Endpoints/ClientLogBatch.php:123; AZCKeeper_Client/Web/src/Endpoints/WindowEpisode.php:140; AZCKeeper_Client/Web/public/admin/login.php:13. |
| MySQL | MySQL 8.0, JSON/upserts/collations del esquema. No asumir MariaDB intercambiable (AZCKeeper_Client/Web/migrations/keeper_full_schema.sql:170, AZCKeeper_Client/Web/migrations/keeper_full_schema.sql:319; AZCKeeper_Client/Web/src/Endpoints/ActivityDay.php:104). |
| Webserver | Apache o Nginx+PHP-FPM, HTTPS, Authorization preservada, PATH_INFO/aliases compatibles (AZCKeeper_Client/Web/public/index.php:13; AZCKeeper_Client/Web/src/Http.php:28; AZCKeeper_Client/Config/ConfigManager.cs:241). |
| Legacy | Tabla employee y columnas que login/sync esperan; no se entrega su esquema completo en la baseline K3 (AZCKeeper_Client/Web/src/Endpoints/ClientLogin.php:38; AZCKeeper_Client/Web/src/LegacySyncService.php:129). |
| Runtime escribible | Sesiones PHP, error_log y buckets temporales del limiter; no necesita que todo el código sea escribible (AZCKeeper_Client/Web/public/admin/admin_auth.php:26; AZCKeeper_Client/Web/src/RateLimiter.php:110, AZCKeeper_Client/Web/src/RateLimiter.php:123; AZCKeeper_Client/Web/src/Endpoints/ProductivityCron.php:82). |
| Frontend externo | Tailwind, Google Fonts, Alpine CDN; aislar de Internet cambia presentación/interacción (AZCKeeper_Client/Web/public/admin/partials/layout_header.php:18, AZCKeeper_Client/Web/public/admin/partials/layout_header.php:49; AZCKeeper_Client/Web/public/admin/partials/layout_footer.php:5). |

El archivo de entorno se busca en Web/.env, hermano de public; el backup en Web/.env.backup. Parser simple, no dotenv completo; valores del archivo prevalecen sobre getenv. Montar variables Docker sin controlar esa precedencia puede conservar configuración antigua (AZCKeeper_Client/Web/src/bootstrap.php:50; AZCKeeper_Client/Web/src/config.php:7, AZCKeeper_Client/Web/src/config.php:25; AZCKeeper_Client/Web/src/Db.php:82).

| Variables consumidas | Función / evidencia |
|---|---|
| DB_HOST, DB_NAME, DB_USER, DB_PASS | Principal obligatoria (AZCKeeper_Client/Web/src/Db.php:64). |
| DB_CHARSET, DB_COLLATION | Charset/collation conexión (AZCKeeper_Client/Web/src/Db.php:106). |
| LEGACY_DB_HOST, LEGACY_DB_NAME, LEGACY_DB_USER, LEGACY_DB_PASS | Fuente legacy global; fallback principal si falta configuración requerida (AZCKeeper_Client/Web/src/Db.php:181). |
| APP_BASE_URL, API_PREFIX | Routing (AZCKeeper_Client/Web/public/index.php:20, AZCKeeper_Client/Web/public/index.php:26). |
| APP_KEY | Cifra passwords de fuentes; conservar al migrar datos cifrados (AZCKeeper_Client/Web/src/Db.php:238, AZCKeeper_Client/Web/src/Db.php:267). |
| CRON_API_KEY | Auth de cron independiente (AZCKeeper_Client/Web/src/Endpoints/ProductivityCron.php:119). |
| DEBUG | Puede exponer detalle de excepción de login (AZCKeeper_Client/Web/src/Endpoints/ClientLogin.php:184). |
| APP_ENV, APP_BASE_PATH | Aparecen en ejemplo; no se encontraron consumidores K3 (AZCKeeper_Client/Web/.env.example:20, AZCKeeper_Client/Web/.env.example:24). |

El ejemplo no documenta todas las variables consumidas: faltan LEGACY_DB_*, APP_KEY, CRON_API_KEY y DEBUG. Preparar especificación de despliegue sin secretos en Git (AZCKeeper_Client/Web/.env.example:20, AZCKeeper_Client/Web/.env.example:31, AZCKeeper_Client/Web/.env.example:63; consumidores anteriores).

### 3.2 Bloqueadores de portabilidad

- **Alto — failover de escritura:** Db::pdo cambia automáticamente a BD de respaldo al fallar la principal; no verifica réplica, consistencia ni escritor único. Puede bifurcar datos si el respaldo no es una autoridad consistente. “Backup” no garantiza failover seguro (AZCKeeper_Client/Web/src/Db.php:26, AZCKeeper_Client/Web/src/Db.php:80).
- **Alto — legacy no es opcional robustamente:** login abre legacyPdo antes del try interno de fallback; fallo de conexión puede impedir llegar al login Keeper. sourceFor(firma) existe y la UI guarda fuentes, pero login/sync usan la fuente global. Se lee db_port pero no se pasa a createConnection (AZCKeeper_Client/Web/src/Endpoints/ClientLogin.php:31, AZCKeeper_Client/Web/src/Endpoints/ClientLogin.php:37; AZCKeeper_Client/Web/src/LegacySyncService.php:93; AZCKeeper_Client/Web/src/Db.php:204, AZCKeeper_Client/Web/src/Db.php:227).
- **Medio — DSN:** principal sin puerto ni opciones explícitas de TLS MySQL. No se comprobó si la conexión productiva está cifrada; definir red privada/TLS y puerto en la migración (AZCKeeper_Client/Web/src/Db.php:106).
- **Alto — URL/docroot discrepantes:** cliente inicial usa https://keep.azclegal.com/public/index.php/api/ aunque public ya es el docroot declarado. Plantilla administrativa conserva https://one.azclegal.com/keeper/public/index.php/api/. Pueden existir aliases externos, pero no están reproducidos en el repo. Además, guardar ApiBaseUrl en handshake no reconstruye HttpClient/BaseAddress (AZCKeeper_Client/Config/ConfigManager.cs:241; AZCKeeper_Client/Web/public/admin/policies.php:1144; AZCKeeper_Client/Web/public/admin/policies_diagnostic_backup.php:896; AZCKeeper_Client/Core/CoreService.cs:523; AZCKeeper_Client/Network/ApiClient.cs:81).
- **Medio — fechas:** panel fuerza America/Bogota y conexión -05:00; sesiones combinan UTC con funciones PHP/SQL y exportación resta cinco horas. El desfase real depende de configuración no inspeccionada. Normalizar UTC almacenado/conversión explícita por tenant (AZCKeeper_Client/Web/public/admin/admin_auth.php:24, AZCKeeper_Client/Web/public/admin/admin_auth.php:31; AZCKeeper_Client/Web/src/Repos/AdminAuthRepo.php:47; AZCKeeper_Client/Web/public/admin/user-dashboard.php:394; AZCKeeper_Client/Web/src/Endpoints/ProductivityCron.php:137).

### 3.3 Migraciones: no son una secuencia reproducible desde cero

Se inventariaron 23 SQL K3, sin ejecutar ninguno.

| Problema | Consecuencia / evidencia |
|---|---|
| Falta CREATE de keeper_enrollment_requests | Pendientes/enrolamiento fallan en instalación limpia; UI cita add_enrollment_requests.sql inexistente (AZCKeeper_Client/Web/src/Repos/PendingEnrollmentRepo.php:24, AZCKeeper_Client/Web/src/Repos/PendingEnrollmentRepo.php:43; AZCKeeper_Client/Web/public/admin/pending-users.php:87). |
| legacy_employee_id NOT NULL en baseline | Alta local requiere reconciliar nullable (AZCKeeper_Client/Web/migrations/keeper_full_schema.sql:172; AZCKeeper_Client/Web/migrations/fix_keeper_users_legacy_nullable.sql:8; AZCKeeper_Client/Web/src/Repos/PendingEnrollmentRepo.php:131). |
| ALTER de columnas ya existentes | applicable_days/manual_override se añaden otra vez (AZCKeeper_Client/Web/migrations/keeper_full_schema.sql:243, AZCKeeper_Client/Web/migrations/keeper_full_schema.sql:262; AZCKeeper_Client/Web/migrations/add_schedule_days.sql:9; AZCKeeper_Client/Web/migrations/add_manual_override_assignments.sql:6). |
| Limpieza de índices depende de modificaciones manuales previas | DROP de índices ausentes y ADD de presentes según baseline (AZCKeeper_Client/Web/migrations/keeper_index_cleanup.sql:15, AZCKeeper_Client/Web/migrations/keeper_index_cleanup.sql:20, AZCKeeper_Client/Web/migrations/keeper_index_cleanup.sql:48, AZCKeeper_Client/Web/migrations/keeper_index_cleanup.sql:69; AZCKeeper_Client/Web/migrations/keeper_full_schema.sql:184, AZCKeeper_Client/Web/migrations/keeper_full_schema.sql:439). |
| Unicidad de assignment divergente | keeper_admin_auth usa usuario único; full schema solo índice (AZCKeeper_Client/Web/migrations/keeper_admin_auth.sql:60; AZCKeeper_Client/Web/migrations/keeper_full_schema.sql:267). |
| Migración destructiva del legado | keeper_org_independence elimina tabla sociedades; no ejecutar ciegamente en BD compartida (AZCKeeper_Client/Web/migrations/keeper_org_independence.sql:167). |
| Seeds incompletos | Roles/catálogo no dejan primer admin, política global activa, release ni cron operativo; handshake falla sin global (AZCKeeper_Client/Web/migrations/keeper_full_schema.sql:503, AZCKeeper_Client/Web/migrations/keeper_full_schema.sql:510; AZCKeeper_Client/Web/src/Endpoints/ClientHandshake.php:90). |
| Dependencias/columnas posteriores | Empleo, decommission, cobertura, productividad y seguridad necesitan orden/reconciliación; populate depende de employee (AZCKeeper_Client/Web/migrations/populate_keeper_user_assignments.sql:47; AZCKeeper_Client/Web/migrations/add_employment_status.sql:4; AZCKeeper_Client/Web/migrations/add_device_decommission.sql:4; AZCKeeper_Client/Web/migrations/keeper_security_state.sql:1). |

**No ejecutar todos los *.sql alfabéticamente.** Propuesta: comparar SHOW CREATE TABLE de una copia autorizada de producción, consolidar baseline y migraciones versionadas y probar instalación vacía, upgrade desde snapshot y restauración. Los índices del repo no prueban su presencia en el servidor.

### 3.4 Cron y archivos generados

Único cron publicado: POST productividad. Lotes 50, presupuesto global 300 s, pausa entre lotes; “timeout por usuario” solo registra exceso después de terminar, no cancela SQL/cálculo. Sin lock/checkpoint; puede responder ok:true habiendo procesado solo parte. Purga de logs al final se omite si productividad está deshabilitada (AZCKeeper_Client/Web/src/Endpoints/ProductivityCron.php:20, AZCKeeper_Client/Web/src/Endpoints/ProductivityCron.php:35, AZCKeeper_Client/Web/src/Endpoints/ProductivityCron.php:58, AZCKeeper_Client/Web/src/Endpoints/ProductivityCron.php:73, AZCKeeper_Client/Web/src/Endpoints/ProductivityCron.php:94, AZCKeeper_Client/Web/src/Endpoints/ProductivityCron.php:104).

Propuesta inicial: scheduler diario 02:00 America/Bogota para ayer, POST con X-Cron-Key desde archivo protegido del scheduler, comprobando processed == total_users. Esa hora es recomendación, no cron encontrado. Separar después retención y productividad en jobs reanudables.

Backend genera sesiones, temporales de rate limit y logs; CSV se emite a respuesta, no requiere directorio persistente de exports. Cliente genera config, DPAPI, SQLite, logs, caché de política y ZIP temporal; esos archivos no van al contenedor PHP (AZCKeeper_Client/Web/src/RateLimiter.php:110; AZCKeeper_Client/Web/public/admin/login.php:13; AZCKeeper_Client/Web/public/admin/user-dashboard.php:362; AZCKeeper_Client/Network/OfflineQueue.cs:26; AZCKeeper_Client/Auth/AuthManager.cs:96; AZCKeeper_Client/Blocking/WebBlockingManager.cs:39; AZCKeeper_Client/Update/UpdateManager.cs:208).

### 3.5 Procedimiento propuesto de despliegue

No ejecutado; resuelve los bloqueadores anteriores.

1. Fijar artefacto productivo con hashes de scp/binarios y configuración efectiva; resolver implementación de bloqueo.
2. Respaldar BD, configuración protegida y APP_KEY; comprobar restauración; identificar dependencia legacy. Un único escritor.
3. Consolidar baseline MySQL 8.0 y upgrades versionados, constraints, índices y seeds.
4. Preparar PHP-FPM 8.2/extensiones, Nginx/Apache, HTTPS, límites de cuerpo/tiempo, logs, BD privada/TLS; código inmutable y runtime escribible mínimo.
5. Publicar solo public; proteger env/src/migrations, diagnóstico backup e includes directos; mantener aliases exactos y Authorization de clientes existentes.
6. Montar configuración fuera de imagen/Git, establecer UTAZCKeeper_Client/conversiones y probar fallos de principal/legacy.
7. Inicializar primer admin seguro, permisos, política, horarios, release validada y cron; alertar jobs incompletos.
8. Probar en staging login/renovación/revocación, alta nativa, batch parcial, duplicados, offline, dos tenants, CSRF, firma del updater y rutas antiguas.
9. Reducir timers y migrar por anillos conservando hostname/contratos; observar sockets/IP, 403/429/5xx, latencia BD y edad de cola.
10. Rollback de artefacto y datos compatible durante transición. Dimensionar VPS mediante carga representativa, sin prometer capacidad por una talla arbitraria.

## 4. Pregunta 3 — Cliente: modularidad y eficiencia

### 4.1 Estructura y configuración

WinForms net8.0-windows con SQLite; CoreService concentra aproximadamente 1.487 líneas de auth, UI, trackers, timers, políticas, red y updater. Program mantiene core estático; TimeSync/LocalLogger son estáticos y CurrentConfig mutable compartido (AZCKeeper_Client/AZCKeeper_Client.csproj:5, AZCKeeper_Client/AZCKeeper_Client.csproj:7, AZCKeeper_Client/AZCKeeper_Client.csproj:22; AZCKeeper_Client/Core/CoreService.cs:138; AZCKeeper_Client/Program.cs:30; AZCKeeper_Client/Core/TimeSync.cs:4; AZCKeeper_Client/Logging/LocalLogger.cs:12; AZCKeeper_Client/Config/ConfigManager.cs:149).

**Medio:** los módulos se construyen al inicializar; actualizar flags no reconstruye consistentemente módulos ni valores capturados. El handshake copia minutos pero no HandshakeIntervalSeconds del DTO antes de preferir los segundos locales. Hot reload no es uniforme (AZCKeeper_Client/Core/CoreService.cs:108, AZCKeeper_Client/Core/CoreService.cs:655, AZCKeeper_Client/Core/CoreService.cs:691, AZCKeeper_Client/Core/CoreService.cs:862, AZCKeeper_Client/Core/CoreService.cs:1008; AZCKeeper_Client/Network/ApiClient.cs:1244).

Propuesta: separar planificador de red, cola persistente, captura, política y updater; configuración efectiva inmutable/versionada; aplicar diferencias, sin reiniciar todo por heartbeat.

### 4.2 Concurrencia y consistencia

| Severidad | Hallazgo | Evidencia |
|---|---|---|
| Alto | Timers auto-reset y async sin exclusión común; handshake bloquea sobre async y offline no tiene gate de pasada. Solapamiento si una pasada tarda más que el intervalo | AZCKeeper_Client/Core/CoreService.cs:398, AZCKeeper_Client/Core/CoreService.cs:472; AZCKeeper_Client/Network/ApiClient.cs:986, AZCKeeper_Client/Network/ApiClient.cs:1013. |
| Alto | Start recupera actividad y el primer handshake exitoso también. Método async void no se espera; SeedDayTotals suma cuando tracker corre. Dos respuestas del mismo acumulado pueden duplicarlo. **Carrera deducida, no reproducción observada** | AZCKeeper_Client/Core/CoreService.cs:144, AZCKeeper_Client/Core/CoreService.cs:146, AZCKeeper_Client/Core/CoreService.cs:149, AZCKeeper_Client/Core/CoreService.cs:716, AZCKeeper_Client/Core/CoreService.cs:1066, AZCKeeper_Client/Core/CoreService.cs:1095; AZCKeeper_Client/Tracking/ActivityTracker.cs:155, AZCKeeper_Client/Tracking/ActivityTracker.cs:161. |
| Alto | Flush final ocurre antes de Stop de WindowsTracker; Stop cierra episodio después, que puede quedar en buffer al salir | AZCKeeper_Client/Core/CoreService.cs:189, AZCKeeper_Client/Core/CoreService.cs:198, AZCKeeper_Client/Core/CoreService.cs:1369; AZCKeeper_Client/Tracking/WindowsTracker.cs:178, AZCKeeper_Client/Tracking/WindowsTracker.cs:188. |
| Medio | Config JSON se escribe directamente sin reemplazo atómico/lock; varios guardados en handshake. Riesgo de corrupción, no corrupción observada | AZCKeeper_Client/Config/ConfigManager.cs:149; AZCKeeper_Client/Core/CoreService.cs:713. |
| Medio | UpdateManager.Start no comprueba timer existente; llamado desde handshake y Start del core. Posible timer anterior sin referencia; gate del chequeo no evita creación duplicada | AZCKeeper_Client/Update/UpdateManager.cs:56, AZCKeeper_Client/Update/UpdateManager.cs:68, AZCKeeper_Client/Update/UpdateManager.cs:107; AZCKeeper_Client/Core/CoreService.cs:151, AZCKeeper_Client/Core/CoreService.cs:615. |

Cerrar sesión espera sincrónicamente red. Persistir primero todos los cierres y luego flush acotado evita depender del tiempo de cierre de Windows (AZCKeeper_Client/Core/CoreService.cs:131, AZCKeeper_Client/Core/CoreService.cs:133, AZCKeeper_Client/Core/CoreService.cs:1249).

### 4.3 CPU, memoria, disco y exactitud

- **CPU:** eventos de foreground y fallback de un segundo, más muestreo local de actividad. Muestreo no equivale a HTTP; no se midió CPU real (AZCKeeper_Client/Tracking/ActivityTracker.cs:268; AZCKeeper_Client/Tracking/WindowsTracker.cs:154, AZCKeeper_Client/Tracking/WindowsTracker.cs:166, AZCKeeper_Client/Tracking/WindowsTracker.cs:228; AZCKeeper_Client/Config/ConfigManager.cs:466).
- **Disco, alto:** cada fallo de actividad se encola; SQLite por entrada sin coalescencia día/dispositivo ni cuota de bytes. A=10 s durante ocho horas caídas: 2.880 snapshots/equipo más episodios, si permanece activo (AZCKeeper_Client/Network/ApiClient.cs:354, AZCKeeper_Client/Network/ApiClient.cs:360; AZCKeeper_Client/Network/OfflineQueue.cs:72).
- **Datos, alto:** cola procesa hasta 10, menos de 5 intentos; elimina agotados. Sin idempotencia, timeout tras commit duplica episodios. HTTP 200 de batch parcial descarta la recuperación de rechazados (AZCKeeper_Client/Network/OfflineQueue.cs:107, AZCKeeper_Client/Network/OfflineQueue.cs:177, AZCKeeper_Client/Network/OfflineQueue.cs:210; AZCKeeper_Client/Network/ApiClient.cs:749; AZCKeeper_Client/Web/src/Endpoints/WindowEpisodeBatch.php:149, AZCKeeper_Client/Web/src/Endpoints/WindowEpisodeBatch.php:161).
- **Memoria, medio:** cola de logs acotada a 200 y dedup contiene memoria pero pierde evidencia prolongada; ZIP se carga completo y carece de máximo de bytes (AZCKeeper_Client/Logging/LocalLogger.cs:59, AZCKeeper_Client/Logging/LocalLogger.cs:77; AZCKeeper_Client/Update/UpdateManager.cs:218).
- **Métricas, alto:** actividad usa GREATEST, no permite corrección hacia abajo, asigna tiempos de recepción y puede sobrescribir JSON con snapshots antiguos manteniendo máximos (AZCKeeper_Client/Web/src/Endpoints/ActivityDay.php:104).
- **Métricas, medio:** batch valida duración declarada/orden temporal, pero no igualdad exacta con diferencia de timestamps; day_date se toma del inicio. Definir división al cruzar medianoche/horarios (AZCKeeper_Client/Web/src/Endpoints/WindowEpisodeBatch.php:76).
- **Métricas, alto:** panel persiste applicable_days, pero PolicyRepo no lo selecciona ni devuelve en horario al cliente (AZCKeeper_Client/Web/public/admin/policies.php:141; AZCKeeper_Client/Web/src/Repos/PolicyRepo.php:94, AZCKeeper_Client/Web/src/Repos/PolicyRepo.php:104).

### 4.4 Código residual y cobertura

Sin consumidores K3 encontrados de MasterTimer, KeyboardHook/MouseHook independientes y EnablePac actual; ProcessTracking se guarda sin ciclo independiente identificado. Son candidatos a confirmar/retirar posteriormente, no cambios de esta auditoría (AZCKeeper_Client/Core/MasterTimer.cs:18; AZCKeeper_Client/Tracking/KeyboardHook.cs:26; AZCKeeper_Client/Tracking/MouseHook.cs:22; AZCKeeper_Client/Blocking/SystemProxyManager.cs:31; AZCKeeper_Client/Core/CoreService.cs:655).

Tests existentes cubren backoff, buffers de logs y contratos/caché/orden de seguridad. No se ejecutó .NET para evitar outputs y estado local. Faltan pruebas significativas de solapamiento, seed doble, shutdown, batch parcial, idempotencia, tenancy y updater (AZCKeeper.Tests/NetworkBackoffPolicyTests.cs:7; AZCKeeper.Tests/LocalLoggerReportQueueTests.cs:5; AZCKeeper.Tests/SecurityReportCacheTests.cs:4; AZCKeeper_Client/Web/tests/run.php:26).


## 5. Pregunta 4 — Tormenta de peticiones y una IP por empresa

### 5.1 Defaults efectivos

| Fuente | Actividad A | Handshake configurado / efectivo H | Episodios B | Offline Q | Update nominal |
|---|---:|---:|---:|---:|---:|
| Propiedades de clases | 30 s | 300 / 300 s | 30 s | 30 s | 60 min |
| CreateDefaultConfig | 10 s | 60 / **120 s por mínimo del core** | 30 s | 30 s | 60 min |

Evidencia: AZCKeeper_Client/Config/ConfigManager.cs:278, AZCKeeper_Client/Config/ConfigManager.cs:371, AZCKeeper_Client/Config/ConfigManager.cs:377, AZCKeeper_Client/Config/ConfigManager.cs:379, AZCKeeper_Client/Config/ConfigManager.cs:385, AZCKeeper_Client/Config/ConfigManager.cs:393; AZCKeeper_Client/Core/CoreService.cs:398, AZCKeeper_Client/Core/CoreService.cs:862.

Configuración persistida/políticas productivas no inspeccionadas: son escenarios del código, no valores asegurados en la flota. El problema de aplicación de segundos remotos descrito en §4 impide asumir que basta editar JSON para cambiar H.

### 5.2 Inventario de productores de red

Tamaños aproximados de JSON UTF-8 sin cabeceras/TLS/compresión, **no mediciones**. Texto, escapes y Unicode cambian bytes; límite de caracteres no es límite de cuerpo.

| Productor | Intervalo/condición | Endpoint y tamaño | Reintento / evidencia |
|---|---|---|---|
| Handshake | Inicio inmediato, disparo adicional tras jitter y luego H>=120 s | POST client/handshake: ~100–250 B; respuesta política completa ~1–5 KiB más dominios | Token, backoff compartido, sin gate único. AZCKeeper_Client/Core/CoreService.cs:144, AZCKeeper_Client/Core/CoreService.cs:398, AZCKeeper_Client/Core/CoreService.cs:435, AZCKeeper_Client/Core/CoreService.cs:472; AZCKeeper_Client/Web/src/Endpoints/ClientHandshake.php:147. |
| Actividad | Cada A>=10 s, jitter inicial; cierre diario y final adicionales | POST client/activity-day: ~0,5–0,9 KiB | Envía también inactividad; fallo HTTP/red se encola. AZCKeeper_Client/Core/CoreService.cs:1123, AZCKeeper_Client/Core/CoreService.cs:1165, AZCKeeper_Client/Core/CoreService.cs:918, AZCKeeper_Client/Core/CoreService.cs:1249; AZCKeeper_Client/Network/ApiClient.cs:360. |
| Episodios | Cada B>=15 s si buffer no vacío; también al llegar a 40 | POST client/window-episodes/batch: ~0,3–1 KiB/episodio corriente; ~12–40 KiB para 40 | Filtra duración <2 s; fallo encola individualmente; 404 fallback individual. AZCKeeper_Client/Core/CoreService.cs:67, AZCKeeper_Client/Core/CoreService.cs:974, AZCKeeper_Client/Core/CoreService.cs:1369, AZCKeeper_Client/Core/CoreService.cs:1398, AZCKeeper_Client/Core/CoreService.cs:1430; AZCKeeper_Client/Network/ApiClient.cs:698, AZCKeeper_Client/Network/ApiClient.cs:729. |
| Offline | Q=30 s inicial, configurable; 10 elementos/pasada | POST activity-day/window-episode, uno por elemento | Sin jitter específico ni gate de pasada; <5 intentos; backoff compartido. AZCKeeper_Client/Network/ApiClient.cs:986, AZCKeeper_Client/Network/ApiClient.cs:999, AZCKeeper_Client/Network/ApiClient.cs:1013, AZCKeeper_Client/Network/ApiClient.cs:1023, AZCKeeper_Client/Network/ApiClient.cs:1034; AZCKeeper_Client/Network/OfflineQueue.cs:107. |
| Versión | Nominal 60 min (mínimo 1); **inmediata en Start, reiniciado por cada handshake que aplica updates** | GET client/version: ~0,3–2 KiB más notas | Auto-update habilitado; gate de chequeo; próximo chequeo tras fallo; API backoff. AZCKeeper_Client/Update/UpdateManager.cs:56, AZCKeeper_Client/Update/UpdateManager.cs:67, AZCKeeper_Client/Update/UpdateManager.cs:73, AZCKeeper_Client/Update/UpdateManager.cs:107; AZCKeeper_Client/Core/CoreService.cs:615. |
| Logs backend | Tras handshake exitoso, pendientes, hasta 50 | POST client/logs: ~0,5–40 KiB habitual, sin cota global garantizada | Reencola fallo, buffer 200, dedup, supresión de recursión. AZCKeeper_Client/Core/CoreService.cs:745, AZCKeeper_Client/Core/CoreService.cs:759, AZCKeeper_Client/Core/CoreService.cs:766, AZCKeeper_Client/Core/CoreService.cs:773; AZCKeeper_Client/Logging/LocalLogger.cs:59, AZCKeeper_Client/Logging/LocalLogger.cs:64; AZCKeeper_Client/Network/ApiClient.cs:586. |
| Seguridad | Evaluación tras handshake; POST si cambia hash o cada 24 h | POST client/security/report: orden ~1–5 KiB | Caché de último éxito; agent_present=false en este flujo. AZCKeeper_Client/Core/CoreService.cs:746, AZCKeeper_Client/Core/CoreService.cs:786, AZCKeeper_Client/Core/CoreService.cs:800; AZCKeeper_Client/Security/SecurityReportCache.cs:22, AZCKeeper_Client/Security/SecurityReportCache.cs:60. |
| Política web | Incluida en handshake | No endpoint/timer independiente identificado; dominios aumentan respuesta | SyncIntervalSeconds es metadato. AZCKeeper_Client/Web/src/Endpoints/ClientHandshake.php:133, AZCKeeper_Client/Web/src/Endpoints/ClientHandshake.php:157; AZCKeeper_Client/Blocking/WebBlockingManager.cs:60, AZCKeeper_Client/Blocking/WebBlockingManager.cs:100. |
| Recuperación diaria | Inicio y primer handshake; posible duplicación | GET client/activity-day: orden ~0,5–1,5 KiB | Sin loop propio; limiter GET y API backoff. AZCKeeper_Client/Core/CoreService.cs:146, AZCKeeper_Client/Core/CoreService.cs:721, AZCKeeper_Client/Core/CoreService.cs:1082; AZCKeeper_Client/Web/src/Endpoints/ActivityDay.php:192. |
| Login / reenrolamiento | Arranque, UI o recuperación tras 401/403 | POST client/login / client/re-enroll; JSON pequeño con secretos de autenticación | No tasa fija; AZCKeeper_Client/Core/CoreService.cs:243, AZCKeeper_Client/Core/CoreService.cs:314, AZCKeeper_Client/Core/CoreService.cs:366; AZCKeeper_Client/Core/CoreService.cs:483. |
| Desbloqueo | Acción local tras comparación local | POST client/device-lock/unlock, PIN pequeño | Sin timer; AZCKeeper_Client/Blocking/KeyBlocker.cs:568; AZCKeeper_Client/Web/src/Endpoints/DeviceLock.php:65. |
| Webhook | Por log autorizado si habilitado; default deshabilitado | URL configurada, **destino distinto al hosting** normalmente | HttpClient propio, Task/envío, sin batching/backoff API. AZCKeeper_Client/Logging/LocalLogger.cs:90, AZCKeeper_Client/Logging/LocalLogger.cs:515, AZCKeeper_Client/Logging/LocalLogger.cs:609; AZCKeeper_Client/Config/ConfigManager.cs:275. |
| ZIP | Versión elegible + modo descarga/forzado | URL de release, GitHub según operación declarada; tamaño real no medido | Cliente independiente, UseProxy=false, timeout 5 min; siguiente check puede repetir descarga. AZCKeeper_Client/Update/UpdateManager.cs:145, AZCKeeper_Client/Update/UpdateManager.cs:192, AZCKeeper_Client/Update/UpdateManager.cs:215, AZCKeeper_Client/Update/UpdateManager.cs:218. |
| PAC histórico | Navegador solicita PAC loopback; reassert por handshake | GET local 127.0.0.1, proporcional a dominios | No hosting en código histórico; ausente del checkout actual. 4f6aea5:AZCKeeper_Client/Blocking/LocalPacServer.cs:117; 4f6aea5:AZCKeeper_Client/Blocking/PacContentBuilder.cs:43. |

Timers sin red propia: actividad local, foreground/fallback, debug, keep-front y MasterTimer residual. TimeSync recibe hora del handshake, no consulta NTP. Updater ejecutable sustituye/reinicia localmente después de que el cliente descargue (AZCKeeper_Client/Tracking/ActivityTracker.cs:268; AZCKeeper_Client/Tracking/WindowsTracker.cs:228; AZCKeeper_Client/Core/DebugWindowForm.cs:50; AZCKeeper_Client/Blocking/KeyBlocker.cs:167; AZCKeeper_Client/Core/MasterTimer.cs:18; AZCKeeper_Client/Core/CoreService.cs:518; AZCKeeperUpdater/Program.cs:29).

### 5.3 Modelo cuantitativo

Definir L como fracción de handshakes con logs; U checks de versión/min; Rb batches/min; S reportes seguridad/min; Rq recuperación/min; Re eventos extraordinarios:

**R = 60/H + 60/A + Rb + U + L×60/H + S + Rq + Re.**

Con updates habilitado y reinicio actual, U≈60/H. Rb=0 sin episodios o ≈60/B si cada periodo tiene episodios sin llegar antes al umbral. S≈1/1.440 para estado invariable. Rq≈10×60/Q con backlog y respuestas rápidas. Fuentes: timers/límites anteriores, especialmente AZCKeeper_Client/Core/CoreService.cs:398, AZCKeeper_Client/Core/CoreService.cs:1123, AZCKeeper_Client/Core/CoreService.cs:1430; AZCKeeper_Client/Update/UpdateManager.cs:73; AZCKeeper_Client/Network/ApiClient.cs:1023.

Supuestos de tabla: todos activos/autenticados a la vez, B=30, buffer con episodios en cada periodo, updates aplicado en cada handshake. No incluye arranque, seguridad diaria, webhook ni ZIP. Flota: **solicitudes/minuto (solicitudes/segundo)** desde una IP.

| Escenario | Equipo/min | 180 equipos | 1.000 equipos | 5.000 equipos |
|---|---:|---:|---:|---:|
| H=300, A=30, sin logs | 4,4 | 792 (13,20) | 4.400 (73,33) | 22.000 (366,67) |
| Anterior + logs cada handshake | 4,6 | 828 (13,80) | 4.600 (76,67) | 23.000 (383,33) |
| Inicial H=120, A=10 | 9,0 | 1.620 (27,00) | 9.000 (150,00) | 45.000 (750,00) |
| Anterior + logs cada handshake | 9,5 | 1.710 (28,50) | 9.500 (158,33) | 47.500 (791,67) |
| H=300/A=30 + backlog 20/min/equipo | 24,4 | 4.392 (73,20) | 24.400 (406,67) | 122.000 (2.033,33) |
| H=120/A=10 + backlog 20/min/equipo | 29,0 | 5.220 (87,00) | 29.000 (483,33) | 145.000 (2.416,67) |

Límites:

- Sin episodios se restan hasta 2 solicitudes/min; muestrear cada segundo no produce un POST cada segundo (AZCKeeper_Client/Core/CoreService.cs:974, AZCKeeper_Client/Core/CoreService.cs:1400).
- Sin updates restar 60/H; si no se aplica el bloque updates, usar frecuencia efectiva del manager (AZCKeeper_Client/Core/CoreService.cs:615; AZCKeeper_Client/Update/UpdateManager.cs:61).
- 20/min offline **no es techo universal**: Q configurable, solapamiento, latencia y circuito abierto cambian solicitudes efectivas. Pasar por lógica de retry no implica que se haya abierto un socket (AZCKeeper_Client/Network/ApiClient.cs:999, AZCKeeper_Client/Network/ApiClient.cs:1013, AZCKeeper_Client/Network/ApiClient.cs:965).
- Arranque no está totalmente disperso: handshake inmediato, posible recuperación doble y update inmediato; timer duplicado puede añadir checks, pero no se asigna tasa fija no demostrada (AZCKeeper_Client/Core/CoreService.cs:144, AZCKeeper_Client/Core/CoreService.cs:146, AZCKeeper_Client/Core/CoreService.cs:435, AZCKeeper_Client/Core/CoreService.cs:721; AZCKeeper_Client/Update/UpdateManager.cs:68).
- **Solicitudes HTTP ≠ conexiones TCP nuevas ≠ conexiones concurrentes.** HttpClient reutiliza pool, pero no conocemos contadores/umbrales CSF instalados. La tabla no identifica la regla exacta del baneo (AZCKeeper_Client/Network/ApiClient.cs:63, AZCKeeper_Client/Network/ApiClient.cs:81).

### 5.4 Bloqueo de dominio: hechos frente a hipótesis

**Actual:** WebBlockingManager declara caché sin enforcement, limpia URLBlocklist y restaura proxy; compara versión/hash/estado y guarda solo cambios. LocalPacServer/PacContentBuilder no existen en este checkout (AZCKeeper_Client/Blocking/WebBlockingManager.cs:14, AZCKeeper_Client/Blocking/WebBlockingManager.cs:44, AZCKeeper_Client/Blocking/WebBlockingManager.cs:60, AZCKeeper_Client/Blocking/WebBlockingManager.cs:81).

**Histórico, separado:** en 4f6aea5, manager construye PAC con dominios recibidos, apunta AutoConfigURL a loopback y reassert reconstruye localmente. Servidor acepta solo loopback; PAC compara host exacto/sufijo, devuelve DIRECT o PROXY 127.0.0.1:9, sin fetch al backend ni resolución remota por dominio (4f6aea5:AZCKeeper_Client/Blocking/WebBlockingManager.cs:102, 4f6aea5:AZCKeeper_Client/Blocking/WebBlockingManager.cs:128; 4f6aea5:AZCKeeper_Client/Blocking/LocalPacServer.cs:13, 4f6aea5:AZCKeeper_Client/Blocking/LocalPacServer.cs:69, 4f6aea5:AZCKeeper_Client/Blocking/LocalPacServer.cs:117; 4f6aea5:AZCKeeper_Client/Blocking/PacContentBuilder.cs:15, 4f6aea5:AZCKeeper_Client/Blocking/PacContentBuilder.cs:30, 4f6aea5:AZCKeeper_Client/Blocking/PacContentBuilder.cs:43).

Respuestas directas:

1. **¿Redescarga política? Sí:** handshake retorna toda effectiveConfig sin comparación condicional ni ETag (AZCKeeper_Client/Web/src/Endpoints/ClientHandshake.php:147).
2. **¿SyncIntervalSeconds crea polling extra?** No hay productor de red independiente identificado; se almacena como metadato (AZCKeeper_Client/Blocking/WebBlockingManager.cs:100).
3. **¿PAC llama al hosting por URL?** No en el histórico inspeccionado; sus conexiones son locales. No atribuir ese comportamiento al binario productivo sin comparar hashes.
4. **¿Reintentos sin backoff?** API sí tiene backoff; ZIP/webhook están fuera y offline/fallback fragmentan lotes; falta presupuesto global de concurrencia (AZCKeeper_Client/Network/ApiClient.cs:965, AZCKeeper_Client/Network/ApiClient.cs:729; AZCKeeper_Client/Update/UpdateManager.cs:215; AZCKeeper_Client/Logging/LocalLogger.cs:609).
5. **¿Eco de logs infinito?** Hay supresión/filtros; no se demostró loop infinito. Errores reales llenan cola y logs se envían por un POST separado, no dentro del handshake (AZCKeeper_Client/Logging/LocalLogger.cs:64, AZCKeeper_Client/Logging/LocalLogger.cs:281; AZCKeeper_Client/Network/ApiClient.cs:586; AZCKeeper_Client/Core/CoreService.cs:742, AZCKeeper_Client/Core/CoreService.cs:769).

**Hipótesis pendiente:** política que bloquee host de gestión/dominio padre + ApiClient que hereda proxy puede interferir con su propia conexión y disparar errores/recuperación. ZIP sí configura UseProxy=false; validador no excluye host de API. No se afirma causa productiva demostrada (AZCKeeper_Client/Network/ApiClient.cs:63; AZCKeeper_Client/Update/UpdateManager.cs:215; AZCKeeper_Client/Web/src/InputValidator.php:92).

Cerrar esa incertidumbre en una ventana posterior autorizada: hash binario, configuración efectiva y correlación de activación con access logs por endpoint/dispositivo y errores locales. No volver a investigar NAT/baneo ya verificados.

### 5.5 Backoff/rate limiting actual

DNS base 5 s/cap 60; transitorio 30/300; 403/429 30/1.800; jitter positivo hasta 20%, acotado al cap. Cambiar clase de fallo reinicia progresión. No hay semáforo global ni una sola prueba de recuperación; varias solicitudes pueden salir antes del primer fallo (AZCKeeper_Client/Network/NetworkBackoffPolicy.cs:38, AZCKeeper_Client/Network/NetworkBackoffPolicy.cs:41, AZCKeeper_Client/Network/NetworkBackoffPolicy.cs:44, AZCKeeper_Client/Network/NetworkBackoffPolicy.cs:82, AZCKeeper_Client/Network/NetworkBackoffPolicy.cs:116, AZCKeeper_Client/Network/NetworkBackoffPolicy.cs:149; AZCKeeper_Client/Network/ApiClient.cs:965).

**Alto:** 403 también conduce a auth inválida/relogin, pudiendo borrar token por un rechazo de infraestructura. No se consume Retry-After en el cálculo revisado (AZCKeeper_Client/Network/ApiClient.cs:269; AZCKeeper_Client/Core/CoreService.cs:483, AZCKeeper_Client/Core/CoreService.cs:487, AZCKeeper_Client/Core/CoreService.cs:498; AZCKeeper_Client/Network/NetworkBackoffPolicy.cs:149).

Limiter PHP reutilizable solo identificado en GET actividad, por usuario+endpoint. Read-modify-write sin lock integral, archivos locales no compartidos entre réplicas; cleanup busca raíz y almacenamiento usa buckets. Reenroll tiene aparte 10/min/IP, adverso para recuperación NAT masiva (AZCKeeper_Client/Web/src/Endpoints/ActivityDay.php:192; AZCKeeper_Client/Web/src/RateLimiter.php:28, AZCKeeper_Client/Web/src/RateLimiter.php:77, AZCKeeper_Client/Web/src/RateLimiter.php:98, AZCKeeper_Client/Web/src/RateLimiter.php:123, AZCKeeper_Client/Web/src/RateLimiter.php:164; AZCKeeper_Client/Web/src/Endpoints/ClientReEnroll.php:43).

### 5.6 Diseño propuesto de reducción

**Etapa A — mantener contratos:**

1. H=600 s, A=120 s, B=120 s si negocio acepta retraso de dos minutos; conservar captura/persistencia local. Corregir prioridad segundos/minutos primero.
2. Updates cada seis horas con fase por dispositivo; no reiniciar ante política idéntica; anillos de descarga.
3. Fase determinista por device y jitter de arranque 0–120 s, sin handshake inmediato adicional; backoff con jitter.
4. Planificador máximo 1–2 solicitudes simultáneas/dispositivo, una prueba al terminar backoff, Retry-After y error auth inequívoco.
5. Cola con snapshot más reciente por día/device, UUID estable de episodio, lotes persistidos y ACK individual; recuperación máximo un lote/30–60 s dentro del presupuesto compartido.
6. No consumir intentos cuando circuito impide hacer red; fallos permanentes con retención/cuota diagnóstica, sin borrado silencioso al quinto.
7. Recordar capacidad batch tras 404 para no repetir fallback por cada lote.

Puntos de cambio: AZCKeeper_Client/Core/CoreService.cs:615, AZCKeeper_Client/Core/CoreService.cs:691, AZCKeeper_Client/Core/CoreService.cs:1398; AZCKeeper_Client/Network/ApiClient.cs:729, AZCKeeper_Client/Network/ApiClient.cs:965, AZCKeeper_Client/Network/ApiClient.cs:1013; AZCKeeper_Client/Network/OfflineQueue.cs:210; AZCKeeper_Client/Web/src/Endpoints/WindowEpisodeBatch.php:149.

Con buffers activos: 0,1 handshake + 0,5 actividad + 0,5 batches + 1/360 versión = **1,103 solicitudes/min/equipo**, más extras; ~75% menos que 4,4, condicionado a desplegar esos cambios.

**Etapa B — POST /api/client/sync propuesto cada 120 s:** identificación autenticada, secuencia/idempotency key, hash conocido de política, snapshot, episodios/logs y delta seguridad. Respuesta con ACK por registro, hora, versión disponible y política solo si cambia. Objetivo inicial a probar: 128 KiB/request, 100–200 episodios; requiere elevar límite servidor actual de 50 y buffer de 40, y mantener contrato viejo durante transición (AZCKeeper_Client/Core/CoreService.cs:67; AZCKeeper_Client/Web/src/Endpoints/WindowEpisodeBatch.php:32, AZCKeeper_Client/Web/src/Endpoints/WindowEpisodeBatch.php:149).

| Equipos/IP | Base propuesta solicitudes/min | Solicitudes/s |
|---:|---:|---:|
| 180 | 90 | 1,50 |
| 1.000 | 500 | 8,33 |
| 5.000 | 2.500 | 41,67 |

Base 0,5/min/equipo: reducción ~88,6% frente a 4,4 y ~94,4% frente a 9. Batching reduce solicitudes, no elimina filas de telemetría.

**Caché/ETag:** un GET de política propuesto puede responder 304 a If-None-Match. No usar 304 como sustituto genérico del POST actual; ETag ahorra bytes/cálculo, no solicitudes por sí solo. [RFC 9110](https://www.rfc-editor.org/rfc/rfc9110.html).

Hash efectivo debe abarcar composición global/tenant/sede/user/device, horario y revisión del validador. policyApplied.version de la capa más específica no identifica todos los cambios heredados; composition ofrece base mejor (AZCKeeper_Client/Web/src/Endpoints/ClientHandshake.php:97, AZCKeeper_Client/Web/src/Endpoints/ClientHandshake.php:118, AZCKeeper_Client/Web/src/Endpoints/ClientHandshake.php:151). Usar caché compartida por tenant/device/versiones: static de PolicyRepo no equivale a caché distribuida entre requests PHP-FPM (AZCKeeper_Client/Web/src/Repos/PolicyRepo.php:63, AZCKeeper_Client/Web/src/Repos/PolicyRepo.php:86).

**Long-poll/push:** long-poll de 25 s puede generar 2,4 requests/min/device y sostener workers; no mejora automáticamente polling cada 5–10 min. Para reacción en segundos, gateway asíncrono SSE/WebSocket con pub/sub, auth device/tenant, backoff y evento “versión cambió”, más polling de recuperación. Es componente nuevo; no existe en router actual (AZCKeeper_Client/Web/public/index.php:44).

### 5.7 N empresas × una IP: backend e infraestructura

Propuestas ligadas a las carencias de §5.5:

- Identidad estable device+tenant derivada de token; limiter por device autenticado, no IP como empresa ni solo hash de token que cambia al rotarlo.
- Contadores compartidos atómicos (por ejemplo Redis). Para nuevo sync, empezar pruebas con 1/min sostenida y burst 3–5; presupuesto tenant proporcional a flota y recuperación. Cliente viejo de hasta 9/min requiere perfil transitorio diferente.
- 429 + Retry-After; mantener antiabuso de endpoints públicos por credencial/invitación/device y límites IP suficientemente amplios. GUID no es prueba de identidad.
- CSF/LFD actúa antes de PHP: rate limit de aplicación no deshace ban de red. Allowlist temporal de IP dedicadas y ajuste de umbrales de infraestructura junto con controles L7; no eliminar toda protección IP.
- Reutilizar keep-alive y verificar HTTP/2 negociado. Handler actual tiene pool lifetime 5 min/connect timeout 15 s, sin HTTP/2 ni MaxConnectionsPerServer explícitos. HTTP/2 no convierte mil equipos NAT en una conexión TCP (AZCKeeper_Client/Network/ApiClient.cs:63).
- Separar capacidad de ingestión, panel y jobs. PDO por request sin persistencia configurada; dimensionar con latencia real y pool PHP, no con usuarios solamente (AZCKeeper_Client/Web/src/Db.php:106; AZCKeeper_Client/Web/src/Endpoints/ProductivityCron.php:66).
- Métricas: requests y sockets/IP, latencia p50/p95/p99, 403/429/5xx por causa, SQL/locks, backlog y edad del episodio más antiguo; tenant no se deduce de IP.


## 6. Pregunta 5 — Multiempresa y más de 1.000 dispositivos por tenant

### 6.1 Organización no equivale a aislamiento

Existen sociedades, firmas, áreas, cargos, sedes y assignments; admins tienen scopes organizativos. Users/devices/sesiones/telemetría no llevan una identidad tenant obligatoria independiente. CC y legacy_employee_id son únicos globales en baseline; login busca CC globalmente. Políticas solo global/user/device; horarios global/user (AZCKeeper_Client/Web/migrations/keeper_full_schema.sql:84, AZCKeeper_Client/Web/migrations/keeper_full_schema.sql:98, AZCKeeper_Client/Web/migrations/keeper_full_schema.sql:150, AZCKeeper_Client/Web/migrations/keeper_full_schema.sql:181, AZCKeeper_Client/Web/migrations/keeper_full_schema.sql:182, AZCKeeper_Client/Web/migrations/keeper_full_schema.sql:192, AZCKeeper_Client/Web/migrations/keeper_full_schema.sql:211, AZCKeeper_Client/Web/migrations/keeper_full_schema.sql:254, AZCKeeper_Client/Web/migrations/keeper_full_schema.sql:298, AZCKeeper_Client/Web/migrations/keeper_full_schema.sql:321; AZCKeeper_Client/Web/src/Endpoints/ClientLogin.php:56; AZCKeeper_Client/Web/src/Repos/PolicyRepo.php:94).

Crear una fila en keeper_firmas no aísla una empresa. Propuesta: decidir si tenant comercial equivale a firma o agrupa varias sociedades/firmas y darle ID obligatorio estable; no derivarlo de IP ni de assignment modificable.

### 6.2 Rupturas concretas de aislamiento

| Severidad | Punto y consecuencia | Evidencia |
|---|---|---|
| Crítico | Handshake/login reasignan un GUID existente; no transferencia explícita con autorización y revocación | AZCKeeper_Client/Web/src/Endpoints/ClientHandshake.php:77; AZCKeeper_Client/Web/src/Endpoints/ClientLogin.php:230. |
| Crítico | GET get_user y búsqueda employee fuera de scope de lista | AZCKeeper_Client/Web/public/admin/users.php:19, AZCKeeper_Client/Web/public/admin/users.php:54. |
| Alto | canViewUser omite sede, scopeFilter sí la aplica: detalle/exportación y lista difieren | AZCKeeper_Client/Web/public/admin/admin_auth.php:150, AZCKeeper_Client/Web/public/admin/admin_auth.php:179; AZCKeeper_Client/Web/public/admin/user-dashboard.php:15. |
| Alto | Scopes todos nulos en no-superadmin pueden dejar alcance global | AZCKeeper_Client/Web/public/admin/admin_auth.php:179. |
| Alto | users/devices/assignments y reviewAlert mutan por ID sin predicado tenant | AZCKeeper_Client/Web/public/admin/users.php:176; AZCKeeper_Client/Web/public/admin/devices.php:19; AZCKeeper_Client/Web/public/admin/assignments.php:42; AZCKeeper_Client/Web/src/Repos/ProductivityRepo.php:285. |
| Alto | Admin-users comprueba jerarquía del rol nuevo, pero no garantiza permiso sobre el objeto ni scope nuevo subconjunto del propio | AZCKeeper_Client/Web/public/admin/admin-users.php:29, AZCKeeper_Client/Web/public/admin/admin-users.php:46, AZCKeeper_Client/Web/public/admin/admin-users.php:69, AZCKeeper_Client/Web/public/admin/admin-users.php:88. |
| Alto | Políticas, horarios globales, releases, ajustes y clasificaciones compartidos | AZCKeeper_Client/Web/public/admin/policies.php:26, AZCKeeper_Client/Web/public/admin/policies.php:131; AZCKeeper_Client/Web/public/admin/releases.php:24; AZCKeeper_Client/Web/public/admin/panel-settings.php:45; AZCKeeper_Client/Web/src/Repos/ProductivityRepo.php:337. |
| Alto | Logs, pendientes y sync legacy globales | AZCKeeper_Client/Web/public/admin/logs.php:47; AZCKeeper_Client/Web/public/admin/pending-users.php:84; AZCKeeper_Client/Web/src/LegacySyncService.php:107. |
| Alto | Recalcular desde panel selecciona usuarios globales aunque lectura tenga scope | AZCKeeper_Client/Web/public/admin/productivity.php:29, AZCKeeper_Client/Web/public/admin/productivity.php:48. |

No se inspeccionaron los roles/permisos productivos: exposición efectiva depende de ellos. Los handlers deben aplicar aislamiento aun cuando el administrador conozca IDs o URLs fuera del menú.

### 6.3 Cambios de modelo propuestos

- tenant_id derivado de credenciales en identidades/membresías, dispositivos, sesiones, enrolamientos, políticas y toda telemetría/agregados.
- Si una persona pertenece a varias empresas, separar persona de membresía; en cualquier caso, identidad legacy única por fuente+tenant, no un entero global.
- Transferencia de dispositivo explícita/auditada, revocando sesiones y definiendo propiedad del histórico.
- Política global plataforma → tenant → sede/grupo → usuario → dispositivo; versiones compuestas y privilegio de plataforma separado.
- Assignment vigente único por membresía, o historial con intervalos no solapados; no escoger LIMIT 1 arbitrario.
- Repositorios con TenantContext obligatorio y constraints compuestas contra referencias cruzadas; configuración/branding/clasificaciones por tenant.

Requiere backfill y reconciliación, no solo una columna nullable. Bases actuales que cambiar: AZCKeeper_Client/Web/migrations/keeper_full_schema.sql:254, AZCKeeper_Client/Web/migrations/keeper_full_schema.sql:267, AZCKeeper_Client/Web/migrations/keeper_full_schema.sql:321; AZCKeeper_Client/Web/src/LegacySyncService.php:36.

### 6.4 Escrituras por segundo y crecimiento

Modelo, no medición: N equipos, E episodios elegibles/minuto, jornada activa de ocho horas.

- Filas episodios/día = N×E×480.
- Filas episodios/segundo durante jornada = N×E/60.
- Upserts actividad/s = N/A.

Derivación del envío/cierre: AZCKeeper_Client/Core/CoreService.cs:974, AZCKeeper_Client/Core/CoreService.cs:1366, AZCKeeper_Client/Core/CoreService.cs:1123.

| E supuesto | Equipos | Episodios/día | Filas episodios/s |
|---:|---:|---:|---:|
| 2/min | 180 | 172.800 | 6,00 |
| 2/min | 1.000 | 960.000 | 33,33 |
| 2/min | 5.000 | 4.800.000 | 166,67 |
| 10/min | 180 | 864.000 | 30,00 |
| 10/min | 1.000 | 4.800.000 | 166,67 |
| 10/min | 5.000 | 24.000.000 | 833,33 |

A=30 suma 6/33,33/166,67 upserts/s; A=10 suma 18/100/500. Más last_seen, sesiones, logs, índices y agregación. Filas, sentencias y round-trips son distintos: batch inserta muchas filas con una sentencia (AZCKeeper_Client/Web/src/Endpoints/WindowEpisodeBatch.php:149; AZCKeeper_Client/Web/src/Endpoints/ActivityDay.php:104; AZCKeeper_Client/Web/src/Repos/DeviceRepo.php:28).

Handshake normal existente/activo: aproximadamente seis sentencias de aplicación: bearer, device lookup, touch, global policy, union user/device y horario. Touch puede evitar modificar last_seen, pero envía query. No cuenta SET NAMES ni protocolo prepared (AZCKeeper_Client/Web/src/Repos/SessionRepo.php:9; AZCKeeper_Client/Web/src/Repos/DeviceRepo.php:7, AZCKeeper_Client/Web/src/Repos/DeviceRepo.php:28; AZCKeeper_Client/Web/src/Repos/PolicyRepo.php:30, AZCKeeper_Client/Web/src/Repos/PolicyRepo.php:63, AZCKeeper_Client/Web/src/Repos/PolicyRepo.php:94; AZCKeeper_Client/Web/src/Db.php:120).

6,9 millones es dato facilitado. Comentario histórico menciona 6,87M, no una medición actual (AZCKeeper_Client/Web/migrations/add_client_logs.sql:8). Con N=1.000/E=2 se añaden 6,9M en ~7,2 jornadas del modelo.

### 6.5 Índices, retención y particiones

**Ya presentes en esquema del repo; no añadir duplicados sin inspeccionar servidor:**

| Tabla | Índices existentes / evidencia |
|---|---|
| keeper_devices | GUID único, user, last_seen (AZCKeeper_Client/Web/migrations/keeper_full_schema.sql:204). |
| keeper_activity_day | UNIQUE(user,device,day), day, user/day, device/day y otros (AZCKeeper_Client/Web/migrations/keeper_full_schema.sql:361). |
| keeper_window_episode | user/day, device/day, start_at, process y user/day/process (AZCKeeper_Client/Web/migrations/keeper_full_schema.sql:435). |
| keeper_client_log | created y compuestos device/created, user/created, level/created, source/created (AZCKeeper_Client/Web/migrations/add_client_logs.sql:29). |
| keeper_user_assignments | Índices individuales user/firm/area/sede/sociedad; user no único en full schema (AZCKeeper_Client/Web/migrations/keeper_full_schema.sql:267). |

Propuestas sujetas a EXPLAIN sobre copia representativa:

- UNIQUE(tenant_id,device_id,event_uuid) para idempotencia; hoy solo INSERT/autoincrement (AZCKeeper_Client/Web/src/Endpoints/WindowEpisodeBatch.php:149; AZCKeeper_Client/Web/migrations/keeper_full_schema.sql:434).
- Índice tenant/user/device/tiempo según filtros/orden real de detalle; no inventar orden óptimo sin plan de ejecución (AZCKeeper_Client/Web/public/admin/user-dashboard.php:329).
- Acceso por fecha al inicio para retención global; user/day no sustituye índice temporal global (AZCKeeper_Client/Web/migrations/keeper_full_schema.sql:435).
- Agregados tenant/día/usuario/sede y claves acordes, para evitar releer crudos por dashboard (AZCKeeper_Client/Web/public/admin/index.php:142; AZCKeeper_Client/Web/src/Services/ProductivityCalculator.php:139).
- Unicidad de assignment vigente e índices compuestos tenant/scope/user; evitar fanout (AZCKeeper_Client/Web/migrations/keeper_full_schema.sql:267; AZCKeeper_Client/Web/src/LegacySyncService.php:36).
- Rate limiter atómico fuera de JSON audit; mantener audit para trazabilidad (AZCKeeper_Client/Web/src/Endpoints/ClientReEnroll.php:43).

No se identificó purga operativa de episodios en los flujos revisados. Logs sí tiene 30 días y lotes DELETE de 5.000, pero depende de productividad habilitada y su bucle no tiene presupuesto temporal propio. Falta política integral de episodios, auditoría y sesiones expiradas (AZCKeeper_Client/Web/src/Repos/ClientLogRepo.php:15, AZCKeeper_Client/Web/src/Repos/ClientLogRepo.php:128; AZCKeeper_Client/Web/src/Endpoints/ProductivityCron.php:35, AZCKeeper_Client/Web/src/Endpoints/ProductivityCron.php:94).

Propuesta para acordar con negocio: crudos/títulos 30–90 días, agregados 13 meses, logs 30 días; archivo cifrado si hace falta más. No se presenta como obligación legal. Jobs separados con presupuesto, checkpoint y comprobación de restauración.

**Particionar no es ALTER rápido:** episodios tiene FK con cascada a users/devices. MySQL 8.0 no combina particionamiento de usuario InnoDB con esas FK; también hay restricciones de claves únicas/columnas de partición. Requiere rediseño, no receta ciega (AZCKeeper_Client/Web/migrations/keeper_full_schema.sql:440). [Documentación MySQL](https://dev.mysql.com/doc/refman/8.0/en/partitioning-limitations-storage-engines.html).

### 6.6 Agregaciones del panel

- **Alto:** users hace fetchAll y array_slice: paginación de lista en PHP; resúmenes sí se restringen a IDs de página (AZCKeeper_Client/Web/public/admin/users.php:278, AZCKeeper_Client/Web/public/admin/users.php:314, AZCKeeper_Client/Web/public/admin/users.php:317).
- **Alto:** user-dashboard descarga episodios del rango sin LIMIT; decide AJAX tras varias consultas pesadas (AZCKeeper_Client/Web/public/admin/user-dashboard.php:183, AZCKeeper_Client/Web/public/admin/user-dashboard.php:305, AZCKeeper_Client/Web/public/admin/user-dashboard.php:329, AZCKeeper_Client/Web/public/admin/user-dashboard.php:338).
- **Alto condicionado:** sedes-dashboard une dispositivos y actividad antes de SUM; varios dispositivos por usuario pueden multiplicar actividad por fanout. Probar fixture multidispositivo (AZCKeeper_Client/Web/public/admin/sedes-dashboard.php:95).
- **Alto:** cron por usuario hace fetchAll de episodios y detección histórica; 300 s sin checkpoint limita crecimiento (AZCKeeper_Client/Web/src/Services/ProductivityCalculator.php:134; AZCKeeper_Client/Web/src/Services/DualJobDetector.php:23; AZCKeeper_Client/Web/src/Endpoints/ProductivityCron.php:58).
- **Medio:** recalcular fecha histórica usa esa fecha para focus, pero DualJobDetector calcula respecto a hoy, sin recibir fecha objetivo (AZCKeeper_Client/Web/src/Endpoints/ProductivityCron.php:66, AZCKeeper_Client/Web/src/Endpoints/ProductivityCron.php:69; AZCKeeper_Client/Web/src/Services/DualJobDetector.php:23).

Propuesta: modelos de lectura agregados, cursor para episodios, exports como job, endpoints AJAX pequeños y workers por tenant/fecha con checkpoint. Mostrar frescura; probar dos dispositivos, cambio de assignment, medianoche, horarios y duplicados.

## 7. Pregunta 6 — Panel: rebrand y nuevas features

### 7.1 Construcción y dificultad

PHP con HTML/SQL/JS por página, partials header/footer, Tailwind CDN, Alpine y Google Fonts; sin React en K3. Header define navy #003A5D/rojo #BE1622; login duplica tema/cargas (AZCKeeper_Client/Web/public/admin/partials/layout_header.php:16, AZCKeeper_Client/Web/public/admin/partials/layout_header.php:18, AZCKeeper_Client/Web/public/admin/partials/layout_header.php:20, AZCKeeper_Client/Web/public/admin/partials/layout_header.php:49; AZCKeeper_Client/Web/public/admin/login.php:69, AZCKeeper_Client/Web/public/admin/login.php:77; AZCKeeper_Client/Web/public/admin/partials/layout_footer.php:5).

**Medio:** rebrand global acotado para elementos comunes, pero no basta editar CSS: tema inline y duplicado. assets/style.css existe, sin referencia encontrada desde páginas auditadas. Por tenant exige resolver empresa antes del render y un layout temático compartido (AZCKeeper_Client/Web/public/admin/assets/style.css:9; AZCKeeper_Client/Web/public/admin/partials/layout_header.php:51; AZCKeeper_Client/Web/public/admin/login.php:77).

**Medio:** Alpine se incluye también en páginas además de footer; revisar doble inicialización y fijar/self-hostear dependencias para despliegue reproducible (AZCKeeper_Client/Web/public/admin/roles.php:623; AZCKeeper_Client/Web/public/admin/panel-settings.php:292; AZCKeeper_Client/Web/public/admin/partials/layout_footer.php:5).

### 7.2 Inventario textual de marca

Búsqueda K3 de AZCKeeper o palabra AZC sin distinguir mayúsculas en código/documentación/proyectos; no secretos. Líneas de coincidencia, **no todas son texto visible**: muchas son namespaces/rutas/nombre de ejecutable.

| Archivo | Líneas |
|---|---|
| AZCKeeper_Client/Auth/AuthManager.cs | 5, 7, 31, 193, 203 |
| AZCKeeper_Client/Auth/LoginForm.cs | 5, 29 |
| AZCKeeper_Client/AZCKeeper_Client.csproj | 18, 29, 30 |
| AZCKeeper_Client/Blocking/KeyBlocker.cs | 8, 9, 11 |
| AZCKeeper_Client/Blocking/SystemProxyManager.cs | 7, 9 |
| AZCKeeper_Client/Blocking/WebBlockingManager.cs | 7, 8, 11, 19, 39, 56 |
| AZCKeeper_Client/BLOCKING_SYSTEM.md | 119 |
| AZCKeeper_Client/Config/ConfigManager.cs | 5, 7, 10, 32, 54, 55 |
| AZCKeeper_Client/Contracts/SecurityControlState.cs | 1 |
| AZCKeeper_Client/Core/CoreService.cs | 5, 6, 7, 8, 9, 10, 11, 12, 14, 235, 783, 828, 1032 |
| AZCKeeper_Client/Core/DebugSnapshot.cs | 3 |
| AZCKeeper_Client/Core/DebugWindowForm.cs | 4, 5, 7, 30 |
| AZCKeeper_Client/Core/MasterTimer.cs | 2, 4 |
| AZCKeeper_Client/Core/TimeSync.cs | 2, 4 |
| AZCKeeper_Client/Logging/LocalLogger.cs | 9, 12, 124, 603 |
| AZCKeeper_Client/Network/ApiClient.cs | 8, 9, 10, 11, 13, 92, 626 |
| AZCKeeper_Client/Network/NetworkBackoffPolicy.cs | 4 |
| AZCKeeper_Client/Network/OfflineQueue.cs | 6, 8, 26 |
| AZCKeeper_Client/Program.cs | 4, 5, 7, 10, 23, 25, 54 |
| AZCKeeper_Client/Security/SecurityControls.cs | 2, 4 |
| AZCKeeper_Client/Security/SecurityReportCache.cs | 8, 9, 11 |
| AZCKeeper_Client/Security/SecurityStateReader.cs | 3, 4, 7, 12 |
| AZCKeeper_Client/SQL1.txt | 2 |
| AZCKeeper_Client/Startup/StartupManager.cs | 4, 6, 16, 28, 93 |
| AZCKeeper_Client/Tracking/ActivityTracker.cs | 3, 4, 6 |
| AZCKeeper_Client/Tracking/KeyboardHook.cs | 1, 26 |
| AZCKeeper_Client/Tracking/MouseHook.cs | 1, 22 |
| AZCKeeper_Client/Tracking/WindowsTracker.cs | 5, 7 |
| AZCKeeper_Client/Tracking/WorkSchedule.cs | 3 |
| AZCKeeper_Client/Update/UpdateManager.cs | 9, 10, 11, 13, 203, 208, 238 |
| AZCKeeper_Client/Web/CHANGELOG_SESSION.md | 1, 12, 13, 267 |
| AZCKeeper_Client/Web/DATABASE_FALLBACK.md | 203 |
| AZCKeeper_Client/Web/Docs/PLAN_PRODUCTIVITY.md | 5 |
| AZCKeeper_Client/Web/migrations/add_client_logs.sql | 5 |
| AZCKeeper_Client/Web/public/admin/logs.php | 7 |
| AZCKeeper_Client/Web/public/admin/policies_diagnostic_backup.php | 265 |
| AZCKeeper_Client/Web/public/admin/releases.php | 3, 197 |
| AZCKeeper_Client/Web/public/admin/server-health.php | 295 |
| AZCKeeper_Client/Web/src/Endpoints/SecurityReport.php | 20 |
| AZCKeeper_Client/Web/src/Repos/ReleaseRepo.php | 17 |
| AZCKeeper_Client/Web/tests/run.php | 4 |
| AZCKeeperUpdater/Program.cs | 6, 18, 24, 29 |
| AZCKeeper.Tests/AZCKeeper.Tests.csproj | 14 |
| AZCKeeper.Tests/LocalLoggerReportQueueTests.cs | 2, 5, 83 |
| AZCKeeper.Tests/LocalLoggerRingBufferTests.cs | 2, 5 |
| AZCKeeper.Tests/NetworkBackoffPolicyTests.cs | 4, 7 |
| AZCKeeper.Tests/SecurityControlsTests.cs | 2 |
| AZCKeeper.Tests/SecurityReportCacheTests.cs | 3, 4 |
| AZCKeeper.Tests/SecurityReportContractTests.cs | 3, 6, 10 |
| AZCKeeper.Tests/SecurityStateReaderOrderTests.cs | 2 |

Otros elementos relevantes aunque no coincidan con esa regex:

| Elemento | Evidencia |
|---|---|
| Keeper Admin / Keeper, favicon y logo navegación | AZCKeeper_Client/Web/public/admin/partials/layout_header.php:16, AZCKeeper_Client/Web/public/admin/partials/layout_header.php:17, AZCKeeper_Client/Web/public/admin/partials/layout_header.php:75, AZCKeeper_Client/Web/public/admin/partials/layout_header.php:77. |
| Keeper en login, favicon y logo_main | AZCKeeper_Client/Web/public/admin/login.php:69, AZCKeeper_Client/Web/public/admin/login.php:70, AZCKeeper_Client/Web/public/admin/login.php:95, AZCKeeper_Client/Web/public/admin/login.php:96. |
| Host productivo y antiguo | AZCKeeper_Client/Config/ConfigManager.cs:241; AZCKeeper_Client/Web/public/admin/policies.php:1144; AZCKeeper_Client/Web/public/admin/policies_diagnostic_backup.php:896. |
| Prefijo técnico azc_keeper del limiter | AZCKeeper_Client/Web/src/RateLimiter.php:110. |
| Nombre visible/login/tray/debug y rutas de estado | AZCKeeper_Client/Auth/LoginForm.cs:29; AZCKeeper_Client/Program.cs:54; AZCKeeper_Client/Core/DebugWindowForm.cs:30; AZCKeeper_Client/Logging/LocalLogger.cs:124; AZCKeeper_Client/Update/UpdateManager.cs:238. |

Assets raster inventariados en admin/assets: ico.png, icoBlack.png, icoMain.png, Icon Black.png, Icon White.png, Logo White.png, logo.png, logo_main.png. Usos activos de icoMain/logo_main en header/login citados. No se infiere contenido textual interno de imágenes no inspeccionadas visualmente.

Separar branding visible de identidad técnica: renombrar AppData, mutex, namespaces o ejecutables requiere migración/compatibilidad; no es requisito automático de cambio visual.

### 7.3 Patrón propuesto de features y tema por tenant

1. Catálogo único de módulos/rutas/permisos, compartido por menú y guards. Hoy catálogo SQL y listas PHP se duplican (AZCKeeper_Client/Web/migrations/keeper_full_schema.sql:18, AZCKeeper_Client/Web/migrations/keeper_full_schema.sql:510; AZCKeeper_Client/Web/public/admin/panel-settings.php:15; AZCKeeper_Client/Web/public/admin/admin_auth.php:251).
2. Caso de uso/servicio, repositorio TenantContext, validación y DTO por feature; página solo entrada/invocación/render.
3. Layout para panel/login/errores/export, CSS variables y assets versionados locales.
4. Configuración tenant de nombre/logos/paleta/textos validada; sin HTML/CSS arbitrario, fallback de plataforma.
5. Listas SQL paginadas y AJAX específico, no render completo para extraer JSON (AZCKeeper_Client/Web/public/admin/user-dashboard.php:338).
6. Tests de permiso de acción y acceso cruzado por tenant; menú oculto no prueba seguridad.


## 8. Pregunta 7 — Documentación de API para terceros

### 8.1 Qué hay hoy

Hay router explícito, handlers, DTO C# y documentación de módulos/cambios. No se encontró OpenAPI/Swagger ni portal de API pública en el alcance K3. El contrato real está repartido entre router, endpoints y DTO (AZCKeeper_Client/Web/public/index.php:44; AZCKeeper_Client/Network/ApiClient.cs:1244; AZCKeeper_Client/Web/Docs/PLAN_PRODUCTIVITY.md:5; AZCKeeper_Client/Web/CHANGELOG_SESSION.md:1).

La autenticación existente resuelve sesiones del cliente o cookies del panel. CRON_API_KEY es un secreto global de operación, no una API key empresarial con scopes. No debe entregarse a integradores ni reutilizarse un bearer de empleado (AZCKeeper_Client/Web/src/Repos/SessionRepo.php:9; AZCKeeper_Client/Web/src/Repos/AdminAuthRepo.php:70; AZCKeeper_Client/Web/src/Endpoints/ProductivityCron.php:119).

**Alto:** publicar endpoints de lectura antes de corregir tenancy expondría los problemas de §6. Primero fijar pertenencia/permiso del objeto, después documentar/exponer.

### 8.2 Requisitos propuestos

- Base /api/v1 para API externa, separada del protocolo legacy del cliente.
- Integraciones registradas por tenant, dueño, scopes, expiración y estado; API keys aleatorias guardadas con hash, prefijo identificador, rotación y auditoría.
- OAuth2 client_credentials para integraciones servidor-servidor; access token corto, audiencia/scopes/tenant. Si después hay delegación de usuario, Authorization Code con PKCE, como proyecto separado.
- Scopes de solo lectura por recurso; títulos de ventanas, logs y controles sensibles requieren scopes adicionales explícitos. No publicar hashes, tokens, PIN, política con secretos ni credenciales de fuentes.
- Tenant inferido de credenciales. Un parámetro tenant_id suministrado no concede acceso.
- Paginación por cursor y límite máximo; rango de fechas acotado; UTC ISO 8601, zona de negocio declarada; errores 401/403/404/422/429/5xx y request ID.
- Cuotas por integración y tenant, no solamente IP; Retry-After, observabilidad, logs sin secretos, política de deprecación y pruebas de contrato/aislamiento.
- Exportaciones grandes como jobs autorizados, no una consulta ilimitada del endpoint de episodios.
- CORS solo si se habilitan clientes de navegador autorizados; no habilitar comodín con cookies del panel.

Formato propuesto OpenAPI 3.0.3, con mecanismos apiKey y OAuth2: [especificación oficial](https://spec.openapis.org/oas/v3.0.3). No existe hoy este servicio OAuth ni las rutas /v1.

### 8.3 Recursos razonables y origen actual

| Recurso propuesto | Campos/alcance inicial | Fuente actual |
|---|---|---|
| users | Identificador de membresía, nombre, estado y assignment; minimizar CAZCKeeper_Client/email | AZCKeeper_Client/Web/public/admin/users.php:260. |
| devices | ID, usuario, versión, estado, last_seen; omitir secretos/GUID de enrolamiento hasta corregirlo | AZCKeeper_Client/Web/public/admin/devices.php:100; AZCKeeper_Client/Web/src/Repos/DeviceRepo.php:28. |
| organization | Firmas, sociedades, sedes, áreas/cargos propios | AZCKeeper_Client/Web/public/admin/organization.php:28. |
| activity-days | Día, acumulados y zona; por usuario/device autorizado | AZCKeeper_Client/Web/src/Endpoints/ActivityDay.php:225. |
| window-episodes | Proceso, intervalo, duración; título solo con permiso adicional | AZCKeeper_Client/Web/public/admin/user-dashboard.php:329. |
| productivity/daily | Focus/productividad y fecha de cálculo/frescura | AZCKeeper_Client/Web/src/Repos/ProductivityRepo.php:15. |
| alerts | Tipo, fecha, estado de revisión; no permitir revisión en primera versión | AZCKeeper_Client/Web/src/Repos/ProductivityRepo.php:190. |
| devices/{id}/security | Último estado reportado; no confundir declaración con attestation | AZCKeeper_Client/Web/src/Repos/SecurityStateRepo.php:20. |
| devices/{id}/policy | Política efectiva **saneada**, hash/versión/composición; excluir PIN/webhooks | AZCKeeper_Client/Web/src/Endpoints/ClientHandshake.php:147. |

### 8.4 Esqueleto OpenAPI 3 propuesto

Dominio example.invalid deliberadamente ilustrativo. API key y OAuth son alternativas; los scopes de API key se validan en servidor aunque OpenAPI no los enumere como scopes OAuth. Page es un contenedor inicial genérico; antes de publicar se deben fijar DTO específicos de la tabla anterior, ejemplos, límites temporales y tipos de cada campo. No es descripción de endpoints ya implementados.

```yaml
openapi: 3.0.3
info:
  title: Keeper - API empresarial de lectura
  version: 1.0.0-draft
  description: >
    Propuesta. El tenant se obtiene de las credenciales.
    No incluye endpoints de control ni datos secretos.
servers:
  - url: https://api.example.invalid/api/v1
security:
  - TenantApiKey: []
  - TenantOAuth: [users:read]
paths:
  /users:
    get:
      operationId: listUsers
      security:
        - TenantApiKey: []
        - TenantOAuth: [users:read]
      parameters:
        - $ref: '#/components/parameters/Cursor'
        - $ref: '#/components/parameters/Limit'
      responses:
        '200': { $ref: '#/components/responses/Page' }
        '401': { $ref: '#/components/responses/Unauthorized' }
        '403': { $ref: '#/components/responses/Forbidden' }
        '429': { $ref: '#/components/responses/RateLimited' }
  /devices:
    get:
      operationId: listDevices
      security:
        - TenantApiKey: []
        - TenantOAuth: [devices:read]
      parameters:
        - $ref: '#/components/parameters/Cursor'
        - $ref: '#/components/parameters/Limit'
      responses:
        '200': { $ref: '#/components/responses/Page' }
        '401': { $ref: '#/components/responses/Unauthorized' }
        '403': { $ref: '#/components/responses/Forbidden' }
        '429': { $ref: '#/components/responses/RateLimited' }
  /organization:
    get:
      operationId: getOrganization
      security:
        - TenantApiKey: []
        - TenantOAuth: [organization:read]
      responses:
        '200': { $ref: '#/components/responses/Resource' }
        '401': { $ref: '#/components/responses/Unauthorized' }
        '403': { $ref: '#/components/responses/Forbidden' }
        '429': { $ref: '#/components/responses/RateLimited' }
  /activity-days:
    get:
      operationId: listActivityDays
      security:
        - TenantApiKey: []
        - TenantOAuth: [activity:read]
      parameters:
        - $ref: '#/components/parameters/From'
        - $ref: '#/components/parameters/To'
        - $ref: '#/components/parameters/UserId'
        - $ref: '#/components/parameters/Cursor'
        - $ref: '#/components/parameters/Limit'
      responses:
        '200': { $ref: '#/components/responses/Page' }
        '401': { $ref: '#/components/responses/Unauthorized' }
        '403': { $ref: '#/components/responses/Forbidden' }
        '422': { $ref: '#/components/responses/InvalidQuery' }
        '429': { $ref: '#/components/responses/RateLimited' }
  /window-episodes:
    get:
      operationId: listWindowEpisodes
      description: El título se omite salvo scope adicional episodes:titles:read.
      security:
        - TenantApiKey: []
        - TenantOAuth: [episodes:read]
      parameters:
        - $ref: '#/components/parameters/From'
        - $ref: '#/components/parameters/To'
        - $ref: '#/components/parameters/UserId'
        - $ref: '#/components/parameters/Cursor'
        - $ref: '#/components/parameters/Limit'
      responses:
        '200': { $ref: '#/components/responses/Page' }
        '401': { $ref: '#/components/responses/Unauthorized' }
        '403': { $ref: '#/components/responses/Forbidden' }
        '422': { $ref: '#/components/responses/InvalidQuery' }
        '429': { $ref: '#/components/responses/RateLimited' }
  /productivity/daily:
    get:
      operationId: listDailyProductivity
      security:
        - TenantApiKey: []
        - TenantOAuth: [productivity:read]
      parameters:
        - $ref: '#/components/parameters/From'
        - $ref: '#/components/parameters/To'
        - $ref: '#/components/parameters/UserId'
        - $ref: '#/components/parameters/Cursor'
        - $ref: '#/components/parameters/Limit'
      responses:
        '200': { $ref: '#/components/responses/Page' }
        '401': { $ref: '#/components/responses/Unauthorized' }
        '403': { $ref: '#/components/responses/Forbidden' }
        '422': { $ref: '#/components/responses/InvalidQuery' }
        '429': { $ref: '#/components/responses/RateLimited' }
  /alerts:
    get:
      operationId: listAlerts
      security:
        - TenantApiKey: []
        - TenantOAuth: [alerts:read]
      parameters:
        - $ref: '#/components/parameters/From'
        - $ref: '#/components/parameters/To'
        - $ref: '#/components/parameters/Cursor'
        - $ref: '#/components/parameters/Limit'
      responses:
        '200': { $ref: '#/components/responses/Page' }
        '401': { $ref: '#/components/responses/Unauthorized' }
        '403': { $ref: '#/components/responses/Forbidden' }
        '422': { $ref: '#/components/responses/InvalidQuery' }
        '429': { $ref: '#/components/responses/RateLimited' }
  /devices/{deviceId}/security:
    get:
      operationId: getDeviceSecurity
      security:
        - TenantApiKey: []
        - TenantOAuth: [security:read]
      parameters:
        - $ref: '#/components/parameters/DeviceId'
      responses:
        '200': { $ref: '#/components/responses/Resource' }
        '401': { $ref: '#/components/responses/Unauthorized' }
        '403': { $ref: '#/components/responses/Forbidden' }
        '404': { $ref: '#/components/responses/NotFound' }
        '429': { $ref: '#/components/responses/RateLimited' }
  /devices/{deviceId}/policy:
    get:
      operationId: getSanitizedDevicePolicy
      description: Excluye PIN, tokens y credenciales; soporta ETag.
      security:
        - TenantApiKey: []
        - TenantOAuth: [policies:read]
      parameters:
        - $ref: '#/components/parameters/DeviceId'
        - in: header
          name: If-None-Match
          schema: { type: string }
      responses:
        '200':
          description: Política efectiva saneada.
          headers:
            ETag:
              schema: { type: string }
          content:
            application/json:
              schema:
                type: object
                required: [version_hash, composition, settings]
                properties:
                  version_hash: { type: string }
                  composition:
                    type: array
                    items: { type: object }
                  settings:
                    type: object
                    description: Lista explícita de campos seguros.
        '304':
          description: La representación no cambió.
        '401': { $ref: '#/components/responses/Unauthorized' }
        '403': { $ref: '#/components/responses/Forbidden' }
        '404': { $ref: '#/components/responses/NotFound' }
        '429': { $ref: '#/components/responses/RateLimited' }
components:
  securitySchemes:
    TenantApiKey:
      type: apiKey
      in: header
      name: X-API-Key
      description: Integración con tenant y permisos de lectura asignados.
    TenantOAuth:
      type: oauth2
      flows:
        clientCredentials:
          tokenUrl: https://auth.example.invalid/oauth/token
          scopes:
            users:read: Consultar usuarios autorizados
            devices:read: Consultar dispositivos
            organization:read: Consultar organización
            activity:read: Consultar actividad diaria
            episodes:read: Consultar episodios sin títulos
            episodes:titles:read: Consultar títulos autorizados
            productivity:read: Consultar agregados
            alerts:read: Consultar alertas
            security:read: Consultar estado reportado
            policies:read: Consultar política saneada
  parameters:
    Cursor:
      in: query
      name: cursor
      schema: { type: string }
      description: Cursor opaco ligado a tenant, filtros y orden.
    Limit:
      in: query
      name: limit
      schema: { type: integer, minimum: 1, maximum: 200, default: 50 }
    From:
      in: query
      name: from
      required: true
      schema: { type: string, format: date }
    To:
      in: query
      name: to
      required: true
      schema: { type: string, format: date }
      description: Validar orden y máximo de rango en servidor.
    UserId:
      in: query
      name: user_id
      schema: { type: string }
      description: Identificador propio del tenant; no concede acceso.
    DeviceId:
      in: path
      name: deviceId
      required: true
      schema: { type: string }
  schemas:
    Page:
      type: object
      required: [data, next_cursor, generated_at]
      properties:
        data:
          type: array
          items:
            type: object
            description: DTO del recurso, pendiente de fijar antes de publicar.
        next_cursor: { type: string, nullable: true }
        generated_at: { type: string, format: date-time }
    Error:
      type: object
      required: [code, message, request_id]
      properties:
        code: { type: string }
        message: { type: string }
        request_id: { type: string }
  responses:
    Page:
      description: Página del recurso dentro del tenant autenticado.
      content:
        application/json:
          schema: { $ref: '#/components/schemas/Page' }
    Resource:
      description: Recurso autorizado y saneado.
      content:
        application/json:
          schema: { type: object }
    Unauthorized:
      description: Credencial ausente, inválida o expirada.
      content:
        application/json:
          schema: { $ref: '#/components/schemas/Error' }
    Forbidden:
      description: Permiso insuficiente.
      content:
        application/json:
          schema: { $ref: '#/components/schemas/Error' }
    NotFound:
      description: Recurso no encontrado dentro del ámbito autorizado.
      content:
        application/json:
          schema: { $ref: '#/components/schemas/Error' }
    InvalidQuery:
      description: Filtros, fechas o rango inválidos.
      content:
        application/json:
          schema: { $ref: '#/components/schemas/Error' }
    RateLimited:
      description: Cuota de integración o tenant agotada.
      headers:
        Retry-After:
          schema: { type: integer, minimum: 1 }
          description: Segundos hasta el siguiente intento.
      content:
        application/json:
          schema: { $ref: '#/components/schemas/Error' }
```

Antes de convertirlo en contrato público: tipar DTO por recurso, agregar ejemplos/errores 5xx y límites concretos, implementar scopes/tenancy, generar validación de contrato y fijar compatibilidad. No exponer automáticamente SELECT * ni effectiveConfig completa.


## 9. Pregunta 8 — Seguridad transversal

### 9.1 Autenticación y ciclo de vida

**Crítico — reenrolamiento:** se activa por defecto si falta setting, exige GUID conocido y estados activos, pero no contraseña, secreto por dispositivo, certificado ni invitación. Crea token válido por 30 días. No publicar como “recuperación segura” y no considerar GUID secreto suficiente. Contención: deshabilitarlo hasta requerir prueba de posesión; mantener proceso de recuperación explícito/auditado (AZCKeeper_Client/Web/src/Endpoints/ClientReEnroll.php:35, AZCKeeper_Client/Web/src/Endpoints/ClientReEnroll.php:56, AZCKeeper_Client/Web/src/Endpoints/ClientReEnroll.php:75, AZCKeeper_Client/Web/src/Endpoints/ClientReEnroll.php:88, AZCKeeper_Client/Web/src/Endpoints/ClientReEnroll.php:103, AZCKeeper_Client/Web/src/Endpoints/ClientReEnroll.php:120; AZCKeeper_Client/Web/src/Repos/SessionRepo.php:27).

**Crítico — dispositivo/IDOR:** handshake cambia propietario de dispositivo ya existente; login también asegura/reasigna device por GUID. Un bearer de un usuario no debe poder tomar otro dispositivo. Vincular sesión a device/tenant y exigir transferencia administrativa explícita (AZCKeeper_Client/Web/src/Endpoints/ClientHandshake.php:77; AZCKeeper_Client/Web/src/Endpoints/ClientLogin.php:230; AZCKeeper_Client/Web/src/Repos/SessionRepo.php:9).

**Alto — revocación:** login inserta expires_at NULL, mientras reenroll usa 30 días. Validación bearer revisa expiración/revocación, no estado actual del usuario; inactivar usuario no revoca sesiones. Fallback de login Keeper tampoco filtra status. Definir expiración uniforme, refresh/rotación y revocación al cambiar usuario/device/tenant (AZCKeeper_Client/Web/src/Endpoints/ClientLogin.php:72, AZCKeeper_Client/Web/src/Endpoints/ClientLogin.php:153; AZCKeeper_Client/Web/src/Repos/UserRepo.php:8; AZCKeeper_Client/Web/src/Repos/SessionRepo.php:9; AZCKeeper_Client/Web/public/admin/users.php:212).

**Alto — contraseñas legacy:** login compara directamente password legacy con el valor recibido. Hay fallback con password_verify local, pero no sustituye el legado. Migrar a hashes robustos y verificar transición; no se leyeron passwords reales (AZCKeeper_Client/Web/src/Endpoints/ClientLogin.php:72, AZCKeeper_Client/Web/src/Endpoints/ClientLogin.php:113).

**Medio — login admin:** verifica estado de cuenta/usuario y password_verify, pero sin rate limiter/CSRF ni regeneración de sesión identificados en el handler; errores distinguen condiciones. Cookie HttpOnly, SameSite=Lax y secure condicionado a HTTPS son controles positivos, con revisión necesaria tras reverse proxy (AZCKeeper_Client/Web/public/admin/login.php:28, AZCKeeper_Client/Web/public/admin/login.php:37, AZCKeeper_Client/Web/public/admin/login.php:43, AZCKeeper_Client/Web/public/admin/login.php:50).

**Controles positivos:** tokens aleatorios de 32 bytes y hash SHA-256 en BD; credenciales/token locales protegidos con DPAPI CurrentUser. DPAPI no protege frente al mismo usuario comprometido ni hace al agente resistente a manipulación local (AZCKeeper_Client/Web/src/Endpoints/ClientLogin.php:153; AZCKeeper_Client/Web/src/Repos/SessionRepo.php:27; AZCKeeper_Client/Auth/AuthManager.cs:96, AZCKeeper_Client/Auth/AuthManager.cs:150, AZCKeeper_Client/Auth/AuthManager.cs:255).

### 9.2 Autorización administrativa y CSRF

**Alto:** scope de lista no autoriza ID de mutación. Hallazgos concretos de users, devices, assignments, admin-users y alertas están en §6. Centralizar autorización de objeto y default-deny, incluidas exportaciones/AJAX (AZCKeeper_Client/Web/public/admin/admin_auth.php:150, AZCKeeper_Client/Web/public/admin/admin_auth.php:179; AZCKeeper_Client/Web/src/Repos/ProductivityRepo.php:285).

**Alto:** políticas aplica acciones globales sin canDo específico; force-handshake valida solo cookie; backup diagnóstico conserva csrfOk=true. Hay protección CSRF real en productivity: no se afirma que todo el panel carezca de ella. SameSite=Lax reduce algunos ataques cross-site, pero no sustituye token CSRF y método seguro, especialmente logout por GET y operaciones same-site (AZCKeeper_Client/Web/public/admin/policies.php:19; AZCKeeper_Client/Web/src/Endpoints/ForceHandshake.php:10; AZCKeeper_Client/Web/public/admin/policies_diagnostic_backup.php:25; AZCKeeper_Client/Web/public/admin/productivity.php:16, AZCKeeper_Client/Web/public/admin/productivity.php:80; AZCKeeper_Client/Web/public/admin/logout.php:12).

**Medio:** panel-settings usa adminUser['id'] para atribución, mientras el repositorio expone admin_id. Puede causar atribución nula/incorrecta o aviso según ejecución; no se comprobó en runtime (AZCKeeper_Client/Web/public/admin/panel-settings.php:64, AZCKeeper_Client/Web/public/admin/panel-settings.php:66; AZCKeeper_Client/Web/src/Repos/AdminAuthRepo.php:77).

### 9.3 Secretos y exposición de datos

- **Alto:** PIN en configuración JSON y mensajes de Info/Warn; redactor remoto cubre bearer/token/password/webhook, no PIN/UnlockPin. Warn puede llegar al backend y panel global. Dejar de registrar valores, sanear origen+servidor y revisar histórico/rotar PIN por procedimiento controlado (AZCKeeper_Client/Config/ConfigManager.cs:416; AZCKeeper_Client/Core/CoreService.cs:551, AZCKeeper_Client/Core/CoreService.cs:555, AZCKeeper_Client/Core/CoreService.cs:561, AZCKeeper_Client/Core/CoreService.cs:576; AZCKeeper_Client/Web/src/Endpoints/ClientLogBatch.php:113; AZCKeeper_Client/Web/public/admin/logs.php:47).
- **Alto:** prueba de conexión de fuentes recibe password por GET, exponiéndolo potencialmente a URL/historial/access logs. El backend conecta al host/puerto proporcionado con PDO. Es una capacidad de conexión saliente sensible; restringir destino/permiso y transportar secretos fuera de URL. No se hizo una prueba de acceso a redes internas (AZCKeeper_Client/Web/public/admin/organization.php:186, AZCKeeper_Client/Web/public/admin/organization.php:196, AZCKeeper_Client/Web/public/admin/organization.php:202).
- **Medio:** passwords de keeper_data_sources cifrados AES-256-CBC con APP_KEY; no autenticación del ciphertext identificada. Mantener clave fuera del repo y usar cifrado autenticado si se conserva esa función (AZCKeeper_Client/Web/src/Db.php:238, AZCKeeper_Client/Web/src/Db.php:251, AZCKeeper_Client/Web/src/Db.php:267).
- **Alto:** títulos de ventana, metadatos y logs pueden contener información sensible. Meta JSON no se limita/redacta integralmente; API pública debe usar allowlist de campos y permiso separado para títulos (AZCKeeper_Client/Web/src/Endpoints/ClientLogBatch.php:90; AZCKeeper_Client/Web/src/Endpoints/WindowEpisodeBatch.php:76; AZCKeeper_Client/Web/public/admin/user-dashboard.php:329).
- **Medio:** errores SQL de endpoints y DEBUG pueden revelar internos; server-health muestra datos globales de host/BD a quien acceda al módulo (AZCKeeper_Client/Web/src/Endpoints/WindowEpisode.php:125; AZCKeeper_Client/Web/src/Endpoints/WindowEpisodeBatch.php:176; AZCKeeper_Client/Web/src/Endpoints/ClientLogin.php:184; AZCKeeper_Client/Web/public/admin/server-health.php:110).
- **Medio, condicionado al consumidor:** exportación CSV usa títulos sin neutralización de fórmulas de hoja de cálculo. Un valor que empiece con fórmula puede interpretarse al abrir el archivo en un spreadsheet. No se ejecutó exploit (AZCKeeper_Client/Web/public/admin/user-dashboard.php:373, AZCKeeper_Client/Web/public/admin/user-dashboard.php:374).

No se encontró necesario leer .env, .env.backup, tokens ni contraseñas reales para estos hallazgos. No se afirma que estén filtrados públicamente: se identificaron rutas de exposición en código.

### 9.4 SQLi, validación y abuso de recursos

PDO usa prepared statements nativos y parámetros; el CRUD dinámico de organización aplica un mapa cerrado de tablas. No hay evidencia suficiente para declarar una SQLi explotable en esos puntos, ni se certifica ausencia total de SQLi por revisión estática (AZCKeeper_Client/Web/src/Db.php:106; AZCKeeper_Client/Web/public/admin/organization.php:28, AZCKeeper_Client/Web/public/admin/organization.php:36).

**Alto:** cuerpo completo antes de validar, metadatos/logs y lista de dominios sin presupuesto global de bytes; límites de registros no evitan un request enorme. Añadir límites coherentes webserver/aplicación, timeout, validación de tipos/duración y cuotas autenticadas (AZCKeeper_Client/Web/src/Http.php:19; AZCKeeper_Client/Web/src/Endpoints/ClientLogBatch.php:90; AZCKeeper_Client/Web/src/InputValidator.php:92; AZCKeeper_Client/Web/src/Endpoints/WindowEpisodeBatch.php:76).

Telemetría y controles son declaraciones del cliente per-user: un token permite enviar datos construidos por software del usuario. No tratar agent_present/registry report como attestation criptográfica ni como prueba incorruptible de actividad (AZCKeeper_Client/Web/src/Endpoints/SecurityReport.php:44; AZCKeeper_Client/Web/src/Repos/SecurityStateRepo.php:20; AZCKeeper_Client/Core/CoreService.cs:800).

### 9.5 Integridad del updater

**Crítico:** URL viene de release administrable, descarga completa, solo umbral mínimo de tamaño, extracción y ejecución del updater contenido en el mismo ZIP. No se identificaron hash/firma, allowlist estricta de origen ni pin de clave de firma. HTTPS por sí solo no protege de una release maliciosa autorizada por servidor comprometido. No se afirmó ZIP-slip: ese ataque no fue demostrado (AZCKeeper_Client/Web/public/admin/releases.php:24; AZCKeeper_Client/Update/UpdateManager.cs:218, AZCKeeper_Client/Update/UpdateManager.cs:219, AZCKeeper_Client/Update/UpdateManager.cs:235, AZCKeeper_Client/Update/UpdateManager.cs:238, AZCKeeper_Client/Update/UpdateManager.cs:259).

Propuesta:

1. Manifest firmado con versión, canal, longitud, hash SHA-256 y compatibilidad mínima.
2. Clave pública de verificación anclada en cliente/updater; hash recibido solo del mismo servidor comprometido no basta.
3. Verificación antes de extraer/ejecutar, política anti-downgrade, límites de bytes y espacio.
4. No ejecutar helper no verificado extraído de paquete; firma del ejecutable y del artefacto, reemplazo/rollback probado.
5. Releases por anillos/tenant con jitter y permisos separados; auditar creación, activación y actualización forzada.

ReleaseRepo ordena por created_at, no por versión semántica: una release cargada después no necesariamente es versión más alta. Definir orden/canales explícitos (AZCKeeper_Client/Web/src/Repos/ReleaseRepo.php:39).

### 9.6 PAC, proxy y bloqueo local

**Alto — estado actual:** K3 actual no hace enforcement web; no se auditó el Agent excluido. No presentar caché de política como protección aplicada (AZCKeeper_Client/Blocking/WebBlockingManager.cs:14, AZCKeeper_Client/Blocking/WebBlockingManager.cs:81).

**Medio — propiedad de configuración:** limpieza elimina listas URLBlocklist HKCU de navegadores y restaura proxy; la heurística de propiedad basada en contener 127.0.0.1 puede afectar otro proxy local corporativo. No hay evidencia de que haya ocurrido, pero el criterio es demasiado amplio (AZCKeeper_Client/Blocking/WebBlockingManager.cs:86; AZCKeeper_Client/Blocking/SystemProxyManager.cs:83, AZCKeeper_Client/Blocking/SystemProxyManager.cs:124).

PAC histórico es local y devuelve blackhole loopback, no exfiltra hacia el backend en la implementación inspeccionada. El servicio local acepta solicitudes por tareas y su disponibilidad también necesita límites si vuelve a usarse; no se hizo prueba de saturación local (4f6aea5:AZCKeeper_Client/Blocking/PacContentBuilder.cs:15; 4f6aea5:AZCKeeper_Client/Blocking/LocalPacServer.cs:105).

**Medio:** endpoint unlock acepta cualquier PIN no vacío, aunque KeyBlocker compara localmente antes de invocarlo. No se concluye que llamar al endpoint desbloquee por sí solo la pantalla; el contrato servidor no verifica la autorización que aparenta representar (AZCKeeper_Client/Web/src/Endpoints/DeviceLock.php:65; AZCKeeper_Client/Blocking/KeyBlocker.cs:568).

Propuesta: propiedad explícita de cambios HKCU, backup/restore verificable, excluir host de gestión de política y distinguir control efectivo de caché/estado declarado. Un programa per-user sin admin no ofrece resistencia fuerte frente al mismo usuario con control de su sesión.


## Suplemento — Revisión disponible al reanudar: 1638bd4

**No sustituye la auditoría completa de 931fd12 solicitada originalmente.** Al reanudar, HEAD era 1638bd493d884a0741e4131ee2d8089b5b2d9b76, rama feature/moazc-bridge; el commit original sigue disponible. No se hizo checkout ni se modificó código. Citas de este suplemento llevan prefijo **1638bd4:**. Una consulta graphify adicional no identificó bien ExternalAuth; se contrastó router/handler directamente.

### Diferencias materiales

- **PAC sí existe en esta revisión:** WebBlockingManager crea LocalPacServer, aplica estado local y añade lock/marca de shutdown. La ausencia de enforcement de 931fd12 no se atribuye a este checkout (1638bd4:AZCKeeper_Client/Blocking/WebBlockingManager.cs:14, 1638bd4:AZCKeeper_Client/Blocking/WebBlockingManager.cs:30, 1638bd4:AZCKeeper_Client/Blocking/WebBlockingManager.cs:50, 1638bd4:AZCKeeper_Client/Blocking/WebBlockingManager.cs:56).
- Router de entrega no publica /client/security/report y añade tres rutas externas. El inventario §2 es completo para 931fd12, no para cualquier rama sin fijar versión (1638bd4:AZCKeeper_Client/Web/public/index.php:44).
- Ya existe un bridge externo concreto, documentado en comentario del handler; no es OAuth/OpenAPI multiempresa. Añadir MOAZC_BRIDGE_SECRET al inventario de entorno si se despliega esta revisión (1638bd4:AZCKeeper_Client/Web/src/Endpoints/ExternalAuth.php:11, 1638bd4:AZCKeeper_Client/Web/src/Endpoints/ExternalAuth.php:133).
- Marca adicional MOAZC en ExternalAuth.php:11, :13, :88 y :93. Inventario principal de marca comprobado contra 931fd12, no líneas desplazadas del worktree.

### Inventario adicional de endpoints

Todos requieren X-Bridge-Secret comparado con MOAZC_BRIDGE_SECRET mediante hash_equals; configuración ausente devuelve 503 y secreto inválido 401. No hay tenant/scopes por integración en ese mecanismo (1638bd4:AZCKeeper_Client/Web/src/Endpoints/ExternalAuth.php:133).

| Método/ruta | Tablas / comportamiento | Evidencia |
|---|---|---|
| GET /api/external/roster | R keeper_users, keeper_user_assignments, keeper_sedes, keeper_firmas, keeper_areas; todos los activos, fetchAll sin paginación/tenant | 1638bd4:AZCKeeper_Client/Web/public/index.php:49; 1638bd4:AZCKeeper_Client/Web/src/Endpoints/ExternalAuth.php:23, 1638bd4:AZCKeeper_Client/Web/src/Endpoints/ExternalAuth.php:101. |
| GET /api/external/sites-and-firms | R keeper_sedes y keeper_firmas globales | 1638bd4:AZCKeeper_Client/Web/public/index.php:50; 1638bd4:AZCKeeper_Client/Web/src/Endpoints/ExternalAuth.php:113. |
| POST /api/external/verify-credentials | R users/assignments/sedes/firmas/áreas y employee legacy; INSERT/UPDATE keeper_users, sync keeper_user_assignments y W keeper_audit_log | 1638bd4:AZCKeeper_Client/Web/public/index.php:64; 1638bd4:AZCKeeper_Client/Web/src/Endpoints/ExternalAuth.php:45, 1638bd4:AZCKeeper_Client/Web/src/Endpoints/ExternalAuth.php:74, 1638bd4:AZCKeeper_Client/Web/src/Endpoints/ExternalAuth.php:87, 1638bd4:AZCKeeper_Client/Web/src/Endpoints/ExternalAuth.php:180. |

### Hallazgos del complemento

1. **Alto:** secreto compartido accede al roster global con CC/email/organización. publicUser omite password_hash, pero no restringe tenant (1638bd4:AZCKeeper_Client/Web/src/Endpoints/ExternalAuth.php:105, 1638bd4:AZCKeeper_Client/Web/src/Endpoints/ExternalAuth.php:205).
2. **Alto:** verificación por email no exige status activo; devolverlo al consumidor no equivale a rechazarlo (1638bd4:AZCKeeper_Client/Web/src/Endpoints/ExternalAuth.php:68, 1638bd4:AZCKeeper_Client/Web/src/Endpoints/ExternalAuth.php:142).
3. **Alto:** compara password legacy en claro y puede crear/actualizar usuario/assignment. Esta ruta no es completamente de lectura y no pertenece al contrato read-only propuesto (1638bd4:AZCKeeper_Client/Web/src/Endpoints/ExternalAuth.php:71, 1638bd4:AZCKeeper_Client/Web/src/Endpoints/ExternalAuth.php:191, 1638bd4:AZCKeeper_Client/Web/src/Endpoints/ExternalAuth.php:195).
4. **Medio/Alto operativo:** 60 intentos/300 s por IP y 10/300 s por email, claves crc32 en limiter de archivos. El límite IP equivale a 12/min: login masivo desde NAT puede agotarlo. No se afirma despliegue productivo de esta rama (1638bd4:AZCKeeper_Client/Web/src/Endpoints/ExternalAuth.php:49, 1638bd4:AZCKeeper_Client/Web/src/Endpoints/ExternalAuth.php:59).
5. **Medio:** roster necesita paginación/cuotas. OpenAPI §8 sigue siendo propuesta; debe encapsular/reemplazar bridge con autorización tenant (1638bd4:AZCKeeper_Client/Web/src/Endpoints/ExternalAuth.php:105).

Acción prioritaria adicional: antes de desplegar bridge a terceros, restringir integración/tenant, rechazar inactivos, separar verificación de credenciales de lectura y dimensionar login NAT. Esfuerzo M, dependiente del proyecto de identidad/tenancy.

## 10. Plan de acción priorizado

Esfuerzo relativo: **S** cambio localizado; **M** varios componentes/pruebas; **L** subsistema/migración; **XL** rediseño transversal. No son estimaciones en días. Ninguna acción de esta tabla se ejecutó durante la auditoría.

### 10.1 Quick wins y contención

| Prioridad | Acción | Esfuerzo | Criterio de cierre / dependencia |
|---|---|---:|---|
| P0 | Deshabilitar reenrolamiento sin prueba de posesión y preparar recuperación autenticada | S / M para sustitución | GUID solo no obtiene token; recuperación legítima auditada (§9.1). |
| P0 | Rechazar reasignación implícita de GUID y comprobar device de sesión | M | Token de A no toma dispositivo de B; transferencia explícita revoca sesiones (§6.2). |
| P0 | Dejar de registrar PIN y sanear envío; revisar histórico/rotación | S | PIN no aparece en local/remoto/meta; recuperación de usuarios prevista (§9.3). |
| P0 | Contener distribución de artefactos no verificados y restringir gestión de releases | S | Ningún paquete nuevo sin validación; solución criptográfica es proyecto separado (§9.5). |
| P0 | Corregir guards de objeto en AJAX/mutaciones y restringir páginas globales | M | Matriz de dos tenants/roles prueba 403/404, incluida exportación (§6.2). |
| P1 | No reiniciar update timer en política idéntica; evitar Start duplicado | S | Cadencia de versión responde al intervalo, no al handshake (§5.2). |
| P1 | Corregir segundos/minutos y aumentar intervalos con jitter de arranque | M | Config efectiva coincide con política; curva real por endpoint se acerca al modelo (§5.1, §5.6). |
| P1 | Diferenciar 403 de auth inválida y respetar Retry-After | M | Ban/429 no borra credenciales; no retry anticipado (§5.5). |
| P1 | CSRF uniforme en mutaciones; retirar exposición del backup diagnóstico | M | Acciones sin token válido fallan; menú no sustituye canDo (§9.2). |
| P1 | Expiración/revocación uniforme y rechazo de usuario inactivo | M | Token anterior deja de servir al inactivar/transferir (§9.1). |
| P1 | Corregir seed doble, cierre final y applicable_days | M | Pruebas de reinicio/retoma/medianoche conservan métricas (§4.2, §4.3). |
| P1 | Separar purga de logs, añadir presupuesto/checkpoint y límites de request | M | Retención funciona con productividad desactivada; request grande se rechaza temprano (§3.4, §9.4). |
| P1 | Mitigación CSF/LFD para IPs dedicadas más límites L7 por identidad | S operativo | Operación acuerda umbrales/allowlist temporal; monitoreo distingue sockets de HTTP (§5.7). |
| P2 | Eliminar password de GET de prueba de conexión y acotar destinos | S/M | URL/log no lleva secreto; prueba solo para destinos autorizados (§9.3). |

### 10.2 Proyectos estructurales

| Prioridad | Proyecto | Esfuerzo | Dependencias / salida verificable |
|---|---|---:|---|
| P0 | Updater con manifest/firma, confianza anclada, rollback y anillos | L | Contención inmediata; rechaza ZIP modificado, origen/firma inválida y downgrade (§9.5). |
| P0 | TenantContext de extremo a extremo y migración de identidades/assignments | XL | Decidir tenant comercial; backfill y constraints; tests cruzados de todas las rutas (§6). |
| P1 | Planificador común, lotes persistentes, coalescencia e idempotencia | L | API ACK por registro; pruebas de timeout tras commit, circuito abierto y reconexión NAT (§4, §5). |
| P1 | /client/sync versionado + caché de política y límites device/tenant compartidos | L | Convivencia legacy; medir objetivo ~0,5 requests/min/device sin extras (§5.6). |
| P1 | Baseline/migraciones reproducibles y despliegue VPS/Docker | L | Fresh install + upgrade + restore; rutas antiguas y legacy controlado (§3). |
| P1 | Retención/archivo, agregados y workers reanudables | L | EXPLAIN, jobs por tenant/fecha, presupuesto de escritura y frescura visible (§6.4–§6.6). |
| P2 | Panel: servicios/repos, paginación SQL, AJAX separado y catálogo único | L | Sin fanout multidispositivo; permisos de acción centralizados (§2, §6.6, §7.3). |
| P2 | Rebrand global y tema tenant | M global / L tenant | Layout/login/export coherentes; assets locales; identidad técnica conservada (§7). |
| P2 | API pública read-only, integración/OAuth/scopes y OpenAPI completo | L | Depende de tenancy; contrato tipado, cuotas y pruebas de consumo externo (§8). |
| P3 | Gateway asíncrono de notificaciones, solo si se requiere reacción en segundos | L | Justificar necesidad frente a polling; backoff/reconexión y auth tenant/device (§5.6). |

### 10.3 Validación de capacidad y criterios de salida

En staging, después de correcciones, probar 180/1.000/5.000 dispositivos simulados con tiempos/payloads representativos, arranque sincronizado, backlog, 429, caída de BD y recuperación. No se ejecutó carga sobre producción.

Criterios:

- Cero acceso cruzado en la matriz de endpoints, objetos, exports y scopes.
- Cero ejecución de ZIP sin firma válida.
- Reenvío tras commit ambiguo no duplica episodios; ACK parcial conserva rechazados.
- Recuperación offline no excede presupuesto y no pierde datos por intentos omitidos.
- Registros de actividad correctos tras reinicio/doble dispositivo/medianoche.
- Peticiones, conexiones y trabajos de BD medidos separadamente; umbrales empresariales validados con infraestructura.
- Jobs completan o reanudan, purga funciona independientemente y restauración está comprobada.
- Contrato legacy sigue funcionando durante rollout.

### 10.4 Pendientes de evidencia externa

Estos puntos no bloquean las conclusiones estáticas, pero sí una afirmación sobre el comportamiento exacto desplegado:

1. Hashes y configuración efectiva de binarios 3.0.3.x y archivos scp.
2. SHOW CREATE TABLE/índices en copia de producción y estado real de migraciones.
3. Access logs y métricas CSF/LFD por endpoint/dispositivo/IP para cuantificar conexiones y correlacionar bloqueo web.
4. Cadencia cron efectiva, timezone de PHP/BD, disponibilidad y configuración de legacy.
5. Prueba de carga/EXPLAIN con distribución real de episodios y periodos de consulta.
6. Matriz de roles/scopes instalada y disponibilidad de enforcement fuera del K3 auditado; árboles excluidos permanecen fuera de esta auditoría.

**Estado:** auditoría estática completada; cambios de producto e infraestructura únicamente propuestos. Archivo de entrega único: docs/audits/2026-09-15-auditoria-k3.md.


### 10.5 Comprobación final del artefacto

- 856 citas comprobadas contra 108 fuentes en sus commits correspondientes: ninguna ruta ausente ni línea fuera de rango. Esto verifica ubicación; no sustituye las lecturas semánticas realizadas durante la auditoría.
- 50 filas del inventario de marca contrastadas con el contenido de 931fd12: sin discrepancias en las líneas indicadas.
- Esqueleto OpenAPI: nueve operationId únicos y ninguna referencia interna inexistente. Comprobación estructural de referencias; no se ejecutó un validador OpenAPI completo ni un servicio OAuth.
- Git no muestra cambios en archivos de código versionados. El reporte es nuevo; graphify-out aparece como árbol no versionado preexistente a la consulta de reanudación. No se incorporó ni modificó deliberadamente ese árbol.
- No se hizo commit, checkout ni despliegue. Las únicas escrituras de esta entrega fueron al archivo del reporte.
