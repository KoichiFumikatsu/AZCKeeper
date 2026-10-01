<?php
declare(strict_types=1);
use Keeper\{Database,ProductivityWorker,Util};

// Regresion (2026-10-01): editar la ficha de una persona a mitad de dia crea una asignacion nueva; el total del dia
// se atribuia a la vieja Y a la nueva y el "pulso de la semana" mostraba el doble de horas.
function daySplitTests(Database $db): void
{
    $before = $GLOBALS['checks'];
    $u = secondTenant($db); $tenant = $u['tenant_id'];
    for ($w = 1; $w <= 7; $w++) { $db->run('INSERT INTO schedule_days (tenant_id,schedule_id,weekday) VALUES (?,?,?)', [$tenant, $u['schedule_id'], $w]); }
    $token = enroll($db, $u, keypair()); $device = Util::bin($token['device_id']);
    $day = (new DateTimeImmutable('yesterday', new DateTimeZone('America/Bogota')))->format('Y-m-d');
    $assignment = $db->one('SELECT id FROM device_assignments WHERE tenant_id=? AND device_id=? ORDER BY starts_at DESC LIMIT 1', [$tenant, $device])['id'];
    $db->run('UPDATE device_assignments SET starts_at=? - INTERVAL 10 DAY WHERE tenant_id=? AND device_id=?', [$day, $tenant, $device]);
    $db->run('UPDATE user_assignments SET starts_at=? - INTERVAL 10 DAY WHERE tenant_id=? AND user_id=?', [$day, $tenant, $u['id']]);
    $db->run('INSERT INTO activity_snapshots (tenant_id,device_id,day,snapshot_id,sequence,user_id,device_assignment_id,active_seconds,idle_seconds,body_hash,received_at) VALUES (?,?,?,?,1,?,?,3600,600,?,UTC_TIMESTAMP(6))',
        [$tenant, $device, $day, Util::bin(Util::uuid()), $u['id'], $assignment, random_bytes(32)]);
    // A las 18:00 de ese dia se edito la ficha: la asignacion vieja termina y empieza otra.
    $cut = (new DateTimeImmutable("$day 18:00", new DateTimeZone('America/Bogota')))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    $old = $db->one('SELECT * FROM user_assignments WHERE tenant_id=? AND user_id=? AND ends_at IS NULL', [$tenant, $u['id']]);
    $db->run('UPDATE user_assignments SET ends_at=? WHERE tenant_id=? AND id=?', [$cut, $tenant, $old['id']]);
    $db->run('INSERT INTO user_assignments (tenant_id,id,user_id,firm_id,site_id,area_id,position_id,schedule_id,starts_at) VALUES (?,?,?,?,?,?,?,?,?)',
        [$tenant, Util::bin(Util::uuid()), $u['id'], $old['firm_id'], $old['site_id'], $old['area_id'], $old['position_id'], $old['schedule_id'], $cut]);
    $db->run('INSERT INTO rollup_days (tenant_id,day) VALUES (?,?) ON DUPLICATE KEY UPDATE dirty=TRUE,input_revision=input_revision+1,finalized_at=NULL', [$tenant, $day]);
    (new ProductivityWorker($db))->run(Util::id($tenant), 5, 20);
    $rows = $db->run('SELECT active_seconds FROM day_summary WHERE tenant_id=? AND day=? AND user_id=?', [$tenant, $day, $u['id']])->fetchAll();
    check(count($rows) === 1 && (int) $rows[0]['active_seconds'] === 3600, 'day counted once despite mid-day reassignment ' . json_encode(array_column($rows, 'active_seconds')));
    echo 'PASS day split (' . ($GLOBALS['checks'] - $before) . " assertions)\n";
}
