<?php
declare(strict_types=1);
use Keeper\{Database,ProductivityWorker,Util};

// Agente 4.0.24 / migracion 0056: tiempo "Bloqueado" aparte de en uso e inactivo, y categoria "system" (Keeper, pantalla de
// bloqueo) fuera de todas las medidas de ventanas. Dia armado: Word 15 min, Keeper.Session 1 min, Word 15 min, LockApp 5 min.
// Sin la categoria, Keeper partia la racha (dos bloques de 15 < 25 min: focus 0) y contaba como uso sin clasificar. Una ventana
// de sistema larga (mas de 2 min) si corta la racha, como cualquier hueco sin uso: bloqueado no es trabajo.
function lockedSystemTests(Database $db): void
{
    $before = $GLOBALS['checks'];
    $u = secondTenant($db); $tenant = $u['tenant_id']; $uid = Util::id($u['id']);
    $platform = sharedPlatform($db); $company = $platform; $company['tenant'] = $tenant;
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
    $ua = $db->one('SELECT id FROM user_assignments WHERE tenant_id=? AND user_id=? AND ends_at IS NULL', [$tenant, $u['id']])['id'];

    $t = (new DateTimeImmutable("$day 09:00", $zone))->setTimezone(new DateTimeZone('UTC'));
    $episode = function (string $process, int $minutes) use ($db, $tenant, $devices, $u, $ua, &$t): void {
        $end = $t->modify("+$minutes minutes");
        $db->run('INSERT INTO episodes (tenant_id,device_id,event_id,user_id,device_assignment_id,user_assignment_id,event_date,started_at,ended_at,process_name,active_seconds,idle_seconds,call_seconds,body_hash) VALUES (?,?,?,?,?,?,?,?,?,?,?,0,0,?)',
            [$tenant, $devices[0][0], Util::bin(Util::uuid()), $u['id'], $devices[0][1], $ua, $t->format('Y-m-d'), $t->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s'), $process, $minutes * 60, random_bytes(32)]);
        $t = $end;
    };
    $episode('WINWORD', 15); $episode('Keeper.Session', 1); $episode('WINWORD', 15); $episode('LockApp', 5);
    $snapshot = function (array $device, string $day, ?int $locked, ?int $workLocked) use ($db, $tenant, $u): void {
        $db->run('INSERT INTO activity_snapshots (tenant_id,device_id,day,snapshot_id,sequence,user_id,device_assignment_id,active_seconds,idle_seconds,locked_seconds,work_hours_locked_seconds,lunch_locked_seconds,after_hours_locked_seconds,body_hash,received_at) VALUES (?,?,?,?,1,?,?,3600,7200,?,?,?,?,?,UTC_TIMESTAMP(6))',
            [$tenant, $device[0], $day, Util::bin(Util::uuid()), $u['id'], $device[1], $locked, $workLocked, $locked === null ? null : 0, $locked === null ? null : $locked - $workLocked, random_bytes(32)]);
    };
    $snapshot($devices[0], $day, 5400, 3600); $snapshot($devices[1], $day, null, null);
    $snapshot($devices[0], $older, null, null);
    // La empresa no puede volver productivo lo que la base marca como sistema, ni marcar algo como sistema.
    expect(adminRequest($company, 'PUT', '/app-classification', ['key' => 'Keeper.Session', 'category' => 'productive']), 200, 'company classifies Keeper.Session');
    expect(adminRequest($company, 'PUT', '/app-classification', ['key' => 'WINWORD', 'category' => 'system']), 422, 'company cannot use system');
    foreach ([$day, $older] as $d) { $db->run('INSERT INTO rollup_days (tenant_id,day) VALUES (?,?) ON DUPLICATE KEY UPDATE dirty=TRUE,input_revision=input_revision+1,finalized_at=NULL', [$tenant, $d]); }
    (new ProductivityWorker($db))->run(Util::id($tenant), 40, 60);

    $d = $db->one('SELECT * FROM day_summary WHERE tenant_id=? AND day=? AND user_id=?', [$tenant, $day, $u['id']]);
    check((int) $d['window_active_seconds'] === 1800 && (int) $d['productive_seconds'] === 1800 && (int) $d['unclassified_seconds'] === 0 && (int) $d['unproductive_seconds'] === 0,
        'system windows out of window/productive/unclassified ' . json_encode([$d['window_active_seconds'], $d['productive_seconds'], $d['unclassified_seconds']]));
    check((int) $d['focus_block_seconds'] === 1800, 'Keeper between two Word runs does not break the focus block ' . $d['focus_block_seconds']);
    check((int) $d['locked_seconds'] === 5400 && (int) $d['work_hours_locked_seconds'] === 3600 && (int) $d['lunch_locked_seconds'] === 0 && (int) $d['after_hours_locked_seconds'] === 1800 && (int) $d['idle_seconds'] === 14400,
        'day_summary sums locked and its split, older device NULL ignored ' . json_encode([$d['locked_seconds'], $d['work_hours_locked_seconds'], $d['after_hours_locked_seconds']]));
    $f = $db->one('SELECT context_switches FROM focus_daily WHERE tenant_id=? AND day=? AND user_id=?', [$tenant, $day, $u['id']]);
    check((int) $f['context_switches'] === 0, 'Word -> Keeper -> Word is not a context switch ' . $f['context_switches']);
    check($db->one("SELECT COUNT(*) n FROM episode_daily WHERE tenant_id=? AND day=? AND process_name IN ('Keeper.Session','LockApp')", [$tenant, $day])['n'] == 0, 'system windows not in per-app daily usage');
    check($db->one('SELECT locked_seconds FROM day_summary WHERE tenant_id=? AND day=? AND user_id=?', [$tenant, $older, $u['id']])['locked_seconds'] === null, 'day without any report keeps locked NULL');

    $to = (new DateTimeImmutable($day))->modify('+1 day')->format('Y-m-d');
    $activity = expect(adminRequest($company, 'GET', "/users/$uid/activity?from=$older&to=$to"), 200, 'activity report with locked');
    $byDay = array_column($activity['data'], 'locked_seconds', 'day');
    check(($byDay[$day] ?? null) === 5400 && array_key_exists($older, $byDay) && $byDay[$older] === null && $activity['totals']['locked_seconds'] === 5400, 'person/day exposes locked_seconds ' . json_encode([$byDay, $activity['totals']['locked_seconds'] ?? 'missing']));
    $row = array_values(array_filter($activity['data'], fn ($r) => $r['day'] === $day))[0];
    check($row['productivity_percent'] == 100 && $row['unclassified_percent'] == 0, 'Keeper.Session counts neither in productivity nor in unclassified % ' . json_encode([$row['productivity_percent'], $row['unclassified_percent']]));
    $people = expect(adminRequest($company, 'GET', "/reports/people?from=$older&to=$to"), 200, 'people report with locked');
    $mine = array_values(array_filter($people['data'], fn ($p) => $p['user_id'] === $uid))[0] ?? null;
    check(($mine['totals']['locked_seconds'] ?? null) === 5400, 'people report exposes locked_seconds');
    check($row['work_hours_active_seconds']===null && $activity['totals']['work_hours_active_seconds']===null,'unmeasured time bands stay null');
    $db->run('UPDATE day_summary SET work_hours_active_seconds=6000,lunch_active_seconds=600,after_hours_active_seconds=600,work_hours_idle_seconds=12000,lunch_idle_seconds=1200,after_hours_idle_seconds=1200 WHERE tenant_id=? AND user_id=? AND day=?',[$tenant,$u['id'],$day]);
    $split=expect(adminRequest($company,'GET',"/users/$uid/activity?from=$day&to=$to"),200,'person measured time bands');
    check($split['totals']['work_hours_active_seconds']===6000 && $split['totals']['lunch_active_seconds']===600 && $split['totals']['after_hours_active_seconds']===600,'time bands total exposed');
    check($split['data'][0]['work_hours_active_seconds']===6000 && $split['data'][0]['first_activity']!==null && $split['data'][0]['last_activity']!==null,'daily CSV fields exposed');
    $ranking=expect(adminRequest($company,'GET',"/reports/people?from=$day&to=$to&user_id=$uid"),200,'ranking supports person filter');
    check($ranking['data'][0]['totals']['days_with_activity']===1 && $ranking['data'][0]['totals']['work_hours_active_seconds']===6000,'ranking measured days and time bands');
    $dashboard=expect(adminRequest($company,'GET',"/dashboard?from=$day&to=$to&user_id=$uid"),200,'dashboard measured activity');
    check($dashboard['active_seconds']===7200 && $dashboard['weekly_output'][0]['active_seconds']===7200 && $dashboard['total_members']===1,'dashboard aggregates multiple devices once per person');
    $productivity = expect(adminRequest($company, 'GET', "/reports/productivity?from=$older&to=$to&user_id=$uid"), 200, 'productivity with locked');
    $series = array_column($productivity['series'], 'locked_seconds', 'day');
    check(($series[$day] ?? null) === 5400 && array_key_exists($older, $series) && $series[$older] === null, 'productivity series exposes locked_seconds ' . json_encode($series));
    $apps = expect(adminRequest($company, 'GET', "/reports/apps?from=$day&to=$to"), 200, 'apps report without system');
    check(!array_intersect(array_column($apps['data'], 'process_name'), ['Keeper.Session', 'LockApp']), 'apps report leaves system windows out');
    $overview = expect(adminRequest($company, 'GET', '/app-classification'), 200, 'classification overview');
    $keeper = array_values(array_filter($overview['apps'], fn ($a) => $a['key'] === 'Keeper.Session'))[0] ?? null;
    check(($keeper['base_category'] ?? null) === 'system' && $keeper['company_category'] === 'productive', 'overview shows system base');
    echo 'PASS locked/system (' . ($GLOBALS['checks'] - $before) . " assertions)\n";
}
