<?php
/**
 * Middleware de autenticación del panel admin K4.
 * Incluir al inicio de cada página protegida:  require_once __DIR__ . '/admin_auth.php';
 * Deja disponibles: $adminUser (cuenta + rol + scope) y $pdo. Sin sesión -> login.php.
 */

require_once __DIR__ . '/../../src/bootstrap.php';

use Keeper\Db;
use Keeper\Repos\AdminAuthRepo;

date_default_timezone_set('America/Bogota');

$pdo = Db::pdo();
$pdo->exec("SET time_zone = '-05:00'"); // panel en hora Colombia

if (!defined('KEEPER_ADMIN_COOKIE')) define('KEEPER_ADMIN_COOKIE', 'keeper_admin_token');

$token = $_COOKIE[KEEPER_ADMIN_COOKIE] ?? null;
if (!$token) { header('Location: login.php'); exit; }

$adminUser = AdminAuthRepo::validateSession($pdo, $token);
if (!$adminUser) {
    setcookie(KEEPER_ADMIN_COOKIE, '', time() - 3600, '/', '', false, true);
    header('Location: login.php');
    exit;
}

/**
 * Gate de módulos por rol. superadmin ve todo; los demás, según su rol.
 * Rebanada 1: mapa mínimo; el RBAC editable por keeper_panel_roles llega en la rebanada 5.
 */
function panelCan(array $adminUser, string $module): bool
{
    if (($adminUser['panel_role'] ?? '') === 'superadmin') return true;
    static $byRole = [
        'admin'  => ['dashboard','process-view','users','devices','pending','tiers','coverage','dual-job','audit'],
        'viewer' => ['dashboard','process-view','coverage'],
    ];
    $allowed = $byRole[$adminUser['panel_role'] ?? 'viewer'] ?? [];
    return in_array($module, $allowed, true);
}
