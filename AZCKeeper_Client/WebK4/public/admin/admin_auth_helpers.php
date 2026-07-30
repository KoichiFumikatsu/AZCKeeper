<?php
/**
 * Funciones puras de autorización del panel (sin exigir sesión, para que login.php también
 * las use). admin_auth.php las incluye y añade la validación de la cookie.
 *
 * Distinción clave (feedback de Koichi): el TABLERO DE FLOTA Y SEGURIDAD (module 'dashboard':
 * equipos activos, agente aplicando, fallo silencioso) es vista de IT / superadmin — al
 * gerente no le interesa. El gerente ve productividad: la VISTA DE PROCESOS, cobertura y
 * doble empleo, de SU firma. RBAC editable (keeper_panel_roles) llega en la rebanada 5.
 */
if (!function_exists('panelCan')) {
    function panelCan(array $adminUser, string $module): bool
    {
        $role = $adminUser['panel_role'] ?? 'viewer';
        if ($role === 'superadmin') return true;
        static $byRole = [
            'it'      => ['dashboard','process-view','users','devices','pending','tiers','policies','coverage','dual-job','audit'],
            'gerente' => ['process-view','coverage','dual-job','users'],   // NO ve flota/seguridad
            'viewer'  => ['process-view','coverage'],
        ];
        return in_array($module, $byRole[$role] ?? [], true);
    }

    /** Página de aterrizaje según rol: IT/superadmin al tablero; gerente a procesos. */
    function panelLanding(array $adminUser): string
    {
        return panelCan($adminUser, 'dashboard') ? 'index.php' : 'process-view.php';
    }
}
