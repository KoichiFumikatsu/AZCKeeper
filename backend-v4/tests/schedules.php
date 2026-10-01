<?php
declare(strict_types=1);
use Keeper\{Database,Util,ActivityCalendar};

// Horarios con franja propia por dia (sabado medio dia) y almuerzo: API, calendario y politica del agente.
function scheduleDayHoursTests(Database $db): void
{
    $before = $GLOBALS['checks'];
    $u = secondTenant($db); $tenant = $u['tenant_id']; $uid = Util::id($u['id']);
    $platform = adminFixture($db, [], [], true); $platform['tenant'] = $tenant;
    $body = ['name' => 'L-V 8-5 y sábado 8-12', 'timezone' => 'America/Bogota', 'days' => [1, 2, 3, 4, 5, 6], 'start_local' => '08:00', 'end_local' => '17:00',
        'lunch_start_local' => '12:00', 'lunch_end_local' => '13:00',
        'day_hours' => [['weekday' => 6, 'start_local' => '08:00', 'end_local' => '12:00', 'lunch_start_local' => null, 'lunch_end_local' => null]]];
    $s = expect(adminRequest($platform, 'POST', '/schedules', $body), 201, 'create schedule with day hours');
    check($s['lunch_start_local'] === '12:00' && count($s['day_hours']) === 1 && $s['day_hours'][0]['end_local'] === '12:00' && $s['day_hours'][0]['lunch_start_local'] === null, 'day hours and lunch stored');
    check(adminRequest($platform, 'POST', '/schedules', ['day_hours' => [['weekday' => 7, 'start_local' => '08:00', 'end_local' => '12:00', 'lunch_start_local' => null, 'lunch_end_local' => null]]] + $body)[1]['detail'] === 'invalid_day_hours', 'day hours only on working days');
    check(adminRequest($platform, 'POST', '/schedules', ['lunch_start_local' => '13:00', 'lunch_end_local' => '12:00'] + $body)[1]['detail'] === 'invalid_lunch', 'lunch order validated');
    // PUT sin los campos nuevos (cliente viejo) conserva almuerzo y franjas.
    $plain = $body; unset($plain['lunch_start_local'], $plain['lunch_end_local'], $plain['day_hours']); $plain['name'] = 'Renombrado';
    $kept = expect(adminRequest($platform, 'PUT', '/schedules/' . $s['id'], $plain, ['if-match' => '"' . $s['version'] . '"']), 200, 'put without new fields');
    check($kept['lunch_end_local'] === '13:00' && count($kept['day_hours']) === 1, 'lunch and day hours preserved');

    // Calendario: el sabado espera 4 horas y empieza a las 08:00; el viernes 9 horas.
    $sid = Util::bin($s['id']);
    ActivityCalendar::create($db, $tenant, '2026-10-02', '2026-10-05');
    $cal = []; foreach ($db->run('SELECT day,expected_seconds,start_local FROM activity_calendar WHERE schedule_id=?', [$sid])->fetchAll() as $r) { $cal[$r['day']] = $r; }
    $db->run('DROP TEMPORARY TABLE IF EXISTS activity_calendar');
    check((int) $cal['2026-10-02']['expected_seconds'] === 9 * 3600 && (int) $cal['2026-10-03']['expected_seconds'] === 4 * 3600 && (int) $cal['2026-10-04']['expected_seconds'] === 0, 'calendar uses the day hours');

    // Politica: almuerzo siempre; franjas por dia solo para agentes 4.0.13+ (los viejos rechazan campos desconocidos).
    $db->run('UPDATE users SET schedule_id=? WHERE tenant_id=? AND id=?', [$sid, $tenant, $u['id']]);
    $db->run('UPDATE user_assignments SET schedule_id=? WHERE tenant_id=? AND user_id=?', [$sid, $tenant, $u['id']]);
    $token = enroll($db, $u, keypair()); $device = Util::bin($token['device_id']);
    $work = function () use ($platform, $uid, $s): object {
        $p = expect(adminRequest($platform, 'GET', '/users/' . $uid . '/policies'), 200, 'effective policies');
        foreach ($p['devices'][0]['policy']['schedules'] as $x) { if ($x['id'] === $s['id']) { return (object) $x; } }
        return (object) [];
    };
    $db->run("UPDATE devices SET agent_version='4.0.12' WHERE tenant_id=? AND id=?", [$tenant, $device]);
    $old = $work();
    check(($old->lunch_start_local ?? null) === '12:00' && !property_exists($old, 'day_hours'), 'old agent gets lunch but no day hours');
    $db->run("UPDATE devices SET agent_version='4.0.13' WHERE tenant_id=? AND id=?", [$tenant, $device]);
    $new = $work();
    check(isset($new->day_hours) && $new->day_hours[0]['weekday'] === 6, 'new agent gets day hours');
    echo 'PASS schedule day hours (' . ($GLOBALS['checks'] - $before) . " assertions)\n";
}
