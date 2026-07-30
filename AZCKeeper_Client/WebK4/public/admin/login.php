<?php
require_once __DIR__ . '/../../src/bootstrap.php';

use Keeper\Db;
use Keeper\Repos\AdminAuthRepo;

date_default_timezone_set('America/Bogota');
if (!defined('KEEPER_ADMIN_COOKIE')) define('KEEPER_ADMIN_COOKIE', 'keeper_admin_token');

$pdo = Db::pdo();
$error = null;

// Ya logueado -> al dashboard.
require_once __DIR__ . '/admin_auth_helpers.php'; // panelCan / panelLanding sin exigir sesión

$existing = $_COOKIE[KEEPER_ADMIN_COOKIE] ?? null;
if ($existing) {
    $sess = AdminAuthRepo::validateSession($pdo, $existing);
    if ($sess) { header('Location: ' . panelLanding($sess)); exit; }
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $email = trim((string)($_POST['email'] ?? ''));
    $pass  = (string)($_POST['password'] ?? '');
    $acct  = ($email !== '' && $pass !== '') ? AdminAuthRepo::login($pdo, $email, $pass) : null;
    if ($acct) {
        $token = AdminAuthRepo::createSession($pdo, (int)$acct['id']);
        $secure = (($_SERVER['HTTPS'] ?? '') === 'on');
        setcookie(KEEPER_ADMIN_COOKIE, $token, [
            'expires' => time() + 28800, 'path' => '/', 'httponly' => true,
            'samesite' => 'Lax', 'secure' => $secure,
        ]);
        header('Location: ' . panelLanding($acct));
        exit;
    }
    $error = 'Credenciales inválidas.';
    usleep(300000); // pequeño freno anti-fuerza-bruta
}
?><!DOCTYPE html>
<html lang="es" class="h-full bg-gray-50">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ingreso — Keeper Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
      tailwind.config = { theme: { extend: { colors: {
        corp: {50:'#f0f7fb',100:'#dceef5',200:'#b8dcea',400:'#4ba3c5',600:'#236c92',800:'#003a5d',900:'#002b47'},
        accent: {500:'#be1622',600:'#a0121d'}, dark:'#353132', muted:'#9d9d9c' } } } }
    </script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>body{font-family:'Inter',sans-serif}</style>
</head>
<body class="h-full">
  <div class="min-h-full flex items-center justify-center px-4">
    <div class="w-full max-w-sm">
      <div class="flex items-center gap-3 justify-center mb-8">
        <div class="w-11 h-11 rounded-xl bg-corp-800 flex items-center justify-center">
          <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3l7 3v5c0 4.4-3 8-7 10-4-2-7-5.6-7-10V6l7-3z"/></svg>
        </div>
        <div>
          <div class="text-xl font-bold text-corp-800 leading-none">Keeper</div>
          <div class="text-xs text-muted">Panel de administración</div>
        </div>
      </div>
      <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-7">
        <h1 class="text-base font-semibold text-dark mb-1">Iniciar sesión</h1>
        <p class="text-xs text-muted mb-5">Acceso restringido a administradores.</p>
        <?php if ($error): ?>
          <div class="mb-4 text-sm text-accent-600 bg-accent-500/5 border border-accent-500/20 rounded-lg px-3 py-2"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <form method="post" class="space-y-4">
          <div>
            <label class="block text-xs font-medium text-gray-600 mb-1">Correo</label>
            <input name="email" type="email" required autofocus autocomplete="username"
              class="w-full px-3 py-2.5 border border-gray-200 rounded-lg text-sm outline-none focus:ring-2 focus:ring-corp-200 focus:border-corp-400">
          </div>
          <div>
            <label class="block text-xs font-medium text-gray-600 mb-1">Contraseña</label>
            <input name="password" type="password" required autocomplete="current-password"
              class="w-full px-3 py-2.5 border border-gray-200 rounded-lg text-sm outline-none focus:ring-2 focus:ring-corp-200 focus:border-corp-400">
          </div>
          <button type="submit" class="w-full bg-corp-800 hover:bg-corp-900 text-white text-sm font-medium rounded-lg py-2.5 transition-colors">Entrar</button>
        </form>
      </div>
      <p class="text-center text-xs text-muted mt-6">AZC Keeper 4 · monitoría de agentes</p>
    </div>
  </div>
</body>
</html>
