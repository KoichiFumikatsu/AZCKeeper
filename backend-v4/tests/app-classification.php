<?php
declare(strict_types=1);
use Keeper\{Database,ProductivityWorker,Util};

// Formulas (decision 2026-10-01) con un dia armado a mano:
// 30 min alternando Word y Outlook (productivo, un solo bloque), 10 min Facebook en Chrome (poco productivo),
// 5 min Explorador (neutro), 5 min una app desconocida (sin clasificar). Uso medido en ventanas = 50 min.
function appClassificationTests(Database $db): void
{
    $before = $GLOBALS['checks'];
    $u = secondTenant($db); $tenant = $u['tenant_id']; $uid = Util::id($u['id']);
    $platform = sharedPlatform($db); $platform['tenant'] = $tenant;
    for ($w = 1; $w <= 7; $w++) { $db->run('INSERT INTO schedule_days (tenant_id,schedule_id,weekday) VALUES (?,?,?)', [$tenant, $u['schedule_id'], $w]); }
    $token = enroll($db, $u, keypair()); $device = Util::bin($token['device_id']);
    $day = (new DateTimeImmutable('yesterday', new DateTimeZone('America/Bogota')))->format('Y-m-d');
    $db->run('UPDATE device_assignments SET starts_at=? - INTERVAL 10 DAY WHERE tenant_id=? AND device_id=?', [$day, $tenant, $device]);
    $db->run('UPDATE user_assignments SET starts_at=? - INTERVAL 10 DAY WHERE tenant_id=? AND user_id=?', [$day, $tenant, $u['id']]);
    $da = $db->one('SELECT id FROM device_assignments WHERE tenant_id=? AND device_id=?', [$tenant, $device])['id'];
    $ua = $db->one('SELECT id FROM user_assignments WHERE tenant_id=? AND user_id=? AND ends_at IS NULL', [$tenant, $u['id']])['id'];
    $t = (new DateTimeImmutable("$day 09:00", new DateTimeZone('America/Bogota')))->setTimezone(new DateTimeZone('UTC'));
    $episode = function (string $process, ?string $site, int $minutes) use ($db, $tenant, $device, $u, $da, $ua, &$t): void {
        $end = $t->modify("+$minutes minutes");
        $db->run('INSERT INTO episodes (tenant_id,device_id,event_id,user_id,device_assignment_id,user_assignment_id,event_date,started_at,ended_at,process_name,site,active_seconds,idle_seconds,call_seconds,body_hash) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,0,0,?)',
            [$tenant, $device, Util::bin(Util::uuid()), $u['id'], $da, $ua, $t->format('Y-m-d'), $t->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s'), $process, $site, $minutes * 60, random_bytes(32)]);
        $t = $end;
    };
    $episode('WINWORD', null, 15); $episode('OUTLOOK', null, 15); $episode('chrome', 'Facebook', 10); $episode('explorer', null, 5); $episode('rarezas', null, 5);
    $recompute = function () use ($db, $tenant, $day): void {
        $db->run('INSERT INTO rollup_days (tenant_id,day) VALUES (?,?) ON DUPLICATE KEY UPDATE dirty=TRUE,input_revision=input_revision+1,finalized_at=NULL', [$tenant, $day]);
        // Clasificar marca 31 dias para recalcular: se procesan todos.
        (new ProductivityWorker($db))->run(Util::id($tenant), 40, 60);
    };
    $recompute();
    $d = $db->one('SELECT * FROM day_summary WHERE tenant_id=? AND day=? AND user_id=?', [$tenant, $day, $u['id']]);
    check((int) $d['window_active_seconds'] === 3000 && (int) $d['productive_seconds'] === 1800 && (int) $d['unproductive_seconds'] === 600 && (int) $d['unclassified_seconds'] === 300, 'day split by category ' . json_encode([$d['window_active_seconds'], $d['productive_seconds'], $d['unproductive_seconds'], $d['unclassified_seconds']]));
    check((int) $d['focus_block_seconds'] === 1800, 'Word+Outlook count as one 30-min focus block');
    $totals = expect(adminRequest($platform, 'GET', "/users/$uid/activity?from=$day&to=" . (new DateTimeImmutable($day))->modify('+1 day')->format('Y-m-d')), 200, 'activity report')['totals'];
    check($totals['productivity_percent'] == 60 && $totals['distraction_percent'] == 20 && $totals['unclassified_percent'] == 10 && $totals['focus_score'] == 100, 'formulas: 60% productive, 20% distraction, 10% unclassified, focus 100 ' . json_encode([$totals['productivity_percent'], $totals['distraction_percent'], $totals['unclassified_percent'], $totals['focus_score']]));

    // Mercadeo: Facebook productivo para su area (excepcion). La empresa sigue viendolo como poco productivo.
    $over = expect(adminRequest($platform, 'POST', '/app-classification/overrides', ['key' => 'site:Facebook', 'category' => 'productive', 'area_id' => Util::id($u['area_id']), 'position_id' => null]), 200, 'area exception');
    $fb = array_values(array_filter($over['apps'], fn ($a) => $a['key'] === 'site:Facebook'))[0];
    check($fb['base_category'] === 'unproductive' && count($fb['overrides']) === 1 && $fb['active_seconds_30d'] === 600, 'overview shows base, exception and usage');
    $recompute();
    $d2 = $db->one('SELECT productive_seconds,focus_block_seconds FROM day_summary WHERE tenant_id=? AND day=? AND user_id=?', [$tenant, $day, $u['id']]);
    check((int) $d2['productive_seconds'] === 2400 && (int) $d2['focus_block_seconds'] === 2400, 'area exception makes Facebook productive and extends the block');
    // La empresa clasifica la app desconocida; quitar la excepcion vuelve a la base.
    expect(adminRequest($platform, 'PUT', '/app-classification', ['key' => 'rarezas', 'category' => 'neutral']), 200, 'classify unknown app');
    expect(adminRequest($platform, 'DELETE', '/app-classification/overrides/' . $fb['overrides'][0]['id']), 200, 'remove exception');
    $recompute();
    $d3 = $db->one('SELECT productive_seconds,unclassified_seconds FROM day_summary WHERE tenant_id=? AND day=? AND user_id=?', [$tenant, $day, $u['id']]);
    check((int) $d3['productive_seconds'] === 1800 && (int) $d3['unclassified_seconds'] === 0, 'company classification and removed exception applied');
    // Mismo navegador con y sin sitio reconocido el mismo dia: dos categorias para chrome. Antes la clave de episode_daily no
    // incluia la categoria y el calculo del dia entero fallaba por clave duplicada (produccion, 2026-10-13 15:40 UTC).
    $episode('chrome', null, 5);
    $recompute();
    $chrome = $db->run("SELECT category,active_seconds FROM episode_daily WHERE tenant_id=? AND day=? AND user_id=? AND process_name='chrome' ORDER BY category", [$tenant, $day, $u['id']])->fetchAll();
    check(count($chrome) === 2 && array_sum(array_column($chrome, 'active_seconds')) == 900, 'chrome with and without site keeps one row per category ' . json_encode($chrome));
    // Sitio reconocido al recibir: solo la etiqueta, nunca el titulo.
    $class = new ReflectionClass(Keeper\Ingest::class); $ingest = $class->newInstanceWithoutConstructor();
    foreach (['db' => $db, 'c' => ['tenant_id' => $tenant]] as $prop => $value) { $class->getProperty($prop)->setValue($ingest, $value); }
    $site = (new ReflectionMethod($ingest, 'site'));
    check($site->invoke($ingest, 'chrome', '(3) Facebook - Google Chrome') === 'Facebook' && $site->invoke($ingest, 'WINWORD', 'Facebook.docx') === null && $site->invoke($ingest, 'msedge', 'Documentos de Google - Informe') === 'Google Docs', 'site label derived from title only for browsers');
    echo 'PASS app classification (' . ($GLOBALS['checks'] - $before) . " assertions)\n";
}
