<?php
declare(strict_types=1);
use Keeper\{Database,DeviceLocations,Util};

function locationVolumeTest(Database $db, string $tenant, string $device, string $user): void
{
    $start=microtime(true);
    $db->run('INSERT INTO device_locations (tenant_id,id,device_id,user_id,at,network_type,result,in_work_hours,body_hash)
        WITH location_digits AS (SELECT 0 n UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4
          UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9)
        SELECT ?,UNHEX(LPAD(HEX(n+1),32,\'0\')),?,?,UTC_TIMESTAMP(6)-INTERVAL MOD(n*13,7776000) SECOND,\'unknown\',\'sin_dato\',TRUE,UNHEX(REPEAT(\'00\',32))
        FROM (SELECT a.n+10*b.n+100*c.n+1000*d.n+10000*e.n+100000*f.n+1000000*g.n n
          FROM location_digits a CROSS JOIN location_digits b CROSS JOIN location_digits c
          CROSS JOIN location_digits d CROSS JOIN location_digits e CROSS JOIN location_digits f
          CROSS JOIN location_digits g WHERE g.n<2) numbers',[$tenant,$device,$user]);
    echo 'VOLUME seeded 2000000 rows in '.round(microtime(true)-$start,2)."s\n";
    foreach (['user_id'=>$user,'device_id'=>$device] as $column=>$id) {
        $sql="SELECT * FROM device_locations WHERE tenant_id=? AND $column=? AND at>=UTC_DATE()-INTERVAL 1 DAY AND at<UTC_DATE() AND in_work_hours=TRUE ORDER BY at,device_id,id LIMIT 501";
        $plan=$db->run('EXPLAIN '.$sql,[$tenant,$id])->fetchAll();
        check($plan[0]['key']!==null && !in_array($plan[0]['type'],['ALL','index'],true),'historial usa rango indexado con dos millones');
        $start=microtime(true); $rows=$db->run($sql,[$tenant,$id])->fetchAll();
        check(count($rows)===501,'pagina completa con dos millones');
        echo 'VOLUME '.$column.' history '.round((microtime(true)-$start)*1000,1).'ms plan='.Util::json($plan)."\n";
    }
    $db->run('INSERT INTO location_reclassification (tenant_id,until_at) VALUES (?,UTC_TIMESTAMP(6))',[$tenant]);
    $start=microtime(true);
    check((new DeviceLocations($db))->reclassify($tenant,500)===500,'recalculo sigue acotado con dos millones');
    check($db->one('SELECT cursor_at FROM location_reclassification WHERE tenant_id=?',[$tenant])['cursor_at']!==null,'volumen conserva cursor');
    echo 'VOLUME reclassify500 '.round((microtime(true)-$start)*1000,1)."ms\n";
}
