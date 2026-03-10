<?php
/**
 * Login del panel admin.
 * Verifica email + password contra keeper_users.password_hash
 * y crea sesión en keeper_admin_sessions.
 */
require_once __DIR__ . '/../../src/bootstrap.php';
require_once __DIR__ . '/../../src/Repos/AdminAuthRepo.php';

use Keeper\Db;
use Keeper\Repos\AdminAuthRepo;

session_start();

define('KEEPER_ADMIN_COOKIE', 'keeper_admin_token');

$pdo = Db::pdo();
$error = '';

// Si ya tiene sesión válida, redirigir al dashboard
$token = $_COOKIE[KEEPER_ADMIN_COOKIE] ?? null;
if ($token && AdminAuthRepo::validateSession($pdo, $token)) {
    header('Location: index.php');
    exit;
}

// Procesar login
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($email) || empty($password)) {
        $error = 'Ingresa tu email y contraseña.';
    } else {
        $admin = AdminAuthRepo::findByEmail($pdo, $email);

        if (!$admin) {
            $error = 'Credenciales inválidas.';
        } elseif (!$admin['admin_active']) {
            $error = 'Tu cuenta de administrador está desactivada.';
        } elseif ($admin['user_status'] !== 'active') {
            $error = 'Tu cuenta de usuario está inactiva.';
        } elseif (!password_verify($password, $admin['password_hash'])) {
            $error = 'Credenciales inválidas.';
        } else {
            // Login exitoso → crear sesión
            $token = AdminAuthRepo::createSession($pdo, (int)$admin['admin_id']);

            // Cookie httpOnly, 8 horas
            setcookie(KEEPER_ADMIN_COOKIE, $token, [
                'expires'  => time() + 28800,
                'path'     => '/',
                'httponly'  => true,
                'samesite' => 'Lax',
                'secure'   => isset($_SERVER['HTTPS']),
            ]);

            header('Location: index.php');
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es" class="h-full bg-gray-50">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Keeper — Iniciar sesión</title>
    <link rel="icon" href="assets/icoMain.png" type="image/png">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        'corp': { 800: '#003a5d', 900: '#002b47' },
                        'accent': { 500: '#be1622', 600: '#a0121d' },
                        'dark': '#353132',
                        'muted': '#9d9d9c',
                    }
                }
            }
        }
    </script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>body { font-family: 'Inter', sans-serif; }</style>
</head>
<body class="h-full flex items-center justify-center">
    <div class="w-full max-w-md px-6">
        <!-- Card -->
        <div class="bg-white rounded-2xl shadow-lg border border-gray-100 p-8">
            <!-- Logo -->
            <div class="text-center mb-8">
                <img src="assets/logo_main.png" alt="Keeper" class="h-10 mx-auto mb-4">
                <h1 class="text-2xl font-bold text-dark">Keeper</h1>
                <p class="text-sm text-muted mt-1">Panel de Administración</p>
            </div>

            <?php if ($error): ?>
            <div class="mb-6 p-3 bg-red-50 border border-red-200 rounded-lg">
                <p class="text-sm text-accent-500 font-medium"><?= htmlspecialchars($error) ?></p>
            </div>
            <?php endif; ?>

            <form method="POST" class="space-y-5">
                <div>
                    <label for="email" class="block text-sm font-medium text-dark mb-1.5">Correo electrónico</label>
                    <input
                        type="email"
                        id="email"
                        name="email"
                        value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                        required
                        autofocus
                        class="w-full px-4 py-2.5 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-corp-800/20 focus:border-corp-800 outline-none transition-all placeholder:text-muted"
                        placeholder="tu@email.com"
                    >
                </div>

                <div>
                    <label for="password" class="block text-sm font-medium text-dark mb-1.5">Contraseña</label>
                    <input
                        type="password"
                        id="password"
                        name="password"
                        required
                        class="w-full px-4 py-2.5 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-corp-800/20 focus:border-corp-800 outline-none transition-all placeholder:text-muted"
                        placeholder="••••••••"
                    >
                </div>

                <button
                    type="submit"
                    class="w-full bg-corp-800 hover:bg-corp-900 text-white font-medium py-2.5 px-4 rounded-lg transition-colors text-sm"
                >
                    Iniciar sesión
                </button>
            </form>
        </div>

        <p class="text-center text-xs text-muted mt-6">
            Keeper v3.0 © GrupoAZC
        </p>
    </div>
</body>
</html>
