<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require __DIR__.'/bootstrap.php';
use Keeper\{Database,ProductivityWorker,Util,Validator};
try {
    $options=getopt('',['tenant:','max-days:','budget-seconds:','from:','to:']);
    $db=new Database(); $tenant=$options['tenant']??null;
    if ($tenant!==null) { (new Validator())->check(['type'=>'string','format'=>'uuid'],$tenant); }
    if (isset($options['from']) || isset($options['to'])) {
        if ($tenant===null || !isset($options['from'],$options['to'])) { throw new InvalidArgumentException('Replay requires tenant, from and to'); }
        foreach (['from','to'] as $f) { (new Validator())->check(['type'=>'string','format'=>'date'],$options[$f]); }
        $from=new DateTimeImmutable($options['from']); $to=new DateTimeImmutable($options['to']);
        if ($from>=$to || $from->diff($to)->days>366) { throw new InvalidArgumentException('Replay range: 1..366 days'); }
        for ($d=$from;$d<$to;$d=$d->modify('+1 day')) {
            $db->run('INSERT INTO rollup_days (tenant_id,day) SELECT tenant_id,? FROM retention_settings WHERE tenant_id=? AND ?>=UTC_DATE()-INTERVAL raw_days DAY AND ?>=DATE_SUB(UTC_DATE(),INTERVAL aggregate_months MONTH) AND ?<=UTC_DATE()+INTERVAL 1 DAY ON DUPLICATE KEY UPDATE dirty=TRUE,input_revision=input_revision+1,finalized_at=NULL',[$d->format('Y-m-d'),Util::bin($tenant),$d->format('Y-m-d'),$d->format('Y-m-d'),$d->format('Y-m-d')]);
        }
    }
    // Finalize only already-current checkpoints after the late-arrival window closes.
    $db->run('UPDATE rollup_days r JOIN retention_settings s ON s.tenant_id=r.tenant_id SET r.finalized_at=UTC_TIMESTAMP(6) WHERE r.dirty=FALSE AND r.calculated_revision=r.input_revision AND r.calculation_version=? AND r.finalized_at IS NULL AND r.day<UTC_DATE()-INTERVAL (s.late_arrival_days+2) DAY'.($tenant===null?'':' AND r.tenant_id=?'),$tenant===null?[ProductivityWorker::VERSION]:[ProductivityWorker::VERSION,Util::bin($tenant)]);
    echo Util::json((new ProductivityWorker($db))->run($tenant,(int)($options['max-days']??5),(int)($options['budget-seconds']??20))).PHP_EOL;
} catch (Throwable $e) { fwrite(STDERR,'productivity_failed: '.get_class($e).PHP_EOL); exit(1); }
