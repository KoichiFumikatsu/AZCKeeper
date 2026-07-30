# Panel de administración Keeper 4 — Plan por rebanadas

**Goal:** Reconstruir el panel admin de Keeper sobre el esquema/API K4, manteniendo la
identidad visual del panel K3 (marca AZC/lawyerdesk: Tailwind + Inter, sidebar blanco, corp
navy #003a5d, acento rojo #be1622).

**Arquitectura:** Páginas PHP server-rendered en `AZCKeeper_Client/WebK4/public/admin/`
(igual que K3), que consultan MySQL vía los repos existentes / PDO. Auth de sesión por
cookie contra `keeper_admin_sessions` → `keeper_admin_accounts` (reemplaza el stopgap
`AdminAuth` de X-Admin-Key para humanos; los endpoints JSON siguen con su gate). Se sirve
desde el servidor real, así que usa Tailwind CDN + Google Fonts como K3 (idéntico, no
réplica). Prototipo de referencia: `docs/presentacion/keeper-panel-prototipo.html`.

**Global Constraints**
- Identidad visual EXACTA de K3 (portar `partials/layout_header.php`).
- Auth: cookie `keeper_admin_token` → `AdminAuthRepo::validateSession`. Sin sesión → login.php.
- Scope por firma: firma-admin solo ve su firma (firma_scope_id); superadmin ve todo.
- Datos sensibles (window_title) enmascarados salvo permiso; consultas se auditan (data_access).
- No tocar producción (DevLinux). Trabajo en feature/modulo-seguridad. DEV = devkeep.

## Rebanada 1 — Fundación + tablero de seguridad (el titular)
- `AdminAuthRepo`: login(email,pass) con password_verify; createSession (token aleatorio,
  guarda sha256); validateSession(token) → cuenta+rol; destroySession.
- `admin_auth.php` (middleware), `login.php`, `logout.php`.
- `partials/layout_header.php` + `layout_footer.php` (visual K3, nav → páginas K4).
- `index.php` (dashboard): stat cards + **tablero de estado del agente** (3 estados desde
  `keeper_security_state` + join usuarios/dispositivos). La query de fallo silencioso
  (agent_present=1 AND agent_can_enforce=0) es el titular.
- Seed de una cuenta admin en DEV. Verificar login + dashboard contra DEV.

## Rebanada 2 — Vista de procesos (prioridad #1)
- `process-view.php`: por persona/día, top procesos (keeper_episode_daily / ProcessViewRepo),
  banderas de cobertura, foco (NULL honesto). Enmascarado de window_title por permiso.

## Rebanada 3 — Personas, dispositivos, enrolamiento
- `users.php`, `devices.php`, `pending-users.php` (aprobar enrolamientos → activa keeper_users).

## Rebanada 4 — Tiers & módulos, políticas
- `tiers.php` (asignar tier a firma, editar tier_module), `policies.php` (override por persona/global).

## Rebanada 5 — Cobertura, doble empleo, releases, auditoría, roles/RBAC
- `coverage.php`, `dual-job.php`, `releases.php` (subir ZIP al feed), `audit.php`, `roles.php`.

Cada rebanada: desplegar a devkeep y verificar con los datos sembrados.
