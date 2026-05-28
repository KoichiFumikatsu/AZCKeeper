# Panel Admin — Keeper (Web) — Resumen Técnico

## Estructura de archivos

```
Web/
??? src/
?   ??? Repos/
?   ?   ??? AdminAuthRepo.php            # Auth: findByEmail, createSession, validateSession, revokeSession
?   ??? bootstrap.php                     # Se agregó require de AdminAuthRepo
??? migrations/
?   ??? keeper_admin_auth.sql             # DDL referencial (las tablas ya existían en BD)
??? public/admin/
    ??? assets/
    ?   ??? logo_main.png                 # Logo principal (subido manualmente)
    ?   ??? icoMain.png                   # Ícono principal / favicon
    ?   ??? style.css                     # CSS legacy (no se usa, todo es Tailwind CDN)
    ??? partials/
    ?   ??? layout_header.php             # Layout base: sidebar + topbar + Tailwind config con paleta corporativa
    ?   ??? layout_footer.php             # Cierra HTML + carga Alpine.js
    ??? admin_auth.php                    # Middleware: valida cookie ? $adminUser + $pdo + helpers
    ??? login.php                         # Login email+password ? cookie httpOnly 8h
    ??? logout.php                        # Revoca sesión + limpia cookie ? redirect login
    ??? index.php                         # Dashboard estilo LawyerDesk
    ??? users.php                         # Lista de usuarios estilo LawyerDesk
```

---

## Tablas de BD usadas

> Todas ya existen en `pipezafra_soporte_db`. No se necesita correr migrations.

| Tabla | Uso |
|---|---|
| `keeper_admin_accounts` | Cuentas admin con `panel_role` (superadmin/admin/viewer) y scope firma/área |
| `keeper_admin_sessions` | Sesiones del panel (FK `admin_id` ? `keeper_admin_accounts.id`) |
| `keeper_users` | Email + `password_hash` (bcrypt) para login |
| `keeper_user_assignments` | Firma/área/cargo asignados a cada usuario keeper |
| `keeper_devices` | `last_seen_at` para calcular status online/away/offline |
| `keeper_activity_day` | Métricas diarias: `active_seconds`, `idle_seconds`, `work_hours_active_seconds`, `lunch_active_seconds`, `after_hours_active_seconds`, `call_seconds`, `first_event_at` |
| `keeper_window_episode` | Top apps por `process_name` + `duration_seconds` por `day_date` |
| `firm` | Nombre de firma (`name`) |
| `areas` | Nombre de área (`nombre`) |
| `cargos` | Nombre de cargo (`nombre`) |

---

## Paleta corporativa (Tailwind custom config)

```js
tailwind.config = {
    theme: {
        extend: {
            colors: {
                'corp': {
                    50:  '#f0f7fb',
                    100: '#dceef5',
                    800: '#003a5d',   // Azul oscuro principal
                    900: '#002b47',   // Hover botones
                },
                'accent': {
                    500: '#be1622',   // Rojo corporativo
                    600: '#a0121d',
                },
                'dark':  '#353132',   // Texto principal
                'muted': '#9d9d9c',   // Texto secundario
            }
        }
    }
}
```

- Fondo general: `bg-gray-50`
- Cards/sidebar: `bg-white` (blanco como color principal)
- Font: Inter (Google Fonts)

---

## Flujo de autenticación

```
login.php ? POST email + password
  ? AdminAuthRepo::findByEmail()
      JOIN keeper_admin_accounts + keeper_users
  ? password_verify() contra keeper_users.password_hash
  ? AdminAuthRepo::createSession()
      INSERT keeper_admin_sessions (token_hash = SHA-256)
      Cookie httpOnly "keeper_admin_token" = token plano, 8h TTL
  ? redirect index.php

Cada página protegida:
  require 'admin_auth.php'
  ? lee cookie ? AdminAuthRepo::validateSession()
  ? Si válida: $adminUser y $pdo quedan disponibles
  ? Si inválida: redirect login.php

Helpers disponibles tras admin_auth.php:
  - hasRole('superadmin')         ? bool, jerarquía superadmin > admin > viewer
  - scopeFilter()                 ? ['sql' => 'AND ...', 'params' => [...]]
  - canViewUser($pdo, $userId)    ? bool, verifica scope del admin
```

---

## Dashboard (index.php)

Estilo LawyerDesk Client Portal con datos reales:

1. **Hero** — "Bienvenido al Panel de Control"
2. **KPI Cards (4 cols)** — Personal Activo, Primer Ingreso Hoy, Tiempo Inactivo, Horas Este Mes
3. **Focus Score** — barra gradiente rojo?amarillo?verde, puntaje 1-10
4. **Productividad** — donut chart SVG (% activo vs total)
5. **Desglose de Tiempo** — barras: Laboral, Almuerzo, Fuera de Horario, Llamadas
6. **Aplicaciones Más Usadas** — desde `keeper_window_episode` agrupado por `process_name`
7. **Personal Activo** — lista con status dots y tiempo relativo
8. **Top Usuarios Hoy** — grid de 5 cards con ranking

---

## Usuarios (users.php)

Estilo LawyerDesk "Your Active Staff Members":

Cada card muestra:
- Avatar con dot de estado (verde/amarillo/gris)
- Nombre + badge (Online / Ausente / Offline)
- Cargo (desde `cargos` vía `keeper_user_assignments`)
- Firma — Área
- 4 métricas: Primer Ingreso, Tiempo Hoy, % Productivo, Focus Score
- Flecha ? enlaza a `user-dashboard.php?id=X`
- Buscador JS filtra por nombre/cargo/firma/área
- Responsive: métricas en fila compacta en móvil

Status se calcula en PHP desde `keeper_devices.last_seen_at`:
- `< 2 min` ? Online
- `< 15 min` ? Away
- `else` ? Offline

---

## Bugs corregidos durante desarrollo

| # | Bug | Causa | Fix |
|---|---|---|---|
| 1 | `<?php` faltante en todos los archivos | Tool `create_file` lo omitía silenciosamente | Recrear archivos + validar con terminal |
| 2 | 500 en login — tabla no encontrada | Código usaba `keeper_admin_users`, BD real tiene `keeper_admin_accounts` | Renombrar en `AdminAuthRepo.php` |
| 3 | 500 en login — columna no encontrada | Código usaba `admin_user_id`, BD real tiene `admin_id` | Corregir FK en queries |
| 4 | 500 en dashboard — columna `device_status` no existe | `keeper_devices` no tiene esa columna | Calcular en PHP desde `last_seen_at` |
| 5 | 500 en dashboard — archivo truncado | Edición cortó `index.php` en línea 401 | Completar + validar con `php -l` |

---

## Pendientes / próximos pasos

- [ ] `user-dashboard.php?id=X` — vista detallada por usuario
- [ ] `devices.php` — gestión de dispositivos
- [ ] `policies.php` — políticas de keeper
- [ ] `releases.php` — versiones del cliente
- [ ] `admin-users.php` — gestión de admins (solo superadmin)
- [ ] `assignments.php` — asignar firma/área/cargo a usuarios
