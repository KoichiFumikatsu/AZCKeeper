<?php
declare(strict_types=1);
use Keeper\{ActivityCalendar,Database,DataSync,Util};

// F4: ausencias de RR. HH.; una ausencia que cubre la jornada deja el dia sin tiempo esperado.
function absenceTests(Database $db): void
{
    $before = $GLOBALS['checks'];
    $u = secondTenant($db); $tenant = $u['tenant_id']; $uid = Util::id($u['id']);
    $platform = sharedPlatform($db); $platform['tenant'] = $tenant;
    for ($w = 1; $w <= 5; $w++) { $db->run('INSERT INTO schedule_days (tenant_id,schedule_id,weekday) VALUES (?,?,?)', [$tenant, $u['schedule_id'], $w]); }
    $expected = function (string $day) use ($db, $tenant, $u): int {
        ActivityCalendar::create($db, $tenant, $day, (new DateTimeImmutable($day))->modify('+1 day')->format('Y-m-d'));
        $e = ActivityCalendar::expected('a');
        $v = (int) $db->one("SELECT $e x FROM user_assignments a JOIN activity_calendar c ON c.schedule_id=a.schedule_id AND c.day=? WHERE a.tenant_id=? AND a.user_id=? AND a.ends_at IS NULL", [$day, $tenant, $u['id']])['x'];
        $db->run('DROP TEMPORARY TABLE IF EXISTS activity_calendar');
        return $v;
    };
    check($expected('2026-10-05') === 9 * 3600, 'monday expects a full day');
    // Vacaciones del lunes 5 (dia completo en Bogota).
    $abs = expect(adminRequest($platform, 'POST', '/absences', ['user_id' => $uid, 'kind' => 'vacaciones', 'starts_at' => '2026-10-05T05:00:00Z', 'ends_at' => '2026-10-06T05:00:00Z', 'all_day' => true]), 201, 'register vacation');
    check($expected('2026-10-05') === 0 && $expected('2026-10-06') === 9 * 3600, 'vacation day expects nothing; next day normal');
    check(adminRequest($platform, 'POST', '/absences', ['user_id' => $uid, 'kind' => 'permiso', 'starts_at' => '2026-10-06T10:00:00Z', 'ends_at' => '2026-10-06T09:00:00Z'])[1]['detail'] === 'invalid_range', 'range validated');
    $list = expect(adminRequest($platform, 'GET', '/absences?user_id=' . $uid), 200, 'list absences')['data'];
    check(count($list) === 1 && $list[0]['kind'] === 'vacaciones', 'absence listed');
    expect(adminRequest($platform, 'PATCH', '/absences/' . $abs['id'], ['status' => 'cancelled'], ['if-match' => '"' . $abs['version'] . '"']), 200, 'cancel vacation');
    check($expected('2026-10-05') === 9 * 3600, 'cancelled absence restores the day');

    // Desde One: dueño de "ausencias"; en Keeper ya no se crean a mano.
    $db->run("INSERT INTO user_external_refs (tenant_id,user_id,source,external_ref) VALUES (?,?,'document','7070707')", [$tenant, $u['id']]);
    $src = expect(adminRequest($platform, 'POST', '/data-sources', ['name' => 'One ausencias', 'base_url' => 'https://127.0.0.1:9/x']), 201, 'source');
    expect(adminRequest($platform, 'PUT', '/data-domains', ['domains' => [['domain' => 'personas', 'source_id' => $src['id']], ['domain' => 'ausencias', 'source_id' => $src['id']]]]), 200, 'owners');
    check(adminRequest($platform, 'POST', '/absences', ['user_id' => $uid, 'kind' => 'permiso', 'starts_at' => '2026-10-07T13:00:00Z', 'ends_at' => '2026-10-07T15:00:00Z'])[1]['detail'] === 'managed_by_source', 'manual absences blocked when One owns them');
    $o = $u + []; $person = ['external_id' => 'e1', 'document' => '7070707', 'display_name' => $u['display_name'], 'email' => null, 'status' => 'active',
        'firm_external_id' => 'f', 'site_external_id' => 's', 'area_external_id' => 'a', 'position_external_id' => 'p', 'schedule_external_id' => 'h'];
    foreach (['f' => 'firm_id', 's' => 'site_id', 'a' => 'area_id', 'p' => 'position_id'] as $ext => $col) { $db->run("INSERT INTO entity_links (tenant_id,entity_type,entity_id,source_id,external_id,link_method) VALUES (?,'org_unit',?,?,?,'manual')", [$tenant, $o[$col], Util::bin($src['id']), $ext]); }
    $db->run("INSERT INTO entity_links (tenant_id,entity_type,entity_id,source_id,external_id,link_method) VALUES (?,'schedule',?,?,'h','manual')", [$tenant, $o['schedule_id'], Util::bin($src['id'])]);
    $world = ['people' => [$person], 'absences' => [['external_id' => 'aus-1', 'person_external_id' => 'e1', 'kind' => 'incapacidad', 'starts_at' => '2026-10-07T05:00:00Z', 'ends_at' => '2026-10-08T05:00:00Z', 'all_day' => true, 'status' => 'approved']]];
    $sync = new DataSync($db, function ($s, $endpoint, $since) use (&$world) { return ['schema' => DataSync::SCHEMA, 'generated_at' => '2026-10-01T00:00:00Z', 'complete' => $since === null, 'next_cursor' => null, 'items' => $world[$endpoint] ?? []]; });
    $run = $db->transaction(fn () => $sync->run($tenant, Util::bin($src['id']), 'full', 'manual', false, false));
    check($db->one('SELECT status FROM sync_runs WHERE tenant_id=? AND id=?', [$tenant, $run])['status'] === 'applied', 'sync applied');
    check($expected('2026-10-07') === 0, 'sick leave from One clears the day');
    $world['absences'][0]['status'] = 'cancelled';
    $db->transaction(fn () => $sync->run($tenant, Util::bin($src['id']), 'incremental', 'manual', false, false));
    check($expected('2026-10-07') === 9 * 3600, 'cancellation from One restores the day');
    echo 'PASS absences (' . ($GLOBALS['checks'] - $before) . " assertions)\n";
}
