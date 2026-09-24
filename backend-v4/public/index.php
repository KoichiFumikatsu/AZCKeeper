<?php
declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
// Preserve the existing API front controller without loading it for presentation.
if ($path === '/v1' || str_starts_with($path, '/v1/') || str_starts_with($path, '/ext/v1/')) {
    ini_set('display_errors', '0');
    require dirname(__DIR__) . '/config/bootstrap.php';
    (new Keeper\Application())->run();
    return;
}

$routes = [
    '/festivos.php'=>'festivos.php','/doble-empleo.php'=>'doble-empleo.php','/apps-sospechosas.php'=>'apps-sospechosas.php','/cobertura.php'=>'cobertura.php',
    '/admin/logs.php'=>'admin/logs.php','/admin/salud.php'=>'admin/salud.php','/admin/ajustes.php'=>'admin/ajustes.php','/admin/festivos.php'=>'admin/festivos.php',
    '/' => 'index.php', '/index.php' => 'index.php', '/login.php' => 'login.php',
    '/equipos.php' => 'equipos.php', '/usuarios.php' => 'usuarios.php',
    '/miembro.php' => 'miembro.php', '/reglas.php' => 'reglas.php', '/reportes.php' => 'reportes.php',
    '/admin/' => 'admin/index.php', '/admin/index.php' => 'admin/index.php',
    '/admin/tenants.php' => 'admin/tenants.php', '/admin/roles.php' => 'admin/roles.php',
    '/admin/tiers.php' => 'admin/tiers.php', '/admin/releases.php' => 'admin/releases.php',
    '/admin/auditoria.php' => 'admin/auditoria.php',
];
if ($path === '/admin') {
    header('Location: /admin/', true, 308);
    return;
}
if ($path === '/branding.svg') {
    header('Location: /assets/brand/logo-main.png', true, 302);
    return;
}
if (PHP_SAPI === 'cli-server' && str_starts_with($path, '/assets/')) {
    $file = realpath(__DIR__ . $path);
    $assets = realpath(__DIR__ . '/assets') . DIRECTORY_SEPARATOR;
    if ($file !== false && str_starts_with($file, $assets) && is_file($file)) { return false; }
}
if (!isset($routes[$path])) {
    http_response_code(404);
    $page = 'not-found'; $title = 'Página no encontrada'; $surface = 'public';
    require __DIR__ . '/partials/layout.php';
    return;
}
if ($routes[$path] !== 'index.php') {
    require __DIR__ . '/' . $routes[$path];
    return;
}
$page = 'home'; $title = 'Panel del coordinador'; $surface = 'portal';
require __DIR__ . '/partials/layout.php';
