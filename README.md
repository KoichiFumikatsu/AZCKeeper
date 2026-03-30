# AZCKeeper

> Sistema de monitoreo de empleados, gestión de dispositivos y control de productividad para empresas.

---

## Tabla de Contenidos

1. [Resumen del Proyecto](#1-resumen-del-proyecto)
2. [Stack Tecnológico](#2-stack-tecnológico)
3. [Flujo de la Aplicación](#3-flujo-de-la-aplicación)
4. [Carga de Requests API y Queries](#4-carga-de-requests-api-y-queries)
5. [Módulos del Cliente C#](#5-módulos-del-cliente-c)
6. [Módulos del Backend PHP](#6-módulos-del-backend-php)
7. [Panel de Control – Features](#7-panel-de-control--features)
8. [Base de Datos](#8-base-de-datos)
9. [Configuración](#9-configuración)

---

## 1. Resumen del Proyecto

**AZCKeeper** es un sistema empresarial de **monitoreo de actividad, gestión de dispositivos y control de productividad**. Está diseñado para organizaciones (firmas de abogados, BPOs, empresas) que necesitan:

- **Registrar actividad** de sus empleados: tiempo activo, tiempo inactivo, aplicaciones usadas, episodios de ventana.
- **Aplicar políticas remotas** sobre los dispositivos: bloqueo de pantalla, restricciones de módulos.
- **Medir productividad** con métricas diarias (focus score, horas laborales, llamadas, doble empleo).
- **Gestionar el ciclo de vida** de dispositivos y versiones del cliente Windows.
- **Administrar la estructura organizacional**: sociedades, firmas, áreas, cargos, sedes.

El sistema se compone de dos partes:

| Componente | Tecnología | Rol |
|---|---|---|
| **Cliente** | C# (Windows Forms, .NET 8) | Se instala en el PC del empleado; envía actividad y recibe políticas |
| **Backend + Panel** | PHP 8 + MySQL 8 | Recibe datos del cliente, calcula métricas y expone el panel web para admins |

---

## 2. Stack Tecnológico

### Cliente Windows (C#)
- **.NET 8.0-windows**, Windows Forms
- **SQLite** (System.Data.SQLite.Core) – cola offline local
- **DPAPI** (Windows Data Protection API) – cifrado de credenciales en disco
- **HttpClient** – comunicación REST con el backend
- Plataforma: Windows 10+ x64

### Backend (PHP)
- **PHP 8.0+**, sin framework (arquitectura propia MVC-lite)
- **MySQL 8.0+** – base de datos principal
- **PDO** – acceso a BD, sin ORM
- Autenticación: Bearer token stateless
- Configuración: variables de entorno `.env`

### Panel de Control (Web)
- **Tailwind CSS** (CDN) + **Alpine.js** – UI reactiva sin build step
- **PHP sessions** (cookie `httpOnly`, 8 h TTL) – autenticación del admin
- Paleta corporativa: azul oscuro `#003a5d` + rojo `#be1622`

---

## 3. Flujo de la Aplicación

### 3.1 Inicio del cliente y autenticación

```
Program.cs
  └─ CoreService.Initialize()
       ├─ ConfigManager.LoadOrCreate()
       │    └─ Lee client_config.json (URL API, DeviceId, versión)
       ├─ AuthManager.TryLoadTokenFromDisk()
       │    ├─ Descifra token de AppData\AZCKeeper\Auth\auth_token.bin (DPAPI)
       │    └─ Si no hay token → TrySilentReLogin() con credenciales guardadas
       └─ Si sigue sin token → muestra LoginForm
```

El empleado ingresa su número de cédula (CC) y contraseña:

```
LoginForm → CoreService.OnLoginSubmitted
  └─ ApiClient.SendLoginAsync()
       POST /api/client/login  { username, password, deviceId, deviceName }
       
Backend (ClientLogin.php):
  1. Valida CC/contraseña contra BD legada (read-only)
  2. Crea keeper_user si no existe (con password_hash bcrypt)
  3. Crea keeper_session con Bearer token
  4. Retorna { token, displayName, expiresAtUtc, userId }

Cliente:
  └─ AuthManager.UpdateAuthToken()
       ├─ Guarda token en memoria
       ├─ Persiste cifrado en disco (DPAPI)
       └─ Guarda credenciales para auto-relogin
```

### 3.2 Handshake inicial y arranque de módulos

```
CoreService.PerformHandshake()
  └─ POST /api/client/handshake  { version, deviceId, deviceName }
       Authorization: Bearer <token>

Backend (ClientHandshake.php) – 6 queries (ver §4):
  1. Valida Bearer token (keeper_sessions)
  2. Crea o busca keeper_device y actualiza last_seen_at
  3. Obtiene políticas efectivas (global + user + device, deep merge)
  4. Obtiene horario laboral (keeper_work_schedules)
  5. Retorna { effectiveConfig, workSchedule, serverTimeUtc, deviceStatus }

CoreService.Start():
  ├─ ActivityTracker.Start()    – hooks mouse/teclado, muestrea cada 5 s
  ├─ WindowTracker.Start()      – detecta ventana activa cada 1 s
  ├─ UpdateManager.Start()      – verifica actualizaciones
  ├─ _activityFlushTimer        – envía activity-day cada 2 min
  └─ _handshakeTimer            – re-sincroniza políticas cada 60 s
```

### 3.3 Tracking de actividad en tiempo real

```
ActivityTracker (cada 5 s)
  ├─ GetLastInputInfo() → segundos de inactividad
  ├─ WorkSchedule.Categorize() → laboral / almuerzo / fuera de horario
  └─ Acumula activeSeconds, idleSeconds, workActiveSeconds, callSeconds…

WindowTracker (cada 1 s)
  ├─ GetForegroundWindow() → título + proceso
  ├─ Detecta apps de llamadas (Teams, Zoom, etc.)
  └─ OnEpisodeClosed() → ApiClient.SendWindowEpisodeAsync()
       POST /api/client/window-episode

ActivityFlushTimer (cada 2 min)
  └─ ApiClient.SendActivityDayAsync()
       POST /api/client/activity-day  { dayDate, activeSeconds, … }

HandshakeTimer (cada 60 s)
  └─ Handshake completo → recibe políticas actualizadas + estado de bloqueo
```

### 3.4 Bloqueo remoto de dispositivo

```
Admin (Panel Web → Políticas)
  └─ Activa blocking.enableDeviceLock = true en la política del usuario/device

Cliente (HandshakeTimer cada 60 s)
  └─ Recibe effectiveConfig.blocking.enableDeviceLock = true
       ├─ Consulta keeper_device_locks → ¿fue desbloqueado manualmente?
       └─ Si debe bloquear → KeyBlocker.ActivateLock(reason, allowPin, pin)
            ├─ Crea LowLevelKeyboardHook (intercepta teclado)
            ├─ Muestra LockScreenForm en todos los monitores
            └─ Si allowPin=true → muestra input de PIN

Usuario ingresa PIN correcto
  └─ ApiClient.POST /api/client/device-lock/unlock
       └─ Backend registra unlocked_at=NOW() en keeper_device_locks
            → Próximo handshake respeta el desbloqueo manual
```

### 3.5 Navegación del Admin en el Panel de Control

```
/admin/login.php
  └─ POST email + password → bcrypt verify → cookie httpOnly "keeper_admin_token" 8 h

Cada página del admin:
  └─ require admin_auth.php → valida cookie → $adminUser + $pdo disponibles

Panel Admin:
  ├─ Dashboard (index.php)          → KPIs, focus score, actividad del día
  ├─ Usuarios (users.php)           → lista con status online/away/offline
  ├─ User Dashboard (user-dashboard.php?id=X) → detalle individual
  ├─ Dispositivos (devices.php)     → gestión y revocación de dispositivos
  ├─ Políticas (policies.php)       → editor JSON global/usuario/device
  ├─ Productividad (productivity.php) → métricas, alertas, doble empleo
  ├─ Organización (organization.php) → sociedades, firmas, áreas, sedes
  ├─ Asignaciones (assignments.php) → mapeo usuario → org
  ├─ Alertas Doble Empleo (dual-job-alerts.php) → detección de moonlighting
  ├─ Releases (releases.php)        → versiones del cliente Windows
  ├─ Admins (admin-users.php)       → cuentas de administrador
  ├─ Roles (roles.php)              → permisos granulares
  ├─ Configuración (panel-settings.php) → ajustes del sistema
  └─ Salud del servidor (server-health.php) → estado de BD y API
```

### 3.6 Cron de Productividad

```
Cron diario (o trigger manual desde el panel)
  └─ POST /api/cron/productivity  { date? }

Backend (ProductivityCron.php):
  ├─ ProductivityCalculator.calculateDay()
  │    ├─ Lee keeper_activity_day del día
  │    ├─ Calcula focus_score (1–10), ratio activo/total
  │    └─ Guarda en keeper_daily_metrics
  └─ DualJobDetector.detect()
       └─ Analiza patrones de actividad fuera de horario → alerta si anomalía
```

---

## 4. Carga de Requests API y Queries

### 4.1 Frecuencia de requests por cliente activo

| Request | Endpoint | Frecuencia | Tipo |
|---|---|---|---|
| Handshake | `POST /api/client/handshake` | Cada **60 s** | Periódico |
| Activity flush | `POST /api/client/activity-day` | Cada **2 min** | Periódico |
| Window episode | `POST /api/client/window-episode` | Cada cambio de ventana (~1–20/min) | Evento |
| Lock status | Incluido en handshake | — | — |
| Login | `POST /api/client/login` | Una vez al inicio (o token expirado) | Inicio |
| Version check | `GET /api/client/version` | Cada **60 min** | Periódico |

> Con **100 clientes activos simultáneos** se pueden esperar:
> - ~1.7 req/s de handshakes
> - ~0.8 req/s de activity-day
> - ~2–30 req/s de window episodes (según ritmo de trabajo)

### 4.2 Queries por endpoint

| Endpoint | Queries MySQL | Detalle |
|---|---|---|
| `POST /client/login` | **4–5** | SELECT legacyDB (auth) · INSERT/SELECT keeper_user · INSERT keeper_session |
| `POST /client/handshake` | **5–6** | SELECT session · SELECT/INSERT/UPDATE device · SELECT policy global (cache 60s) · SELECT policy user+device (UNION) · SELECT work_schedule · UPDATE last_seen_at |
| `POST /client/activity-day` | **2** | SELECT session · UPSERT keeper_activity_day |
| `POST /client/window-episode` | **2** | SELECT session · INSERT keeper_window_episode |
| `POST /client/device-lock/unlock` | **2** | SELECT session · INSERT keeper_device_locks |
| `GET /client/activity-day` | **2** | SELECT session · SELECT keeper_activity_day |
| `POST /cron/productivity` | **4–N** | SELECT activity_day · calcular métricas · UPSERT daily_metrics (N usuarios) |
| Admin Dashboard | **6–8** | Múltiples SELECTs agregados (KPIs, activity, window_episodes, devices) |

> **Optimización clave**: La política global (`scope='global'`) se cachea **60 segundos en memoria** dentro del proceso PHP, eliminando la query más frecuente del handshake.

---

## 5. Módulos del Cliente C#

### Auth
| Archivo | Descripción |
|---|---|
| `Auth/AuthManager.cs` | Persiste y descifra el Bearer token en disco usando DPAPI. Guarda credenciales para auto-relogin silencioso. |
| `Auth/LoginForm.cs` | Formulario Windows Forms para ingreso de CC y contraseña. Dispara el evento `OnLoginSubmitted`. |

### Core
| Archivo | Descripción |
|---|---|
| `Core/CoreService.cs` | **Orquestador principal.** Inicializa todos los módulos, gestiona timers (handshake cada 60 s, flush cada 2 min), controla el ciclo de vida de la aplicación. |
| `Core/MasterTimer.cs` | Coordinación centralizada de timers para evitar conflictos de hilo. |
| `Core/TimeSync.cs` | Sincroniza el reloj del cliente con el `serverTimeUtc` del handshake. |

### Tracking
| Archivo | Descripción |
|---|---|
| `Tracking/ActivityTracker.cs` | Muestrea actividad cada 5 s usando `GetLastInputInfo()`. Clasifica segundos en: laboral activo, laboral inactivo, almuerzo, fuera de horario, llamadas. |
| `Tracking/WindowTracker.cs` | Detecta cambio de ventana activa cada 1 s. Registra episodios (proceso + título + duración + `is_in_call`). |
| `Tracking/MouseHook.cs` | Hook de bajo nivel para detectar movimiento de mouse (marca actividad). |
| `Tracking/KeyboardHook.cs` | Hook de bajo nivel para detectar pulsaciones de teclado (marca actividad). |
| `Tracking/WorkSchedule.cs` | Define franjas horarias: laboral (07:00–19:00), almuerzo (12:00–13:00), fuera de horario. Categoriza los segundos muestreados. |

### Blocking
| Archivo | Descripción |
|---|---|
| `Blocking/KeyBlocker.cs` | Crea un `LowLevelKeyboardHook` y abre un `LockScreenForm` en **cada monitor detectado**. Bloquea el teclado completamente hasta que se ingrese el PIN correcto o el admin desactive el bloqueo. |

### Network
| Archivo | Descripción |
|---|---|
| `Network/ApiClient.cs` | Cliente HTTP REST. Encapsula todos los endpoints: login, handshake, activity-day, window-episode, device-lock, version check. Maneja retry, timeouts y el header `Authorization: Bearer`. |
| `Network/OfflineQueue.cs` | Cola persistente en SQLite local. Guarda requests fallidos y los reintenta cuando se restaura la conexión (hasta 7 días de historial). |

### Config / Logging / Startup / Update
| Archivo | Descripción |
|---|---|
| `Config/ConfigManager.cs` | Lee y guarda `client_config.json` en `AppData\AZCKeeper\Config\`. Almacena URL de la API, DeviceId, versión, módulos habilitados. |
| `Logging/LocalLogger.cs` | Logging estructurado a `AppData\AZCKeeper\Logs\`. Rotación por tamaño (máx. 5 MB) e historial (máx. 10 archivos). |
| `Startup/StartupManager.cs` | Registra/desregistra la aplicación en el inicio de Windows (HKCU Run). |
| `Update/UpdateManager.cs` | Consulta `GET /api/client/version` cada 60 min. Si hay versión nueva, descarga e instala automáticamente. Soporta flag `force_update`. |

---

## 6. Módulos del Backend PHP

### Endpoints (`Web/src/Endpoints/`)

| Archivo | Método/Ruta | Descripción |
|---|---|---|
| `ClientLogin.php` | `POST /api/client/login` | Autentica al empleado contra la BD legada. Crea `keeper_user` y `keeper_session`. Retorna Bearer token. |
| `ClientHandshake.php` | `POST /api/client/handshake` | Sincronización central del cliente: políticas efectivas, horario laboral, estado de bloqueo, `serverTimeUtc`. |
| `ActivityDay.php` | `POST /GET /api/client/activity-day` | Persiste y recupera el resumen diario de actividad (UPSERT por user+device+fecha). |
| `WindowEpisode.php` | `POST /api/client/window-episode` | Registra un episodio de ventana con proceso, título, duración e indicador de llamada. |
| `DeviceLock.php` | `POST /api/client/device-lock/*` | Verifica si el dispositivo debe estar bloqueado y registra desbloqueos manuales con PIN. |
| `EventIngest.php` | `POST /api/client/event` | Ingesta de eventos genéricos (errores, alertas, auditoría del cliente). |
| `ClientVersion.php` | `GET /api/client/version` | Retorna la versión activa del cliente para auto-actualización. |
| `ForceHandshake.php` | `POST /api/client/force-handshake` | Permite al admin forzar una re-sincronización inmediata de la configuración del dispositivo. |
| `ProductivityCron.php` | `POST /api/cron/productivity` | Calcula métricas diarias de productividad y detecta patrones de doble empleo. Invocado por cron job. |
| `Health.php` | `GET /api/health` | Health check: verifica conexión a BD y retorna timestamp del servidor. |

### Repositorios (`Web/src/Repos/`)

| Archivo | Descripción |
|---|---|
| `SessionRepo.php` | Valida Bearer tokens contra `keeper_sessions`. Verifica expiración y revocación. |
| `DeviceRepo.php` | CRUD de `keeper_devices`: buscar por GUID, crear, actualizar `last_seen_at`, revocar. |
| `UserRepo.php` | Búsqueda y creación de `keeper_users`, incluyendo integración con BD legada. |
| `PolicyRepo.php` | Consulta políticas (`global`, `user`, `device`). **Cachea la política global 60 s en memoria.** Merge en 3 niveles. |
| `ProductivityRepo.php` | Persiste y recupera métricas diarias (`keeper_daily_metrics`) y resúmenes de actividad. |
| `HandshakeRepo.php` | Registra el historial de handshakes en `keeper_handshake_log` para auditoría. |
| `AuditRepo.php` | Inserta eventos de auditoría en `keeper_audit_log` para todas las acciones sensibles. |
| `AdminAuthRepo.php` | Gestiona cuentas de admin (`keeper_admin_accounts`) y sesiones del panel (`keeper_admin_sessions`). |
| `LegacyAuthRepo.php` | Consulta la BD legada (read-only) para validar credenciales de empleados durante el login. |
| `ReleaseRepo.php` | CRUD de versiones del cliente en `keeper_client_releases`. |

### Servicios (`Web/src/`)

| Archivo | Descripción |
|---|---|
| `PolicyService.php` | Realiza el **deep merge** de políticas en 3 niveles: global → usuario → dispositivo. El nivel más específico sobrescribe al anterior campo por campo. |
| `AuthService.php` | Utilidades de validación de sesión para las páginas del panel admin. |
| `RateLimiter.php` | Limita requests por IP/token para proteger la API de abuso. |
| `Db.php` | Pool de conexiones PDO. Gestiona dos conexiones: BD principal Keeper + BD legada (read-only). |

---

## 7. Panel de Control – Features

### Autenticación del Admin
- Login con email + contraseña (bcrypt)
- Sesión cookie `httpOnly` de 8 horas
- Roles jerárquicos: `superadmin` > `admin` > `viewer`
- Scope por firma/área (admins pueden ver solo su organización)

### Dashboard Principal
- **KPI Cards**: Personal activo, primer ingreso del día, tiempo inactivo, horas del mes
- **Focus Score**: Barra de progreso 1–10 con gradiente rojo→amarillo→verde
- **Gráfico de Productividad**: Donut SVG con % activo vs. total
- **Desglose de Tiempo**: Laboral / Almuerzo / Fuera de horario / Llamadas
- **Aplicaciones más usadas**: Top apps del día por `process_name` y duración
- **Personal Activo**: Lista en tiempo real con dots de estado (Online / Away / Offline)
- **Top Usuarios**: Grid de los 5 con mayor productividad del día

### Gestión de Usuarios
- Listado con badge de estado calculado desde `last_seen_at` del dispositivo
- Información de cargo, firma, área (join con tablas de organización)
- Métricas por tarjeta: primer ingreso, tiempo hoy, % productivo, focus score
- Buscador en tiempo real (JS) por nombre / cargo / firma / área
- Enlace a dashboard individual por usuario

### Dashboard Individual de Usuario
- Historial de actividad diaria
- Gráficos de tiempo activo/inactivo por franja horaria
- Episodios de ventana y aplicaciones usadas
- Alertas de doble empleo detectadas

### Gestión de Dispositivos
- Tabla de todos los `keeper_devices` con estado y última conexión
- Acción de **revocar dispositivo** (bloquea el acceso del token)
- Identificación por GUID, nombre de equipo y usuario asignado

### Editor de Políticas
- **Jerarquía de 3 niveles**: Global → Usuario → Dispositivo
- Editor JSON con esquema de módulos (`enableBlocking`, `enableTracking`, `enableAutoUpdate`)
- Configuración de bloqueo: `blocking.enableDeviceLock`, `blocking.allowUnlockWithPin`, `blocking.unlockPin`
- Propagación de cambios al cliente en el próximo handshake (~60 s)

### Bloqueo Remoto
- Activar/desactivar bloqueo por usuario o dispositivo desde `policies.php`
- Configurar PIN de desbloqueo
- El bloqueo se aplica en el cliente en ≤60 segundos
- El sistema respeta desbloqueos manuales (no vuelve a bloquear tras PIN correcto)

### Productividad y Métricas
- Cálculo de **focus score** (1–10) por empleado y día
- Métricas: ratio activo/total, horas laborales, horas fuera de horario, llamadas
- Vista agregada por firma/área/sede

### Alertas de Doble Empleo
- Detección automática de patrones de actividad inusuales (fuera de horario laboral intenso)
- Vista dedicada `dual-job-alerts.php` con historial de alertas
- Generado por `DualJobDetector` en el cron de productividad

### Estructura Organizacional
- CRUD de **Sociedades** (empresas), **Firmas** (clientes), **Áreas** (departamentos con jerarquía padre/hijo), **Cargos** (puestos con nivel jerárquico) y **Sedes** (oficinas/ciudades)
- Asignación de usuarios a cada nivel de la estructura

### Gestión de Releases
- Subida de nuevas versiones del cliente Windows
- Flags: `beta`, `force_update`, `is_active`
- Clientes descargan e instalan automáticamente al detectar versión nueva

### Administración de Admins
- CRUD de cuentas `keeper_admin_accounts`
- Asignación de roles y scope organizacional
- Solo `superadmin` puede gestionar admins

### Configuración del Sistema
- Ajustes globales en `keeper_panel_settings` (key-value JSON)
- Horarios laborales globales y por usuario

### Salud del Servidor
- Endpoint `GET /api/health` y página `server-health.php`
- Verifica conectividad a BD, retorna latencia y timestamp

---

## 8. Base de Datos

La base de datos MySQL tiene **24 tablas** organizadas en grupos:

| Grupo | Tablas | Descripción |
|---|---|---|
| **Identidad** | `keeper_users`, `keeper_devices`, `keeper_sessions` | Empleados, dispositivos y tokens de autenticación |
| **Actividad** | `keeper_activity_day`, `keeper_window_episode`, `keeper_events` | Datos de monitoreo enviados por el cliente |
| **Políticas** | `keeper_policy_assignments`, `keeper_module_catalog` | Configuración y políticas en 3 niveles |
| **Horarios** | `keeper_work_schedules` | Horarios laborales por usuario o global |
| **Bloqueo** | `keeper_device_locks` | Registro de bloqueos manuales y desbloqueos con PIN |
| **Métricas** | `keeper_daily_metrics` | KPIs calculados por el cron de productividad |
| **Organización** | `keeper_user_assignments`, `keeper_sociedades`, `keeper_firmas`, `keeper_areas`, `keeper_cargos`, `keeper_sedes` | Estructura organizacional |
| **Admin Panel** | `keeper_admin_accounts`, `keeper_admin_sessions`, `keeper_panel_roles`, `keeper_panel_settings` | Autenticación y configuración del panel web |
| **Auditoría** | `keeper_audit_log`, `keeper_handshake_log` | Trazabilidad de acciones y requests |
| **Releases** | `keeper_client_releases` | Versiones del cliente Windows |

---

## 9. Configuración

### Cliente (`AppData\AZCKeeper\Config\client_config.json`)
```json
{
  "Version": "1.0.0.1",
  "ApiBaseUrl": "https://api.empresa.com/keeper",
  "DeviceId": "550e8400-e29b-41d4-a716-446655440000",
  "Logging": {
    "MinLevel": "Info",
    "MaxFilesizeKB": 5120,
    "MaxHistoryFiles": 10
  },
  "Modules": {
    "EnableTracking": true,
    "EnableBlocking": true,
    "EnableAutoUpdate": true
  },
  "Updates": {
    "EnableAutoUpdate": true,
    "CheckIntervalMinutes": 60
  }
}
```

### Backend (`.env`)
```env
APP_ENV=production
DB_HOST=mysql.db.example.com
DB_NAME=keeper_db
DB_USER=keeper_user
DB_PASS=***
DB_CHARSET=utf8mb4
API_PREFIX=/api
```

Ver `.env.example` para la lista completa de variables disponibles.

---

> **Documentación interna adicional**
> - [`BLOCKING_SYSTEM.md`](./BLOCKING_SYSTEM.md) – Arquitectura detallada del sistema de bloqueo remoto
> - [`Web/public/admin/RESUMEN_PANEL_ADMIN.md`](./Web/public/admin/RESUMEN_PANEL_ADMIN.md) – Resumen técnico del panel admin web
> - [`SQL1.txt`](./SQL1.txt) – DDL completo de la base de datos MySQL
