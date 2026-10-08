# Administración v4

Implementa 47 operaciones administrativas y las cuatro de sesión bajo `/v1`, conservando los schemas y métodos del OpenAPI. No modifica K3/K4. Requiere aplicar `0009_admin_surface.sql` mediante el runner existente; añade fechas/índices para paginación y una cola persistente de recompilación. No altera las ocho migraciones anteriores.

| Recurso | Métodos y rutas relativas a `/v1` |
|---|---|
| Sesión | `GET /auth/csrf`; `POST /auth/login`, `/auth/logout`, `/auth/reauth` |
| Empresas | `GET/POST /tenants`; `GET/PATCH /tenants/{id}`; `PATCH /tenants/{id}/rbac-gate` |
| Organización | `GET/POST /organization`; `GET/PATCH /organization/{id}` |
| Horarios | `GET/POST /schedules`; `GET/PUT /schedules/{id}` |
| Miembros | `GET/POST /users`; `GET/PATCH /users/{id}`; `PUT /users/{id}/access` |
| Equipos | `GET /tenants/{id}/devices`; `GET/PATCH /devices/{id}`; `POST /devices/{id}/assignment`; `GET/POST /devices/{id}/commands`; `POST /enrollments` |
| Políticas | `GET/POST /policies`; `GET/PATCH /policies/{id}` |
| RBAC | `GET/POST /roles`; `GET/PATCH /roles/{id}`; `GET /permissions`; `GET/PATCH /permissions/{code}` |
| Planes | `GET /person-plans/available`; `PUT /users/{id}/plan`; catálogo de planes solo plataforma (`/person-plans`) |
| Reportes | `GET /reports/productivity` |
| Releases | `GET/POST /releases`; `POST /release-deployments` |
| Auditoría | `GET /audit` |

## Autenticación y autorización

`Auth::admin()` integra `AdminAuth` en el router existente. Pre-sesión de cinco minutos, password hash, cookie Secure/HttpOnly/SameSite=Strict y CSRF HMAC-SHA256 ligado a la cookie, usando la clave de configuración fuera de BD. Login consume la pre-sesión y emite otra cookie. Todas las mutaciones exigen CSRF y Origin canónico. Cada petición comprueba cuenta, usuario y empresa activos, revisión de cuenta, revocación, ocho horas absolutas y treinta minutos de inactividad en `admin_sessions`. Reauth dura cinco minutos; logout revoca la sesión. No se habilita login al crear Colaboradores ni al asignar roles.

`x-permission` del contrato selecciona el permiso; los comandos usan la correspondencia exacta documentada por acción. `AdminAccess` lee en cada petición el único acceso de la persona (`access_grants`: 1 rol + 1 alcance) o, para una cuenta de grupo, su acceso en esa empresa. El atributo de plataforma está en `admin_accounts`: un nombre de rol nunca lo concede.

`X-Tenant-ID` es obligatorio donde lo exige el contrato; en rutas de empresa manda el ID del path. Se verifica contra la cuenta antes del handler. Las consultas por ID, joins, referencias de cuerpos, filtros, colas, auditoría e idempotencia incluyen tenant. Los listados y detalles de personas, equipos, actividad, reportes, ausencias, cobertura, señales de cumplimiento y logs aplican el alcance de la persona. Puede ser: todo; filtros Empresa/Ciudad/Sede/Área (O dentro de un filtro, Y entre filtros); su equipo; o solo lo propio. Productividad usa la asignación organizativa histórica.

IDs fuera del alcance devuelven 404. Un filtro pedido, un objetivo de regla, una persona creada o movida fuera del alcance, o una pantalla de todo el cliente, devuelven 403 `scope_denied`. La API externa usa credenciales de todo el cliente y no tiene alcance de cuenta.

Autogestión de accesos: exige la compuerta de la empresa, la función `autogestion_accesos` y `roles.gestionar`. Plataforma puede operar con la compuerta apagada.

Crear o editar roles exige alcance «todo». Un rol nunca incluye permisos de funciones no contratadas (`feature_not_contracted:<f>`). Dar un acceso valida que el rol no tenga permisos que el actor no tiene (ni `roles.gestionar`, ni no delegables) y que el alcance no sea más amplio que el propio. Así nadie amplía su propio acceso (`delegation_exceeds_own_access`).

La asignación por cargo propone el rol y nunca toca el alcance. Cambiar un acceso invalida las sesiones de la persona.

Cuotas predeterminadas separadas de ingesta: admin 120/min, burst 30; tenant 600/min, burst 100. El harness configura cuotas mayores únicamente para pruebas. Auditoría append-only por actor real/tenant/recurso/request ID y nombres de campos; no contiene cuerpos, passwords, tokens, PIN o texto de motivos libre. `If-Match` obligatorio en las rutas indicadas; respuestas versionadas incluyen ETag. Idempotencia transaccional cifrada durante 24 horas para acciones, asignación de equipos y despliegues.

## Persistencia y compilación

`PolicyInput` administra documento, nueva versión inmutable, reglas y asignación en una transacción. Desactivar equivale a `enabled=false`; borrar reglas/asignaciones se expresa publicando otra revisión/target, conservando historia. Targets y horarios se validan dentro del tenant. No se admite bloquear el host configurado de gestión.

Los cambios de políticas, horarios, organización, usuarios y planes de persona registran trabajo en `admin_policy_recompiles`. Antes del commit se valida la composición; los conflictos retornan 409 y revierten la edición. Después del commit se llama `PolicyCompiler::recompileForTenant`: abarca equipos activos del tenant y solo publica versiones si cambia el hash de composición. Incluye equipos de targets anteriores al reasignar una política.

La cola se elimina comparando su revisión, para no perder una edición concurrente. Si falla la compilación tras el commit, retorna 503 y conserva el trabajo: una petición admin posterior vuelve a intentarlo. Para recuperación sin tráfico HTTP:

```powershell
php backend-v4/config/compile-pending.php
```


Los comandos se encolan con secuencia monotónica y expiración futura máxima de 24 horas; no ejecutan nada. Wipe exige reauth y hostname escrito. Los motivos no se exponen en respuestas. Baja de usuario invalida credenciales, revoca sus equipos/tickets pendientes y cancela comandos pendientes. Reasignar equipo conserva historia y revoca claves/sesiones previas; requiere nuevo ticket para recuperar acceso.

Productividad lee únicamente `day_summary`, `episode_daily` y `focus_daily`, con filtros históricos y agregación separada para evitar multiplicar métricas al unir procesos. Prefiere el resumen diario y usa episodios agregados cuando falta; no consulta tablas crudas. Todos los listados usan cursores cifrados de 15 minutos, vinculados a tenant/principal/revisión/filtros/ruta/snapshot y consultas SQL acotadas, sin OFFSET. Los catálogos con fechas usan `(created_at,id)`; permisos usa `code`.

Registrar una release exige SHA-256, key ID y manifest JWS ES256 verificado contra `RELEASE_KEYS_FILE`. Todos los campos deben coincidir con el payload firmado. Registro y publicación son el mismo POST del contrato; `published_at` controla disponibilidad. Solo plataforma registra manifests inmutables y hace visible una release a un tenant; después su admin autorizado puede cambiar anillo/porcentaje. Branding sin logo en las semillas usa el SVG público local.

## Discrepancias del alcance solicitado

Se conserva el OpenAPI sin ampliarlo. No existen rutas/schemas para reset de contraseña, CRUD de `sociedades`, edición de `firma_module_override`, selección de equipos individuales en deployments ni comandos `logoff`/`diag`. No se inventaron esas APIs. `firm` se administra mediante `/organization`; firma significa firma organizativa, no firma manuscrita. El compilador existente sí lee `firma_module_override`. Los comandos definidos son `lock`, `unlock`, `restart`, `shutdown`, `wipe` y `refresh_policy`; rollout es por tenant/anillo/porcentaje. Tampoco existe una ruta publish separada ni CRUD independiente de versiones/reglas/asignaciones.

Integraciones, webhooks, notificaciones, transferencia entre empresas, dashboard y actividad/check-ins administrativos quedan fuera de esta entrega enumerada. Sus rutas no se exportan como implementadas. El catálogo global de permisos permite lectura y edición de metadata solo por plataforma, tal como define el YAML.

## Verificación

### PIN de desbloqueo local (2026-10-07)

En **Ajustes → PIN de desbloqueo local**, un administrador con `equipos.desbloquear` y alcance de todo el
cliente puede configurar o consultar un PIN numérico de 8 a 32 dígitos. Cada acción del panel pide su contraseña;
la API exige reautenticación de menos de 5 minutos, sesión, CSRF y contexto del cliente concordante con la ruta.

| Operación | Ruta bajo `/v1` | Respuesta |
|---|---|---|
| GET | `/admin/tenants/{id}/unlock-pin` | Configurado, revisión y fecha; sin PIN ni huella |
| PUT | `/admin/tenants/{id}/unlock-pin` | Recibe `{ "pin": "…" }`; sustituye el PIN y aumenta la revisión |
| POST | `/admin/tenants/{id}/unlock-pin/reveal` | Revela el PIN y registra la consulta en auditoría |

La migración `0052_unlock_pin.sql` crea una fila por cliente. El valor recuperable usa AES-256-GCM con clave
derivada de `KEEPER_RESPONSE_KEY` y AAD vinculada al cliente. La huella usa PBKDF2-HMAC-SHA256, 210000
iteraciones, salt aleatorio de 32 bytes y resultado de 32 bytes. Ninguna auditoría guarda el valor del PIN.

El sync de agentes **4.0.17 o posteriores** incluye `unlock_pin` (revisión, salt y hash en base64). No se añade
el campo a versiones anteriores, que rechazan campos desconocidos. La revisión 0 significa sin PIN configurado.
El equipo guarda únicamente esa huella bajo DPAPI del servicio, dentro del directorio protegido del agente.
Cambiar el PIN se refleja en el siguiente sync; un equipo desconectado conserva el anterior hasta sincronizar.

El contador se comparte entre sesiones del equipo. Cinco fallos activan 15 minutos de espera medidos con un reloj
monotónico. Se persiste el tiempo restante: cambiar la hora de Windows no reduce la espera y el tiempo con
el agente detenido no se descuenta. Al cargar un estado de una versión anterior con cinco fallos, se exige
la espera completa sin confiar en la fecha guardada. Una orden remota repetida de bloqueo no reinicia el contador. Un desbloqueo
remoto seguido de un nuevo bloqueo sí empieza otro ciclo. La rotación del PIN no elimina la espera en curso.

Un desbloqueo local guarda atómicamente el estado desbloqueado y su evento pendiente. El evento
`DeviceLock/local_unlock` pasa a la cola durable, queda protegido frente al descarte por falta de espacio y se
reintenta con el mismo UUID. El panel lo muestra en Logs y en la ficha del equipo; la ingesta añade una entrada
`device.local_unlock_reported` de auditoría al recibirlo, incluso si llegó después del plazo de retención de logs.
La fecha del log conserva la hora declarada por el equipo, incluso si está adelantada; la fecha de auditoría
corresponde a la recepción según el servidor. Solo `DeviceLock/local_unlock` admite fechas futuras, para que
un reloj incorrecto no provoque rechazo ni cuarentena del reporte. Los reintentos no duplican entradas.

Si se sospecha una filtración, rotar el PIN de todo el cliente desde **Ajustes → PIN de desbloqueo local →
Cambiar PIN**, confirmar la contraseña del panel y guardar un PIN nuevo de 8 a 32 dígitos que no reutilice
el anterior. Un administrador local o SYSTEM puede extraer el verificador e intentar adivinarlo sin conexión;
el mínimo de 8 dígitos no elimina ese riesgo y el PIN es común a todos los equipos del cliente.
Verificar la siguiente sincronización de todos los equipos; los desconectados conservan el PIN anterior
hasta reconectarse. Mantener aislados los equipos comprometidos y recuperar su integridad antes de permitir
que reciban el nuevo PIN. La rotación aumenta la revisión y renueva el salt y el verificador, sin borrar
una espera en curso. Los PIN antiguos de menos de 8 dígitos deben sustituirse para usarlos con este agente.

Pruebas específicas (bases aisladas; sin instalar el agente ni bloquear esta máquina):

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File backend-v4/tests/mysql-smoke.ps1 -DebugResponses -UnlockPinOnly
dotnet test client-v4/tests/Keeper.Agent.Tests/Keeper.Agent.Tests.csproj -c Release -m:1 -nodeReuse:false --filter FullyQualifiedName~UnlockPinTests
node backend-v4/tests/unlock-pin-panel.cjs
```

La prueba del panel requiere Playwright con Chromium; `KEEPER_PLAYWRIGHT_MODULE` puede apuntar a una instalación
existente de `playwright-core`. Usa la interfaz real con una API simulada. La suite PHP comprueba la API real
con MySQL. Antes de habilitarlo en la flota: desplegar backend y migración, empaquetar el agente 4.0.17 y probar
en el piloto un bloqueo, desconexión, cinco fallos, reinicio del servicio, espera, desbloqueo y posterior reporte.

### Smart App Control por cliente (2026-10-08, agente 4.0.19)

Smart App Control (SAC) de Windows 11 bloquea programas sin firma; en ACT-0000 impidió arrancar
`Keeper.Bootstrapper.exe` (Win32 4551) y el equipo quedó sin actualizar. En **Ajustes → Smart App Control**, con
`equipos.desbloquear` y alcance de todo el cliente, la casilla «Desactivar Smart App Control en los equipos de este
cliente» (apagada por defecto) hace que cada agente 4.0.19+ ponga `VerifiedAndReputablePolicyState=0` en
`HKLM\SYSTEM\CurrentControlSet\Control\CI\Policy` si vale 1 (activo) o 2 (evaluación); aplica al reiniciar y el agente
no fuerza el reinicio. **Apagar SAC es irreversible en Windows**: desmarcar la casilla solo evita que se apague en
equipos nuevos; el agente nunca lo reactiva.

| Operación | Ruta bajo `/v1` | Respuesta |
|---|---|---|
| GET | `/admin/tenants/{id}/windows-security` | `disable_smart_app_control`, `updated_at`, `updated_by` (sin fila: `false`/`null`) |
| PUT | `/admin/tenants/{id}/windows-security` | Recibe `{ "disable_smart_app_control": bool }`; exige reauth < 5 min; audita `windows_security.changed` |

Migración `0053_windows_security.sql`. El sync de agentes 4.0.19+ incluye `windows_security`; el inventario trae
`smart_app_control` (`off`/`on`/`evaluation`/`unknown`), se guarda en `devices.specs` y la ficha del equipo lo
muestra. Logs nuevos: `SmartAppControl/smart_app_control_disabled`, `SmartAppControl/smart_app_control_disable_failed`
(error_code = tipo de excepción), `UpdateManager/update_launch_failed` (error_code `win32_<código>` o tipo) y
`UpdateManager/update_apply_stalled` (más de 30 min en «applying» sin que el servicio se detuviera; se reintenta).

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File backend-v4/tests/mysql-smoke.ps1 -DebugResponses -WindowsSecurityOnly
dotnet test client-v4/tests/Keeper.Agent.Tests/Keeper.Agent.Tests.csproj -c Release -m:1 -nodeReuse:false --filter "FullyQualifiedName~SmartAppControl|FullyQualifiedName~UpdateLaunch"
node backend-v4/tests/windows-security-panel.cjs
```

### Suite general

`tests/admin.php` se integra en el smoke completo sobre MySQL aislado. Comprueba contratos de salida además del modo `KEEPER_DEBUG`, dos tenants, distintos roles, gates, autoescalada directa/indirecta, expiración, CSRF/origen, IDOR, scopes, cursores, versiones de edición, compilación y rollback de conflictos, comandos auditados/idempotentes, revocación y releases firmadas. Incluye datos agregados reales y verifica que no se duplican métricas ni se cruzan tenants.

También simula un fallo posterior al commit del cambio de horario: devuelve 503, conserva la solicitud de compilación y la procesa al recuperarse el compilador.

```powershell
rg --files backend-v4 -g '*.php' | ForEach-Object { php -l $_ }
powershell -NoProfile -ExecutionPolicy Bypass -File backend-v4/tests/mysql-smoke.ps1 -DebugResponses
```

El harness detiene PHP/MySQL y elimina su datadir en `finally`. No conecta a producción ni prueba ejecución del agente/updater Windows. No incluye pruebas de carga.

Validación de esta entrega: PHP lint completo; MySQL 8.4.3 con validación de respuestas; 503 aserciones del smoke (223 admin) y 6 de transporte, todas PASS.
