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

// Funciones de autorización (panelCan / panelLanding) y CSRF — compartidas con login.php.
require_once __DIR__ . '/admin_auth_helpers.php';

// Gate CSRF: todo POST del panel debe traer un _csrf valido. Centralizado aqui, protege a
// TODAS las paginas que incluyen este middleware. Los formularios incrustan csrf_field().
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && !csrf_check()) {
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    echo 'Sesión de formulario inválida o expirada (CSRF). Recarga la página e inténtalo de nuevo.';
    exit;
}
