# Presencia + Control remoto — Plan de implementación

> Ejecutar con superpowers:executing-plans (inline, autónomo). Spec: `2026-07-31-presencia-y-control-remoto-design.md`.

**Goal:** Semáforo de presencia por persona + doble candado (RBAC+tier) del control remoto + acciones (lock/shutdown real/restart/logoff).

## Global Constraints
- Solo DEV (devkeep + pipezafra_keepdev). Rama `feature/modulo-seguridad`. PROD no se toca.
- Deploy: `scp` a keeper-hosting; SQL vía `ssh keeper-hosting` con `MYSQL_PWD=$(sed -n 's/^DB_PASS=//p' .env)`. Un SSH por comando.
- Lint PHP: `php -l` local o `/usr/local/php8.2/bin/php -n -l` remoto. Client: `dotnet build`/`dotnet test`.
- Umbrales presencia: OFFLINE_SECONDS=600, AWAY_IDLE_SECONDS=300.
- Mapa comando→módulo: lock→deviceLock; shutdown/restart/logoff→remoteShutdown; network_diag→networkDiagnostic; screenshot_now→screenshots; rename_computer→(sin tier).

---

### Task 1: Esquema — idle + módulo remote-control (mig 17, 18)
- Create `Web/migrations/keeper4/17_device_idle.sql`: `ALTER TABLE keeper_devices ADD COLUMN last_idle_seconds INT NULL AFTER last_seen_at;`
- Create `Web/migrations/keeper4/18_remote_control_role.sql`: `UPDATE keeper_panel_roles SET permissions_json = JSON_ARRAY_APPEND(permissions_json,'$.modules','remote-control') WHERE role_code IN ('it','admin') AND JSON_CONTAINS(permissions_json,'"remote-control"','$.modules')=0;`
- Aplicar en devkeep. Verificar columna + permiso.
- Commit.

### Task 2: Presencia — cliente reporta idle en handshake
- `K4ApiClient.HandshakeAsync(version, deviceName, idleSeconds=0)`: body suma `idleSeconds`.
- `CoreService`: campo `Func<int>? _idleSeconds`; ctor lo recibe; pasa `_idleSeconds?.Invoke() ?? 0` al handshake (ambas llamadas). Program pasa `() => idle.IdleSeconds`.
- `dotnet build` + `dotnet test` (actualizar call-sites de tests de CoreService/HandshakeAsync si aplica).
- Commit.

### Task 3: Presencia — backend guarda idle
- `DeviceRepo::touch(pdo, deviceId, name, version, idleSeconds=null)`: set `last_idle_seconds = COALESCE(:idle, last_idle_seconds)`.
- `ClientHandshake`: leer `idleSeconds` del body, pasarlo a `touch`.
- Lint, deploy, verificar con un handshake que `last_idle_seconds` se guarda.
- Commit.

### Task 4: Presencia — helper + vista en el panel
- `admin_auth_helpers.php`: `presence($lastSeenEpoch, $idleSeconds, $deviceCount): array` → `['key','label','cls']` (sin_keeper/offline/ausente/activa) con OFFLINE_SECONDS/AWAY_IDLE_SECONDS.
- `users.php`: el SELECT suma el equipo activo más reciente (`MAX last_seen`, su `last_idle_seconds`, `UNIX_TIMESTAMP`); columna "Presencia" con el pill.
- `index.php` (dashboard): resumen de conteos activa/ausente/offline/sin-keeper del alcance.
- Lint, deploy, verificar en vivo.
- Commit.

### Task 5: Doble candado — RBAC + tier en el panel
- `admin_auth_helpers.php`: `panelModules()` gana `'remote-control'=>'Control remoto'`; respaldos it/admin lo incluyen.
- `device-command.php`: gate `panelCan('remote-control')` (en vez de 'devices'); tipos permitidos suman `lock,restart,logoff`; ANTES de encolar, resolver firma del device y exigir el módulo de catálogo del mapa contra `TierResolver::effectiveModules` (rename_computer exento).
- `AdminCommand::enqueue`: mismos tipos + mismo gate de tier.
- Lint, deploy. Verificar: rol sin permiso→403; tier sin módulo→rechazo.
- Commit.

### Task 6: Acciones — CommandModule (lock/shutdown real/restart/logoff)
- `CommandModule`: inyectar `Action<string,string>? _runProcess` (file,args) con default `Process.Start` — para testear sin ejecutar. Casos nuevos en el `switch`: `lock` (LockWorkStation vía `user32`), `shutdown` (`shutdown /s /t <grace>`, grace de params o 60), `restart` (`/r /t <grace>`), `logoff` (`/l`). Reportan `done`.
- Tests: verifican que cada tipo llama `_runProcess` con los args correctos (sin ejecutar). shutdown lee graceSeconds de params.
- `dotnet build` + `dotnet test`.
- Commit.

### Task 7: Panel — botones en devices.php
- `devices.php`: por equipo, botones Bloquear/Apagar/Reiniciar/Cerrar sesión (solo si `panelCan('remote-control')` y el tier de la firma incluye el módulo). Destructivos con `confirm()`. Postean a `device-command.php` con csrf_field.
- Lint, deploy. Verificar E2E: encolar un `lock` → el cliente lo ejecuta.
- Commit.

### Task 8: Despliegue final + verificación E2E + memoria
- Verificar los 3 flujos (presencia, candado, acciones) en vivo contra devkeep con un cliente real.
- Actualizar progress + memoria dual. Commit.
- (Con Koichi: probar apagado/lock reales en los 3 equipos.)
