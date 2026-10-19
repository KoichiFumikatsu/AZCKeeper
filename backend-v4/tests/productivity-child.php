<?php
declare(strict_types=1);
// Proceso aparte para la prueba de concurrencia de productivity-batches.php: corre el calculo (viejo o nuevo) de un cliente.
// El nuevo se detiene al terminar de preparar hasta que la prueba suelta el GET_LOCK 'productivity-race'.
if (PHP_SAPI !== 'cli') { exit(1); }
require dirname(__DIR__) . '/config/bootstrap.php';
require_once __DIR__ . '/legacy/ProductivityWorkerLegacy.php';
[, $kind, $tenant] = $argv;
$db = new Keeper\Database();
try {
    $gate = function (string $point) use ($db): void {
        if ($point === 'prepared') { $db->one("SELECT GET_LOCK('productivity-race',20) g"); $db->one("SELECT RELEASE_LOCK('productivity-race') r"); }
    };
    $worker = $kind === 'legacy' ? new Keeper\ProductivityWorkerLegacy($db) : new Keeper\ProductivityWorker($db, $gate);
    echo json_encode(['ok' => $worker->run($tenant, 5, 30)]);
} catch (Throwable $e) {
    echo json_encode(['error' => get_class($e), 'driver' => $e instanceof PDOException ? (int) ($e->errorInfo[1] ?? 0) : 0, 'at' => basename($e->getFile()) . ':' . $e->getLine()]);
}
