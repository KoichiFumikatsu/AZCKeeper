<?php
declare(strict_types=1);
use Keeper\{Database,DataSync,DoorEvents,SyncFailure,Util};

function dataSyncDoorTests(Database $db): void
{
    $before = $GLOBALS['checks'];
    $u = secondTenant($db); $tenant = $u['tenant_id'];
    $platform = sharedPlatform($db); $platform['tenant'] = $tenant;
    $src = expect(adminRequest($platform, 'POST', '/data-sources', ['name' => 'One entradas', 'base_url' => 'https://127.0.0.1:9/api/keeper/v1']), 201, 'create door source');
    $sid = Util::bin($src['id']);
    $assign = fn ($domains) => expect(adminRequest($platform, 'PUT', '/data-domains', ['domains' => $domains]), 200, 'assign door source');
    $assign([['domain' => 'entradas', 'source_id' => $src['id']]]);
    $source = fn () => $db->one('SELECT * FROM data_sources WHERE tenant_id=? AND id=?', [$tenant, $sid]);
    $watermark = fn () => (int) $db->one('SELECT last_log_id FROM data_source_door_cursors WHERE tenant_id=? AND source_id=?', [$tenant, $sid])['last_log_id'];
    $zone = $db->one('SELECT timezone FROM tenants WHERE tenant_id=?', [$tenant])['timezone'];
    $today = (new DateTimeImmutable('today', new DateTimeZone($zone)))->format('Y-m-d');
    check($source()['door_events_from_date'] === $today && !$source()['approval_required'], 'entries assignment fixes company today without catalog approval');
    $patch = function (array $body) use ($platform, $src, $source): array {
        return expect(adminRequest($platform, 'PATCH', '/data-sources/' . $src['id'], $body, ['if-match' => '"' . $source()['version'] . '"']), 200, 'configure source date');
    };
    $db->run("INSERT INTO entity_links (tenant_id,entity_type,entity_id,source_id,external_id,link_method) VALUES (?,'user',?,?,'emp-1','manual')", [$tenant, $u['id'], $sid]);
    $door = expect(adminRequest($platform, 'POST', '/doors', ['name' => 'Rejas', 'source_ref' => 'Casa 1 · Rejas', 'site_id' => Util::id($u['site_id'])]), 201, 'Unicode door reference');
    $at = gmdate('Y-m-d\TH:i:s\Z', time() - 1);
    $item = fn (int $id, array $extra = []) => array_replace(['external_id' => "puerta-$id", 'log_id' => $id, 'person_external_id' => 'emp-1', 'door_ref' => 'Casa 1 · Rejas', 'direction' => 'in', 'occurred_at' => $at], $extra);
    $world = []; $calls = []; $broken = false; $failAt = null;
    $sync = new DataSync($db, function ($s, $endpoint, $since, $cursor) use (&$world, &$calls, &$broken, &$failAt, $watermark): array {
        $calls[] = [$endpoint, $s['door_events_after_id'] ?? null, $s['door_events_from_date'], $since, $cursor];
        if ($endpoint !== 'door-events') { return ['schema' => DataSync::SCHEMA, 'generated_at' => '2026-11-05T12:00:00Z', 'complete' => true, 'items' => $world[$endpoint] ?? [], 'next_cursor' => null]; }
        if ($broken || ($failAt !== null && $cursor === $failAt)) { throw new SyncFailure('source_unreachable'); }
        if ($cursor !== null && !($world['simulating'] ?? false)) { check($watermark() === $s['door_events_after_id'], 'previous page committed before next fetch'); }
        return $world[$cursor ?? 'first'] ?? ['items' => [], 'next_cursor' => null];
    });
    $run = function (string $kind = 'full', bool $simulate = false, bool $override = false) use ($db, $sync, $tenant, $sid): array {
        $id = $db->transaction(fn () => $sync->run($tenant, $sid, $kind, 'manual', $override, $simulate));
        return $db->one('SELECT * FROM sync_runs WHERE tenant_id=? AND id=?', [$tenant, $id]);
    };
    $count = fn () => (int) $db->one('SELECT COUNT(*) n FROM check_ins WHERE tenant_id=? AND source_identity=?', [$tenant, DoorEvents::sourceIdentity($sid)])['n'];
    $waiting = fn () => (int) $db->one('SELECT COUNT(*) n FROM unmatched_check_ins WHERE tenant_id=?', [$tenant])['n'];
    $reason = fn ($run, $reason) => (bool) $db->one('SELECT 1 FROM sync_items WHERE tenant_id=? AND run_id=? AND domain=\'entradas\' AND reason=?', [$tenant, $run['id'], $reason]);

    $world = ['first' => ['items' => [$item(1), $item(2, ['direction' => null])], 'next_cursor' => 'page2'], 'page2' => ['items' => [$item(3)], 'next_cursor' => null], 'simulating' => true];
    $sim = $run('full', true);
    check($sim['status'] === 'simulated' && $watermark() === 0 && $count() === 0 && $waiting() === 0, 'simulation traverses pages without writing events or cursor');
    check(json_decode($sim['counts'], true)['entradas']['accepted'] === 3, 'simulation counts all pages');
    unset($world['simulating']);
    $failAt = 'page2';
    $failed = $run();
    check($failed['status'] === 'failed' && $reason($failed, 'source_unreachable') && $watermark() === 2 && $count() === 2, 'failure after first page preserves applied cursor and events');
    $failAt = null; $calls = [];
    $world = ['first' => ['items' => [$item(3)], 'next_cursor' => null]];
    $resumed = $run('incremental');
    check($resumed['status'] === 'applied' && $calls[0][1] === 2 && $watermark() === 3 && $count() === 3, 'incremental resumes last committed log id');
    $calls = []; $world = ['first' => ['items' => [$item(1), $item(2), $item(3)], 'next_cursor' => null]];
    $replay = $run('full');
    check($calls[0][1] === 3 && $watermark() === 3 && $count() === 3 && json_decode($replay['counts'], true)['entradas']['duplicate'] === 3, 'full keeps cursor and resend is idempotent');
    check($db->one('SELECT direction FROM check_ins WHERE tenant_id=? AND event_id=?', [$tenant, substr(hash('sha256', DoorEvents::sourceIdentity($sid) . '|puerta-2', true), 0, 16)])['direction'] === null, 'unknown direction remains SQL NULL');
    $reader = externalCredential($db, $u, 'api_key', ['members:read', 'presence:read']);
    $tomorrow = (new DateTimeImmutable($today))->modify('+1 day')->format('Y-m-d');
    $read = expect(externalRequest('/ext/v1/members/' . Util::id($u['id']) . '/check-ins?from=' . $today . '&to=' . $tomorrow, $reader['headers']), 200, 'read source check-ins');
    check(count(array_filter($read['data'], static fn ($e) => $e['direction'] === null)) === 1, 'external read preserves null direction');
    $query = DataSync::restQuery($source() + ['door_events_after_id' => 3], 'door-events', 'ignored', 'page2', ['company' => 'grupo']);
    check($query === ['empresa' => 'grupo', 'after_id' => 3, 'from_date' => $today, 'cursor' => 'page2'], 'event HTTP query always date/cursor and never updated_since');
    check(DataSync::restQuery([], 'people', 'since', null, ['company' => 'grupo']) === ['empresa' => 'grupo', 'updated_since' => 'since'], 'catalog HTTP query unchanged');

    $oldDate = (new DateTimeImmutable($today))->modify('-2 days')->format('Y-m-d');
    $oldTime = (new DateTimeImmutable($oldDate . ' 12:00:00', new DateTimeZone($zone)))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    $world = ['first' => ['items' => [$item(4, ['occurred_at' => $oldTime])], 'next_cursor' => null]];
    $run();
    check($watermark() === 4 && $count() === 3, 'default date excludes past report days even if source returns them');
    $configured = $patch(['door_events_from_date' => $oldDate]);
    check($configured['door_events_from_date'] === $oldDate && $watermark() === 0, 'explicit earlier date resets cursor for backfill');
    $run();
    check($count() === 4 && $watermark() === 4, 'explicit earlier date allows backfill');
    $world['first']['items'] = [$item(5, ['occurred_at' => $oldTime])]; $calls = [];
    $db->run("UPDATE users SET status='inactive' WHERE tenant_id=? AND id=?", [$tenant, $u['id']]);
    $run('incremental');
    check($watermark() === 5 && $count() === 5 && $calls[0][1] === 4 && $calls[0][2] === $oldDate && $calls[0][3] === null, 'late row advances by new log id despite old date');
    $db->run("UPDATE users SET status='active' WHERE tenant_id=? AND id=?", [$tenant, $u['id']]);
    $assign([['domain' => 'entradas', 'source_id' => null]]);
    $assign([['domain' => 'entradas', 'source_id' => $src['id']]]);
    check($watermark() === 5 && $source()['door_events_from_date'] === $oldDate, 'reassignment preserves cursor and configured cutoff');

    // Una cedula igual al id externo no basta: debe existir el vinculo de esta fuente.
    $db->run("INSERT INTO user_external_refs (tenant_id,user_id,source,external_ref) VALUES (?,?,'document','8080001')", [$tenant, $u['id']]);
    $world = ['first' => ['items' => [$item(6, ['person_external_id' => '8080001']), $item(7, ['door_ref' => 'Casa 2 · Rejas', 'direction' => null]), $item(8, ['door_ref' => null]), $item(9)], 'next_cursor' => null]];
    $sim = $run('full', true);
    check($watermark() === 5 && $count() === 5 && $waiting() === 0 && $reason($sim, 'unknown_person'), 'quarantine simulation writes nothing');
    $mixed = $run();
    check($watermark() === 9 && $count() === 6 && $waiting() === 2, 'unknown references quarantine and invalid door does not stop page');
    check($reason($mixed, 'unknown_person') && $reason($mixed, 'unknown_door') && $reason($mixed, 'invalid_door_ref'), 'specific item errors recorded');
    check(!$db->one("SELECT 1 FROM sync_items WHERE tenant_id=? AND run_id=? AND action='pending'", [$tenant, $mixed['id']]), 'no name or orphan approvals for events');
    $q = expect(adminRequest($platform, 'GET', '/doors/quarantine'), 200, 'source quarantine visible');
    check($q['unknown_doors'][0]['door_ref'] === 'Casa 2 · Rejas' && $q['unknown_people'][0]['document_hint'] === '8080001', 'waiting view shows source door and external person');
    expect(adminRequest($platform, 'POST', '/doors', ['name' => 'Casa 2', 'source_ref' => 'Casa 2 · Rejas', 'site_id' => Util::id($u['site_id'])]), 201, 'register missing source door');
    check($count() === 7 && $waiting() === 1, 'register door rematches source event with null direction');
    $db->run("UPDATE entity_links SET external_id='8080001' WHERE tenant_id=? AND source_id=? AND entity_type='user'", [$tenant, $sid]);
    $world = ['first' => ['items' => [], 'next_cursor' => null]];
    $run();
    check($count() === 8 && $waiting() === 0, 'scheduled run rematches person with stored source link');
    $db->run("UPDATE entity_links SET external_id='emp-1' WHERE tenant_id=? AND source_id=? AND entity_type='user'", [$tenant, $sid]);

    $world = ['first' => ['items' => [$item(10)], 'next_cursor' => null]];
    expect(adminRequest($platform, 'PATCH', '/doors/' . $door['id'], ['active' => false]), 200, 'disable source door');
    $run(); check($watermark() === 10 && $count() === 8, 'inactive door ignored');
    expect(adminRequest($platform, 'PATCH', '/doors/' . $door['id'], ['active' => true, 'counts_for_presence' => false]), 200, 'internal source door');
    $world['first']['items'] = [$item(11)]; $run();
    check($watermark() === 11 && $count() === 8, 'non-presence door ignored');
    expect(adminRequest($platform, 'PATCH', '/doors/' . $door['id'], ['counts_for_presence' => true]), 200, 'restore source door');

    $db->run("DELETE FROM tenant_features WHERE tenant_id=? AND feature_code='puertas'", [$tenant]);
    $calls = []; $world['first']['items'] = [$item(12)]; $skipped = $run();
    check($calls === [] && $watermark() === 11 && $count() === 8 && $reason($skipped, 'feature_puertas_disabled'), 'feature gate skips fetch and explains run');
    $db->run("INSERT INTO tenant_features (tenant_id,feature_code) VALUES (?,'puertas')", [$tenant]);

    $assign([['domain' => 'areas', 'source_id' => $src['id']]]);
    $world['org-units'] = [['external_id' => 'area-new', 'kind' => 'area', 'name' => 'Nueva desde One', 'active' => true]];
    $broken = true; $isolated = $run('full', false, true);
    check($isolated['status'] === 'applied' && $reason($isolated, 'source_unreachable') && $watermark() === 11, 'event error isolated from catalog status and cursor');
    check((bool) $db->one("SELECT 1 FROM org_units WHERE tenant_id=? AND name='Nueva desde One'", [$tenant]), 'other domain committed despite event transport failure');
    $broken = false;

    // Error SQL a mitad de pagina: ni su primera fila ni su cursor deben confirmarse.
    $badId = bin2hex(substr(hash('sha256', DoorEvents::sourceIdentity($sid) . '|puerta-13', true), 0, 16));
    $db->run("CREATE TRIGGER test_door_page_failure BEFORE INSERT ON check_ins FOR EACH ROW BEGIN IF NEW.event_id=UNHEX('$badId') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='test page failure'; END IF; END");
    try {
        $world['first']['items'] = [$item(12), $item(13)];
        $sqlError = $run();
        check($reason($sqlError, 'door_events_failed') && $watermark() === 11 && $count() === 8, 'SQL failure rolls back entire event page and watermark');
    } finally { $db->run('DROP TRIGGER test_door_page_failure'); }
    $run(); check($watermark() === 13 && $count() === 10, 'failed page resumes without losing its first row');

    $world['first'] = ['items' => array_map($item, range(14, 514)), 'next_cursor' => null];
    $badId = bin2hex(substr(hash('sha256', DoorEvents::sourceIdentity($sid) . '|puerta-514', true), 0, 16));
    $db->run("CREATE TRIGGER test_door_chunk_failure BEFORE INSERT ON check_ins FOR EACH ROW BEGIN IF NEW.event_id=UNHEX('$badId') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='test chunk failure'; END IF; END");
    try {
        $large = $run();
        check($reason($large, 'door_events_failed') && $watermark() === 13 && $count() === 510, '500 event chunk committed but cursor waits for entire 501 event page');
    } finally { $db->run('DROP TRIGGER test_door_chunk_failure'); }
    $run(); check($watermark() === 514 && $count() === 511, 'partial large page replays without duplicates and advances only when complete');
    $world['first'] = ['items' => array_map($item, range(14, 513)), 'next_cursor' => 'last'];
    $world['last'] = ['items' => [$item(514)], 'next_cursor' => null];
    $run(); check($watermark() === 514 && $count() === 511, '500 event page and next page both deduplicate on resend');
    $world['first'] = ['items' => [$item(516), $item(515)], 'next_cursor' => null];
    $unordered = $run(); check($reason($unordered, 'door_events_invalid_log_id') && $watermark() === 514, 'unordered log ids cannot skip unprocessed rows');

    // Una fuente distinta y una integracion no comparten la identidad del evento.
    $src2 = expect(adminRequest($platform, 'POST', '/data-sources', ['name' => 'Otra fuente', 'base_url' => 'https://127.0.0.1:9', 'door_events_from_date' => $oldDate]), 201, 'second event source');
    $sid2 = Util::bin($src2['id']);
    $assign([['domain' => 'entradas', 'source_id' => $src2['id']]]);
    $db->run("INSERT INTO entity_links (tenant_id,entity_type,entity_id,source_id,external_id,link_method) VALUES (?,'user',?,?,'emp-1','manual')", [$tenant, $u['id'], $sid2]);
    $world = ['first' => ['items' => [$item(1)], 'next_cursor' => null]];
    $db->transaction(fn () => $sync->run($tenant, $sid2, 'full', 'manual', false, false));
    check((int) $db->one('SELECT COUNT(*) n FROM check_ins WHERE tenant_id=? AND source_identity=?', [$tenant, DoorEvents::sourceIdentity($sid2)])['n'] === 1, 'same event id belongs independently to each source');
    check(DoorEvents::sourceIdentity($sid) !== DoorEvents::identity($sid), 'source namespace separate from integration namespace');
    // Pasos hibridos en asistencia, solo en dias sin actividad del PC.
    $day = (new DateTimeImmutable('today', new DateTimeZone($zone)))->modify('-14 days');
    while ($day->format('N') !== '3') { $day = $day->modify('-1 day'); }
    $from = $day->format('Y-m-d'); $to = $day->modify('+1 day')->format('Y-m-d');
    $started = $db->one('SELECT id,starts_at FROM user_assignments WHERE tenant_id=? AND user_id=? ORDER BY starts_at DESC LIMIT 1', [$tenant, $u['id']]);
    $db->run('UPDATE user_assignments SET starts_at=? WHERE tenant_id=? AND id=?', ['2020-01-01 00:00:00', $tenant, $started['id']]);
    $pass = function (string $time, ?string $direction) use ($db, $tenant, $u, $from, $door): void {
        $id = Util::bin(Util::uuid());
        $db->run("INSERT INTO check_ins (tenant_id,id,event_id,source_identity,user_id,door_id,site_id,source,direction,occurred_at,body_hash) VALUES (?,?,?,'test:hybrid',?,?,?,'door',?,?,?)", [$tenant, $id, $id, $u['id'], Util::bin($door['id']), $u['site_id'], $direction, "$from $time", random_bytes(32)]);
    };
    $presence = function () use ($platform, $u, $from, $to): array {
        $rows = expect(adminRequest($platform, 'GET', "/reports/presence?from=$from&to=$to&user_id=" . Util::id($u['id'])), 200, 'presence with door passes')['data'];
        return array_values(array_filter($rows, static fn ($r) => $r['day'] === $from))[0];
    };
    $pass('14:00:00', null);
    $one = $presence();
    check(strtotime($one['first_activity']) === strtotime("$from 14:00:00 UTC") && $one['last_activity'] === null, 'single hybrid pass is arrival, not departure');
    $pass('20:00:00', null);
    $two = $presence();
    check(strtotime($two['first_activity']) === strtotime("$from 14:00:00 UTC") && strtotime($two['last_activity']) === strtotime("$from 20:00:00 UTC"), 'first and last hybrid passes are arrival and departure');
    $pass('21:00:00', 'in');
    check(strtotime($presence()['last_activity']) === strtotime("$from 20:00:00 UTC"), 'explicit in pass is never departure');
    $db->run("DELETE FROM check_ins WHERE tenant_id=? AND source_identity='test:hybrid'", [$tenant]);
    $db->run('UPDATE user_assignments SET starts_at=? WHERE tenant_id=? AND id=?', [$started['starts_at'], $tenant, $started['id']]);
    echo 'PASS data sync doors (' . ($GLOBALS['checks'] - $before) . " assertions)\n";
}
