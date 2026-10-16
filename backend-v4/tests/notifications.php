<?php
declare(strict_types=1);
use Keeper\{AccessScope,ActivityCalendar,ComplianceWorker,Database,Util};

// Icono de notificaciones del panel (docs/design/2026-10-15-encargo-notificaciones.md): conteo de pendientes, baja al
// revisar y al abrir, persistencia por cuenta, alcance (coordinador de una sede), funciones no compradas ocultas y resumen
// del dia de llegadas tarde, sin conectarse y ausencias con la misma regla de puntualidad que /reports/presence.
function notificationTests(Database $db): void
{
    $before = $GLOBALS['checks'];
    $o = accessOrg($db); $t = $o['tenant'];
    $db->run("UPDATE tenants SET timezone='America/Bogota' WHERE tenant_id=?", [$t]);
    $zone = new DateTimeZone('America/Bogota');
    $today = (new DateTimeImmutable('now', $zone))->format('Y-m-d');
    $yday = (new DateTimeImmutable($today))->modify('-1 day')->format('Y-m-d');
    $tomorrow = (new DateTimeImmutable($today))->modify('+1 day')->format('Y-m-d');
    $utc = fn (string $local) => (new DateTimeImmutable($local, $zone))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    $ua = fn (string $key) => $db->one('SELECT id FROM user_assignments WHERE tenant_id=? AND user_id=?', [$t, $o[$key]['id']])['id'];
    $device = fn (string $key) => $db->one('SELECT id FROM devices WHERE tenant_id=? AND user_id=?', [$t, $o[$key]['id']])['id'];

    // Alertas de doble empleo: hoy (cuentan), una revisada y una de hace 40 dias (no cuentan).
    $alert = function (string $key, string $day, bool $reviewed = false) use ($db, $t, $o): string {
        $id = Util::bin(Util::uuid());
        $db->run("INSERT INTO dual_job_alerts (tenant_id,id,user_id,day,alert_type,severity,evidence,reviewed,source) VALUES (?,?,?,?,'remote_desktop','high','{}',?,'k3')", [$t, $id, $o[$key]['id'], $day, (int) $reviewed]);
        return Util::id($id);
    };
    $juanAlert = $alert('juan', $today); $medAlert = $alert('ag808', $today);
    $alert('juan', $today, true); $alert('juan', (new DateTimeImmutable($today))->modify('-40 days')->format('Y-m-d'));
    // Apps sospechosas de ayer: la de juan con el id que recalcularia el trabajador (para probar que conserva la revision).
    $app = Util::bin(Util::uuid());
    $db->run("INSERT INTO suspicious_apps (tenant_id,id,app_pattern,category,description,active) VALUES (?,?,'anydesk','remote_desktop','',TRUE)", [$t, $app]);
    $detection = function (string $key, string $process, ?string $id = null) use ($db, $t, $o, $ua, $device, $app, $yday, $utc): string {
        $id ??= Util::bin(Util::uuid());
        $db->run('INSERT INTO suspicious_app_detections (tenant_id,id,user_id,user_assignment_id,device_id,app_id,day,process_name,active_seconds,first_activity,last_activity) VALUES (?,?,?,?,?,?,?,?,600,?,?)',
            [$t, $id, $o[$key]['id'], $ua($key), $device($key), $app, $yday, $process, $utc("$yday 09:00:00"), $utc("$yday 09:10:00")]);
        return Util::id($id);
    };
    $juanDetection = $detection('juan', 'anydesk.exe', ComplianceWorker::id($t . $yday . $ua('juan') . $device('juan') . $app . 'anydesk.exe'));
    $medDetection = $detection('ag808', 'AnyDesk.exe');
    // Jornada de hoy: juan (Tequendama) y ag808 (Medellin) llegan 10:30 con horario 08:00 y margen 15 (llegada tarde).
    $late = function (string $user, string $assignment) use ($db, $t, $today, $utc): void {
        $db->run("INSERT INTO day_summary (tenant_id,day,user_id,user_assignment_id,active_seconds,idle_seconds,productive_seconds,expected_seconds,coverage_percent,productivity_percent,calculation_version,timezone,calculated_at,data_through,first_activity) VALUES (?,?,?,?,3600,0,3600,32400,10,100,'notifications-test','America/Bogota',UTC_TIMESTAMP(6),UTC_TIMESTAMP(6),?)",
            [$t, $today, $user, $assignment, $utc("$today 10:30:00")]);
    };
    $late($o['juan']['id'], $ua('juan')); $late($o['ag808']['id'], $ua('ag808'));
    // Ausencias aprobadas que tocan hoy: bl1 (Tequendama) y jur808 (Medellin); una cancelada no cuenta.
    foreach ([['bl1', 'approved'], ['jur808', 'approved'], ['zafra', 'cancelled']] as [$key, $status]) {
        $db->run("INSERT INTO absences (tenant_id,id,user_id,kind,starts_at,ends_at,all_day,status) VALUES (?,?,?,'vacaciones',?,?,TRUE,?)", [$t, Util::bin(Util::uuid()), $o[$key]['id'], $utc("$today 00:00:00"), $utc("$tomorrow 00:00:00"), $status]);
    }

    // Sin conectarse hoy: salvo juan y ag808 (horario 08:00), todos pasan a un horario que entra a las 00:00, asi su hora
    // limite (entrada + 15 min de margen) ya paso a cualquier hora del dia salvo entre 00:00 y 00:15 de Bogota ($nc = 0).
    $early = Util::bin(Util::uuid());
    $db->run("INSERT INTO schedules (tenant_id,id,name,timezone,start_local,end_local) VALUES (?,?,'madrugada','America/Bogota','00:00:00','17:00:00')", [$t, $early]);
    for ($w = 1; $w <= 7; $w++) { $db->run('INSERT INTO schedule_days (tenant_id,schedule_id,weekday) VALUES (?,?,?)', [$t, $early, $w]); }
    $nobody = Util::bin(Util::uuid()); $nobodyUa = Util::bin(Util::uuid()); $b = $o['base'];
    $db->run("INSERT INTO users (tenant_id,id,display_name,firm_id,site_id,area_id,position_id,schedule_id,company_id) VALUES (?,?,'sin conexion',?,?,?,?,?,?)", [$t, $nobody, $b['firm_id'], $o['teq'], $o['agentes'], $b['position_id'], $early, $o['azc']]);
    $db->run("INSERT INTO user_assignments (tenant_id,id,user_id,firm_id,site_id,area_id,position_id,schedule_id,company_id,starts_at) VALUES (?,?,?,?,?,?,?,?,?,'2026-09-01')", [$t, $nobodyUa, $nobody, $b['firm_id'], $o['teq'], $o['agentes'], $b['position_id'], $early, $o['azc']]);
    foreach (['users' => 'id', 'user_assignments' => 'user_id'] as $table => $col) { $db->run("UPDATE $table SET schedule_id=? WHERE tenant_id=? AND $col NOT IN (?,?)", [$early, $t, $o['juan']['id'], $o['ag808']['id']]); }
    $clock = (new DateTimeImmutable('now', $zone))->format('H:i');
    $nc = $clock >= '00:16' ? 1 : 0;
    // Sin conectarse (Medellin, Torre y la sede base): zafra, bl2, agTorre, coord2, base; en Tequendama: «sin conexion». bl1 y jur808 tienen ausencia.
    $ncAll = 6 * $nc; $ncTeq = $nc;
    $withNc = fn (array $counts, int $n) => $n ? array_slice($counts, 0, 3, true) + ['not_connected' => $n] + array_slice($counts, 3, null, true) : $counts;

    $platform = sharedPlatform($db); $platform['tenant'] = $t;
    accessResetPreloginQuota();
    $perms = ['cumplimiento.ver', 'cumplimiento.editar', 'reportes.ver', 'presencia.ver', 'usuarios.ver'];
    $coord = accessAccount($db, $o, 'juan', $perms, new AccessScope('filters', [], [], [$o['teq']]));
    $reportsOnly = accessAccount($db, $o, 'zafra', ['reportes.ver'], AccessScope::all());
    $get = fn (array $a, string $label) => expect(adminRequest($a, 'GET', '/notifications'), 200, $label);
    $seen = fn (array $a, string $label) => expect(adminRequest($a, 'POST', '/notifications/seen'), 200, $label);
    $byType = fn (array $n) => array_column($n['items'], null, 'type');
    $counts = fn (array $n) => array_map(fn ($i) => $i['count'], $byType($n));

    $all = $get($platform, 'platform notifications');
    check($all['day'] === $today && $all['seen_at'] === null, 'today in company zone, never opened');
    check($all['available'] === ['dual_job', 'suspicious_apps', 'late_arrivals', 'not_connected', 'absences'], 'every purchased section available');
    check($counts($all) === $withNc(['dual_job' => 2, 'suspicious_apps' => 2, 'late_arrivals' => 2, 'absences' => 2], $ncAll), 'platform counts: pending alerts, late, not connected, absent today');
    check($all['badge_count'] === 6 + $nc, 'badge = 4 pending alerts + unseen day summaries');
    if ($nc) {
        check($byType($all)['not_connected']['title'] === '6 sin conectarse hoy' && $byType($all)['not_connected']['group'] === 'today'
            && $byType($all)['not_connected']['href'] === "/reportes.php?desde=$today&hasta=$today&pestana=presencia&estado=sin_conectarse", 'not connected: separate day summary with filtered link');
    }
    check($byType($all)['late_arrivals']['title'] === '2 llegadas tarde hoy' && $byType($all)['absences']['title'] === '2 ausencias hoy', 'day summary, not one per person');
    check($byType($all)['late_arrivals']['group'] === 'today' && $byType($all)['dual_job']['group'] === 'alerts', 'grouped Alertas / Jornada de hoy');
    check($byType($all)['dual_job']['href'] === "/doble-empleo.php?reviewed=0&from=" . (new DateTimeImmutable($today))->modify('-29 days')->format('Y-m-d') . "&to=$tomorrow", 'dual job link filtered to pending');
    check($byType($all)['late_arrivals']['href'] === "/reportes.php?desde=$today&hasta=$today&pestana=presencia&estado=tarde" && $byType($all)['absences']['href'] === "/ausencias.php?dia=$today"
        && $byType($all)['suspicious_apps']['href'] === '/apps-sospechosas.php?reviewed=0', 'day links filtered');
    // La regla de llegada tarde es la de /reports/presence: mismas personas en estado «tarde».
    $presence = expect(adminRequest($platform, 'GET', '/reports/presence'), 200, 'presence today');
    check(count(array_filter($presence['data'], fn ($r) => $r['status'] === 'tarde')) === 2, 'same late rule as presence report');
    check(count(array_filter($presence['data'], fn ($r) => Keeper\ActivityReports::notConnected($r, time()))) === $ncAll, 'same not-connected rule as presence report');
    $deadlines = array_column($presence['data'], 'arrival_deadline_at', 'display_name');
    check($deadlines['juan'] === (new DateTimeImmutable("$today 08:15:00", $zone))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z')
        && $deadlines['sin conexion'] === (new DateTimeImmutable("$today 00:15:00", $zone))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z') && $deadlines['bl1'] === null, 'arrival deadline = start + company margin; null on an absence day');

    // Alcance: el coordinador de Tequendama no ve Medellin.
    $c = $get($coord, 'coordinator notifications');
    check($counts($c) === $withNc(['dual_job' => 1, 'suspicious_apps' => 1, 'late_arrivals' => 1, 'absences' => 1], $ncTeq) && $c['badge_count'] === 4 + $nc, 'coordinator sees only his site');
    if ($nc) { check($byType($c)['not_connected']['title'] === '1 sin conectarse hoy', 'coordinator: only his site not connected'); }
    check($byType($c)['late_arrivals']['title'] === '1 llegada tarde hoy' && $byType($c)['absences']['title'] === '1 ausencia hoy', 'singular titles');
    check(adminRequest($coord, 'PATCH', "/reports/dual-job-alerts/$medAlert", ['reviewed' => true])[0] === 404, 'cannot review an alert of another site');
    check(adminRequest($coord, 'PATCH', "/reports/suspicious-apps/$medDetection", ['reviewed' => true])[0] === 404, 'cannot review a detection of another site');
    check(array_column(expect(adminRequest($coord, 'GET', "/reports/suspicious-apps?from=$yday&to=$tomorrow"), 200, 'scoped detections')['data'], 'id') === [$juanDetection], 'detections list follows scope');

    // Revisar baja el numero.
    expect(adminRequest($coord, 'PATCH', "/reports/dual-job-alerts/$juanAlert", ['reviewed' => true]), 204, 'review dual job alert');
    $c = $get($coord, 'after dual review');
    check($c['badge_count'] === 3 + $nc && !isset($byType($c)['dual_job']) && in_array('dual_job', $c['available'], true), 'reviewing an alert lowers the badge');
    expect(adminRequest($coord, 'PATCH', "/reports/suspicious-apps/$juanDetection", ['reviewed' => true]), 204, 'review suspicious detection');
    expect(adminRequest($coord, 'PATCH', "/reports/suspicious-apps/$juanDetection", ['reviewed' => true]), 204, 'review is idempotent');
    check((int) $db->one("SELECT COUNT(*) n FROM audit_log WHERE tenant_id=? AND action='reviewSuspiciousDetection'", [$t])['n'] === 2, 'detection review audited');
    $c = $get($coord, 'after detection review');
    check($c['badge_count'] === 2 + $nc && !isset($byType($c)['suspicious_apps']), 'reviewing a detection lowers the badge');
    check(expect(adminRequest($coord, 'GET', "/reports/suspicious-apps?from=$yday&to=$tomorrow&reviewed=0"), 200, 'pending detections')['data'] === [], 'pending filter');
    $done = expect(adminRequest($coord, 'GET', "/reports/suspicious-apps?from=$yday&to=$tomorrow&reviewed=1"), 200, 'reviewed detections')['data'];
    check(count($done) === 1 && $done[0]['reviewed'] === true, 'reviewed flag exposed');

    // Abrir la lista baja el numero (los resumenes del dia quedan vistos) y se guarda por cuenta, no por sesion.
    $opened = $seen($coord, 'open the list');
    check($opened['badge_count'] === 0 && $opened['seen_at'] !== null && count($opened['items']) === 2 + $nc && !($byType($opened)['not_connected']['unseen'] ?? false) && !$byType($opened)['late_arrivals']['unseen'] && !$byType($opened)['absences']['unseen'], 'opening clears the day summaries');
    check($get($coord, 'after opening')['badge_count'] === 0, 'opening persists');
    accessResetPreloginQuota();
    $otherBrowser = adminLogin($coord);
    check($get($otherBrowser, 'same account, new session')['badge_count'] === 0, 'seen state belongs to the account, not the browser');
    check($get($platform, 'other account untouched')['badge_count'] === 4 + $nc, 'another account keeps its own state (2 alerts + unseen summaries)');
    check($seen($platform, 'platform opens')['badge_count'] === 2, 'platform opening leaves only pending alerts');

    // Lo nuevo desde la ultima apertura: otra llegada tarde en la sede. El resumen se cachea 5 minutos por cuenta.
    $newcomer = Util::bin(Util::uuid()); $newcomerUa = Util::bin(Util::uuid());
    $db->run("INSERT INTO users (tenant_id,id,display_name,firm_id,site_id,area_id,position_id,schedule_id,company_id) VALUES (?,?,'recien',?,?,?,?,?,?)", [$t, $newcomer, $b['firm_id'], $o['teq'], $o['agentes'], $b['position_id'], $b['schedule_id'], $o['azc']]);
    $db->run("INSERT INTO user_assignments (tenant_id,id,user_id,firm_id,site_id,area_id,position_id,schedule_id,company_id,starts_at) VALUES (?,?,?,?,?,?,?,?,?,'2026-09-01')", [$t, $newcomerUa, $newcomer, $b['firm_id'], $o['teq'], $o['agentes'], $b['position_id'], $b['schedule_id'], $o['azc']]);
    $late($newcomer, $newcomerUa);
    $c = $get($coord, 'cached day summary');
    check($byType($c)['late_arrivals']['count'] === 1 && $c['badge_count'] === 0, 'late arrivals cached per account (no recalculation on every page)');
    $expire = fn () => $db->run('UPDATE panel_notification_state SET digest_at=digest_at-INTERVAL 10 MINUTE WHERE tenant_id=?', [$t]);
    $expire();
    $c = $get($coord, 'refreshed day summary');
    check($byType($c)['late_arrivals']['count'] === 2 && $byType($c)['late_arrivals']['unseen'] && !$byType($c)['absences']['unseen'] && $c['badge_count'] === 1, 'a new late arrival after opening counts again');
    // Margen de puntualidad de la empresa (Horarios): con 1430 minutos nadie llego tarde a las 10:30 y la hora limite de
    // quien entra a las 00:00 pasa a las 23:50 (aun nadie esta «sin conectarse»).
    $db->run("INSERT INTO punctuality_margins (tenant_id,from_day,minutes) VALUES (?,'2000-01-01',1430)", [$t]); $expire();
    $c = $get($coord, 'company margin');
    check(!isset($byType($c)['late_arrivals']) && in_array('late_arrivals', $c['available'], true), 'late arrivals use the company punctuality margin');
    if ((new DateTimeImmutable('now', $zone))->format('H:i') < '23:50') { check(!isset($byType($c)['not_connected']) && in_array('not_connected', $c['available'], true), 'not connected only after start + company margin'); }
    $db->run("DELETE FROM punctuality_margins WHERE tenant_id=? AND from_day='2000-01-01'", [$t]); $expire();

    // Rol sin permisos de cumplimiento ni presencia: solo ve lo que su rol abre.
    $r = $get($reportsOnly, 'reports-only role');
    check($r['available'] === ['late_arrivals', 'not_connected'] && array_column($r['items'], 'type') === ($nc ? ['late_arrivals', 'not_connected'] : ['late_arrivals']), 'sections follow the role permissions');

    // Funciones no compradas: esa parte no aparece (tampoco para superadmin). Sin nada: estado vacio.
    $db->run("DELETE FROM tenant_features WHERE tenant_id=? AND feature_code IN ('cumplimiento','ausencias')", [$t]);
    $p = $get($platform, 'without compliance and absences');
    check($p['available'] === ['late_arrivals', 'not_connected'] && array_column($p['items'], 'type') === ($nc ? ['late_arrivals', 'not_connected'] : ['late_arrivals']), 'unpurchased compliance and absences hidden');
    check($get($coord, 'coordinator without features')['available'] === ['late_arrivals', 'not_connected'], 'hidden for client accounts too');
    $db->run("DELETE FROM tenant_features WHERE tenant_id=? AND feature_code='presencia'", [$t]);
    $p = $get($platform, 'nothing purchased');
    check($p['available'] === [] && $p['items'] === [] && $p['badge_count'] === 0, 'empty state when nothing applies');
    foreach (['cumplimiento', 'ausencias', 'presencia'] as $code) { $db->run('INSERT INTO tenant_features (tenant_id,feature_code) VALUES (?,?)', [$t, $code]); }

    // El recalculo diario de cumplimiento conserva lo revisado y rehace lo pendiente.
    $dev = $device('juan'); $da = Util::bin(Util::uuid());
    $db->run("INSERT INTO device_assignments (tenant_id,id,device_id,user_id,starts_at,reason) VALUES (?,?,?,?,'2026-09-01','notifications test')", [$t, $da, $dev, $o['juan']['id']]);
    $db->run("INSERT INTO episodes (tenant_id,device_id,event_id,user_id,device_assignment_id,user_assignment_id,event_date,started_at,ended_at,process_name,active_seconds,idle_seconds,call_seconds,body_hash) VALUES (?,?,?,?,?,?,?,?,?,'anydesk.exe',1200,0,0,?)",
        [$t, $dev, Util::bin(Util::uuid()), $o['juan']['id'], $da, $ua('juan'), $yday, $utc("$yday 09:00:00"), $utc("$yday 09:30:00"), random_bytes(32)]);
    ActivityCalendar::create($db, $t, $yday, $today);
    (new ComplianceWorker($db))->calculate($t, $yday);
    $db->run('DROP TEMPORARY TABLE IF EXISTS activity_calendar');
    $kept = $db->one('SELECT reviewed,active_seconds FROM suspicious_app_detections WHERE tenant_id=? AND id=?', [$t, Util::bin($juanDetection)]);
    check($kept !== null && (bool) $kept['reviewed'] && (int) $kept['active_seconds'] === 1200, 'recalculation keeps the review and refreshes the detection');
    check($db->one('SELECT id FROM suspicious_app_detections WHERE tenant_id=? AND id=?', [$t, Util::bin($medDetection)]) === null, 'unreviewed detections are recalculated');
    echo 'PASS notifications (' . ($GLOBALS['checks'] - $before) . " assertions)\n";
}
