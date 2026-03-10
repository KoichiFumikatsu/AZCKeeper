# AZCKeeper Admin Panel — Resumen de Cambios

> Documento de referencia para futuros prompts. Describe la arquitectura, patrones y todos los cambios realizados en las sesiones de desarrollo.

---

## 1. Arquitectura General

### Stack
- **Backend:** PHP 8.x sobre XAMPP, MySQL 8.0 remoto (`mysql.server1872.mylogin.co`), DB: `pipezafra_soporte_db`
- **Frontend:** Tailwind CSS (CDN), Alpine.js (deferred CDN), Google Fonts Inter
- **Cliente desktop:** .NET 8 (C#, WinForms) — proyecto `AZCKeeper_Client`
- **Updater:** .NET 8 — proyecto `AZCKeeperUpdater`

### Estructura de archivos del panel admin
```
Web/public/admin/
├── admin_auth.php          # Auth middleware, helpers (canDo, canAccessModule, scopeFilter, getLeisureApps, etc.)
├── index.php               # Dashboard principal con métricas globales
├── users.php               # Listado de usuarios con paginación server-side, búsqueda, crear/eliminar
├── user-dashboard.php      # Dashboard individual por usuario (actividad, ventanas, gráficas)
├── sedes-dashboard.php     # KPIs y métricas agrupadas por sede (vista cards + detail)
├── devices.php             # Gestión de dispositivos (ver, revocar, activar, eliminar)
├── organization.php        # CRUD de firmas, áreas, cargos y sedes
├── policies.php            # Configuración de políticas de bloqueo (schedule, leisure apps/windows)
├── releases.php            # Gestión de versiones del cliente
├── admin-users.php         # Administración de cuentas admin del panel
├── assignments.php         # Mapeo usuario → firma/área/cargo/sede
├── roles.php               # Gestión de roles y permisos granulares
├── panel-settings.php      # Visibilidad de módulos por rol
└── partials/
    ├── layout_header.php   # Sidebar nav + layout wrapper
    └── layout_footer.php   # Cierre HTML + scripts
```

### Patrón de página PHP
```php
require __DIR__ . '/admin_auth.php';
requireModule('nombre_modulo');
$pageTitle = 'Título';
$currentPage = 'slug_sidebar';
// POST handlers...
// Data queries (usando scopeFilter() para filtrar por firma/sede)...
require __DIR__ . '/partials/layout_header.php';
// HTML con Tailwind + Alpine.js...
require __DIR__ . '/partials/layout_footer.php';
```

### Sistema de permisos
- **`canAccessModule($mod)`** — ¿el rol del admin tiene acceso a ese módulo? (según `menu_visibility` en `keeper_panel_settings`)
- **`canDo($mod, $action)`** — ¿el rol tiene ese permiso granular? (según `keeper_panel_roles.permissions` JSON)
- **`requireModule($mod)`** — redirige si no tiene acceso
- **`scopeFilter()`** — devuelve `['sql' => '...', 'params' => [...]]` para filtrar datos según la firma/sede del admin

### Tablas clave
| Tabla | Uso |
|---|---|
| `keeper_users` | Usuarios rastreados (id, cc, display_name, email, password_hash, status) |
| `keeper_devices` | Dispositivos (id, user_id, device_guid, device_name, client_version, serial_hint, status[active/revoked], last_seen_at) |
| `keeper_user_assignments` | Enlace usuario → firm_id, area_id, cargo_id, sede_id |
| `firm` | Firmas (id, name, manager, mail_manager) |
| `areas` | Áreas (id, nombre, descripcion, padre_id) |
| `cargos` | Cargos (id, nombre) |
| `sedes` | Sedes (id, nombre, codigo, descripcion, activa) |
| `keeper_window_episode` | Episodios de ventana (user_id, window_title, process_name, start_at, end_at) |
| `keeper_activity_day` | Resumen diario de actividad (user_id, day_date, first_event_at, last_event_at, total_keys, total_clicks) |
| `keeper_admin_accounts` | Cuentas admin (id, keeper_user_id, panel_role, is_active, display_name) |
| `keeper_panel_roles` | Roles (slug, label, level, color_bg, color_text, permissions JSON) |
| `keeper_panel_settings` | Config clave-valor (menu_visibility JSON, etc.) |
| `keeper_policies` | Políticas de bloqueo |
| `keeper_releases` | Versiones del cliente |

### Módulos y permisos (`$modulePermissions` en roles.php)
```
users         → can_view, can_create, can_edit, can_delete, can_export, can_toggle_admin
devices       → can_view, can_edit, can_delete
policies      → can_view, can_create, can_edit, can_delete, can_force_push
releases      → can_view, can_create, can_edit, can_delete
admin-users   → can_view, can_create, can_edit, can_delete
assignments   → can_view, can_edit
organization  → can_view, can_edit, can_delete
roles         → can_view, can_create, can_edit, can_delete
settings      → can_view, can_edit
```

---

## 2. Cambios Realizados

### 2.1 Leisure: Split en Apps + Windows
**Archivos:** `admin_auth.php`, `policies.php`, `users.php`, `user-dashboard.php`, `index.php`, `sedes-dashboard.php`

- La configuración de ocio (leisure) se dividió en dos criterios independientes:
  - **Por Aplicación** (`process_name`): coincidencia exacta contra lista de procesos
  - **Por Ventana** (`window_title`): coincidencia parcial (LIKE) contra lista de títulos
- `getLeisureApps()` en `admin_auth.php` ahora retorna `['apps' => [...], 'windows' => [...]]`
- Backward compatible: detecta formato plano antiguo y lo trata como apps-only
- En todas las páginas que calculan tiempo de ocio, la query combina ambos criterios con OR cuando ambos están configurados

### 2.2 Timezone: Eliminar parche de ventanas
**Archivo:** `user-dashboard.php`

- Se removió `- INTERVAL 5 HOUR` de `w.start_at` y `w.end_at` en la tabla de actividad de ventanas
- Los tiempos de episodios de ventana ya estaban correctos en la DB, no necesitaban ajuste

### 2.3 Primer Ingreso: Primer movimiento de ventana después de 5 AM
**Archivos:** `user-dashboard.php`, `users.php`, `index.php`, `sedes-dashboard.php`

- Se cambió `MIN(a.first_event_at) - INTERVAL 5 HOUR` por una subconsulta contra `keeper_window_episode`:
  ```sql
  (SELECT MIN(we.start_at) FROM keeper_window_episode we
   WHERE we.user_id = u.id AND DATE(we.start_at) = CURDATE()
   AND TIME(we.start_at) >= '05:00:00')
  ```
- En `index.php` y `sedes-dashboard.php` se usaron queries separadas (en vez de subqueries correlacionadas) para evitar conflictos de parámetros con `scopeFilter()`
- En `sedes-dashboard.php` se creó array `$firstLoginPerSede` para la vista de cards

### 2.4 devices.php — Gestión de Dispositivos (NUEVO)
**Archivo:** `devices.php` (~380 líneas)

- **POST handlers:** revoke, activate, delete (con checks de permisos)
- **Query:** JOIN `keeper_devices` → `keeper_users` → `keeper_user_assignments` → firm/areas/sedes, filtrada con `scopeFilter()`
- **KPI cards:** Total, Online Ahora, Activos, Revocados
- **Tabla:** nombre/GUID, usuario (link a dashboard), versión (badge), estado (Online/Ausente/Offline/Revocado), último contacto, registrado, acciones (dropdown)
- **Búsqueda + filtro:** Alpine.js con `x-effect` para filtrado reactivo por texto y estado, estilo unificado con users.php
- **Sidebar:** Gráfica de versiones del cliente + tarjeta informativa

### 2.5 organization.php — CRUD Organizacional (NUEVO)
**Archivo:** `organization.php` (~450 líneas)

- CRUD para 4 entidades: **Firma**, **Área**, **Cargo**, **Sede**
- **Tabs** navegables con KPI cards clickeables (Alpine.js `x-show`)
- **Crear:** valida nombres duplicados antes de insertar; Sede valida código único
- **Editar:** misma validación excluyendo el registro actual
- **Eliminar:** verifica FK en `keeper_user_assignments`, SET NULL antes de borrar, aviso de usuarios vinculados
- **Campos por entidad:**
  - Firma: name, manager, mail_manager
  - Área: nombre, descripcion
  - Cargo: nombre (card layout en grid)
  - Sede: nombre, codigo, descripcion, activa (con badge de estado)
- **Modal Alpine.js** para crear/editar con campos dinámicos según entidad (`x-if` templates)
- Permisos: `can_edit` para crear/editar, `can_delete` para eliminar

### 2.6 Sidebar + Roles: Módulo organization
**Archivos:** `layout_header.php`, `roles.php`, `panel-settings.php`

- `layout_header.php`: Agregado link "Organización" en sección avanzada (entre Asignaciones y Roles), con icono de grid 4 cuadrados. Agregado a `$advModules` array.
- `roles.php`: Agregado módulo `organization` con permisos: `can_view`, `can_edit` (crear/editar), `can_delete`
- `panel-settings.php`: Agregado en 3 lugares:
  - `$allModules` — label + description
  - `$icons` — SVG path del icono grid
  - `$defaults` — default `['superadmin']`

### 2.7 devices.php: Fix búsqueda y filtro
**Archivo:** `devices.php`

- **Bug:** El getter `get filtered()` de Alpine.js nunca se invocaba, por lo que búsqueda y filtro no funcionaban
- **Fix:** Reemplazado por método `applyFilters()` invocado con `x-effect` (reactivo)
- **Estilo:** Barra de búsqueda movida fuera de la tarjeta, estilo unificado con users.php (input `text-sm`, `py-2`, icono lupa, `max-w-md`)
- **Mejoras:** debounce 200ms en input, contador de resultados visible cuando hay filtro activo
- Se usó `x-ref="deviceTable"` para scoped DOM queries

### 2.8 users.php: Permiso can_create para botón "Crear Usuario"
**Archivos:** `users.php`, `roles.php`

- `roles.php`: Agregado permiso `can_create` → "Crear usuario" al módulo `users`
- `users.php`:
  - Botón "Crear Usuario" envuelto en `<?php if (canDo('users', 'can_create')): ?>`
  - Handler POST `create_user` protegido con `canDo('users', 'can_create')`
- Ahora el botón solo es visible para roles que tengan `can_create` asignado

### 2.9 Plan: Productividad, Focus Score y Detección de Doble Empleo (DISEÑADO)
**Documento:** `Web/Docs/PLAN_PRODUCTIVITY.md`

- **Diseño completo aprobado** para 3 sistemas nuevos: Productividad avanzada, Focus Score 0-100, Detección doble empleo
- **3 tablas nuevas**: `keeper_focus_daily`, `keeper_dual_job_alerts`, `keeper_suspicious_apps`
- **7 archivos nuevos**: migración SQL, ProductivityCalculator.php, DualJobDetector.php, ProductivityRepo.php, ProductivityCron.php, productivity.php, dual-job-alerts.php
- **5 archivos a modificar**: index.php, user-dashboard.php, layout_header.php, panel-settings.php, roles.php
- **Decisiones clave**: Server-side only (sin cambios al cliente C#), cron nocturno 2AM, Focus Score reemplaza gauge actual 0-10 por 0-100
- **Validación de foco**: Ratio actividad × duración ventana, penalización >2h sin cambio de título, títulos sospechosos, constancia en bloques 30min
- **Rendimiento validado** para 500 usuarios concurrentes, BD partida (trimestre activo + archivo), multi-tenant futuro
- **Infraestructura**: Recomendación subir `innodb_buffer_pool_size` de 2GB a 6-8GB
- **Estado**: Pendiente de implementación — Fase 1 (BD) bloquea todo lo demás

---

## 3. Convenciones de Código

### PHP
- Validar siempre con `php -l` después de cada cambio
- Usar `htmlspecialchars()` para toda salida de datos
- POST handlers con PRG (Post-Redirect-Get) en éxito
- Queries parametrizadas (PDO prepared statements), nunca concatenar inputs
- `canDo()` en handlers POST y en la UI (botones/links)

### Frontend
- Tailwind CSS con clases custom: `text-dark`, `text-muted`, `bg-corp-50/100/200/800/900`, `border-corp-200`
- Alpine.js para interactividad (modales, filtros, tabs) — siempre `x-data` en el contenedor relevante
- SVG inline para iconos (no icon fonts)
- Tablas: `text-sm`, `border-b border-gray-100` en thead, `divide-y divide-gray-50` en tbody
- Cards: `bg-white rounded-xl border border-gray-100 p-5/p-6`
- Badges/pills: `px-2 py-0.5 text-xs font-medium rounded-full`
- Búsqueda: input con icono lupa `left-3`, `pl-9`, `text-sm`, `border-gray-200`, `focus:ring-corp-800/20`

### Patrones frecuentes
- **Flash messages:** `$msg`/`$msgType` con div coloreado (emerald para success, red para error)
- **Filtrado Alpine.js:** `x-effect="applyFilters()"` con `x-ref` en el contenedor de filas
- **Permisos en UI:** `<?php if (canDo('modulo', 'accion')): ?>` envolviendo botones/forms
- **Scope filter:** `$scope = scopeFilter(); $params = $scope['params'];` + `WHERE 1=1 {$scope['sql']}`
