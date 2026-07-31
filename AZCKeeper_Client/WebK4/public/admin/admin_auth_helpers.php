<?php
/**
 * Autorización del panel, sin exigir sesión (para que login.php también la use).
 * admin_auth.php la incluye y añade la validación de la cookie.
 *
 * Distinción clave (feedback de Koichi): el TABLERO DE FLOTA Y SEGURIDAD (module 'dashboard':
 * equipos activos, agente aplicando, fallo silencioso) es vista de IT / superadmin — al
 * gerente no le interesa. El gerente ve productividad: la VISTA DE PROCESOS, cobertura y
 * doble empleo, de SU firma.
 *
 * Rebanada 5: los permisos viven en `keeper_panel_roles` (editables desde roles.php). El mapa
 * en código queda como RESPALDO y solo entra si la tabla no responde o el rol no está
 * sembrado — así una migración a medias degrada al comportamiento anterior en vez de dejar
 * a todo el mundo fuera del panel.
 */
/**
 * CSRF: token ligado a la sesión (sin almacenamiento extra). Se deriva por HMAC de la cookie
 * de sesión, que es httpOnly y secreta — un sitio atacante no puede leerla ni, por tanto,
 * reproducir el token. Cada formulario POST del panel incrusta csrf_field(); admin_auth.php
 * rechaza cualquier POST cuyo _csrf no coincida.
 */
/**
 * Semáforo de presencia por persona, derivado de su equipo activo más reciente.
 *   sin_keeper : no tiene equipo activo.
 *   offline    : tiene equipo pero sin handshake reciente (> OFFLINE_SECONDS).
 *   ausente    : online pero inactivo (idle >= AWAY_IDLE_SECONDS).
 *   activa     : online y con actividad reciente.
 * $lastSeenEpoch = UNIX_TIMESTAMP(last_seen_at) (evita el desfase de zona del panel), o null.
 */
if (!function_exists('presence')) {
    if (!defined('PRESENCE_OFFLINE_SECONDS'))   define('PRESENCE_OFFLINE_SECONDS', 600);   // 10 min sin handshake = desconectada
    if (!defined('PRESENCE_AWAY_IDLE_SECONDS')) define('PRESENCE_AWAY_IDLE_SECONDS', 300); // 5 min de inactividad = ausente

    function presence(?int $lastSeenEpoch, ?int $idleSeconds, int $deviceCount): array
    {
        if ($deviceCount <= 0)
            return ['key' => 'sin_keeper', 'label' => 'Sin keeper', 'cls' => 'text-gray-500 bg-gray-100'];
        if ($lastSeenEpoch === null || (time() - $lastSeenEpoch) > PRESENCE_OFFLINE_SECONDS)
            return ['key' => 'offline', 'label' => 'Desconectada', 'cls' => 'text-accent-500 bg-accent-500/10'];
        if ($idleSeconds !== null && $idleSeconds >= PRESENCE_AWAY_IDLE_SECONDS)
            return ['key' => 'ausente', 'label' => 'Ausente', 'cls' => 'text-amber-700 bg-amber-50'];
        return ['key' => 'activa', 'label' => 'Activa', 'cls' => 'text-emerald-700 bg-emerald-50'];
    }
}

if (!function_exists('csrf_token')) {
    function csrf_token(): string
    {
        $cookieName = defined('KEEPER_ADMIN_COOKIE') ? KEEPER_ADMIN_COOKIE : 'keeper_admin_token';
        $sessTok = $_COOKIE[$cookieName] ?? '';
        $key = \Keeper\Config::get('APP_KEY', '');
        return hash_hmac('sha256', 'csrf|' . $sessTok, $key !== '' ? $key : 'k4-csrf-fallback');
    }

    function csrf_field(): string
    {
        return '<input type="hidden" name="_csrf" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES) . '">';
    }

    /** ¿El POST actual trae un _csrf válido? */
    function csrf_check(): bool
    {
        $sent = $_POST['_csrf'] ?? '';
        return is_string($sent) && $sent !== '' && hash_equals(csrf_token(), $sent);
    }
}

if (!function_exists('panelCan')) {

    /** Catálogo de módulos del panel: código => etiqueta. Es la fuente de la matriz de roles.php. */
    function panelModules(): array
    {
        return [
            'dashboard'    => 'Flota y seguridad',
            'process-view' => 'Vista de procesos',
            'users'        => 'Usuarios',
            'pending'      => 'Accesos pendientes',
            'devices'      => 'Dispositivos',
            'tiers'        => 'Tiers y módulos',
            'policies'     => 'Políticas',
            'coverage'     => 'Cobertura',
            'dual-job'     => 'Doble empleo',
            'audit'        => 'Auditoría',
            'releases'     => 'Versiones del cliente',
            'diagnostics'  => 'Diagnóstico en vivo',
            'remote-control' => 'Control remoto',
            'roles'        => 'Roles y cuentas',
        ];
    }

    /** Mapa de respaldo, idéntico al que gobernaba antes de que la tabla mandara. */
    function panelFallbackPermissions(): array
    {
        return [
            'it'      => ['dashboard','process-view','users','devices','pending','tiers','policies','coverage','dual-job','audit','releases','diagnostics','remote-control'],
            'admin'   => ['dashboard','process-view','users','devices','pending','tiers','policies','coverage','dual-job','audit','releases','diagnostics','remote-control'],
            'gerente' => ['process-view','coverage','dual-job','users'],   // NO ve flota/seguridad
            'viewer'  => ['process-view','coverage'],
        ];
    }

    /**
     * Permisos por rol desde keeper_panel_roles, una sola vez por request.
     * Un rol con `modules` vacío se cachea igual (permiso a nada es una decisión válida);
     * solo se omite el que no trae ninguna de las dos claves, para que caiga al respaldo.
     */
    function panelRolePermissions(): array
    {
        static $cache = null;
        if ($cache !== null) return $cache;
        $cache = [];
        try {
            $rows = \Keeper\Db::pdo()
                ->query("SELECT role_code, permissions_json FROM keeper_panel_roles")
                ->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $r) {
                $p = json_decode((string)$r['permissions_json'], true);
                if (!is_array($p)) continue;
                if (!empty($p['all'])) { $cache[$r['role_code']] = ['all' => true]; continue; }
                if (isset($p['modules']) && is_array($p['modules'])) {
                    $cache[$r['role_code']] = ['modules' => array_values(array_filter($p['modules'], 'is_string'))];
                }
            }
        } catch (\Throwable $e) {
            error_log('panelRolePermissions: ' . $e->getMessage());   // se sigue con el respaldo
        }
        return $cache;
    }

    function panelCan(array $adminUser, string $module): bool
    {
        $role  = $adminUser['panel_role'] ?? 'viewer';
        $perms = panelRolePermissions();

        if (isset($perms[$role])) {
            if (!empty($perms[$role]['all'])) return true;
            return in_array($module, $perms[$role]['modules'] ?? [], true);
        }

        if ($role === 'superadmin') return true;   // nunca se queda fuera, tabla o no
        return in_array($module, panelFallbackPermissions()[$role] ?? [], true);
    }

    /**
     * Página de aterrizaje: la primera a la que el rol tenga acceso, en orden de utilidad.
     * Recorrer el catálogo (en vez de devolver process-view.php a secas) evita el bucle de
     * redirección que aparecería en cuanto un rol editable se quede sin ese módulo.
     */
    function panelLanding(array $adminUser): string
    {
        static $pages = [
            'dashboard'    => 'index.php',
            'process-view' => 'process-view.php',
            'coverage'     => 'coverage.php',
            'dual-job'     => 'dual-job.php',
            'users'        => 'users.php',
            'pending'      => 'pending-users.php',
            'devices'      => 'devices.php',
            'tiers'        => 'tiers.php',
            'policies'     => 'policies.php',
            'releases'     => 'releases.php',
            'audit'        => 'audit.php',
            'diagnostics'  => 'diagnostics.php',
            'roles'        => 'roles.php',
        ];
        foreach ($pages as $mod => $page) {
            if (panelCan($adminUser, $mod)) return $page;
        }
        return 'no-access.php';
    }
}
