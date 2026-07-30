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

// Funciones de autorización (panelCan / panelLanding) — compartidas con login.php.
require_once __DIR__ . '/admin_auth_helpers.php';
