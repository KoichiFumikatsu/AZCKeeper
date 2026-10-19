<?php
declare(strict_types=1);
// Proceso aparte para tests/auth-locks.php: autentica un token de equipo con Auth::authenticate (sin prueba de firma) en su
// propia conexion y transaccion, como la segunda transaccion de una peticion de /v1/client/*. Imprime el resultado en JSON.
if (PHP_SAPI !== 'cli') { exit(1); }
require dirname(__DIR__) . '/config/bootstrap.php';
require_once __DIR__ . '/auth-locks-request.php';
[, $token] = $argv;
$db = new Keeper\Database();
$db->run('SET SESSION innodb_lock_wait_timeout=20');
$auth = new Keeper\Auth($db, new Keeper\Signature(), new Keeper\RateLimiter());
$start = microtime(true);
try {
    $c = $db->transaction(fn () => $auth->authenticate(authLockRequest($token), false));
    echo json_encode(['ok' => true, 'device_id' => Keeper\Util::id($c['device_id']), 'ms' => round(1000 * (microtime(true) - $start))]);
} catch (Keeper\ApiError $e) {
    echo json_encode(['status' => $e->status, 'code' => $e->errorCode, 'ms' => round(1000 * (microtime(true) - $start))]);
} catch (Throwable $e) {
    echo json_encode(['error' => get_class($e), 'driver' => $e instanceof PDOException ? (int) ($e->errorInfo[1] ?? 0) : 0, 'ms' => round(1000 * (microtime(true) - $start))]);
}
