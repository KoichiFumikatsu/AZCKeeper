# Keeper 4 — Diagnóstico en vivo por persona + Login con entorno en el primer arranque

**Fecha:** 2026-07-31
**Rama:** `feature/modulo-seguridad` (continúa el trabajo de K4 en DEV; PROD no se toca)
**Entorno:** `devkeep.azclegal.com` + `pipezafra_keepdev`
**Contexto previo:** el panel K4 quedó completo (5 rebanadas). Este documento cubre dos
features nuevas pedidas por Koichi sobre el cliente K4 y su backend.

---

## Resumen

Dos features independientes que comparten el hecho de que **hoy no existen** en K4:

**A. Diagnóstico en vivo por persona, en el panel.** IT marca a una persona en el panel;
su cliente entra en "modo diagnóstico" y sube, cada ~4 s, un snapshot con el estado
esperado-vs-real de cada módulo, el flujo de logs, la actividad en crudo y la salud de red.
El panel muestra una vista por persona que se auto-refresca. Subproducto obligado: vuelve el
**módulo de logging** del cliente (hoy es `Console.WriteLine`, que bajo WinExe se pierde).

**B. Login real en el primer arranque.** La única UI que el cliente muestra en su vida: un
diálogo con selector de entorno + cédula + contraseña, mostrado solo cuando no hay sesión.
El backend valida la contraseña (hoy `/client/login` la ignora). Tras el primer login, el
token DPAPI persiste y no se vuelve a pedir.

Ninguna feature toca producción. Ambas se verifican en devkeep.

---

## Restricción de infraestructura que condiciona el diseño

El hosting compartido `server1872` **no sostiene WebSockets ni streaming** y **no tiene
egreso a internet** (verificado 2026-07-31). Por eso:

- "Tiempo real" = **near-real-time por sondeo**: el cliente hace POST de snapshots cada ~4 s
  y el panel se auto-refresca cada ~4 s. Latencia percibida ~2–5 s. No es un stream.
- La **activación** del modo diagnóstico viaja en el handshake, así que tarda hasta un ciclo
  (≤5 min) en arrancar. Una vez activo, el flujo sí es near-real-time. Se documenta en el
  panel ("esperando primer snapshot").

---

## Feature A — Diagnóstico en vivo por persona

### A.1 Esquema (migración `14_diagnostics.sql`)

**`keeper_diagnostic_session`** — el flag por persona, con expiración de seguridad.

```sql
CREATE TABLE keeper_diagnostic_session (
  user_id     BIGINT UNSIGNED NOT NULL,
  enabled_by  BIGINT UNSIGNED NULL COMMENT 'admin_id que lo encendió',
  started_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at  DATETIME NOT NULL COMMENT 'auto-apagado; started_at + 4h por defecto',
  PRIMARY KEY (user_id),
  KEY ix_diag_expires (expires_at),
  CONSTRAINT fk_diag_user FOREIGN KEY (user_id) REFERENCES keeper_users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
```

Una fila = una persona con diagnóstico activo. Sin fila (o `expires_at` pasado) = apagado.
El auto-apagado es implícito: el handshake y el panel tratan una sesión vencida como ausente;
el cron la purga.

**`keeper_diagnostic_snapshot`** — los snapshots efímeros. Retención 2 h.

```sql
CREATE TABLE keeper_diagnostic_snapshot (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id     BIGINT UNSIGNED NOT NULL,
  device_id   BIGINT UNSIGNED NOT NULL,
  captured_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'llegada al servidor (UTC)',
  client_ts   DATETIME NULL COMMENT 'reloj del cliente al capturar',
  payload     JSON NOT NULL COMMENT 'los 4 bloques',
  PRIMARY KEY (id),
  KEY ix_diagsnap_user (user_id, id),
  KEY ix_diagsnap_purge (captured_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
```

Se guarda historia corta (para rebobinar los últimos minutos), no infinita. Sin FK a
`keeper_users`/`keeper_devices`: es telemetría desechable y no debe encarecer los INSERT.

**Módulo RBAC `diagnostics`** — se agrega a `panelModules()` y a los roles `it`/`admin` en la
migración de roles (los `gerente`/`viewer` no lo ven). Solo IT/superadmin.

### A.2 Cliente — `LocalLogger` (el módulo de logging que vuelve)

Reemplaza el `void Log(string) => Console.WriteLine` de `Program.cs`. Clase nueva
`AZCKeeper.K4.Shell.LocalLogger`:

- **Anillo en memoria** (últimas ~500 entradas): `{ ts, level, source, message }`. Siempre
  activo. Es de donde salen los "logs en vivo" del snapshot.
- **Archivo** en `%APPDATA%\AZCKeeper4\Logs\keeper_yyyyMMdd.log`. Siempre activo, para que el
  forense sobreviva aunque nadie esté mirando. Rotación diaria simple por nombre de archivo.
- **Niveles** `Debug|Info|Warn|Error`. `Log(msg)` sigue existiendo (mapea a Info) para no
  reescribir los ~20 call-sites; se añaden `Debug/Warn/Error(source, msg)`.
- Expone `RecentSince(long seq)` para que el snapshot mande solo lo nuevo desde el último
  envío (cada entrada lleva un número de secuencia monótono).

Se cablea en `Program.cs` sustituyendo el `Log` local; el `ModuleHost.onError` y los handlers
de excepción no manejada pasan a `Error`.

### A.3 Cliente — `DiagnosticSnapshot` y el loop rápido

**Ensamblado del snapshot** (`AZCKeeper.K4.Core.DiagnosticSnapshot`), los 4 bloques:

1. **`modules[]` — esperado vs real.** Por cada módulo registrado en `ModuleHost`:
   `{ code, expected: bool, running: bool, lastError: string? }`.
   - `running` = `ModuleHost.Snapshot()` (ya existe: `IKeeperModule.IsRunning`).
   - `expected` = del último `effectiveConfig.modules` del handshake (CoreService lo guarda).
   - `lastError` = último error de ese módulo capturado por `onError` (el LocalLogger lo tiene).
2. **`logs[]`** — `LocalLogger.RecentSince(lastSeq)` → `{ ts, level, source, message }`.
3. **`activity`** — ventana en foco, proceso, inactividad, último episodio, profundidad de la
   cola offline. Se arma con lo que ya exponen `WinForegroundWindow`, `WinIdleMonitor` y
   `OfflineQueue.Count`. Los módulos exponen un getter de "último visto" (mínimo, solo lectura).
4. **`net`** — `{ backingOff, backoffUntil, lastHandshakeStatus, queueDepth, version,
   latestVersion }`. Sale de accesores que `K4ApiClient` ya tiene (`IsBackingOff`,
   `LastHandshakeStatus`, `PendingQueueCount`) + `K4UpdateManager.Last*`.

**Activación y loop.** El handshake pasa a devolver un bloque `diagnostics`:

```json
"diagnostics": { "enabled": true, "intervalSeconds": 4, "untilUtc": "2026-07-31T20:00:00Z" }
```

`CoreService.RunOnceAsync` lee ese bloque. Cuando `enabled`, arranca (si no está ya) un
`DiagnosticLoop` en background que cada `intervalSeconds`:
- ensambla el snapshot y hace `POST /client/diagnostics`;
- se detiene solo cuando `untilUtc` pasa o el siguiente handshake trae `enabled=false`.

El loop es independiente del loop de handshake (no lo acelera). Si el POST falla, **no** se
encola en la OfflineQueue (es telemetría desechable: se pierde el snapshot y ya). El loop
respeta el backoff de red igual que el resto.

### A.4 Backend — `POST /client/diagnostics`

Endpoint nuevo `ClientDiagnostics::handle`. Auth por bearer token (como el resto de
`/client/*`). Valida que el device pertenezca a la sesión (mismo patrón que el handshake).
Rechaza el snapshot si la persona **no** tiene sesión de diagnóstico activa (`enabled=false`
o vencida) → `409`, para que un cliente que no apagó a tiempo no siga escribiendo. Si es
válido, inserta en `keeper_diagnostic_snapshot`. Tamaño de payload acotado (p.ej. 64 KB;
recorta `logs[]` si excede).

El handshake (`ClientHandshake::handle`) agrega el bloque `diagnostics` leyendo
`keeper_diagnostic_session` del usuario de la sesión (activa y no vencida).

### A.5 Backend — purga en el cron

`ProductivityCron` (ya corre de noche con `CRON_API_KEY`) suma dos `DELETE`:
- `keeper_diagnostic_snapshot WHERE captured_at < NOW() - INTERVAL 2 HOUR`.
- `keeper_diagnostic_session WHERE expires_at < NOW()` (limpieza de flags vencidos).

### A.6 Panel — `diagnostics.php`

Módulo `diagnostics` (IT/superadmin). Dos zonas:

- **Selector de persona** (con scope de firma, como el resto del panel) + toggle
  "Diagnóstico en vivo" que hace UPSERT/DELETE en `keeper_diagnostic_session`
  (`expires_at = NOW() + 4h`), auditado.
- **Vista en vivo** de la persona seleccionada: renderiza el snapshot más reciente
  (`ORDER BY id DESC LIMIT 1`) en los 4 bloques —
  módulos como semáforo esperado/real, consola de logs con scroll, tarjetas de actividad y de
  red. Auto-refresco cada ~4 s por `fetch` a `diagnostics.php?data=1&user=<id>` (devuelve el
  snapshot en JSON) — no recarga la página entera. Si no hay snapshot aún: "esperando primer
  snapshot (hasta 5 min tras activar)".

Estados honestos: si la sesión está activa pero el último snapshot tiene >30 s, se marca
"cliente sin reportar" (el equipo puede estar caído o sin red).

---

## Feature B — Login con entorno en el primer arranque

### B.1 Cliente — diálogo de primer arranque

Clase nueva `AZCKeeper.K4.Shell.FirstRunLogin` (WinForms), mostrada por `Program.Main` **solo**
cuando no hay token restaurado ni cédula en config (equipo nuevo / config borrada). Campos:

- **Entorno** — desplegable poblado de una constante `Environments` en el cliente. Hoy:
  `Desarrollo → http://devkeep.azclegal.com/public/index.php/api`. (Producción se agrega a la
  constante cuando K4 tenga prod; no se incluye una URL muerta.)
- **Cédula** — texto.
- **Contraseña** — texto oculto. Hint visible con la convención (`z<cédula>Z@!$`).

Al aceptar: intenta `LoginAsync(cc, password, ...)`. Según la respuesta del backend (B.3):
- **200** → guarda `BaseUrl` (del entorno) + `Cc` en config, persiste el token, cierra el
  diálogo y arranca el residente invisible.
- **202 pending** → mensaje "Equipo en espera de aprobación", el diálogo queda abierto para
  reintentar (tras que IT apruebe).
- **401** → "Cédula o contraseña incorrecta", reintentar.

Requiere sesión interactiva (el caso normal de IT instalando; si el install va por SYSTEM sin
sesión, la config puede pre-sembrarse — se documenta como limitación, no se resuelve aquí).

Elimina el fallback de desarrollo `cfg.Cc = "K4TEST"` y la URL fija del código como default de
producción (la constante de entornos pasa a ser la fuente).

### B.2 Cliente — `K4ApiClient.LoginAsync` con contraseña

La firma pasa a incluir `password`. El body del POST agrega `"password"`. El re-login
silencioso de `CoreService` (hoy re-postea solo el CC en un 401 de handshake) **deja de
funcionar sin contraseña**: se ajusta para reusar la contraseña de la sesión inicial, que se
guarda cifrada con DPAPI junto al token (`K4CredentialStore`) para poder re-loguear sin volver
a mostrar UI. (Sin esto, un token vencido dejaría al equipo fuera hasta reinstalar.)

### B.3 Backend — `/client/login` con validación de contraseña

`ClientLogin::handle` cambia su lógica de credenciales (el enrolamiento por device se mantiene
igual). Tres caminos:

1. **Cédula no existe** → `pending` + solicitud de enrolamiento (como hoy). No se fija
   contraseña.
2. **Cédula existe, `password_hash` NULL** → **primer login fija la contraseña**. El servidor
   **exige** que la contraseña recibida sea exactamente `z<cédula>Z@!$` (la convención); si no
   coincide → `401`. Si coincide, `password_hash = password_hash(bcrypt)` y continúa el login.
   *(Suposición confirmada por Koichi: el servidor exige el patrón al fijar. Si en el futuro se
   quiere aceptar cualquier contraseña en este caso, es un `if` localizado aquí.)*
3. **Cédula existe, `password_hash` presente** → `password_verify`; si falla → `401` (no enrola,
   no crea sesión). Si pasa, continúa.

El rate-limit por IP existente (10/min) se mantiene y ahora protege también contra fuerza
bruta de contraseña. La respuesta `200` no cambia de forma (token + expiración + displayName).

### B.4 Panel — contraseña en la ficha de personas (opcional, de apoyo)

Para el caso 3, IT necesita poder **restablecer** la contraseña de un agente (olvidos, rotación).
Se agrega a `users.php` (o a la aprobación en `pending-users.php`) un "restablecer contraseña"
que hace `UPDATE keeper_users SET password_hash = ...`, auditado — reusando el patrón ya escrito
para cuentas admin en `roles.php`. Poner la contraseña de vuelta a NULL vuelve a habilitar el
auto-set del caso 2 (útil para re-provisionar).

---

## Alcance explícito

**Entra:** las dos migraciones, el `LocalLogger`, el snapshot + loop de diagnóstico, el
endpoint `/client/diagnostics`, el bloque `diagnostics` en el handshake, la purga en el cron,
`diagnostics.php`, el diálogo de primer arranque, la validación de contraseña en `/client/login`,
el DPAPI de la contraseña, y el reset de contraseña en el panel.

**No entra (fuera de alcance, no re-prometer):**
- Ventana de diagnóstico **local** en el equipo — se decidió mandar todo al panel.
- Streaming real / WebSockets — el hosting no lo sostiene.
- Historial de diagnóstico de largo plazo — es efímero por diseño (evita el write-amp que ya
  golpeó dos veces).
- Cambio de contraseña por el propio agente / contraseña temporal — descartado por Koichi.
- Producción de K4 — no existe; la lista de entornos solo trae Desarrollo.
- CSRF en el panel — deuda conocida desde la rebanada 1, se aborda aparte.

---

## Plan de verificación (todo contra devkeep)

1. **Login:** cédula desconocida → pending; cédula sin contraseña + patrón correcto → fija y
   entra; patrón incorrecto → 401; cédula con contraseña buena/mala → 200/401. Token persiste:
   segundo arranque no pide login.
2. **Diagnóstico:** encender el flag en el panel → el cliente empieza a subir snapshots dentro
   de un ciclo; la vista en vivo muestra los 4 bloques y se refresca; apagar el flag detiene los
   POST; forzar un módulo a fallar se ve como esperado=ON/real=OFF con su error; la purga borra
   snapshots viejos y flags vencidos.
3. **RBAC:** `diagnostics` visible para IT/superadmin y oculto/302 para gerente.

---

## Archivos afectados (mapa para el plan)

**Backend (`WebK4/`):** `src/Endpoints/ClientDiagnostics.php` (nuevo), `src/Endpoints/ClientLogin.php`,
`src/Endpoints/ClientHandshake.php`, `src/Endpoints/ProductivityCron.php`, `src/Repos/` (repo de
diagnóstico nuevo + método de contraseña en `UserRepo`), `public/index.php` (ruta nueva),
`public/admin/diagnostics.php` (nuevo), `public/admin/users.php` y/o `pending-users.php`,
`public/admin/admin_auth_helpers.php` (módulo `diagnostics`), `public/admin/partials/layout_header.php`.

**Esquema:** `Web/migrations/keeper4/14_diagnostics.sql` (nuevo), y una línea en la migración de
roles para el módulo `diagnostics`.

**Cliente (`AZCKeeper.K4/`):** `Shell/LocalLogger.cs` (nuevo), `Shell/FirstRunLogin.cs` (nuevo),
`Core/DiagnosticSnapshot.cs` (nuevo), `Core/DiagnosticLoop.cs` (nuevo), `Core/CoreService.cs`,
`Core/K4ApiClient.cs`, `Shell/K4CredentialStore.cs`, `Shell/K4Config.cs`, `Program.cs`, y getters
mínimos de "último visto" en los módulos que alimentan el bloque `activity`.

**Tests (`AZCKeeper.Tests`):** LocalLogger (anillo + secuencia), DiagnosticSnapshot (esperado vs
real), decisión de activación del loop, y la lógica de los tres caminos de login (con un doble
del PDO o a nivel de repo).
