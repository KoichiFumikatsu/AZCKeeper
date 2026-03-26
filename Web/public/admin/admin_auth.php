<?php
/**
 * Middleware de autenticación del panel admin.
 *
 * Incluir al inicio de cada página protegida:
 *   require_once __DIR__ . '/admin_auth.php';
 *
 * Tras incluirlo, las siguientes variables están disponibles:
 *   $adminUser → array con: admin_id, panel_role, firm_scope_id, area_scope_id, display_name, email
 *   $pdo       → conexión PDO activa
 *
 * Si no hay sesión válida, redirige a login.php automáticamente.
 */

require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/Repos/AdminAuthRepo.php';
require_once __DIR__ . '/../../src/LegacySyncService.php';

use Keeper\Db;
use Keeper\Repos\AdminAuthRepo;
use Keeper\LegacySyncService;

// Zona horaria del panel: America/Bogota (UTC-5)
date_default_timezone_set('America/Bogota');

session_start();

$pdo = Db::pdo();

// Alinear zona horaria de MySQL con PHP (America/Bogota = UTC-5)
$pdo->exec("SET time_zone = '-05:00'");

// Nombre de la cookie
if (!defined('KEEPER_ADMIN_COOKIE')) {
    define('KEEPER_ADMIN_COOKIE', 'keeper_admin_token');
}

// Leer token de cookie
$token = $_COOKIE[KEEPER_ADMIN_COOKIE] ?? null;

if (!$token) {
    header('Location: login.php');
    exit;
}

$adminUser = AdminAuthRepo::validateSession($pdo, $token);

if (!$adminUser) {
    setcookie(KEEPER_ADMIN_COOKIE, '', time() - 3600, '/', '', false, true);
    header('Location: login.php');
    exit;
}
// Sync automático: sincronizar assignments desde legacy (throttle 5 min)
try {
    LegacySyncService::syncAllFromPanel($pdo);
} catch (\Throwable $e) {
    error_log('LegacySyncService::syncAllFromPanel error: ' . $e->getMessage());
}
/**
 * Helper: carga todos los roles desde keeper_panel_roles (con cache).
 * Retorna array indexado por slug: ['superadmin' => [...], 'admin' => [...], ...]
 * Si la tabla no existe aún, retorna los 3 roles hardcoded.
 */
function getAllRoles(): array {
    global $pdo;
    static $cache = null;
    if ($cache !== null) return $cache;

    // Defaults hardcoded (fallback antes de migración)
    $defaults = [
        'superadmin' => ['slug' => 'superadmin', 'label' => 'Superadmin', 'description' => 'Acceso total', 'hierarchy_level' => 100, 'color_bg' => 'bg-red-100', 'color_text' => 'text-red-800', 'is_system' => 1, 'permissions' => null],
        'admin'      => ['slug' => 'admin',      'label' => 'Admin',      'description' => 'Gestión operativa', 'hierarchy_level' => 50,  'color_bg' => 'bg-blue-100', 'color_text' => 'text-blue-800', 'is_system' => 1, 'permissions' => null],
        'viewer'     => ['slug' => 'viewer',     'label' => 'Viewer',     'description' => 'Solo lectura',     'hierarchy_level' => 10,  'color_bg' => 'bg-gray-100', 'color_text' => 'text-gray-600', 'is_system' => 1, 'permissions' => null],
    ];

    try {
        $st = $pdo->query("SELECT * FROM keeper_panel_roles ORDER BY hierarchy_level DESC");
        $rows = $st->fetchAll(\PDO::FETCH_ASSOC);
        if ($rows) {
            $cache = [];
            foreach ($rows as $r) {
                $r['permissions'] = $r['permissions'] ? json_decode($r['permissions'], true) : null;
                $cache[$r['slug']] = $r;
            }
            return $cache;
        }
    } catch (\Throwable $e) {
        // Tabla no existe aún
    }

    $cache = $defaults;
    return $cache;
}

/**
 * Helper: obtiene info de un rol por slug.
 */
function getRoleInfo(string $slug): ?array {
    $roles = getAllRoles();
    return $roles[$slug] ?? null;
}

/**
 * Helper: verifica si el admin actual tiene al menos el rol indicado.
 * Usa hierarchy_level dinámico de keeper_panel_roles.
 */
function hasRole(string $minimumRole): bool {
    global $adminUser;
    $roles = getAllRoles();
    $currentRole = $roles[$adminUser['panel_role']] ?? null;
    $requiredRole = $roles[$minimumRole] ?? null;
    $current  = $currentRole  ? (int)$currentRole['hierarchy_level']  : 0;
    $required = $requiredRole ? (int)$requiredRole['hierarchy_level'] : 999;
    return $current >= $required;
}

/**
 * Helper: verifica si el rol actual tiene un permiso granular en un módulo.
 * Ej: canDo('users', 'can_edit'), canDo('policies', 'can_force_push')
 * Superadmin siempre retorna true.
 */
function canDo(string $module, string $action): bool {
    global $adminUser;
    $role = $adminUser['panel_role'] ?? '';

    // Superadmin puede todo
    if ($role === 'superadmin') return true;

    $roleInfo = getRoleInfo($role);
    if (!$roleInfo || !$roleInfo['permissions']) return false;

    $perms = $roleInfo['permissions'];
    return !empty($perms[$module][$action]);
}

/**
 * Helper: verifica si el admin puede ver a un usuario específico.
 */
function canViewUser(PDO $pdo, int $keeperUserId): bool {
    global $adminUser;
    if ($adminUser['panel_role'] === 'superadmin') return true;

    $sql = "SELECT 1 FROM keeper_user_assignments WHERE keeper_user_id = :uid";
    $params = [':uid' => $keeperUserId];

    if ($adminUser['firm_scope_id']) {
        $sql .= " AND firm_id = :fid";
        $params[':fid'] = $adminUser['firm_scope_id'];
    }
    if ($adminUser['area_scope_id']) {
        $sql .= " AND area_id = :aid";
        $params[':aid'] = $adminUser['area_scope_id'];
    }
    if (!empty($adminUser['sociedad_scope_id'])) {
        $sql .= " AND sociedad_id = :socid";
        $params[':socid'] = $adminUser['sociedad_scope_id'];
    }

    $st = $pdo->prepare($sql . " LIMIT 1");
    $st->execute($params);
    return (bool)$st->fetch();
}

/**
 * Helper: retorna la condición SQL para filtrar usuarios por scope del admin.
 * Retorna ['sql' => 'AND ...', 'params' => [...]]
 */
function scopeFilter(): array {
    global $adminUser;

    if ($adminUser['panel_role'] === 'superadmin') {
        return ['sql' => '', 'params' => []];
    }

    $sql = '';
    $params = [];

    if ($adminUser['firm_scope_id']) {
        $sql .= " AND ua.firm_id = :scope_firm";
        $params[':scope_firm'] = $adminUser['firm_scope_id'];
    }
    if ($adminUser['area_scope_id']) {
        $sql .= " AND ua.area_id = :scope_area";
        $params[':scope_area'] = $adminUser['area_scope_id'];
    }
    if (!empty($adminUser['sede_scope_id'])) {
        $sql .= " AND ua.sede_id = :scope_sede";
        $params[':scope_sede'] = $adminUser['sede_scope_id'];
    }
    if (!empty($adminUser['sociedad_scope_id'])) {
        $sql .= " AND ua.sociedad_id = :scope_sociedad";
        $params[':scope_sociedad'] = $adminUser['sociedad_scope_id'];
    }

    return ['sql' => $sql, 'params' => $params];
}

/**
 * Helper: carga la visibilidad de módulos desde keeper_panel_settings.
 * Cache en variable global para no repetir query por request.
 * Retorna array asociativo: ['dashboard' => ['superadmin','admin','viewer'], ...]
 */
function getMenuVisibility(): array {
    global $pdo;
    static $cache = null;
    if ($cache !== null) return $cache;

    // Defaults hardcoded (fallback si la tabla no existe o no tiene datos)
    $defaults = [
        'dashboard'        => ['superadmin', 'admin', 'viewer'],
        'sedes-dashboard'  => ['superadmin'],
        'users'            => ['superadmin', 'admin', 'viewer'],
        'devices'          => ['superadmin', 'admin'],
        'productivity'     => ['superadmin', 'admin', 'viewer'],
        'policies'    => ['superadmin'],
        'releases'    => ['superadmin'],
        'admin-users' => ['superadmin'],
        'assignments' => ['superadmin'],
        'roles'       => ['superadmin'],
        'settings'    => ['superadmin'],
        'server-health' => ['superadmin'],
        'dual_job'    => ['superadmin', 'admin'],
    ];

    try {
        $st = $pdo->prepare("SELECT setting_value FROM keeper_panel_settings WHERE setting_key = 'menu_visibility' LIMIT 1");
        $st->execute();
        $row = $st->fetch(\PDO::FETCH_ASSOC);
        if ($row && !empty($row['setting_value'])) {
            $decoded = json_decode($row['setting_value'], true);
            if (is_array($decoded)) {
                $cache = array_merge($defaults, $decoded);
                return $cache;
            }
        }
    } catch (\Throwable $e) {
        // Tabla no existe aún → usar defaults
    }

    $cache = $defaults;
    return $cache;
}

/**
 * Helper: verifica si el rol actual puede ver un módulo del panel.
 * Usa la configuración dinámica de keeper_panel_settings.
 * Superadmin SIEMPRE puede ver todo (hardcoded safety).
 */
function canAccessModule(string $module): bool {
    global $adminUser;
    $role = $adminUser['panel_role'] ?? '';

    // Superadmin siempre puede todo (safety net)
    if ($role === 'superadmin') return true;

    $visibility = getMenuVisibility();
    $allowedRoles = $visibility[$module] ?? [];
    return in_array($role, $allowedRoles, true);
}

/**
 * Helper: carga listas de descanso/despeje para deducirlas de productividad.
 * Se almacenan en keeper_panel_settings con key 'leisure_apps'.
 * Retorna ['apps' => [...], 'windows' => [...]]
 *   - apps:    coincide con process_name en keeper_window_episode
 *   - windows: coincide con window_title (LIKE %keyword%)
 * Backward-compatible: si el JSON es un array plano, se trata como 'apps'.
 */
function getLeisureApps(): array {
    global $pdo;
    static $cache = null;
    if ($cache !== null) return $cache;
    $empty = ['apps' => [], 'windows' => []];
    try {
        $st = $pdo->prepare("SELECT setting_value FROM keeper_panel_settings WHERE setting_key = 'leisure_apps' LIMIT 1");
        $st->execute();
        $row = $st->fetch(\PDO::FETCH_ASSOC);
        if ($row && !empty($row['setting_value'])) {
            $decoded = json_decode($row['setting_value'], true);
            if (is_array($decoded)) {
                // Backward compat: flat array → apps only
                if (isset($decoded[0]) || empty($decoded)) {
                    $cache = ['apps' => array_values($decoded), 'windows' => []];
                } else {
                    $cache = [
                        'apps'    => array_values($decoded['apps'] ?? []),
                        'windows' => array_values($decoded['windows'] ?? []),
                    ];
                }
                return $cache;
            }
        }
    } catch (\Throwable $e) {}
    $cache = $empty;
    return $cache;
}

/**
 * Helper: guard para páginas — redirige 403 si el rol no tiene acceso al módulo.
 * Usar al inicio de cada página: requireModule('policies');
 */
function requireModule(string $module): void {
    if (!canAccessModule($module)) {
        http_response_code(403);
        echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>403</title></head><body style="font-family:Inter,sans-serif;display:flex;align-items:center;justify-content:center;height:100vh;background:#f9fafb"><div style="text-align:center"><h1 style="font-size:4rem;color:#be1622;margin:0">403</h1><p style="color:#9d9d9c">No tienes acceso a este módulo.</p><a href="index.php" style="color:#003a5d">Volver al Dashboard</a></div></body></html>';
        exit;
    }
}
