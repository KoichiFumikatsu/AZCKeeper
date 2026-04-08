# AZCKeeper — Resumen técnico del proyecto

## ¿Qué es AZCKeeper?

AZCKeeper es un sistema de **monitoreo de productividad laboral** compuesto por tres capas:

1. **Cliente Windows** (`AZCKeeper_Client` — C# / WinForms) — se instala en el equipo del empleado y corre en segundo plano.
2. **Backend API** (`Web/` — PHP 8) — recibe los datos del cliente y los almacena en MySQL.
3. **Panel de control** (`Web/public/admin/` — PHP + Tailwind CSS) — permite a los administradores ver la actividad, aplicar políticas y bloquear dispositivos.

---

## Arquitectura general

```
┌──────────────────────┐          HTTPS/JSON         ┌────────────────────────────┐
│   Cliente C# (PC)    │ ─────────────────────────── │   Backend PHP (servidor)   │
│                      │                              │                            │
│  CoreService         │  POST /api/client/login      │  ClientLogin.php           │
│  ActivityTracker     │  POST /api/client/handshake  │  ClientHandshake.php       │
│  WindowTracker       │  POST /api/client/activity-day│ ActivityDay.php           │
│  KeyBlocker          │  POST /api/client/window-episode│ WindowEpisode.php       │
│  UpdateManager       │  GET  /api/client/version    │  ClientVersion.php         │
│                      │  POST /client/device-lock/.. │  DeviceLock.php            │
└──────────────────────┘                              └────────────┬───────────────┘
                                                                   │  MySQL
                                                                   ▼
                                                      ┌────────────────────────────┐
                                                      │   Base de Datos MySQL      │
                                                      │  (keeper_* tables)         │
                                                      └────────────┬───────────────┘
                                                                   │
                                                      ┌────────────▼───────────────┐
                                                      │   Panel Admin (PHP)        │
                                                      │  /admin/index.php, etc.    │
                                                      └────────────────────────────┘
```

---

## Flujo completo del cliente

### 1. Arranque (`Program.cs`)

- Se crea un **Mutex** para garantizar una sola instancia del proceso.
- Se instancia `CoreService` y se llama a `Initialize()` → `Start()`.
- El proceso corre en segundo plano sin ventana visible (usando `ApplicationContext` vacío).

### 2. Inicialización (`CoreService.Initialize()`)

| Paso | Detalle |
|------|---------|
| `ConfigManager.LoadOrCreate()` | Lee `config.json` (`%APPDATA%\AZCKeeper\`) con `ApiBaseUrl`, `DeviceId`, versión, PIN, horario, etc. |
| `AuthManager.TryLoadTokenFromDisk()` | Intenta cargar el token de sesión guardado. |
| Sin token → Login | Si no hay token, muestra `LoginForm` o intenta silent re-login con credenciales guardadas. |
| `ApiClient` | Se construye el cliente HTTP con base URL y token. |
| `PerformHandshake()` | Primer handshake con el servidor para obtener política efectiva. |
| `InitializeModules()` | Crea `ActivityTracker`, `WindowTracker`, `KeyBlocker` (si política lo permite), `UpdateManager`. |
| `StartupManager.EnableStartup()` | Registra la app en el inicio de Windows. |

### 3. Login del cliente (`POST /api/client/login`)

```
Cliente envía: { username (CC numérico), password, deviceId, deviceName }
                         │
           1. Valida contra BD legacy (tabla `employee` — contraseña en texto plano)
           2. Crea o actualiza usuario en `keeper_users` (con bcrypt)
           3. Sincroniza firma/área/cargo/sede → `keeper_user_assignments`
           4. Crea o actualiza dispositivo → `keeper_devices`
           5. Genera token (64 bytes hex) + guarda hash SHA-256 en `keeper_sessions`
           6. Devuelve: { token, userId, deviceId, displayName }
```

El cliente guarda el token en disco para reutilizarlo en reinicios.

### 4. Handshake periódico (`POST /api/client/handshake`) — cada 60 s

Es el latido central del sistema. Devuelve todo lo que el cliente necesita en una sola respuesta:

```
Cliente envía: { version, deviceId, deviceName }  + Bearer token
                         │
           1. Valida token → obtiene userId/deviceId
           2. Actualiza last_seen_at en keeper_devices
           3. Calcula política efectiva: global → user → device (deepMerge)
           4. Obtiene horario laboral (keeper_work_schedules)
           5. Calcula estado del dispositivo: active / away / inactive
           6. Actualiza keeper_devices.device_status + day_summary_json
           7. Devuelve: { effectiveConfig, workSchedule, deviceStatus, policyApplied, serverTimeUtc }
```

`effectiveConfig` incluye, entre otros:
- `modules.*` — qué módulos están habilitados (tracking, blocking, updates…)
- `blocking.*` — si el dispositivo debe estar bloqueado y el PIN
- `tracking.*` — umbrales de inactividad
- `update.*` — URL del instalador si hay nueva versión

### 5. Tracking de actividad (`ActivityTracker`)

- Un `System.Timers.Timer` dispara cada N segundos (configurable).
- Usa `GetLastInputInfo` (Win32) para medir tiempo sin input (idle).
- Clasifica cada intervalo como **activo** o **inactivo**.
- Gestiona cambios de día (medianoche local) dividiendo el delta.
- El tiempo se categoriza según el horario laboral en:
  - `workHoursActive/Idle` — dentro del horario laboral
  - `lunchActive/Idle` — durante la hora de almuerzo
  - `afterHoursActive/Idle` — fuera de horario

Cada ~5 minutos (o al cerrar el día) se hace flush a la API:

### 6. Envío de actividad (`POST /api/client/activity-day`)

```
Cliente envía: { deviceId, dayDate, activeSeconds, idleSeconds, callSeconds,
                 workHoursActiveSeconds, lunchActiveSeconds, afterHoursActiveSeconds,
                 samplesCount, tzOffsetMinutes, isWorkday, ... }
                         │
           INSERT / ON DUPLICATE KEY UPDATE en keeper_activity_day
           (usa GREATEST para no reducir acumulados si llegan retrasados)
```

Al arrancar, el cliente primero lee el acumulado del día con `GET /api/client/activity-day` para retomar donde dejó.

### 7. Tracking de ventanas (`WindowTracker` / `POST /api/client/window-episode`)

- Monitorea la ventana activa en primer plano usando Win32 hooks.
- Cuando cambia la ventana, cierra el episodio anterior y lo envía:

```
Cliente envía: { deviceId, startLocalTime, endLocalTime, durationSeconds,
                 processName, windowTitle, isCallApp }
                         │
           INSERT en keeper_window_episode
```

- Si `isCallApp=true` (Teams, Zoom, etc.) se activa `ActivityOverridePredicate` en `ActivityTracker` para contar el tiempo como activo aunque no haya input de teclado/ratón.

### 8. Actualizaciones automáticas (`GET /api/client/version` + `UpdateManager`)

- El handshake incluye `update.latestVersion` y `update.downloadUrl`.
- Si la versión del servidor es mayor, `UpdateManager` descarga el instalador y lo ejecuta.

---

## Flujo del Panel de Control (Admin)

### Autenticación del admin

```
login.php
  POST email + password
        │
  AdminAuthRepo::findByEmail()
    JOIN keeper_admin_accounts + keeper_users
        │
  password_verify() vs keeper_users.password_hash
        │
  AdminAuthRepo::createSession()
    INSERT keeper_admin_sessions (token_hash SHA-256)
    Cookie httpOnly "keeper_admin_token", TTL 8h
        │
  redirect → index.php
```

Cada página llama a `require 'admin_auth.php'` que valida la cookie y expone `$adminUser` y `$pdo`.

### Páginas del panel

| Archivo | Función |
|---------|---------|
| `index.php` | Dashboard: KPIs, Focus Score, donut de productividad, apps más usadas, top usuarios |
| `users.php` | Lista de empleados con estado (Online/Ausente/Offline), métricas del día |
| `user-dashboard.php` | Vista detallada de un usuario (timeline, ventanas, etc.) |
| `devices.php` | Gestión de dispositivos registrados |
| `policies.php` | Crear/editar políticas (global, por usuario, por dispositivo) |
| `assignments.php` | Asignar firma/área/cargo a usuarios |
| `roles.php` | Gestión de roles de panel (`superadmin / admin / viewer`) |
| `admin-users.php` | Gestión de cuentas admin (solo superadmin) |
| `releases.php` | Publicar nuevas versiones del cliente |
| `organization.php` | Gestión de firmas, áreas, cargos, sedes, sociedades |
| `productivity.php` | Métricas de productividad calculadas por cron |
| `sedes-dashboard.php` | Dashboard agregado por sede |
| `panel-settings.php` | Ajustes globales del panel |
| `server-health.php` | Estado del servidor y la BD |

### Estado online del usuario

El estado se calcula en PHP desde `keeper_devices.last_seen_at`:

```
< 2 minutos  →  🟢 Online
< 15 minutos →  🟡 Ausente
≥ 15 minutos →  ⚫ Offline
```

---

## Sistema de Bloqueo Remoto

Ver `BLOCKING_SYSTEM.md` para la documentación completa. Resumen:

| Campo | Función |
|-------|---------|
| `modules.enableBlocking` | Enciende el módulo `KeyBlocker` en el cliente (motor) |
| `blocking.enableDeviceLock` | Activa el bloqueo inmediato de pantallas (acelerador) |

**Flujo:**
1. Admin activa `enableDeviceLock` en la política del usuario/dispositivo.
2. Cliente detecta el cambio en el handshake (máx. 60 s).
3. `KeyBlocker` bloquea todas las pantallas con superposición de pantalla completa.
4. Usuario ingresa PIN → validación local → notifica servidor (`POST /client/device-lock/unlock`).
5. El servidor registra el desbloqueo en `keeper_device_locks.unlocked_at`.
6. Handshake respeta el desbloqueo manual: no vuelve a bloquear.

---

## Base de Datos — Tablas principales

| Tabla | Descripción |
|-------|-------------|
| `keeper_users` | Empleados con hash de contraseña bcrypt |
| `keeper_devices` | Equipos registrados (GUID único por máquina) |
| `keeper_sessions` | Tokens de sesión del cliente (hash SHA-256, sin expiración) |
| `keeper_admin_accounts` | Cuentas con acceso al panel admin |
| `keeper_admin_sessions` | Sesiones del panel admin (8h TTL) |
| `keeper_activity_day` | Segundos activos/inactivos/llamada por usuario+dispositivo+día |
| `keeper_window_episode` | Episodios de ventana activa (proceso, título, duración) |
| `keeper_policies` | Definición de políticas en JSON |
| `keeper_policy_assignments` | Asignación de política a scope (global/user/device) |
| `keeper_work_schedules` | Horarios laborales por usuario |
| `keeper_device_locks` | Registros de bloqueo/desbloqueo manual |
| `keeper_firmas` | Firmas/clientes |
| `keeper_areas` | Áreas organizacionales (jerarquía padre/hijo) |
| `keeper_cargos` | Cargos con nivel jerárquico |
| `keeper_sedes` | Sedes/ubicaciones físicas |
| `keeper_sociedades` | Sociedades/organizaciones |
| `keeper_user_assignments` | Asignación firma+área+cargo+sede a cada usuario |
| `keeper_client_releases` | Versiones publicadas del cliente C# |
| `keeper_audit_log` | Registro de auditoría (login, cambios de política, etc.) |
| `keeper_productivity_scores` | Puntajes calculados por el cron de productividad |

### Integración con BD legacy

El login valida primero contra la tabla `employee` de la BD **legacy** (contraseña en texto plano). Si es válida, `LegacySyncService` sincroniza automáticamente los datos del empleado (firma, área, cargo, sede) hacia las tablas `keeper_*`.

---

## Endpoints de la API

| Método | Ruta | Descripción |
|--------|------|-------------|
| `GET`  | `/api/health` | Estado del servidor |
| `POST` | `/api/client/login` | Login del cliente con CC + password |
| `POST` | `/api/client/handshake` | Latido principal (cada 60 s) |
| `GET`  | `/api/client/activity-day` | Recuperar acumulado del día |
| `POST` | `/api/client/activity-day` | Guardar/actualizar acumulado del día |
| `POST` | `/api/client/window-episode` | Guardar episodio de ventana |
| `GET`  | `/api/client/version` | Versión disponible del cliente |
| `POST` | `/api/client/force-handshake` | Forzar handshake inmediato |
| `POST` | `/cron/productivity` | Calcular puntajes de productividad (cron) |

---

## Configuración del entorno (`Web/.env`)

El backend se configura mediante un archivo `.env` (ver `.env.example`):

```
DB_HOST, DB_NAME, DB_USER, DB_PASS        # BD principal keeper_*
LEGACY_DB_HOST, LEGACY_DB_NAME, ...       # BD legacy (empleados)
APP_BASE_URL                              # Prefijo de URL si está en subdirectorio
API_PREFIX                                # Prefijo de la API (default: /api)
DEBUG                                     # true/false — expone detalles en errores 500
```

---

## Resumen del flujo end-to-end

```
[Empleado enciende PC]
       │
[Cliente C# inicia] → Mutex (1 sola instancia)
       │
[CoreService.Initialize()]
   ├─ Carga config.json
   ├─ Carga token guardado
   ├─ POST /api/client/login  (si no hay token)
   │       └─ Devuelve token → se guarda en disco
   └─ POST /api/client/handshake (primer handshake)
           └─ Aplica política efectiva (modules, blocking, tracking, update)
       │
[CoreService.Start()] → inicia timers
   ├─ HandshakeTimer (cada 60s) → POST /api/client/handshake
   ├─ ActivityFlushTimer (cada ~5 min) → POST /api/client/activity-day
   └─ Módulos iniciados:
       ├─ ActivityTracker (mide activo/inactivo con GetLastInputInfo)
       ├─ WindowTracker (detecta cambios de ventana → POST /api/client/window-episode)
       ├─ KeyBlocker (si modules.enableBlocking=true — espera señal de bloqueo)
       └─ UpdateManager (comprueba versión nueva en cada handshake)
       │
[Backend guarda en MySQL]
   ├─ keeper_activity_day  (acumulados diarios)
   └─ keeper_window_episode (historial de aplicaciones)
       │
[Panel Admin lee MySQL]
   ├─ Dashboard: KPIs, Focus Score, productividad, apps más usadas
   ├─ Users: estado online, métricas del día
   └─ Puede activar bloqueo remoto → cliente bloquea en ≤60s
```
