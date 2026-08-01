<?php
require_once __DIR__ . '/../src/bootstrap.php';

use Keeper\Http;

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = $_SERVER['PATH_INFO'] ?? (parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/');

$pos = stripos($path, '/index.php');
if ($pos !== false) {
  $path = substr($path, $pos + strlen('/index.php'));
  if ($path === '') $path = '/';
}

$apiPrefix = Keeper\Config::get('API_PREFIX', '/api');
if (!str_starts_with($path, $apiPrefix)) {
  Http::json(404, ['ok' => false, 'error' => 'Not Found']);
}
$endpoint = substr($path, strlen($apiPrefix));
if ($endpoint === '') $endpoint = '/';

$routes = [
  'GET' => [
    '/health'             => [Keeper\Endpoints\Health::class, 'handle'],
    '/client/version'     => [Keeper\Endpoints\ClientVersion::class, 'handle'],
    '/admin/process-view' => [Keeper\Endpoints\ProcessView::class, 'handle'],
    '/admin/coverage'     => [Keeper\Endpoints\AdminCoverage::class, 'list'],
    '/client/commands'    => [Keeper\Endpoints\ClientCommands::class, 'poll'],
  ],
  'POST' => [
    '/client/login'            => [Keeper\Endpoints\ClientLogin::class, 'handle'],
    '/client/handshake'        => [Keeper\Endpoints\ClientHandshake::class, 'handle'],
    '/client/episodes/batch'   => [Keeper\Endpoints\EpisodeBatch::class, 'handle'],
    '/client/activity-day'     => [Keeper\Endpoints\ActivityDay::class, 'handle'],
    '/client/module-state'     => [Keeper\Endpoints\ModuleStateReport::class, 'handle'],
    '/client/commands/result'  => [Keeper\Endpoints\ClientCommands::class, 'result'],
    '/client/security/report'  => [Keeper\Endpoints\SecurityReport::class, 'handle'],
    '/client/screenshots'      => [Keeper\Endpoints\ScreenshotMeta::class, 'handle'],
    '/client/location'         => [Keeper\Endpoints\LocationReport::class, 'handle'],
    '/client/diagnostics'      => [Keeper\Endpoints\ClientDiagnostics::class, 'handle'],
    '/client/logs'             => [Keeper\Endpoints\ClientLogBatch::class, 'handle'],
    '/admin/commands'          => [Keeper\Endpoints\AdminCommand::class, 'enqueue'],
    '/admin/enrollment'        => [Keeper\Endpoints\AdminEnrollment::class, 'handle'],
    '/cron/productivity'       => [Keeper\Endpoints\ProductivityCron::class, 'handle'],
    '/admin/coverage/note'     => [Keeper\Endpoints\AdminCoverage::class, 'setNote'],
  ],
];

$existsOther = false; $allowed = [];
foreach ($routes as $m => $map) {
  if (isset($map[$endpoint])) { $existsOther = true; $allowed[] = $m; }
}
if (!isset($routes[$method][$endpoint])) {
  if ($existsOther) Http::json(405, ['ok' => false, 'error' => 'Method Not Allowed', 'allowed' => $allowed]);
  Http::json(404, ['ok' => false, 'error' => 'Not Found']);
}

[$class, $fn] = $routes[$method][$endpoint];
try {
  call_user_func([$class, $fn]);
} catch (\Throwable $e) {
  // En dev, devolver el mensaje para diagnosticar; en prod, 500 generico.
  $dev = Keeper\Config::get('APP_ENV', 'prod') === 'dev';
  error_log('K4 uncaught: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
  Http::json(500, array_merge(
    ['ok' => false, 'error' => 'Internal error'],
    $dev ? ['detail' => $e->getMessage(), 'at' => basename($e->getFile()) . ':' . $e->getLine()] : []
  ));
}
