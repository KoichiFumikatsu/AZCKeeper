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
| Mudanzas de servidor | `GET/POST /relocations`; `PATCH /relocations/{id}` (solo plataforma) |
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

### K3 residual en equipos v4 (2026-10-08)

Tras la release puente K3 → v4, el agente (módulo `LegacyK3`, siguiente versión tras 4.0.20) revisa al arrancar y cada
15 min si Keeper 3 sigue en el equipo: `running` (algún proceso `AZCKeeper_Client` en cualquier sesión), `autostart`
(valor `AZCKeeper_Cliente` en `HKEY_USERS\<SID>\Software\Microsoft\Windows\CurrentVersion\Run` de algún perfil cargado;
sin `.DEFAULT` ni `*_Classes`) e `installed` (`AZCKeeper_Client.exe` en `AppData\Local|Roaming\AZCKeeper\app` de algún
perfil de `C:\Users`, sin seguir uniones). Un dato que no se pudo leer viaja `null`. **v4 solo detecta: nunca cierra
ni desinstala K3.** El inventario lleva `legacy_k3 {running, autostart, installed}`; como se manda una vez por arranque,
cuando el estado cambia el módulo llama `Inventory.UpdateLegacyK3Async`, que vuelve a encolar el inventario completo
(el outbox se queda solo con el último sin enviar). El servidor lo guarda en `devices.specs.legacy_k3` y el DTO del
equipo lo expone (`null` si nunca lo reportó). Ficha: línea «Keeper 3 (K3)» (No está / Abierto / Cerrado pero arranca
al iniciar sesión / Instalado, no arranca / Desconocido); lista de equipos: columna «K3 abierto». Logs:
`LegacyK3/legacy_k3_running` (warn, al pasar a corriendo), `legacy_k3_autostart` (info) y `legacy_k3_gone` (info, deja
de correr y no queda autoarranque), una vez por cambio.

**Orden de despliegue:** primero el backend (el contrato de servidores anteriores rechaza `legacy_k3` con 422 y el
sync del agente nuevo fallaría), después el agente.

### Entrada física vs. simulada — agente 4.0.22 (2026-10-09)

GetLastInputInfo también se refresca con entrada inyectada (SendInput de un «mouse jiggler», visores remotos como
DWService): en producción daba 15-22 h seguidas «en uso» de noche con la pantalla de bloqueo delante. Desde 4.0.22 el
`ActivityTracker` de Keeper.Session solo cuenta como uso el teclado/ratón con dispositivo (Raw Input, `RIDEV_INPUTSINK`,
ventana solo-mensajes en hilo propio; `hDevice == 0` = inyectado). Con entrada según Windows pero sin física, el tiempo
es **inactivo** y además suma `simulated_seconds`. En sesión RDP (`SM_REMOTESESSION`) se usa GetLastInputInfo como antes
y el tiempo activo suma `remote_seconds`. Si Raw Input no registra, mide como antes y sin esos campos.

`ActivitySnapshot` lleva `simulated_seconds` y `remote_seconds` opcionales (ausentes = no medido). Migración
`0054_simulated_input.sql`: columnas NULL en `activity_snapshots` y `day_summary`; `ProductivityWorker` las suma por
persona y día (NULL si ningún equipo las reporta). Exponen `simulated_seconds`: `/users/{id}/activity` (por día y total),
`/reports/people` (total) y `/reports/productivity` (serie). Panel: columna «Entrada simulada (h)» en las tablas diarias
y aviso en la ficha de la persona y en Productividad si el período pasa de 30 min. Logs: `ActivityTracker/simulated_input`
(warn, una vez por racha de 5 min o más), `simulated_input_end` (info, error_code = duración, p. ej. `1830s`) y
`physical_input_unavailable` (warn, una vez por sesión, error_code `win32_<código>`).

**Orden de despliegue:** primero migración y backend (el contrato anterior rechaza los campos nuevos con 422), después
el agente: `build-installer.ps1 -Version 4.0.22`.

### Mudanza de servidor por orden firmada — agente 4.0.24 (2026-10-13)

Todo v4 corre hoy en devkeep; al terminar la migración K3 → v4 la BD de devkeep se copia a producción (keep, mismo
Hestia, otra BD, otro sitio y su propio `KEEPER_ORIGIN`) y devkeep queda de pruebas. El agente toma la API de
`KEEPER_API_BASE` (fijada al instalar); para cambiarla sin reinstalar, plataforma firma una **orden de mudanza**: un JWS
ES256 con la **misma clave de releases** (la que el agente ya trae en `installation-trust.json`). Que la entregue el
servidor no basta: sin esa firma el agente la ignora.

Payload: `type: "relocation"`, `id` (uuid), `sequence` (monótona), `from_base` (`https://devkeep.azclegal.com/`),
`to_base` (`https://keep.azclegal.com/`), `tenants` (tenant_id incluidos), `exclude_devices` (device_id que se quedan,
p. ej. los 3 de prueba), `not_before`, `expires_at`. El agente (`RelocationVerifier`) acepta solo si: firma válida con su
clave de confianza; `to_base` https bajo `.azclegal.com` (lista fija en código, servidor y agente); `from_base` = su base
actual; su tenant está incluido y su equipo no está excluido; `sequence` mayor que la última aplicada; dentro de
`not_before..expires_at`. Entonces prueba el destino (`POST /client/auth/challenges` + login firmado con la MISMA clave de
equipo: la BD copiada lo conoce). Si funciona escribe `{data}\api-base.json` (`api_base`, `installed_base`, `sequence`,
`relocation_id`), que manda sobre `KEEPER_API_BASE` mientras esa variable siga siendo `installed_base` (una reinstalación
contra otro servidor manda sobre el archivo); abre conexión nueva (la firma HTTP usa la URI absoluta y el token se guarda
con clave base+keyId: login nuevo) y reenvía la cola (el lote pendiente sale igual, con su misma idempotencia). Si el
destino falla (DNS, TLS, 401 porque la BD aún no está copiada) se queda donde está y reintenta cada 15 min hasta
`expires_at`; la cola no se toca. Logs `ServerRelocation/…`: `relocated` (info, error_code = host destino),
`relocation_failed` (warn, motivo: `dns`, `tls`, `timeout`, `network`, `http_<código>`, `persist_failed`; al servidor
solo cuando cambia el motivo), `relocation_rejected` (warn, motivo, una vez por orden) y `device_relocated` (info, el
origen respondió 409). El bootstrapper (salud tras un update) y `Keeper-Recovery.ps1` también leen `api-base.json`; las
descargas de release usan la URL absoluta del manifiesto y no dependen de la base.

| Operación | Ruta bajo `/v1` | Detalle |
|---|---|---|
| GET | `/relocations` | Lista paginada (solo plataforma, contexto de plataforma; `servidor.mudar`) |
| POST | `/relocations` | `{ "relocation_jws": "…" }`; verifica firma contra `RELEASE_KEYS_FILE`, forma, `from_base` = `ORIGIN` de este servidor, vigencia y `sequence` mayor que toda la registrada (409 si no); reauth < 5 min; audita `relocation.registered` |
| PATCH | `/relocations/{id}` | `{ "enforced": bool }` activa/quita el **modo mudado**; reauth < 5 min; audita `relocation.enforced` / `relocation.released` |

Migración `0055_relocations.sql` (tabla `relocations` + permiso de plataforma no delegable `servidor.mudar`, dado al rol
de plataforma). `/client/sync` incluye `relocation` (el JWS) para agentes **4.0.24+** de los tenants incluidos y no
excluidos, mientras la orden esté vigente. **Modo mudado** (`enforced_at`): toda llamada `/v1/client/*` de esos equipos
—challenge y login incluidos— responde `409 device_relocated` con el JWS en `Problem.relocation` y no acepta ni guarda
nada; los excluidos y los agentes anteriores a 4.0.24 siguen normal (no sabrían mudarse y con 409 tampoco recibirían la
actualización que sí sabe). La fila viaja con la BD copiada pero en keep no aplica: su `ORIGIN` no es `from_base`.
Panel: **Releases → Mudanzas de servidor** (registrar pegando el archivo firmado, activar/quitar modo mudado).

Firmar (en la máquina y perfil de Windows que tienen la clave DPAPI de releases; desde `client-v4`):

```powershell
powershell -NoProfile -File tools/sign-relocation.ps1 `
  -ReleaseKeyPrivate C:\ruta\release-signing-key.dpapi -ReleaseKeyPublic C:\ruta\release-key.public.json `
  -Tenants 6e888b88-b3ef-4ac8-adae-d2fcf12d6b12 -ExcludeDevices <prueba1>,<prueba2>,<prueba3> `
  -Sequence 1 -NotBefore 2026-10-17T00:00:00Z -Days 14
# deja artifacts/relocation-1.json = cuerpo de POST /relocations; autoverificado con el verificador del agente
```

Equivale a `Keeper.ReleaseTool relocation --key … --public … --tenants … --exclude … --sequence N [--from …] [--to …]
[--not-before …] [--expires-at … | --days N] --out …`. Cada orden nueva (otra ventana, otros tenants) lleva `sequence`
mayor; una orden vencida no se reactiva: se firma otra.

**Orden de despliegue:** migración 0055 + backend (`Relocations.php`, `Application.php`, `AdminApi.php`,
`Resources.php`, `ApiError.php`, `Features.php`, `config/contract.json`, `public/assets/{app,k3,relocations}.js`) en
devkeep **y** en keep; después el agente 4.0.24 (`build-installer.ps1 -Version 4.0.24`) a toda la flota antes del corte.

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File backend-v4/tests/mysql-smoke.ps1 -DebugResponses   # incluye tests/relocations.php
dotnet test client-v4/tests/Keeper.Agent.Tests/Keeper.Agent.Tests.csproj -c Release -m:1 -nodeReuse:false --filter "FullyQualifiedName~RelocationTests"
```

### Tiempo bloqueado y categoría «Sistema» — agente 4.0.24 (decisión del dueño 2026-10-09)

«Si estoy en pantalla de bloqueo 8 horas, quiere decir que encendió el equipo pero no trabajó en nada.» En producción la
pantalla de bloqueo de Keeper estuvo 3,5 h delante contando como en uso. Desde 4.0.24 el `ActivityTracker` de
Keeper.Session, con el equipo bloqueado (pantalla de bloqueo de Windows, por `SystemEvents.SessionSwitch` con estado
inicial de `WTSQuerySessionInformation`, o bloqueo de Keeper, `SessionRequest.Locked`), no suma activo, inactivo ni
simulado: suma `locked_seconds` y su reparto por franja (`work_hours_/lunch_/after_hours_locked_seconds`). Bloquear corta
la racha de entrada simulada. Los tramos bloqueados no entran en ningún episodio de ventana.

`ActivitySnapshot` lleva los cuatro campos opcionales (ausentes = agente anterior). Migración `0056_locked_system.sql`:
columnas NULL en `activity_snapshots` y `day_summary`; la ingesta acepta activo + inactivo + bloqueado hasta el largo del
día + 120 s (recorta el bloqueado a 86400). `ProductivityWorker` los suma por persona y día (NULL si ningún equipo los
reporta); el registro vs. jornada cuenta el bloqueado como equipo encendido. Exponen `locked_seconds`:
`/users/{id}/activity` (día y total), `/reports/people` y `/reports/productivity`. Panel: columna «Bloqueado (h)».

Categoría `system` («Sistema») en `app_classification`, base de plataforma para `Keeper.Session`, `Keeper.Agent`,
`Keeper.Bootstrapper`, `LockApp` (antes neutra) y `LogonUI`. Manda sobre la clasificación de la empresa y las excepciones;
sus episodios salen del cálculo antes de todo: no cuentan en uso por ventanas, productividad, distracción, sin
clasificar, focus, constancia ni cambios de contexto (no cortan rachas), ni en el reporte de apps. Solo la plataforma
puede poner `system` (`PUT /app-classification` desde su tenant; otra empresa recibe 422 `system_platform_only`). La
migración marca los últimos 30 días para recalcular.

**Orden de despliegue:** migración y backend primero, después el agente 4.0.24.

### Suite general

`tests/admin.php` se integra en el smoke completo sobre MySQL aislado. Comprueba contratos de salida además del modo `KEEPER_DEBUG`, dos tenants, distintos roles, gates, autoescalada directa/indirecta, expiración, CSRF/origen, IDOR, scopes, cursores, versiones de edición, compilación y rollback de conflictos, comandos auditados/idempotentes, revocación y releases firmadas. Incluye datos agregados reales y verifica que no se duplican métricas ni se cruzan tenants.

También simula un fallo posterior al commit del cambio de horario: devuelve 503, conserva la solicitud de compilación y la procesa al recuperarse el compilador.

```powershell
rg --files backend-v4 -g '*.php' | ForEach-Object { php -l $_ }
powershell -NoProfile -ExecutionPolicy Bypass -File backend-v4/tests/mysql-smoke.ps1 -DebugResponses
```

El harness detiene PHP/MySQL y elimina su datadir en `finally`. No conecta a producción ni prueba ejecución del agente/updater Windows. No incluye pruebas de carga.

Validación de esta entrega: PHP lint completo; MySQL 8.4.3 con validación de respuestas; 503 aserciones del smoke (223 admin) y 6 de transporte, todas PASS.
