<?php
require_once __DIR__ . '/../../src/bootstrap.php';

use Keeper\Db;
use Keeper\Repos\AdminAuthRepo;

if (!defined('KEEPER_ADMIN_COOKIE')) define('KEEPER_ADMIN_COOKIE', 'keeper_admin_token');

$token = $_COOKIE[KEEPER_ADMIN_COOKIE] ?? null;
if ($token) {
    try { AdminAuthRepo::revokeSession(Db::pdo(), $token); } catch (\Throwable $e) { /* best-effort */ }
    setcookie(KEEPER_ADMIN_COOKIE, '', time() - 3600, '/', '', false, true);
}
header('Location: login.php');
exit;
