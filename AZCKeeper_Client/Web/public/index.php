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
    '/client/version' => [Keeper\Endpoints\ClientVersion::class, 'handle'],
    '/external/roster' => [Keeper\Endpoints\ExternalAuth::class, 'roster'],
    '/external/sites-and-firms' => [Keeper\Endpoints\ExternalAuth::class, 'sitesAndFirms'],
    // Reportes para MOAZC/One (K3-ADP-01…06): X-Bridge-Secret + X-Acting-Admin; el ámbito lo aplica Keeper.
    '/external/reports/contract'     => [Keeper\Endpoints\ExternalReports::class, 'contract'],
    '/external/reports/summary'      => [Keeper\Endpoints\ExternalReports::class, 'summary'],
    '/external/reports/users'        => [Keeper\Endpoints\ExternalReports::class, 'users'],
    '/external/reports/productivity' => [Keeper\Endpoints\ExternalReports::class, 'productivity'],
    '/external/reports/coverage'     => [Keeper\Endpoints\ExternalReports::class, 'coverage'],
    '/external/reports/presence'     => [Keeper\Endpoints\ExternalReports::class, 'presence'],
    '/external/reports/alerts'       => [Keeper\Endpoints\ExternalReports::class, 'alerts'],
    // Sincronización compartida con One (K3-ADP-08): asignaciones con fuente y excepción manual.
    '/external/assignments'          => [Keeper\Endpoints\ExternalAssignments::class, 'index'],
  ],
  'POST' => [
    '/client/handshake' => [Keeper\Endpoints\ClientHandshake::class, 'handle'],
    '/client/login' => [Keeper\Endpoints\ClientLogin::class, 'handle'],
    '/client/activity-day' => [Keeper\Endpoints\ActivityDay::class, 'handle'],
    '/client/window-episode' => [Keeper\Endpoints\WindowEpisode::class, 'handle'],
    '/client/window-episodes/batch' => [Keeper\Endpoints\WindowEpisodeBatch::class, 'handle'],
    '/client/logs' => [Keeper\Endpoints\ClientLogBatch::class, 'handle'],
    '/client/device-lock/unlock' => [Keeper\Endpoints\DeviceLock::class, 'tryUnlock'],
    '/client/event' => [Keeper\Endpoints\EventIngest::class, 'handle'],
    '/client/force-handshake' => [Keeper\Endpoints\ForceHandshake::class, 'handle'],
    '/client/re-enroll' => [Keeper\Endpoints\ClientReEnroll::class, 'handle'],
    '/cron/productivity' => [Keeper\Endpoints\ProductivityCron::class, 'handle'],
    '/external/verify-credentials' => [Keeper\Endpoints\ExternalAuth::class, 'verifyCredentials'],
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

// Rutas con parámetro en el camino (One): detalle de un colaborador (K3-ADP-02) y aplicar una asignación (K3-ADP-07).
if (preg_match('#^/external/reports/users/(\d+)$#', $endpoint, $m)) {
  if ($method !== 'GET') json_405(['GET']);
  Keeper\Endpoints\ExternalReports::userDetail((int)$m[1]);
}
if (preg_match('#^/external/assignments/(\d+)$#', $endpoint, $m)) {
  if ($method !== 'PUT') json_405(['PUT']);
  Keeper\Endpoints\ExternalAssignments::apply((int)$m[1]);
}

if (!isset($routes[$method][$endpoint])) {
  if ($existsInOtherMethod) json_405($allowed);
  json_404();
}

[$class, $fn] = $routes[$method][$endpoint];
call_user_func([$class, $fn]);
