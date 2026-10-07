<?php
declare(strict_types=1);
// Revisión nocturna de retención (RetentionWorker): cuenta lo que quedó fuera de los plazos de cada cliente y solo lo
// borra donde la retención está aplicada (retention_settings.enforce). Salida: 0 bien, 1 algún cliente falló (detalle
// por cliente en STDERR), 3 ya había otra revisión corriendo.
//   php config/retention-cron.php [--tenant=<uuid>] [--batch=5000] [--budget-seconds=900]
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require __DIR__.'/bootstrap.php';
use Keeper\{Database,RetentionWorker,Util,Validator};
try {
    $options=getopt('',['tenant:','batch:','budget-seconds:']);
    $tenant=$options['tenant']??null;
    if ($tenant!==null) { (new Validator())->check(['type'=>'string','format'=>'uuid'],$tenant); }
    $batch=(int)($options['batch']??5000); $budget=(int)($options['budget-seconds']??900);
    if ($batch<1 || $batch>50000 || $budget<1 || $budget>21600) { throw new InvalidArgumentException('batch 1..50000, budget-seconds 1..21600'); }
    $result=(new RetentionWorker(new Database()))->run($tenant===null?null:Util::bin($tenant),$batch,$budget);
    if ($result===null) { fwrite(STDERR,'retention_already_running'.PHP_EOL); exit(3); }
    $failed=0;
    foreach ($result as $r) {
        if ($r['error']!==null) { $failed++; fwrite(STDERR,'retention_failed tenant='.$r['tenant_id'].' error='.$r['error'].PHP_EOL); }
    }
    echo Util::json($result).PHP_EOL;
    exit($failed?1:0);
} catch (Throwable $e) { fwrite(STDERR,'retention_failed: '.get_class($e).PHP_EOL); exit(1); }
