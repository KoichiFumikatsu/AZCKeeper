# Brechas K3 → K4 — informe consolidado (2026-07-31)

**Criterio (Koichi):** K4 = K3 completo + lo nuevo. Cada función de K3 debe estar **igual o mejor**
en K4. Se marca **FALTA** (ausente), **REGRESIÓN** (existe pero más débil) o **✅** (igual/mejor).

Fuentes: tres auditorías paralelas (backend/API, panel, cliente C# + agente) sobre
`C:\Users\FumiWork\Documents\AZCKeeper` (K3 = `AZCKeeper_Client/` + `Web/`; K4 = `AZCKeeper.K4/` +
`WebK4/` + `AZCKeeperAgent/`).

---

## TIER 1 — Funciones núcleo de K3 ausentes (bloquean la paridad)

### 1. Bloqueo web operativo (el que Koichi no ve) — **FALTA (end-to-end)**
- Diseño correcto: enforcement por el **agente elevado** (URLBlocklist/DownloadRestrictions/
  ExtensionInstallBlocklist en **HKLM**), no en el cliente per-user.
- Pero el agente hoy es **runner de desarrollo con política hardcodeada**: sin host de servicio
  Windows, sin instalador (`sc create`), sin recibir la política del servidor.
- Además el cliente K4 **no limpia los residuos legacy** de K3 (PAC/URLBlocklist HKCU/proxy) que
  `WebBlockingManager`/`SystemProxyManager` sí limpiaban → equipos migrados quedan con restos.
- Falta también la **UI del panel** para configurar los dominios bloqueados por política/firma, y
  la validación `webBlocking.domains` en el handshake (K3 la tenía; K4 no).
- **Cerrar:** servicio SYSTEM del agente + instalador + costura de transporte (política del panel →
  `%ProgramData%\AZCKeeper\policy.json` → agente → HKLM → `applied.json` → cliente reporta) + panel
  de dominios + limpieza de residuos en el cliente.

### 2. Bloqueo de equipo con PIN (device lock) — **FALTA (end-to-end)**
- K3 `Blocking/KeyBlocker.cs`: pantalla completa en TODOS los monitores, `LowLevelKeyboardHook`
  (bloquea Win, Alt+Tab, Ctrl+Shift+Esc), keep-on-top, **desbloqueo por PIN local**, reactivación
  por handshake, notifica `client/device-lock/unlock`.
- K4: solo `CommandModule` → `LockWorkStation()` nativo (el usuario desbloquea con su clave normal).
  NO es bloqueo coercitivo.
- Backend: faltan `POST /client/device-lock/unlock` y `getStatus`.
- **Cerrar:** portar KeyBlocker como módulo K4 (o al agente si se quiere hook robusto) + endpoints
  de unlock/estado + PIN en la política.

### 3. Organización + asignaciones + import de usuarios — **FALTA (end-to-end)**
- Panel K3: `organization.php` (CRUD sociedades/firmas/áreas/cargos/sedes + **fuente de datos
  multi-tenant + probador de conexión**) y `assignments.php` (vincular persona ↔ org, incl. batch).
- Backend K3: `LegacySyncService` (sync `employee`→`keeper_user_assignments`), `LegacyAuthRepo`.
- K4: **no existe ninguna de las dos páginas ni el sync**. Se asume "import multi-tenant" que puebla
  `keeper_users`, pero **ese import no está cableado ni tiene UI**. Sin esto no hay alta real de
  estructura organizacional ni de usuarios.
- **Cerrar:** decidir el modelo (import multi-tenant `keeper_source` del plan maestro §0.5) y
  construir su panel + el mecanismo de sync/import.

### 4. Tracking de llamadas real — **REGRESIÓN**
- K3: `CallSessionSeconds` (segundos reales en llamada), `IsInCallNow`, alimenta `CallSeconds` en
  activity-day y el override "contar llamada como actividad" (`CountCallsAsActive`,
  `CallActiveMaxIdleSeconds`), keywords configurables.
- K4: `WindowModule` solo marca `IsCallApp` (bool) por episodio; `CallSeconds` va **hardcodeado a 0**.
  `ActivityModule` ya referencia un módulo `"callTracking"` que **no existe**.
- **Cerrar:** módulo `callTracking` que acumule segundos + el override de actividad-por-llamada.

### 5. Categorización horaria + WorkSchedule + resume del día — **REGRESIÓN / FALTA**
- K3: `WorkSchedule.cs` divide activo/idle en **trabajo / almuerzo / fuera-de-horario**; el servidor
  envía el horario en el handshake; `TryResumeTodayActivityFromServer()` retoma el día al reiniciar.
- K4: `ActivityModule` solo manda `Work=active`; **sin lunch/after-hours, sin horario remoto**; el
  handshake K4 **no devuelve `workSchedule`**; y arranca contadores en 0 → **pierde datos al
  reiniciar a media jornada** (falta `GET /client/activity-day` de relectura).
- **Cerrar:** WorkSchedule en handshake + categorización en el cliente + relectura del día.

---

## TIER 2 — Visibilidad / soporte

### 6. Logging cliente → panel — **FALTA (end-to-end)**
- K3: `POST /client/logs` + `ClientLogRepo` + drain periódico de Warn/Error del cliente + página
  `logs.php` (pestaña "log del cliente" con histórico por equipo).
- K4: `LocalLogger` guarda local pero **no drena al panel**; **no hay endpoint `/client/logs` ni
  repo ni página**. `diagnostics.php` es efímero (2 h), no reemplaza el histórico.
- **Cerrar:** endpoint + repo + drain en el cliente + página de historial (o pestaña en audit).

### 7. Productividad / Focus Score (UI) — **FALTA**
- K3: `productivity.php` (Focus Score, ranking, tendencias, cálculo manual del día).
- K4: no hay UI de productividad ni ranking. El cron calcula focus, pero **no se muestra**.
- **Cerrar:** página de productividad sobre `keeper_focus_daily`.

### 8. Dashboard de KPIs de productividad — **REGRESIÓN**
- K3 `index.php`: KPIs agregados de flota + contador de doble empleo por período.
- K4 `index.php`: tablero de estado del **agente/seguridad**, no de productividad.
- **Cerrar:** decidir si el dashboard lleva ambos (seguridad + productividad) o dos vistas.

---

## TIER 3 — Resiliencia / operación

### 9. Re-enroll por device_guid — **FALTA**
- K3: `POST /client/re-enroll` + el cliente intenta re-enroll SIN credenciales antes que DPAPI.
- K4: solo re-login con cédula+password. Un equipo que pierde el token y no tiene creds queda fuera.

### 10. Force-handshake (bump de política a la flota) — **FALTA**
- K3: `POST /client/force-handshake` (admin sube la versión global → toda la flota re-pull).
- K4: no existe. (El `force_update` de releases es para el binario, no para la política.)

### 11. TimeSync con hora del servidor — **FALTA**
- K3: `Core/TimeSync.cs` ajusta el reloj desde `ServerTimeUtc`. K4 usa reloj local.

---

## TIER 4 — Secundarias / confirmar

- **`sedes-dashboard.php`** (vista por sede) — FALTA (previsto como GROUP BY en index, no hecho).
- **`server-health.php`** (PHP/disco/MySQL/API) — FALTA.
- **`policies.php`**: falta **override por usuario**, **batch**, y **scope por dispositivo**
  (K3 `policies_diagnostic_backup.php`) — REGRESIÓN.
- **`POST /client/window-episode`** (episodio único) — FALTA (menor; existe el batch).
- **ScreenshotModule**: blob store es `StubBlobStore` → metadata sí, subida real pendiente (object
  storage). El screenshot on-demand del tab de control depende de esto.
- **Ventana de debug LOCAL**: K3 tenía `DebugWindowForm` en el equipo; K4 lo movió al panel
  (`diagnostics.php`). Sin herramienta local para soporte en sitio (aceptable, confirmar).
- **Verificar equivalencia de fórmulas** de productividad K3 (`ProductivityCalculator`) vs K4
  (`Metrics`).

---

## Lo que K4 AÑADE sobre K3 (no son brechas, es el "+ lo nuevo")
tiers/licenciamiento, process-view (visor de procesos #1 del plan), diagnóstico en vivo, control
remoto (tab), specs del equipo, presencia, comandos remotos (shutdown/restart/logoff/lock/net-diag),
module-state, location (GPS), screenshots (metadata), auditoría con actor, RBAC editable, auto-update
con force por canal, self-install per-user, aplicar config en caliente sin reinicio.

---

## Orden propuesto para cerrar (a validar con Koichi)
1. **Bloqueo web operativo** (agente SYSTEM + transporte + panel de dominios + limpieza residuos) —
   es lo que Koichi pide y desbloquea el módulo estrella de control.
2. **Organización + import de usuarios** — sin alta de datos, nada escala más allá de pruebas.
3. **Bloqueo de equipo con PIN** — control coercitivo, muy usado en BPO.
4. **Calidad de productividad**: llamadas reales + WorkSchedule + resume del día + UI de Focus Score.
5. **Logging cliente→panel** (endpoint + página) — soporte.
6. **Resiliencia**: re-enroll + force-handshake + TimeSync.
7. **Secundarias**: sedes, server-health, policies (override/batch/device), object storage de screenshots.
