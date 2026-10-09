<?php
declare(strict_types=1);
use Keeper\{Database,ProductivityWorker,Util};

// Agente 4.0.22: entrada simulada (GetLastInputInfo sin teclado/raton fisico) y tiempo en RDP. Dos equipos de la misma
// persona: uno lo reporta y otro es un agente anterior (NULL). El dia suma lo reportado; un dia sin ningun reporte queda NULL.
function simulatedInputTests(Database $db): void
{
    $before = $GLOBALS['checks'];
    $u = secondTenant($db); $tenant = $u['tenant_id']; $uid = Util::id($u['id']);
    $platform = sharedPlatform($db); $platform['tenant'] = $tenant;
    for ($w = 1; $w <= 7; $w++) { $db->run('INSERT INTO schedule_days (tenant_id,schedule_id,weekday) VALUES (?,?,?)', [$tenant, $u['schedule_id'], $w]); }
    $zone = new DateTimeZone('America/Bogota');
    $day = (new DateTimeImmutable('yesterday', $zone))->format('Y-m-d');
    $older = (new DateTimeImmutable('yesterday', $zone))->modify('-1 day')->format('Y-m-d');
    $devices = [];
    foreach ([0, 1] as $i) {
        $token = enroll($db, $u, keypair()); $device = Util::bin($token['device_id']);
        $db->run('UPDATE device_assignments SET starts_at=? - INTERVAL 10 DAY WHERE tenant_id=? AND device_id=?', [$older, $tenant, $device]);
        $devices[] = [$device, $db->one('SELECT id FROM device_assignments WHERE tenant_id=? AND device_id=? ORDER BY starts_at DESC LIMIT 1', [$tenant, $device])['id']];
    }
    $db->run('UPDATE user_assignments SET starts_at=? - INTERVAL 10 DAY WHERE tenant_id=? AND user_id=?', [$older, $tenant, $u['id']]);
    $snapshot = function (array $device, string $day, ?int $simulated, ?int $remote) use ($db, $tenant, $u): void {
        $db->run('INSERT INTO activity_snapshots (tenant_id,device_id,day,snapshot_id,sequence,user_id,device_assignment_id,active_seconds,idle_seconds,simulated_seconds,remote_seconds,body_hash,received_at) VALUES (?,?,?,?,1,?,?,3600,7200,?,?,?,UTC_TIMESTAMP(6))',
            [$tenant, $device[0], $day, Util::bin(Util::uuid()), $u['id'], $device[1], $simulated, $remote, random_bytes(32)]);
    };
    $snapshot($devices[0], $day, 2400, 600); $snapshot($devices[1], $day, null, null);
    $snapshot($devices[0], $older, null, null);
    foreach ([$day, $older] as $d) { $db->run('INSERT INTO rollup_days (tenant_id,day) VALUES (?,?) ON DUPLICATE KEY UPDATE dirty=TRUE,input_revision=input_revision+1,finalized_at=NULL', [$tenant, $d]); }
    (new ProductivityWorker($db))->run(Util::id($tenant), 5, 20);
    $row = fn (string $d) => $db->one('SELECT simulated_seconds,remote_seconds,idle_seconds FROM day_summary WHERE tenant_id=? AND day=? AND user_id=?', [$tenant, $d, $u['id']]);
    $r = $row($day);
    check((int) $r['simulated_seconds'] === 2400 && (int) $r['remote_seconds'] === 600 && (int) $r['idle_seconds'] === 14400, 'day_summary sums simulated/remote, older device NULL ignored ' . json_encode($r));
    $r = $row($older);
    check($r !== null && $r['simulated_seconds'] === null && $r['remote_seconds'] === null, 'day without any report keeps simulated NULL');
    $to = (new DateTimeImmutable($day))->modify('+1 day')->format('Y-m-d');
    $activity = expect(adminRequest($platform, 'GET', "/users/$uid/activity?from=$older&to=$to"), 200, 'activity report with simulated');
    $byDay = array_column($activity['data'], 'simulated_seconds', 'day');
    check(($byDay[$day] ?? null) === 2400 && array_key_exists($older, $byDay) && $byDay[$older] === null && $activity['totals']['simulated_seconds'] === 2400, 'person/day exposes simulated_seconds ' . json_encode([$byDay, $activity['totals']['simulated_seconds'] ?? 'missing']));
    $productivity = expect(adminRequest($platform, 'GET', "/reports/productivity?from=$older&to=$to&user_id=$uid"), 200, 'productivity with simulated');
    $series = array_column($productivity['series'], 'simulated_seconds', 'day');
    check(($series[$day] ?? null) === 2400 && array_key_exists($older, $series) && $series[$older] === null, 'productivity series exposes simulated_seconds ' . json_encode($series));
    echo 'PASS simulated input (' . ($GLOBALS['checks'] - $before) . " assertions)\n";
}
