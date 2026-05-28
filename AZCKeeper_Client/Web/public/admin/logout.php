<?php
require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/Repos/AdminAuthRepo.php';

use Keeper\Db;
use Keeper\Repos\AdminAuthRepo;

if (!defined('KEEPER_ADMIN_COOKIE')) {
    define('KEEPER_ADMIN_COOKIE', 'keeper_admin_token');
}

$token = $_COOKIE[KEEPER_ADMIN_COOKIE] ?? null;
if ($token) {
    $pdo = Db::pdo();
    AdminAuthRepo::revokeSession($pdo, $token);
    setcookie(KEEPER_ADMIN_COOKIE, '', time() - 3600, '/', '', false, true);
}

header('Location: login.php');
exit;
