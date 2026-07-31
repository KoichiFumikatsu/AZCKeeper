# Keeper 4 — Diagnóstico en vivo + Login con entorno — Plan de implementación

> **For agentic workers:** REQUIRED SUB-SKILL: superpowers:executing-plans (ejecución inline, autónoma). Los pasos usan checkbox (`- [ ]`).

**Goal:** Dar a K4 (1) diagnóstico near-real-time por persona desde el panel, con el módulo de logging del cliente de vuelta, y (2) un login real (entorno + cédula + contraseña) en el primer arranque.

**Architecture:** Backend PHP (`WebK4/`) + panel PHP + esquema MySQL en devkeep; cliente C# (`AZCKeeper.K4/`, net8.0-windows) compilado/probado en FumiWork. Diagnóstico = flag por persona en el handshake → loop rápido en el cliente → `POST /client/diagnostics` → tabla efímera → vista auto-refrescada en el panel. Login = validación de `password_hash` en `/client/login` + diálogo WinForms de primer arranque.

**Tech Stack:** PHP 8.2 (hosting), PDO/MySQL, Tailwind CDN + Alpine (panel), .NET 8 WinForms, xUnit (`AZCKeeper.Tests`).

## Global Constraints

- **Solo DEV.** Rama `feature/modulo-seguridad`. `devkeep.azclegal.com` + `pipezafra_keepdev`. PROD NO SE TOCA.
- **Auth cliente:** header `X-Auth-Token` (el hosting no propaga `Authorization`). Endpoints `/client/*` validan bearer + pertenencia del device a la sesión.
- **Colación:** `utf8mb4_general_ci` en toda tabla nueva (evita error 3988).
- **Zona horaria:** `captured_at`/`created_at` en UTC; `client_ts` = reloj del cliente.
- **Convención de contraseña por defecto:** `z` + cédula + `Z@!$` (ej. `z12345678Z@!$`).
- **Deploy DEV:** `scp` a `keeper-hosting:www/devkeep.azclegal.com/{public,src}/...`; SQL vía `ssh keeper-hosting` con `MYSQL_PWD=$(sed -n 's/^DB_PASS=//p' .env)`. Un SSH por comando (límite de forks MSYS).
- **Lint server:** `/usr/local/php8.2/bin/php -n -l <archivo>`.
- **Cuentas DEV panel:** `techsupport@azclegal.com`/`Keeper4Demo!` (superadmin), `gerente@azclegal.com`/`Gerente4Demo!`.

---

## Estructura de archivos

**Backend nuevo:** `WebK4/src/Endpoints/ClientDiagnostics.php`, `WebK4/src/Repos/DiagnosticRepo.php`, `WebK4/public/admin/diagnostics.php`.
**Backend modificado:** `WebK4/public/index.php` (ruta), `WebK4/src/Endpoints/ClientHandshake.php` (bloque diagnostics), `WebK4/src/Endpoints/ClientLogin.php` (password), `WebK4/src/Endpoints/ProductivityCron.php` (purga), `WebK4/src/Repos/UserRepo.php` (password), `WebK4/src/bootstrap.php` (requires), `WebK4/public/admin/admin_auth_helpers.php` (módulo `diagnostics`), `WebK4/public/admin/partials/layout_header.php` (nav), `WebK4/public/admin/users.php` (reset password).
**Esquema:** `Web/migrations/keeper4/14_diagnostics.sql`, `Web/migrations/keeper4/15_diagnostics_role.sql`.
**Cliente nuevo:** `AZCKeeper.K4/Shell/LocalLogger.cs`, `AZCKeeper.K4/Core/DiagnosticSnapshot.cs`, `AZCKeeper.K4/Core/DiagnosticLoop.cs`, `AZCKeeper.K4/Shell/FirstRunLogin.cs`.
**Cliente modificado:** `AZCKeeper.K4/Core/K4ApiClient.cs`, `AZCKeeper.K4/Core/CoreService.cs`, `AZCKeeper.K4/Program.cs`.
**Tests:** `AZCKeeper.Tests/LocalLoggerTests.cs`, `AZCKeeper.Tests/DiagnosticSnapshotTests.cs`.

---

## Feature A — Diagnóstico en vivo

### Task 1: Esquema de diagnóstico + módulo RBAC

**Files:**
- Create: `Web/migrations/keeper4/14_diagnostics.sql`
- Create: `Web/migrations/keeper4/15_diagnostics_role.sql`

**Produces:** tablas `keeper_diagnostic_session(user_id PK, enabled_by, started_at, expires_at)` y `keeper_diagnostic_snapshot(id, user_id, device_id, captured_at, client_ts, payload JSON)`; módulo `diagnostics` en los roles `it`/`admin`.

- [ ] **Step 1:** Escribir `14_diagnostics.sql` con las dos tablas (DDL de la spec §A.1, colación `utf8mb4_general_ci`, índices `ix_diag_expires`, `ix_diagsnap_user (user_id, id)`, `ix_diagsnap_purge (captured_at)`).
- [ ] **Step 2:** Escribir `15_diagnostics_role.sql`: `UPDATE keeper_panel_roles` para añadir `"diagnostics"` al array `modules` de `it` y `admin` con `JSON_ARRAY_APPEND` sólo si no está (usar `JSON_CONTAINS` como guardia), idempotente.
- [ ] **Step 3:** Aplicar ambas en devkeep (`ssh keeper-hosting … mysql < …`). Verificar: `SHOW TABLES LIKE 'keeper_diagnostic_%'` → 2 filas; `SELECT permissions_json FROM keeper_panel_roles WHERE role_code='it'` contiene `diagnostics`.
- [ ] **Step 4:** Commit `feat(k4-diag): esquema de diagnostico + modulo RBAC`.

### Task 2: `DiagnosticRepo` + backend de escritura/lectura

**Files:**
- Create: `WebK4/src/Repos/DiagnosticRepo.php`
- Modify: `WebK4/src/bootstrap.php`

**Produces:** `DiagnosticRepo::activeSession(pdo,userId): ?array`, `::setSession(pdo,userId,adminId,hours)`, `::clearSession(pdo,userId)`, `::insertSnapshot(pdo,userId,deviceId,clientTs,payloadJson)`, `::latestSnapshot(pdo,userId): ?array`, `::purge(pdo)`.

- [ ] **Step 1:** Escribir `DiagnosticRepo` con esos métodos. `activeSession` = `SELECT … WHERE user_id=:u AND expires_at > UTC_TIMESTAMP()`. `setSession` = UPSERT con `expires_at = UTC_TIMESTAMP() + INTERVAL :h HOUR`. `insertSnapshot` inserta `payload` crudo (ya JSON válido). `purge` = los dos DELETE de la spec §A.5.
- [ ] **Step 2:** `require_once` de `DiagnosticRepo.php` en `bootstrap.php` (junto a los otros repos).
- [ ] **Step 3:** `php -l` local de ambos. Commit `feat(k4-diag): DiagnosticRepo`.

### Task 3: Bloque `diagnostics` en el handshake

**Files:**
- Modify: `WebK4/src/Endpoints/ClientHandshake.php`

**Consumes:** `DiagnosticRepo::activeSession`.
**Produces:** la respuesta del handshake incluye `"diagnostics": {enabled, intervalSeconds, untilUtc}` cuando hay sesión activa (o `{enabled:false}` si no).

- [ ] **Step 1:** Tras resolver `$userId`, leer `DiagnosticRepo::activeSession($pdo,$userId)`. Construir `$diag = $sess ? ['enabled'=>true,'intervalSeconds'=>4,'untilUtc'=>gmdate('Y-m-d\TH:i:s\Z', strtotime($sess['expires_at'].' UTC'))] : ['enabled'=>false]`. Añadir `'diagnostics' => $diag` al JSON de respuesta.
- [ ] **Step 2:** `php -l`, deploy a devkeep, y probar con un token válido de DEV (curl al handshake) que aparece `"diagnostics":{"enabled":false}`.
- [ ] **Step 3:** Commit `feat(k4-diag): handshake anuncia modo diagnostico`.

### Task 4: Endpoint `POST /client/diagnostics`

**Files:**
- Create: `WebK4/src/Endpoints/ClientDiagnostics.php`
- Modify: `WebK4/public/index.php`, `WebK4/src/bootstrap.php`

**Consumes:** `SessionRepo::validateBearer`, `DeviceRepo::findByGuid`, `DiagnosticRepo::{activeSession,insertSnapshot}`.
**Produces:** ruta `POST /client/diagnostics`.

- [ ] **Step 1:** Escribir `ClientDiagnostics::handle`: valida bearer + device pertenece a la sesión (patrón de `ClientHandshake`); si no hay sesión de diagnóstico activa → `409 {ok:false,error:'diagnostics not enabled'}`; si el payload supera 64 KB, recorta `logs[]`; inserta vía `DiagnosticRepo::insertSnapshot` con `client_ts` del body; responde `200 {ok:true}`.
- [ ] **Step 2:** Registrar la ruta en `index.php` (grupo POST) y el `require_once` en `bootstrap.php`.
- [ ] **Step 3:** `php -l`, deploy. Probar: sin sesión activa → 409. Commit `feat(k4-diag): endpoint POST /client/diagnostics`.

### Task 5: Purga en el cron

**Files:**
- Modify: `WebK4/src/Endpoints/ProductivityCron.php`

**Consumes:** `DiagnosticRepo::purge`.

- [ ] **Step 1:** Al final del cron, llamar `DiagnosticRepo::purge($pdo)` dentro de try/catch; sumar el conteo borrado al resumen que ya devuelve el cron.
- [ ] **Step 2:** `php -l`, deploy, correr el cron con `CRON_API_KEY` y ver que no rompe. Commit `feat(k4-diag): purga de snapshots y sesiones vencidas en el cron`.

### Task 6: Panel `diagnostics.php`

**Files:**
- Create: `WebK4/public/admin/diagnostics.php`
- Modify: `WebK4/public/admin/admin_auth_helpers.php` (añadir `'diagnostics'=>'Diagnóstico en vivo'` a `panelModules()` y a los respaldos `it`/`admin`), `WebK4/public/admin/partials/layout_header.php` (navLink en "Gestión avanzada").

**Consumes:** `DiagnosticRepo::{activeSession,setSession,clearSession,latestSnapshot}`, `AuditRepo::log`, `panelCan`.
**Produces:** página `diagnostics` (IT/superadmin).

- [ ] **Step 1:** `panelModules()` gana `diagnostics`; respaldos `it`/`admin` en `panelFallbackPermissions()` lo incluyen; `panelLanding()` mapea `'diagnostics'=>'diagnostics.php'`.
- [ ] **Step 2:** `layout_header.php`: navLink `diagnostics.php` en el bloque "Gestión avanzada", y añadir la condición `|| panelCan($adminUser,'diagnostics')` al `if` que abre ese bloque.
- [ ] **Step 3:** `diagnostics.php`: gate `panelCan('diagnostics')`; selector de persona (scope de firma, como `dual-job.php`); POST `toggle` (setSession 4h / clearSession) auditado; endpoint AJAX interno `?data=1&user=<id>` que devuelve `json_encode(['session'=>activeSession,'snapshot'=>latestSnapshot])`; vista con los 4 bloques y auto-refresco Alpine cada 4 s por `fetch`. Estados: sin snapshot → "esperando"; snapshot >30 s → "cliente sin reportar".
- [ ] **Step 4:** `php -l`, deploy, verificar login superadmin → la página carga y el toggle escribe en `keeper_diagnostic_session`. Commit `feat(k4-diag): panel diagnostics.php + RBAC`.

### Task 7: Cliente — `LocalLogger`

**Files:**
- Create: `AZCKeeper.K4/Shell/LocalLogger.cs`
- Create: `AZCKeeper.Tests/LocalLoggerTests.cs`

**Produces:** `LocalLogger` con `Log/Debug/Info/Warn/Error(source,msg)`, anillo en memoria, secuencia monótona, archivo diario, y `IReadOnlyList<LogEntry> RecentSince(long seq)`. `record LogEntry(long Seq, DateTime Ts, string Level, string Source, string Message)`.

- [ ] **Step 1 (test):** `RecentSince` devuelve solo lo posterior a un seq dado y el anillo respeta la capacidad (escribir capacidad+10, quedar con capacidad). Escribir a un dir temporal para no ensuciar `%APPDATA%`.
- [ ] **Step 2:** Correr → falla (no compila). **Step 3:** Implementar `LocalLogger` (anillo `Queue`/array circular con lock, `Interlocked` para el seq, append a archivo `keeper_yyyyMMdd.log` best-effort). **Step 4:** Correr → pasa.
- [ ] **Step 5:** Commit `feat(k4-diag): LocalLogger del cliente (anillo + archivo)`.

### Task 8: Cliente — `DiagnosticSnapshot`

**Files:**
- Create: `AZCKeeper.K4/Core/DiagnosticSnapshot.cs`
- Create: `AZCKeeper.Tests/DiagnosticSnapshotTests.cs`

**Consumes:** `ModuleHost.{Modules,Snapshot,IsModuleRunning}`, `LocalLogger.RecentSince`, `IForegroundWindow`, `IIdleMonitor`, `OfflineQueue.PendingCount`, `K4ApiClient.{IsBackingOff,BackoffUntilUtc,LastHandshakeStatus,PendingQueueCount}`.
**Produces:** `DiagnosticSnapshot.Build(...)` → objeto serializable con `{ clientTs, modules[], logs[], activity, net }`. `modules[] = {code, expected, running, lastError}` (expected desde un `IReadOnlyDictionary<string,bool>` de la última config; lastError desde un mapa que `LocalLogger` mantiene por source de últimos Error).

- [ ] **Step 1 (test):** dado un ModuleHost con un módulo corriendo y expected=true, y otro expected=true pero no corriendo, `Build` los marca `running` correctamente y refleja `expected`.
- [ ] **Step 2:** falla. **Step 3:** implementar `Build` (arma el POCO; `activity` = foreground process/title + idleSeconds + queueDepth; `net` = accesores del api). **Step 4:** pasa.
- [ ] **Step 5:** Commit `feat(k4-diag): DiagnosticSnapshot (esperado vs real + logs + actividad + red)`.

### Task 9: Cliente — `K4ApiClient.SendDiagnosticsAsync` + `DiagnosticLoop` + wiring

**Files:**
- Modify: `AZCKeeper.K4/Core/K4ApiClient.cs`, `AZCKeeper.K4/Core/CoreService.cs`, `AZCKeeper.K4/Program.cs`
- Create: `AZCKeeper.K4/Core/DiagnosticLoop.cs`

**Consumes:** `DiagnosticSnapshot.Build`, `HandshakeResult`.
**Produces:** `K4ApiClient.SendDiagnosticsAsync(object payload): Task<bool>` (POST `client/diagnostics`, con token, NO encola); `HandshakeResult.Diagnostics` (lee el bloque `diagnostics`); `DiagnosticLoop` que arranca/para según el flag; `CoreService` guarda la última config esperada y expone el bloque diagnostics.

- [ ] **Step 1:** `K4ApiClient`: método `SendDiagnosticsAsync` (usa `PostJsonAsync`, no `PostDataAsync`). `HandshakeResult`: parsear `diagnostics` a `record DiagnosticsFlag(bool Enabled, int IntervalSeconds, DateTime? UntilUtc)` con default `Enabled=false`; guardar el `JsonElement` completo en el ctor (hoy solo guarda `effectiveConfig`).
- [ ] **Step 2:** `CoreService.RunOnceAsync`: tras `ToModuleConfig`, guardar `_lastExpected` (dict code→Enabled) y `_lastDiagnostics = hs.Diagnostics`. Exponer `IReadOnlyDictionary<string,bool> LastExpected` y `DiagnosticsFlag LastDiagnostics`.
- [ ] **Step 3:** `DiagnosticLoop`: recibe funcs `snapshot()` y `send(payload)` + `shouldRun()`; corre un `PeriodicTimer(intervalSeconds)` mientras `shouldRun()` y `untilUtc` no pase; respeta backoff (si `IsBackingOff`, salta el tick).
- [ ] **Step 4:** `Program.cs`: instanciar `LocalLogger` como el `Log`; crear `DiagnosticLoop` cableado a `DiagnosticSnapshot.Build(host, logger, fg, idle, queue, api, core.LastExpected)` y `api.SendDiagnosticsAsync`; el loop consulta `core.LastDiagnostics` para arrancar/parar. Arrancarlo junto a los otros loops.
- [ ] **Step 5:** `dotnet build` → 0 errores. `dotnet test` → verde. `--once` contra devkeep imprime handshake OK. Commit `feat(k4-diag): loop de diagnostico + envio al backend + wiring`.

---

## Feature B — Login con entorno

### Task 10: Backend — `/client/login` con contraseña + reset en panel

**Files:**
- Modify: `WebK4/src/Endpoints/ClientLogin.php`, `WebK4/src/Repos/UserRepo.php`, `WebK4/public/admin/users.php`

**Produces:** `UserRepo::setPassword(pdo,userId,hash)`; login que valida/fija contraseña; reset de contraseña en `users.php`.

- [ ] **Step 1:** `ClientLogin::handle`: leer `$password = (string)($body['password'] ?? '')`. Tras `findByCc`: si `$user` existe y `status='active'`: si `password_hash` es NULL → exigir `$password === 'z'.$cc.'Z@!$'`; si no coincide → `401 {status:'bad_credentials'}`; si coincide → `UserRepo::setPassword` con `password_hash($password, PASSWORD_DEFAULT)`. Si `password_hash` presente → `password_verify($password, $hash)`; si falla → `401`. El resto (enrolar device, crear sesión) sin cambios. Caso cédula desconocida y `pending` sin cambios.
- [ ] **Step 2:** `UserRepo::findByCc` debe traer `password_hash` (verificar el SELECT; añadirlo si falta). Añadir `UserRepo::setPassword`.
- [ ] **Step 3:** `users.php`: acción POST `reset_password` (solo IT/superadmin) que hace `UserRepo::setPassword` (o NULL para re-provisionar), auditada; celda/botón inline por persona.
- [ ] **Step 4:** `php -l`, deploy. Probar los tres caminos con curl (ver plan de verificación). Commit `feat(k4-login): validacion de password en /client/login + reset en panel`.

### Task 11: Cliente — `LoginAsync` con contraseña + `FirstRunLogin` + wiring

**Files:**
- Modify: `AZCKeeper.K4/Core/K4ApiClient.cs`, `AZCKeeper.K4/Core/CoreService.cs`, `AZCKeeper.K4/Program.cs`
- Create: `AZCKeeper.K4/Shell/FirstRunLogin.cs`

**Consumes:** `K4CredentialStore.{SaveCredentials,LoadCredentials}` (ya existen).
**Produces:** login con contraseña de punta a punta; diálogo de primer arranque; re-login silencioso con la contraseña guardada.

- [ ] **Step 1:** `K4ApiClient.LoginAsync(cc, password, deviceName, version)` — añadir `password` al body. Actualizar los call-sites.
- [ ] **Step 2:** `CoreService`: recibir la contraseña (por ctor o por un provider); el re-login silencioso del 401 usa `cc + password` en vez de solo `cc`. La contraseña viene de `K4CredentialStore.LoadCredentials()`.
- [ ] **Step 3:** `FirstRunLogin.cs` (WinForms): combo Entorno (constante `Environments`, hoy solo `Desarrollo`), campo cédula, campo contraseña (hint `z<cédula>Z@!$`). Botón "Ingresar" → `LoginAsync`; maneja 200 (guarda BaseUrl+Cc en cfg, `SaveCredentials`, cierra), 202 (mensaje pending, sigue abierto), 401 (mensaje, reintenta).
- [ ] **Step 4:** `Program.cs`: si no hay token restaurado NI credenciales guardadas → mostrar `FirstRunLogin` (STA) antes del loop; si el usuario cancela, salir. Quitar el fallback `cfg.Cc="K4TEST"`. Tras login, seguir al residente invisible.
- [ ] **Step 5:** `dotnet build` + `dotnet test` verdes. Commit `feat(k4-login): pantalla de primer arranque (entorno + cedula + password) + re-login con password`.

---

## Task 12: Despliegue final + verificación E2E en devkeep

- [ ] **Step 1:** Confirmar todo el backend/panel desplegado y lintado en devkeep; migraciones 14/15 aplicadas.
- [ ] **Step 2:** Publicar un build self-contained del cliente (`build-release`/`dotnet publish`) para pruebas de escritorio; NO se registra release (es prueba local).
- [ ] **Step 3:** Verificación §Plan de verificación de la spec: login (4 casos + persistencia), diagnóstico (encender→snapshots→vista→apagar→purga→módulo forzado a fallar), RBAC (`diagnostics` oculto a gerente).
- [ ] **Step 4:** Actualizar `.superpowers/sdd/progress.md` y la memoria dual. Commit final.
- [ ] **Step 5 (con Koichi):** revisión módulo por módulo con 3 equipos en DEV.

---

## Notas de verificación (comandos)

- **Handshake diag:** `curl -s -H "X-Auth-Token: <tok>" -d '{"deviceId":"<guid>","version":"4.0.0.0"}' http://devkeep.azclegal.com/public/index.php/api/client/handshake | grep diagnostics`
- **Login patrón:** cédula existente sin hash + `z<cc>Z@!$` → 200 y fija; con otra contraseña → 401; segunda vez con la misma → 200 (ya por `password_verify`).
- **Token en DEV:** hay uno sembrado en `scratchpad/k4_token.txt` (progress.md); si expiró, re-login con user de prueba.
