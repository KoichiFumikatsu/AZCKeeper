# AZCKeeper v4 — Mapa de módulos y brechas contra K3

> Documento maestro de estructura. Define **los módulos canónicos de v4** (mismo vocabulario en backend,
> panel y cliente) y la **matriz de lo que se trae de K3**. Guía la construcción restante, incluido el
> cliente C# que se rehace desde cero.
> Fecha: 2026-09-17. Verificado contra K3 en producción y contra v4 desplegado en `devkeep`.

---

## 1. Inventario real de K3 (lo que funciona hoy en producción)

**Panel (22 páginas):** `index` (tablero) · `users` · `user-dashboard` (ficha por persona) · `devices` ·
`assignments` · `organization` · `sedes-dashboard` · `policies` · `productivity` · `dual-job-alerts` ·
`install-coverage` · `logs` · `releases` · `roles` · `admin-users` · `pending-users` · `panel-settings` ·
`server-health` · `login`/`logout`/`admin_auth`.

**Endpoints (14):** ActivityDay · ClientHandshake · ClientLogBatch · ClientLogin · ClientReEnroll ·
ClientVersion · DeviceLock · EventIngest · ExternalAuth · ForceHandshake · Health · ProductivityCron ·
WindowEpisode · WindowEpisodeBatch.

**Repos (12):** AdminAuth · Audit · ClientLog · Device · Handshake · LegacyAuth · PendingEnrollment ·
Policy · Productivity · Release · Session · User.

**Cliente (módulos reales):** `Auth` · `Blocking` (KeyBlocker/PIN, WebBlocking PAC, SystemProxy) ·
`Config` · `Core` (CoreService god object, TimeSync) · `Logging` · `Network` (ApiClient, backoff,
OfflineQueue) · `Startup` · `Tracking` (Activity, Windows, WorkSchedule; Keyboard/MouseHook muertos) ·
`Update`.

**BD (40 tablas):** users, devices, sessions, user_assignments, firmas, sedes, areas, cargos,
work_schedules, **holidays**, holiday_sociedades, activity_day, focus_daily, window_episode, client_log,
**dual_job_alerts**, **install_coverage_notes**, **suspicious_apps**, policy_assignments, client_releases,
admin_accounts, admin_sessions, panel_roles, panel_settings, audit_log.

---

## 2. Módulos canónicos de v4

Un solo vocabulario para backend, panel y cliente. Todo lo que se construya debe caer en uno de estos.

| # | Módulo | Qué cubre | Estado v4 |
|---|---|---|---|
| 1 | **Identidad y organización** | tenants, org_units (firma/sede/área/cargo), users, assignments, roles/permisos, horarios | ✅ completo |
| 2 | **Dispositivos** | devices, enrolamiento, claves, comandos remotos, releases/updater | ✅ base; falta cobertura de instalación |
| 3 | **Políticas** | documentos/versiones/reglas/asignaciones, compilador, política efectiva | ✅ completo |
| 4 | **Actividad** | episodes, episode_daily, day_summary, focus_daily, clasificación de apps | ⚠️ datos sí, **procesamiento y vistas no** |
| 5 | **Presencia** | check-ins, puertas, puntualidad vs horario, festivos | ⚠️ tabla sí, **festivos y cálculo no** |
| 6 | **Cumplimiento** | bloqueo web/USB/instalación, bloqueo con PIN, doble empleo, apps sospechosas, anti-robo | ⚠️ enforcement parcial; **doble empleo y apps sospechosas faltan** |
| 7 | **Reportes** | productividad, top de apps, presencia, ranking por área/sede | ⚠️ solo `/reports/productivity` |
| 8 | **Licenciamiento** | tiers, módulos por tier, suscripción por miembro | ✅ completo |
| 9 | **Operación** | auditoría, logs del cliente, notificaciones, diagnóstico, salud del servidor, ajustes | ⚠️ tablas sí, **vistas no** |
| 10 | **Integraciones** | API externa `/ext/v1`, OAuth/API-key, webhooks | ✅ completo |

---

## 3. Matriz K3 → v4 (qué se trae, qué falta, qué se descarta)

### 3.1 Ya está en v4 (mejorado)
| K3 | v4 | Mejora |
|---|---|---|
| users/devices/organization/assignments | Módulo 1 y 2 | multi-tenant real, RBAC parametrizable |
| policies (una capa) | Módulo 3 | compilador con precedencia y versión por hash |
| client_releases | Módulo 2 | **firma/hash obligatorios** (cierra el updater inseguro) |
| audit_log, client_log | Módulo 9 | actor + tenant |
| ExternalAuth | Módulo 10 | OAuth2 + API-key + scopes |
| window_episode, activity_day, focus_daily | Módulo 4 | particionado + retención automática |

### 3.2 HAY QUE TRAER de K3 (falta en v4) — ordenado por valor
| # | Capacidad K3 | Módulo v4 | Por qué importa |
|---|---|---|---|
| 1 | **Cron de productividad** (`ProductivityCron`) | 4 Actividad | Sin él no se calculan agregados; el panel queda sin datos frescos |
| 2 | **`user-dashboard`** (ficha por persona) | 4+7 | Es LA vista que usa el coordinador |
| 3 | **`productivity` / rankings** | 7 Reportes | Comparar personas/áreas |
| 4 | **Top de apps** (desde window_episode) | 4+7 | Saber en qué se va el tiempo |
| 5 | **Festivos** (`holidays`) | 5 Presencia | Sin esto los días laborables y la puntualidad salen mal |
| 6 | **Doble empleo** (`dual_job_alerts`) | 6 Cumplimiento | Detección que ya da valor hoy |
| 7 | **Cobertura de instalación** (`install_coverage_notes`) | 2 Dispositivos | Saber qué equipos faltan por enrolar |
| 8 | **Apps sospechosas** (`suspicious_apps`) | 6 Cumplimiento | Señal de riesgo |
| 9 | **`sedes-dashboard`** | 7 Reportes | Vista por sede |
| 10 | **Vista de logs del cliente** | 9 Operación | Soporte sin entrar a la BD |
| 11 | **`server-health`, `panel-settings`** | 9 Operación | Operación del día a día |
| 12 | **Bloqueo con PIN** (`KeyBlocker` + `DeviceLock`) | 6 Cumplimiento | Existe en K3 y se quiere conservar |

### 3.3 Se descarta de K3 (no migrar)
- `LegacyAuthRepo` / tabla `employee` legacy → v4 usa identidad propia con `user_external_refs`.
- `KeyboardHook` / `MouseHook` del cliente → **código muerto** (no implementados).
- `MasterTimer` → reemplazado por el planificador único.
- `policies_diagnostic_backup.php`, `user-dashboard copy.php` → restos.
- Web-blocking por **PAC/proxy** → reemplazado por enforcement real en HKLM desde el servicio SYSTEM.

---

## 4. Estructura del cliente C# (se rehace desde cero, ordenado por módulos)

Regla que ordena todo: **el enforcement necesita SYSTEM; el tracking necesita la sesión del usuario.**
Por eso K3 (todo per-user, sin admin) no podía bloquear de verdad.

```
Keeper.Shared                     ← contrato común (sin lógica de SO)
  Protocol/        DTOs generados del OpenAPI
  Policy/          modelo de política tipado
  Contracts/       IModule, ModuleContext, ModuleSnapshot

Keeper.Agent        (Servicio Windows, LocalSystem, tamper-protected)
  Hosting/         ModuleHost + Scheduler único con presupuesto de red
  Transport/       SyncClient (RFC 9421), reintentos, backoff
  Storage/         Outbox durable, cache de política firmada, claves
  Policy/          aplicación y versión efectiva
  Modules/
    Enforcement/   WebEnforcer · UsbEnforcer · InstallEnforcer · DownloadEnforcer   [Módulo 6]
    Devices/       CommandExecutor (bloquear/apagar/reiniciar) · Inventory/specs     [Módulo 2]
    Update/        UpdateManager con verificación de FIRMA                            [Módulo 2]
    Security/      TamperGuard · DeviceLock (PIN) coordinado con la sesión            [Módulo 6]
    Diagnostics/   salud del agente, reporte de estado                                [Módulo 9]

Keeper.Session      (Proceso por sesión de usuario, lanzado por el Agent)
  Modules/
    Activity/      ActivityTracker (activo/idle)                                      [Módulo 4]
    Windows/       WindowTracker (episodios de ventana, apps)                         [Módulo 4]
    Calls/         detección de llamadas                                              [Módulo 4]
    Presence/      primer login, horario, puntualidad                                 [Módulo 5]
    UI/            LockScreen con PIN · avisos al usuario                             [Módulo 6]
  Ipc/             named pipe con ACL hacia el Agent (jamás red directa)

Keeper.Installer    MSI (WiX) firmado + bootstrap (servicio, azcadmin, degradación)
```

**Qué se rescata de K3 (lógica, no código):** `ActivityTracker` (muestreo idle/activo), `WindowsTracker`
(episodios de ventana y llamadas), `WorkSchedule` (categorización laboral/almuerzo/fuera de horario),
`KeyBlocker` (pantalla de bloqueo con PIN), `OfflineQueue` (cola offline), `NetworkBackoffPolicy` (backoff).
**Qué NO se rescata:** `CoreService` (god object), la doble jerarquía de configuración, los 8 timers sueltos,
el web-blocking por PAC, los hooks muertos.

---

## 5. Orden de construcción propuesto

**Fase A — cerrar el módulo Actividad y Reportes (lo que pide el coordinador):**
1. Cron/worker de productividad (agregados desde `episodes` → `episode_daily`/`focus_daily`/`day_summary`).
2. Endpoints: `/reports/apps`, `/reports/presence`, `/users/{id}/activity`, `/users/{id}/policies`.
3. Vistas: Inicio con gráficos reales, `miembro` completo (pestañas), Reportes, Reglas.

**Fase B — traer lo que falta de K3:** festivos → doble empleo → cobertura de instalación →
apps sospechosas → vistas de operación (logs, salud, ajustes).

**Fase C — cliente C# por módulos** (según §4), empezando por Session/Activity+Windows (es lo que llena
el panel) y Agent/Enforcement (lo que da el control), con el bootstrap al final.

**Regla transversal:** cada cosa nueva se declara primero en el **contrato** (OpenAPI), luego backend,
luego panel, luego cliente. Un solo camino de datos: la API.
