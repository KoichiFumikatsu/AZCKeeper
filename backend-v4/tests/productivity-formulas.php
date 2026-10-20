<?php
declare(strict_types=1);
use Keeper\{ActivityCalendar,Database,ProductivityFormula,Util};

// Formulas de productividad, focus y distraccion (decision de Koichi 2026-10-09) sobre la jornada esperada J.
// Semana fija con horario lun-vie 08:00-17:00 y almuerzo 12:00-13:00 (Bogota): J de un dia normal = 8 h.
function productivityFormulaTests(Database $db): void
{
    $before = $GLOBALS['checks'];
    $u = secondTenant($db); $tenant = $u['tenant_id']; $uid = Util::id($u['id']);
    $platform = sharedPlatform($db); $platform['tenant'] = $tenant;
    $db->run("UPDATE schedules SET lunch_start_local='12:00:00',lunch_end_local='13:00:00' WHERE tenant_id=? AND id=?", [$tenant, $u['schedule_id']]);
    for ($w = 1; $w <= 5; $w++) { $db->run('INSERT INTO schedule_days (tenant_id,schedule_id,weekday) VALUES (?,?,?)', [$tenant, $u['schedule_id'], $w]); }
    $db->run("UPDATE user_assignments SET starts_at='2026-09-01' WHERE tenant_id=? AND user_id=?", [$tenant, $u['id']]);
    $ua = $db->one('SELECT id FROM user_assignments WHERE tenant_id=? AND user_id=?', [$tenant, $u['id']])['id'];
    $h = 3600;
    // Dia medido por ventanas: uso en ventanas, productivo, poco productivo y sin clasificar (el resto es neutral).
    $summary = function (string $day, int $window, int $productive, int $unproductive, int $unclassified) use ($db, $tenant, $u, $ua): void {
        $db->run("INSERT INTO day_summary (tenant_id,day,user_id,user_assignment_id,active_seconds,idle_seconds,productive_seconds,expected_seconds,coverage_percent,productivity_percent,calculation_version,timezone,calculated_at,data_through,window_active_seconds,unproductive_seconds,unclassified_seconds)
            VALUES (?,?,?,?,?,0,?,0,0,NULL,'formula-test','America/Bogota',UTC_TIMESTAMP(6),UTC_TIMESTAMP(6),?,?,?)", [$tenant, $day, $u['id'], $ua, $window, $productive, $window, $unproductive, $unclassified]);
    };
    $absence = function (string $from, string $to) use ($db, $tenant, $u): void {
        $db->run("INSERT INTO absences (tenant_id,id,user_id,kind,starts_at,ends_at,all_day,status) VALUES (?,?,?,'permiso',?,?,0,'approved')", [$tenant, Util::bin(Util::uuid()), $u['id'], $from, $to]);
    };
    $summary('2026-09-07', 6 * $h, 4 * $h, $h, 1800);          // lunes: 4 h productivas, 1 h distraccion, 30 min neutral + 30 min sin clasificar
    $db->run("INSERT INTO holidays (tenant_id,id,day,name) VALUES (?,?,'2026-09-08','Festivo de prueba')", [$tenant, Util::bin(Util::uuid())]);
    $summary('2026-09-08', 2 * $h, 2 * $h, 0, 0);               // martes festivo: trabaja 2 h que no cuentan
    $absence('2026-09-09 13:00:00', '2026-09-09 22:00:00');     // miercoles: ausencia de dia completo (08:00-17:00 Bogota)
    $absence('2026-09-10 13:00:00', '2026-09-10 15:00:00');     // jueves: ausencia parcial 08:00-10:00 => J = 6 h
    $summary('2026-09-10', 3 * $h, 3 * $h, 0, 0);
    // viernes 11: laborable sin ninguna fila de actividad => cuenta con 0
    $summary('2026-09-12', $h, $h, 0, 0);                        // sabado: no laborable, su hora no cuenta
    $range = fn (string $from, string $to) => "?from=$from&to=$to";

    $week = expect(adminRequest($platform, 'GET', "/users/$uid/activity" . $range('2026-09-07', '2026-09-14')), 200, 'week activity');
    $byDay = array_column($week['data'], null, 'day');
    check($byDay['2026-09-07']['workday_expected_seconds'] === 8 * $h, 'lunch is outside the expected workday (9 h - 1 h)');
    check($byDay['2026-09-07']['workday_neutral_seconds'] === $h && $byDay['2026-09-07']['productivity_percent'] == 50 && $byDay['2026-09-07']['focus_score'] == 62.5 && $byDay['2026-09-07']['distraction_percent'] == 37.5,
        'unclassified counts as neutral; day formulas ' . json_encode(array_intersect_key($byDay['2026-09-07'], array_flip(['workday_neutral_seconds', 'productivity_percent', 'focus_score', 'distraction_percent']))));
    check($byDay['2026-09-08']['workday_expected_seconds'] === 0 && $byDay['2026-09-08']['workday_productive_seconds'] === 0 && $byDay['2026-09-08']['productivity_percent'] === null && $byDay['2026-09-08']['status'] === 'no_laborable', 'holiday is out of the period, even with activity');
    check($byDay['2026-09-09']['workday_expected_seconds'] === 0 && $byDay['2026-09-09']['focus_score'] === null, 'full-day absence is out of the period');
    check($byDay['2026-09-10']['workday_expected_seconds'] === 6 * $h && $byDay['2026-09-10']['productivity_percent'] == 50, 'partial absence subtracts its hours from the workday');
    check($byDay['2026-09-11']['workday_expected_seconds'] === 8 * $h && $byDay['2026-09-11']['productivity_percent'] == 0 && $byDay['2026-09-11']['focus_score'] == 0 && $byDay['2026-09-11']['distraction_percent'] == 100, 'workday without activity is 0, not missing');
    check($byDay['2026-09-12']['workday_expected_seconds'] === 0 && $byDay['2026-09-12']['productivity_percent'] === null && $byDay['2026-09-13']['workday_expected_seconds'] === 0, 'weekend is out of the period');
    // Periodo: J = 8 + 6 + 8 = 22 h, P = 4 + 3 = 7 h, N = 1 h. Promediar los dias daria 33,33 % (50, 50, 0).
    $t = $week['totals'];
    check($t['workday_expected_seconds'] === 22 * $h && $t['workday_productive_seconds'] === 7 * $h && $t['workday_neutral_seconds'] === $h, 'period sums workday parts ' . json_encode(array_intersect_key($t, array_flip(ProductivityFormula::FIELDS))));
    check($t['productivity_percent'] == 31.82 && $t['focus_score'] == 36.36 && $t['distraction_percent'] == 63.64, 'period is sum of times / sum of workdays, not a mean of days ' . json_encode([$t['productivity_percent'], $t['focus_score'], $t['distraction_percent']]));
    check($t['productive_seconds'] === 10 * $h, 'raw productive time still includes non-working days');
    // Mismo periodo por /reports/people y /reports/productivity (tabla de hechos compartida con la API externa).
    $people = expect(adminRequest($platform, 'GET', '/reports/people' . $range('2026-09-07', '2026-09-14')), 200, 'people report');
    $mine = array_values(array_filter($people['data'], fn ($p) => $p['user_id'] === $uid))[0]['totals'];
    check($mine['productivity_percent'] == 31.82 && $mine['focus_score'] == 36.36, 'people report uses the same formulas');
    $report = expect(adminRequest($platform, 'GET', '/reports/productivity' . $range('2026-09-07', '2026-09-14') . '&user_id=' . $uid), 200, 'productivity report');
    $points = array_column($report['series'], null, 'day');
    check(count($report['series']) === 7 && $points['2026-09-11']['productivity_percent'] == 0 && $points['2026-09-08']['productivity_percent'] === null, 'productivity series has every day, workday without activity at 0');
    check($report['workday_expected_seconds'] === 22 * $h && $report['productivity_percent'] == 31.82 && $report['focus_score'] == 36.36 && $report['distraction_percent'] == 63.64, 'productivity report period totals ' . json_encode([$report['productivity_percent'], $report['focus_score']]));
    // Todo el periodo sin jornada (fin de semana) => null: «Sin jornada en el periodo».
    $weekend = expect(adminRequest($platform, 'GET', "/users/$uid/activity" . $range('2026-09-12', '2026-09-14')), 200, 'weekend activity');
    check($weekend['totals']['workday_expected_seconds'] === 0 && $weekend['totals']['productivity_percent'] === null && $weekend['totals']['focus_score'] === null && $weekend['totals']['distraction_percent'] === null, 'no workday in the period => null');
    $weekendReport = expect(adminRequest($platform, 'GET', '/reports/productivity' . $range('2026-09-12', '2026-09-14') . '&user_id=' . $uid), 200, 'weekend report');
    check($weekendReport['productivity_percent'] === null && $weekendReport['focus_score'] === null, 'report without workday => null');
    // Tope: trabajar mas que la jornada deja 100 %, nunca mas.
    $summary('2026-09-14', 10 * $h, 10 * $h, 0, 0);
    $long = expect(adminRequest($platform, 'GET', "/users/$uid/activity" . $range('2026-09-14', '2026-09-15')), 200, 'long day');
    check($long['totals']['productivity_percent'] == 100 && $long['totals']['focus_score'] == 100 && $long['totals']['distraction_percent'] == 0, 'percentages capped at 100');
    // Ausencia parcial que cruza el almuerzo (11:00-14:00): resta 2 h, no 3 (el almuerzo ya estaba fuera).
    $absence('2026-09-16 16:00:00', '2026-09-16 19:00:00');
    $wed = expect(adminRequest($platform, 'GET', "/users/$uid/activity" . $range('2026-09-16', '2026-09-17')), 200, 'absence over lunch');
    check($wed['totals']['workday_expected_seconds'] === 6 * $h, 'absence over lunch subtracts lunch once ' . $wed['totals']['workday_expected_seconds']);
    // Dia importado de K3: sin medicion por ventanas; N = uso - productivo - distraccion de focus_daily.
    $db->run("INSERT INTO day_summary (tenant_id,day,user_id,user_assignment_id,active_seconds,idle_seconds,productive_seconds,expected_seconds,coverage_percent,productivity_percent,calculation_version,timezone,calculated_at,data_through)
        VALUES (?,'2026-09-15',?,?,?,0,?,0,0,NULL,'k3-import-test','America/Bogota',UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))", [$tenant, $u['id'], $ua, 4 * $h, 2 * $h]);
    $db->run("INSERT INTO focus_daily (tenant_id,day,user_id,user_assignment_id,focus_seconds,focus_score,distraction_seconds,calculation_version,calculated_at,data_through) VALUES (?,'2026-09-15',?,?,0,NULL,?,'k3-import-test',UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))", [$tenant, $u['id'], $ua, $h]);
    $k3 = expect(adminRequest($platform, 'GET', "/users/$uid/activity" . $range('2026-09-15', '2026-09-16')), 200, 'K3 day');
    check($k3['totals']['workday_neutral_seconds'] === $h && $k3['totals']['productivity_percent'] == 25 && $k3['totals']['focus_score'] == 37.5, 'K3 imported day uses total use as its base ' . json_encode([$k3['totals']['workday_neutral_seconds'], $k3['totals']['productivity_percent'], $k3['totals']['focus_score']]));
    // Hoy pesa solo hasta ahora; un dia laborable futuro tiene J = 0.
    $next = new DateTimeImmutable('tomorrow', new DateTimeZone('America/Bogota'));
    while ((int) $next->format('N') > 5) { $next = $next->modify('+1 day'); }
    ActivityCalendar::create($db, $tenant, $next->format('Y-m-d'), $next->modify('+1 day')->format('Y-m-d'));
    try {
        $future = $db->one('SELECT ' . ActivityCalendar::expected('a') . ' expected,' . ActivityCalendar::workday('a') . ' workday FROM user_assignments a JOIN activity_calendar c ON c.schedule_id=a.schedule_id WHERE a.tenant_id=? AND a.user_id=?', [$tenant, $u['id']]);
    } finally { $db->run('DROP TEMPORARY TABLE IF EXISTS activity_calendar'); }
    check((int) $future['expected'] === 9 * $h && (int) $future['workday'] === 0, 'future workday has no expected time yet');
    echo 'PASS productivity formulas (' . ($GLOBALS['checks'] - $before) . " assertions)\n";
}
