# Keeper 4 — Semáforo de presencia + Control remoto (doble candado + acciones)

**Fecha:** 2026-07-31 · **Rama:** `feature/modulo-seguridad` · **Entorno:** devkeep + pipezafra_keepdev · **PROD NO SE TOCA.**
**Aprobado por Koichi** (2026-07-31). Backlog de origen: `2026-07-31-control-remoto-catalogo-backlog.md`.

## Resumen

Tres piezas independientes sobre el canal de comandos y el handshake, que YA existen y son extensibles:

- **A. Semáforo de presencia** por persona: activa / ausente / desconectada / sin keeper. El cliente
  reporta su inactividad en el handshake normal; el panel calcula el estado.
- **B. Doble candado del control remoto**: RBAC (módulo de panel `remote-control`) + tier (cada comando
  exige el módulo de catálogo correspondiente en el tier de la firma).
- **C. Acciones nuevas**: bloquear pantalla, apagar REAL (hoy simulado), reiniciar, cerrar sesión.

Screenshot on-demand queda FUERA (depende del object storage pendiente).

---

## A. Semáforo de presencia

### A.1 Esquema (mig 17)
`ALTER TABLE keeper_devices ADD COLUMN last_idle_seconds INT NULL` — segundos de inactividad
reportados en el último handshake. `last_seen_at` ya existe (lo actualiza `DeviceRepo::touch`).

### A.2 Cliente
El handshake ya envía `{deviceId, version, deviceName}`. Se agrega `idleSeconds` (del `WinIdleMonitor`).
- `K4ApiClient.HandshakeAsync(version, deviceName, idleSeconds)` — nuevo parámetro; el body suma `idleSeconds`.
- `CoreService` recibe un `Func<int>? idleSeconds` (Program le pasa `() => idle.IdleSeconds`) y lo pasa al handshake.
- El idle es una instantánea de hasta un ciclo atrás (~5 min): granularidad suficiente para un semáforo.

### A.3 Backend
- `ClientHandshake` lee `idleSeconds` del body y lo pasa a `DeviceRepo::touch`.
- `DeviceRepo::touch(pdo, deviceId, name, version, idleSeconds = null)` — set `last_idle_seconds` (COALESCE).

### A.4 Panel — cálculo y vista
Helper `presence()` (en un include compartido del admin) que, dado `last_seen_epoch`, `last_idle_seconds`
y `device_count`, devuelve el estado + etiqueta + color:
- **sin_keeper**: `device_count = 0` (gris) — "Sin keeper".
- **offline**: sin device reciente — `last_seen` NULL o `> 10 min` (rojo) — "Desconectada".
- **ausente**: online (`last_seen <= 10 min`) y `last_idle_seconds >= 300` (ámbar) — "Ausente".
- **activa**: online y `last_idle_seconds < 300` (verde) — "Activa".

Umbrales (constantes, ajustables): `OFFLINE_SECONDS = 600`, `AWAY_IDLE_SECONDS = 300`.

Presencia por PERSONA = su equipo activo más recientemente visto (MIN idle / MAX last_seen). Se muestra:
- **Columna "Presencia" en `users.php`** (por persona).
- **Mini-resumen en el dashboard (`index.php`)**: conteo activa/ausente/offline/sin-keeper del alcance.

Se calcula por SQL (`UNIX_TIMESTAMP(last_seen_at)` para evitar el desfase de zona de la conexión del panel,
que corre en −05:00).

---

## B. Doble candado del control remoto

### B.1 RBAC — módulo de panel `remote-control` (mig 18)
- `panelModules()` gana `'remote-control' => 'Control remoto'`.
- Se siembra en los roles `it`/`admin` (no gerente/viewer). superadmin ya tiene `all`.
- Los respaldos en código (`panelFallbackPermissions`) lo incluyen para it/admin.
- **`device-command.php` pasa a exigir `panelCan($adminUser, 'remote-control')`** (hoy exige `'devices'`).
- Roles nuevos (supervisor/coordinador) se crean desde `roles.php` con el módulo DESMARCADO — sin cambio de código.

### B.2 Tier — cada comando exige su módulo de catálogo
Mapa comando → módulo de catálogo (`keeper_module`), exigido contra `TierResolver::effectiveModules(firmaId)`:
| Comando | Módulo de catálogo requerido |
|---|---|
| `lock` | `deviceLock` |
| `shutdown` / `restart` / `logoff` | `remoteShutdown` |
| `network_diag` | `networkDiagnostic` |
| `screenshot_now` | `screenshots` |
| `rename_computer` | (sin tier — operación de IT; solo requiere el permiso `remote-control`) |

En `device-command.php`, antes de encolar: resolver la firma del usuario del device
(`PolicyRepo::firmaOfUser` sobre `d.user_id`), y si el módulo requerido no está en el tier efectivo → rechazar
(y en la UI, no mostrar el botón). El mismo gate se aplica en `AdminCommand::enqueue` (la vía API).

---

## C. Acciones nuevas (cliente per-user, sin admin)

Se suman al `switch` de `CommandModule` y a los tipos permitidos del panel/endpoint.

- **`lock`** — `LockWorkStation()` (user32). Reporta `done`. Módulo `deviceLock`.
- **`shutdown`** — REAL: `shutdown /s /t <grace>` (grace por defecto 60 s, en `params.graceSeconds`).
  Hoy está simulado (`ExecuteShutdown` sólo loguea) — se activa la ejecución real. Reporta `done` con
  `{scheduled:true, graceSeconds}`. Módulo `remoteShutdown`.
- **`restart`** — `shutdown /r /t <grace>`. Módulo `remoteShutdown`.
- **`logoff`** — `shutdown /l` (logoff no admite `/t`). Módulo `remoteShutdown`.

Guardas:
- **Confirmación en el panel** antes de encolar los destructivos (JS `confirm`).
- **Ventana de gracia** (`/t 60`): el usuario ve el aviso nativo de Windows y puede guardar; `shutdown /a`
  no se expone (fuera de alcance).
- **`expires_at`** (ya existe, 3 días): un comando rezagado no se ejecuta tarde.
- **Auditoría** (ya existe): `command_enqueued` con actor.
- La ejecución real vive tras `#if !DEBUG`-style guard NO — se ejecuta siempre; para pruebas de escritorio
  Koichi lo verá apagar de verdad (es el objetivo). El apagado se dispara vía `Process.Start("shutdown", ...)`.

### Panel — `devices.php`
Botones nuevos por equipo (bajo el permiso `remote-control` y el tier de la firma): Bloquear, Apagar,
Reiniciar, Cerrar sesión. Los destructivos con `confirm()`. Postean a `device-command.php`.

---

## Alcance explícito

**Entra:** migraciones 17 (idle) y 18 (módulo remote-control); idle en handshake (cliente+backend);
`presence()` + columna en users + resumen en dashboard; gate RBAC+tier en `device-command.php` y
`AdminCommand`; comandos `lock`/`restart`/`logoff` + `shutdown` real en `CommandModule`; botones en `devices.php`.

**No entra:** screenshot on-demand (object storage pendiente); `shutdown /a` (cancelar); mensaje/llamada
(descartados); presencia con granularidad < ciclo de handshake (el idle es del último handshake).

---

## Verificación (devkeep)

1. **Presencia:** un cliente con actividad → "Activa"; dejarlo idle > 5 min → "Ausente"; matarlo / sin
   handshake > 10 min → "Desconectada"; persona sin equipo → "Sin keeper". Columna en users + resumen en dashboard.
2. **Doble candado:** con rol sin `remote-control` → device-command 403 y botones ocultos; con firma cuyo
   tier no incluye `remoteShutdown` → botón apagar oculto/rechazado; con ambos → encola.
3. **Acciones:** `lock` bloquea la pantalla del equipo de prueba; `shutdown` real programa el apagado con
   gracia 60 s (verificable en el equipo); `restart`/`logoff` idem. Resultado `done` reportado.

## Archivos afectados

**Esquema:** `Web/migrations/keeper4/17_device_idle.sql`, `18_remote_control_role.sql`.
**Backend:** `ClientHandshake.php`, `DeviceRepo.php`, `AdminCommand.php`.
**Panel:** `admin_auth_helpers.php` (módulo `remote-control` + helper `presence()`), `device-command.php`
(gate RBAC+tier + tipos nuevos), `devices.php` (botones), `users.php` (columna presencia),
`index.php` (resumen), `partials/layout_header.php` (si hace falta nav — no, remote-control no es página).
**Cliente:** `K4ApiClient.cs` (handshake idle), `CoreService.cs` (idle provider), `Program.cs` (pasa idle),
`Modules/CommandModule.cs` (lock/restart/logoff + shutdown real).
**Tests:** `CommandModule` (nuevos tipos, sin ejecutar el shutdown real en test — inyectar un ejecutor);
`presence()` no es C# (PHP) — se verifica en vivo.
