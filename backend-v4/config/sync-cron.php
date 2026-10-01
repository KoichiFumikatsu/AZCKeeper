<?php
declare(strict_types=1);
// Vueltas de sincronizacion vencidas (incremental cada N minutos, completa diaria) de todas las empresas.
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require __DIR__.'/bootstrap.php';
use Keeper\{AdminApi,Database,DataSync,DoorEvents,Util};
try {
    $db=new Database(); $sync=new DataSync($db); $out=[];
    foreach ($sync->due() as [$source,$kind]) {
        try {
            $run=$db->transaction(fn()=>$sync->run($source['tenant_id'],$source['id'],$kind,'schedule'));
            try { AdminApi::compilePending($db,$source['tenant_id'],null); } catch (Throwable) { /* el siguiente cambio de admin la completa */ }
            $out[]=['source'=>Util::id($source['id']),'kind'=>$kind,'status'=>$db->one('SELECT status FROM sync_runs WHERE tenant_id=? AND id=?',[$source['tenant_id'],$run])['status']];
        } catch (Throwable $e) { $out[]=['source'=>Util::id($source['id']),'kind'=>$kind,'status'=>'error','error'=>get_class($e)]; }
    }
    // Entradas por puerta en cuarentena: se reintentan (pudo aparecer la persona o registrarse la puerta).
    foreach ($db->run('SELECT DISTINCT tenant_id FROM unmatched_check_ins')->fetchAll() as $t) {
        try { $n=$db->transaction(fn()=>(new DoorEvents($db))->rematch($t['tenant_id'])); if ($n) { $out[]=['tenant'=>Util::id($t['tenant_id']),'door_rematched'=>$n]; } } catch (Throwable) { /* siguiente vuelta */ }
    }
    echo Util::json($out).PHP_EOL;
} catch (Throwable $e) { fwrite(STDERR,'sync_failed: '.get_class($e).PHP_EOL); exit(1); }
