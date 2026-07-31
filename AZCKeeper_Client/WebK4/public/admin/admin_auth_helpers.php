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
            'roles'        => 'Roles y cuentas',
        ];
    }

    /** Mapa de respaldo, idéntico al que gobernaba antes de que la tabla mandara. */
    function panelFallbackPermissions(): array
    {
        return [
            'it'      => ['dashboard','process-view','users','devices','pending','tiers','policies','coverage','dual-job','audit','releases'],
            'admin'   => ['dashboard','process-view','users','devices','pending','tiers','policies','coverage','dual-job','audit','releases'],
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
            'roles'        => 'roles.php',
        ];
        foreach ($pages as $mod => $page) {
            if (panelCan($adminUser, $mod)) return $page;
        }
        return 'no-access.php';
    }
}
