<?php
declare(strict_types=1);
// Ejecutar cada minuto; cada lote confirma su cursor para continuar tras una interrupción.
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require __DIR__.'/bootstrap.php';
use Keeper\{Database,DeviceLocations};
try {
    $db=new Database(); $worker=new DeviceLocations($db); $deadline=microtime(true)+45;
    do {
        $jobs=$db->run('SELECT tenant_id FROM location_reclassification ORDER BY tenant_id')->fetchAll();
        foreach ($jobs as $job) {
            if (microtime(true)>=$deadline) { break 2; }
            $worker->reclassify($job['tenant_id']);
        }
    } while ($jobs && microtime(true)<$deadline);
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR,'location_reclassification_failed: '.get_class($e).PHP_EOL); exit(1);
}
