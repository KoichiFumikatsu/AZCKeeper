<?php
declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
// Preserve the existing API front controller without loading it for presentation.
if ($path === '/v1' || str_starts_with($path, '/v1/') || str_starts_with($path, '/ext/v1/') || str_starts_with($path, '/portal/v1/')) {
    ini_set('display_errors', '0');
    require dirname(__DIR__) . '/config/bootstrap.php';
    (new Keeper\Application())->run();
    return;
}

$routes = [
    '/supervisor.php'=>'supervisor.php', '/supervisor-persona.php'=>'supervisor-persona.php',
    '/proteccion.php'=>'proteccion.php',
    '/horarios.php'=>'horarios.php','/fuentes.php'=>'fuentes.php','/grupo.php'=>'grupo.php','/puertas.php'=>'puertas.php','/ausencias.php'=>'ausencias.php','/clasificacion.php'=>'clasificacion.php','/planes.php'=>'planes.php','/auditoria.php'=>'auditoria.php','/logs.php'=>'logs.php','/ajustes.php'=>'ajustes.php','/empresas.php'=>'empresas.php','/cuentas.php'=>'cuentas.php','/roles.php'=>'roles.php','/sedes.php'=>'sedes.php','/organizacion.php'=>'organizacion.php','/asignaciones.php'=>'asignaciones.php','/alta.php'=>'alta.php','/puesta-en-marcha.php'=>'puesta-en-marcha.php',
    '/festivos.php'=>'festivos.php','/doble-empleo.php'=>'doble-empleo.php','/apps-sospechosas.php'=>'apps-sospechosas.php','/cobertura.php'=>'cobertura.php',
    '/admin/logs.php'=>'admin/logs.php','/admin/salud.php'=>'admin/salud.php','/admin/ajustes.php'=>'admin/ajustes.php','/admin/festivos.php'=>'admin/festivos.php',
    '/' => 'index.php', '/index.php' => 'index.php', '/login.php' => 'login.php',
    '/equipos.php' => 'equipos.php', '/equipo.php' => 'equipo.php', '/usuarios.php' => 'usuarios.php',
    '/miembro.php' => 'miembro.php', '/reglas.php' => 'reglas.php', '/reportes.php' => 'reportes.php',
    '/admin/' => 'admin/index.php', '/admin/index.php' => 'admin/index.php',
    '/admin/tenants.php' => 'admin/tenants.php', '/admin/roles.php' => 'admin/roles.php',
    '/admin/releases.php' => 'admin/releases.php', '/admin/portal.php' => 'admin/portal.php',
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
$page = 'home'; $title = 'Inicio'; $surface = 'portal';
require __DIR__ . '/partials/layout.php';
