<?php
require_once __DIR__ . '/../src/bootstrap.php';

use Keeper\Http;

// Method
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// Path: PATH_INFO (si existe) o REQUEST_URI
$path = $_SERVER['PATH_INFO'] ?? (parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/');

// Normaliza: si viene /.../index.php/api/... recorta hasta index.php
$pos = stripos($path, '/index.php');
if ($pos !== false) {
  $path = substr($path, $pos + strlen('/index.php'));
  if ($path === '') $path = '/';
}

// Opcional: recortar APP_BASE_URL si lo usas (en local déjalo vacío)
$baseUrl = Keeper\Config::get('APP_BASE_URL', '');
if ($baseUrl && str_starts_with($path, $baseUrl)) {
  $path = substr($path, strlen($baseUrl));
  if ($path === '') $path = '/';
}

$apiPrefix = Keeper\Config::get('API_PREFIX', '/api');

function json_404() {
  Http::json(404, ['ok' => false, 'error' => 'Not Found']);
}
function json_405(array $allowed) {
  Http::json(405, ['ok' => false, 'error' => 'Method Not Allowed', 'allowed' => $allowed]);
}

// Solo /api/*
if (!str_starts_with($path, $apiPrefix)) {
  json_404();
}

$endpoint = substr($path, strlen($apiPrefix));
if ($endpoint === '') $endpoint = '/';

// Tabla completa de rutas
$routes = [
  'GET' => [
    '/health' => [Keeper\Endpoints\Health::class, 'handle'],
    '/client/activity-day' => [Keeper\Endpoints\ActivityDay::class, 'handleGet'], 
    '/client/version', [Keeper\Endpoints\ClientVersion::class, 'handle'],
  ],
  'POST' => [
    '/client/handshake' => [Keeper\Endpoints\ClientHandshake::class, 'handle'],
    '/client/login' => [Keeper\Endpoints\ClientLogin::class, 'handle'],
    '/client/activity-day' => [Keeper\Endpoints\ActivityDay::class, 'handle'],
    '/client/window-episode' => [Keeper\Endpoints\WindowEpisode::class, 'handle'],
    '/client/event' => [Keeper\Endpoints\EventIngest::class, 'handle'],
    '/client/device-lock/status' => [Keeper\Endpoints\DeviceLock::class, 'getStatus'],
    '/client/device-lock/unlock', [Keeper\Endpoints\DeviceLock::class, 'tryUnlock'],
    '/client/force-handshake' => [Keeper\Endpoints\ForceHandshake::class, 'handle'],
  ],
];

// Si endpoint existe pero método no, devuelve 405
$existsInOtherMethod = false;
$allowed = [];
foreach ($routes as $m => $map) {
  if (isset($map[$endpoint])) {
    $existsInOtherMethod = true;
    $allowed[] = $m;
  }
}

if (!isset($routes[$method][$endpoint])) {
  if ($existsInOtherMethod) json_405($allowed);
  json_404();
}

[$class, $fn] = $routes[$method][$endpoint];
call_user_func([$class, $fn]);
