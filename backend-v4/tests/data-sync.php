<?php
declare(strict_types=1);
use Keeper\{Database,DataSync,SyncFailure,Util};

// Motor de sincronizacion (F1): simulacion, vinculo por cedula, frenos, pendientes, bloqueo de edicion y fallas.
function dataSyncTests(Database $db): void
{
    $before = $GLOBALS['checks'];
    $u = secondTenant($db); $tenant = $u['tenant_id']; $tid = Util::id($tenant);
    $platform = sharedPlatform($db); $platform['tenant'] = $tenant;
    // Persona previa (como si viniera de K3) con cedula 9990001: One la trae y debe vincularse, no duplicarse.
    $db->run("INSERT INTO user_external_refs (tenant_id,user_id,source,external_ref) VALUES (?,?,'k3:cc','9990001')", [$tenant, $u['id']]);
    $local = $db->one('SELECT * FROM users WHERE tenant_id=? AND id=?', [$tenant, $u['id']]);
    $orphan = Util::bin(Util::uuid());
    $db->run('INSERT INTO users (tenant_id,id,display_name,firm_id,site_id,area_id,position_id,schedule_id) VALUES (?,?,\'Persona local\',?,?,?,?,?)', [$tenant, $orphan, $local['firm_id'], $local['site_id'], $local['area_id'], $local['position_id'], $local['schedule_id']]);
    $db->run("INSERT INTO org_units (tenant_id,id,kind,name) VALUES (?,?,'site','Sede Norte')", [$tenant, $localSite = Util::bin(Util::uuid())]);

    $src = expect(adminRequest($platform, 'POST', '/data-sources', ['name' => 'AZC One', 'base_url' => 'https://127.0.0.1:9/api/keeper/v1', 'company' => 'grupo-azc', 'token' => 'secreto-de-prueba']), 201, 'create data source');
    check($src['mode'] === 'simulate' && $src['has_token'] && !str_contains(json_encode($src), 'secreto'), 'source starts simulated and hides token');
    check(adminRequest($platform, 'POST', '/data-sources', ['name' => 'x', 'base_url' => 'http://inseguro.test'])[1]['detail'] === 'https_required', 'https required');
    $domains = array_map(fn ($d) => ['domain' => $d, 'source_id' => $src['id']], ['firmas', 'sedes', 'areas', 'cargos', 'horarios', 'festivos', 'personas']);
    $owners = expect(adminRequest($platform, 'PUT', '/data-domains', ['domains' => $domains]), 200, 'assign domain owners');
    check(count(array_filter($owners['data'], fn ($d) => $d['source_id'] === $src['id'])) === 7, 'seven domains owned by One');
    $sid = Util::bin($src['id']);

    // Nomina de One (formato canonico).
    $people = [];
    for ($n = 1; $n <= 12; $n++) {
        $people[] = ['external_id' => "emp-$n", 'document' => (string) (1000000 + $n), 'display_name' => "Persona $n", 'email' => "p$n@example.test",
            'firm_external_id' => 'f1', 'site_external_id' => 's1', 'area_external_id' => 'a1', 'position_external_id' => 'c1', 'schedule_external_id' => 'j1', 'status' => 'active'];
    }
    $people[] = ['external_id' => 'emp-k3', 'document' => '999.000-1', 'display_name' => 'Persona de K3', 'email' => null, 'firm_external_id' => 'f1', 'site_external_id' => 's1',
        'area_external_id' => 'a1', 'position_external_id' => 'c1', 'schedule_external_id' => 'j1', 'status' => 'active'];
    $world = [
        'org-units' => [['external_id' => 'f1', 'kind' => 'firm', 'name' => 'Firma One', 'parent_external_id' => null, 'active' => true],
            ['external_id' => 'a1', 'kind' => 'area', 'name' => 'Área Uno', 'parent_external_id' => 's1', 'active' => true],
            ['external_id' => 's1', 'kind' => 'site', 'name' => 'Palmetto', 'parent_external_id' => null, 'active' => true],
            ['external_id' => 's2', 'kind' => 'site', 'name' => 'SEDE NORTE', 'parent_external_id' => null, 'active' => true],
            ['external_id' => 'c1', 'kind' => 'position', 'name' => 'Abogado', 'parent_external_id' => null, 'active' => true]],
        'schedules' => [['external_id' => 'j1', 'name' => 'L-V 8-5 y sábado', 'timezone' => 'America/Bogota', 'active' => true, 'days' => array_merge(
            array_map(fn ($w) => ['weekday' => $w, 'start_local' => '08:00', 'end_local' => '17:00', 'lunch' => ['start_local' => '12:00', 'end_local' => '13:00']], [1, 2, 3, 4, 5]),
            [['weekday' => 6, 'start_local' => '08:00', 'end_local' => '12:00', 'lunch' => null]])]],
        'holidays' => [['external_id' => 'h1', 'day' => '2026-12-08', 'name' => 'Inmaculada Concepción', 'active' => true]],
        'people' => $people,
    ];
    $fail = false;
    $fetch = function (array $source, string $endpoint, ?string $since, ?string $cursor) use (&$world, &$fail): array {
        if ($fail) { throw new SyncFailure('source_unreachable'); }
        return ['schema' => DataSync::SCHEMA, 'company' => 'grupo-azc', 'generated_at' => '2026-09-30T13:00:00Z', 'complete' => $since === null, 'next_cursor' => null, 'items' => $world[$endpoint]];
    };
    $sync = new DataSync($db, $fetch);
    $run = fn (string $kind, bool $override = false, ?bool $simulate = null) => $db->one('SELECT * FROM sync_runs WHERE tenant_id=? AND id=?', [$tenant, $db->transaction(fn () => $sync->run($tenant, $sid, $kind, 'manual', $override, $simulate))]);
    $users = fn () => (int) $db->one('SELECT COUNT(*) n FROM users WHERE tenant_id=?', [$tenant])['n'];

    // 1) Simulacion: calcula todo y no escribe nada.
    $count = $users();
    $sim = $run('full');
    $counts = json_decode($sim['counts'], true);
    check($sim['status'] === 'simulated' && $users() === $count && ($counts['personas']['create'] ?? 0) === 12 && ($counts['personas']['link'] ?? 0) === 1, 'simulation computes without writing');
    check(!$db->one("SELECT id FROM org_units WHERE tenant_id=? AND name='Palmetto'", [$tenant]), 'simulation leaves no org units');
    check(adminRequest($platform, 'GET', '/sync-runs/' . Util::id($sim['id']) . '/items')[0] === 200, 'simulation items visible');

    // 2) Real: aplica, vincula por cedula y crea el resto.
    $s1 = expect(adminRequest($platform, 'GET', '/data-sources'), 200, 'list sources')['data'][0];
    expect(adminRequest($platform, 'PATCH', '/data-sources/' . $src['id'], ['mode' => 'live'], ['if-match' => '"' . $s1['version'] . '"']), 200, 'go live after simulation');
    $live = $run('full');
    check($live['status'] === 'applied', 'live run applied ' . $live['status'] . ' ' . $live['blocked_reason']);
    check($users() === $count + 12, 'twelve people created');
    $k3 = $db->one("SELECT entity_id,link_method FROM entity_links WHERE tenant_id=? AND source_id=? AND external_id='emp-k3'", [$tenant, $sid]);
    check($k3 && $k3['entity_id'] === $u['id'] && $k3['link_method'] === 'matched_document', 'K3 person linked by document, not duplicated');
    $p1 = $db->one("SELECT u.* FROM users u JOIN entity_links l ON l.tenant_id=u.tenant_id AND l.entity_id=u.id WHERE u.tenant_id=? AND l.external_id='emp-1'", [$tenant]);
    check($p1 && $db->one('SELECT id FROM user_assignments WHERE tenant_id=? AND user_id=? AND ends_at IS NULL', [$tenant, $p1['id']]) && $db->one("SELECT 1 FROM user_external_refs WHERE tenant_id=? AND user_id=? AND source='document' AND external_ref='1000001'", [$tenant, $p1['id']]), 'person created with assignment and document');
    $sched = $db->one('SELECT * FROM schedules WHERE tenant_id=? AND id=?', [$tenant, $p1['schedule_id']]);
    check($sched['start_local'] === '08:00:00' && $sched['lunch_start_local'] === '12:00:00' && $db->one('SELECT end_local FROM schedule_days WHERE tenant_id=? AND schedule_id=? AND weekday=6', [$tenant, $p1['schedule_id']])['end_local'] === '12:00:00', 'schedule with saturday hours');
    check((bool) $db->one("SELECT id FROM holidays WHERE tenant_id=? AND day='2026-12-08'", [$tenant]), 'holiday created');
    $pending = expect(adminRequest($platform, 'GET', '/sync-items/pending'), 200, 'pending items')['data'];
    $reasons = array_count_values(array_column($pending, 'reason'));
    check(($reasons['matched_name'] ?? 0) === 1 && ($reasons['orphan'] ?? 0) === 1, 'name match and orphan pending ' . json_encode($reasons));

    // 3) Bloqueo de edicion de lo que gestiona One.
    $pid = Util::id($p1['id']);
    $user = expect(adminRequest($platform, 'GET', '/users/' . $pid), 200, 'read synced person');
    check($user['managed_by'] === 'AZC One', 'person shows its source');
    check(adminRequest($platform, 'PATCH', '/users/' . $pid, ['display_name' => 'Otro'], ['if-match' => '"' . $user['version'] . '"'])[1]['detail'] === 'managed_by_source', 'synced person locked');
    $orphanDto = expect(adminRequest($platform, 'GET', '/users/' . Util::id($orphan)), 200, 'read local person');
    check($orphanDto['managed_by'] === null && adminRequest($platform, 'PATCH', '/users/' . Util::id($orphan), ['display_name' => 'Persona local editada'], ['if-match' => '"' . $orphanDto['version'] . '"'])[0] === 200, 'local person still editable');
    check(adminRequest($platform, 'POST', '/users', ['display_name' => 'Nueva', 'email' => null] + array_map(fn ($v) => Util::id($v), array_intersect_key($p1, array_flip(['firm_id', 'site_id', 'area_id', 'position_id', 'schedule_id']))))[1]['detail'] === 'managed_by_source', 'cannot create people when One owns them');

    // 4) Pendientes: vincular la sede por nombre, dejar a la huerfana como local.
    foreach ($pending as $item) {
        expect(adminRequest($platform, 'POST', '/sync-items/' . $item['id'] . '/resolve', ['approve' => $item['reason'] === 'matched_name']), 200, 'resolve ' . $item['reason']);
    }
    check($db->one("SELECT entity_id FROM entity_links WHERE tenant_id=? AND source_id=? AND external_id='s2'", [$tenant, $sid])['entity_id'] === $localSite, 'local site linked after approval');
    check($db->one('SELECT status FROM users WHERE tenant_id=? AND id=?', [$tenant, $orphan])['status'] === 'active', 'rejected orphan stays active');

    // 5) Incremental: cambios de nombre y de cedula (esta ultima pendiente de aprobacion).
    $world['people'][0]['display_name'] = 'Persona Uno Renombrada';
    $world['people'][1]['document'] = '7777777';
    $inc = $run('incremental');
    check($inc['status'] === 'applied' && $db->one('SELECT display_name FROM users WHERE tenant_id=? AND id=?', [$tenant, $p1['id']])['display_name'] === 'Persona Uno Renombrada', 'incremental update applied');
    $run('incremental');
    $docItem = array_values(array_filter(expect(adminRequest($platform, 'GET', '/sync-items/pending'), 200, 'pending after incremental')['data'], fn ($i) => $i['reason'] === 'document_change'));
    check(count($docItem) === 1 && !str_contains(json_encode($docItem[0]['fields']), '1000002'), 'document change pending and masked');
    expect(adminRequest($platform, 'POST', '/sync-items/' . $docItem[0]['id'] . '/resolve', ['approve' => true]), 200, 'approve document change');
    check((bool) $db->one("SELECT 1 FROM user_external_refs WHERE tenant_id=? AND source='document' AND external_ref='7777777'", [$tenant]), 'document updated after approval');

    // 6) Frenos: seis retiros de golpe (limite 5) se bloquean; una lista vacia tambien. Nada se aplica.
    $full = $world['people'];
    $world['people'] = array_slice($full, 6);
    $blocked = $run('full');
    check($blocked['status'] === 'blocked' && $blocked['blocked_reason'] === 'too_many_deactivations', 'mass deactivation blocked');
    check((int) $db->one("SELECT COUNT(*) n FROM users u JOIN entity_links l ON l.tenant_id=u.tenant_id AND l.entity_id=u.id WHERE u.tenant_id=? AND l.source_id=? AND u.status='active'", [$tenant, $sid])['n'] === 13, 'nobody retired while blocked');
    $world['people'] = [];
    check($run('full')['blocked_reason'] === 'list_too_small', 'empty list blocked');
    $world['people'] = array_slice($full, 6);
    $forced = $run('full', true);
    check($forced['status'] === 'applied' && json_decode($forced['counts'], true)['personas']['deactivate'] === 6, 'override applies the retirements');
    $reasons = array_column(expect(adminRequest($platform, 'GET', '/sync-items/pending'), 200, 'pending after forced run')['data'], 'reason');
    check(!in_array('orphan', $reasons, true) && !in_array('matched_name', $reasons, true), 'answered questions are not asked again');
    $gone = $db->one("SELECT u.status FROM users u JOIN entity_links l ON l.tenant_id=u.tenant_id AND l.entity_id=u.id WHERE u.tenant_id=? AND l.external_id='emp-1'", [$tenant]);
    check($gone['status'] === 'inactive', 'absent person retired');
    // La API de aprobar vuelve a leer la fuente real (inalcanzable aqui): la vuelta queda fallida, sin cambios.
    $approved = expect(adminRequest($platform, 'POST', '/sync-runs/' . Util::id($blocked['id']) . '/approve'), 201, 'approve blocked run via API');
    check($approved['status'] === 'failed' && $approved['error_code'] === 'source_unreachable', 'unreachable source fails safely');

    // 7) Una fuente caida no cambia nada.
    $fail = true;
    $failed = $run('full');
    check($failed['status'] === 'failed' && $failed['error_code'] === 'source_unreachable', 'failed run recorded');
    $fail = false;
    // 8) Festivo quitado en One desaparece de Keeper.
    $world['holidays'][0]['active'] = false;
    $run('incremental');
    check(!$db->one("SELECT id FROM holidays WHERE tenant_id=? AND day='2026-12-08'", [$tenant]), 'holiday removed when inactive in source');
    // 9) Devolver un dominio a Keeper desbloquea la edicion.
    expect(adminRequest($platform, 'PUT', '/data-domains', ['domains' => [['domain' => 'personas', 'source_id' => null]]]), 200, 'return people to Keeper');
    $again = expect(adminRequest($platform, 'GET', '/users/' . Util::id($u['id'])), 200, 'read person after release');
    check($again['managed_by'] === null, 'person editable again');

    // 10) R1: configurar la fuente de verdad de RR. HH. equivale a controlar cargos y jefes (y con ellos las reglas por
    // cargo): gestionar y aprobar integraciones son solo de la plataforma; ni la plataforma los pone en un rol del cliente.
    foreach (['integraciones.gestionar', 'integraciones.aprobar'] as $code) {
        $flags = $db->one('SELECT delegable,platform_only FROM permissions WHERE code=?', [$code]);
        check((bool) $flags['platform_only'] && !(bool) $flags['delegable'], "$code is platform-only and not delegable");
    }
    check((int) $db->one("SELECT COUNT(*) n FROM role_template_permissions t JOIN role_templates r ON r.seed_key=t.seed_key WHERE r.platform_only=FALSE AND t.permission_code IN ('integraciones.gestionar','integraciones.aprobar')")['n'] === 0, 'no client template carries integration management');
    $db->run("INSERT IGNORE INTO tenant_features (tenant_id,feature_code) VALUES (?,'one')", [$tenant]);
    // Admin del cliente con todo lo que un rol del cliente puede tener.
    $client = adminFixture($db, adminMember($db, $u), array_column($db->run('SELECT code FROM permissions WHERE platform_only=FALSE')->fetchAll(), 'code')); $client['tenant'] = $tenant;
    accessResetPreloginQuota();
    $role = expect(adminRequest($platform, 'POST', '/roles', ['name' => 'Integrador', 'permissions' => ['integraciones.ver'], 'active' => true]), 201, 'client role for integrations');
    check(adminRequest($platform, 'PATCH', '/roles/' . $role['id'], ['name' => 'Integrador', 'permissions' => ['integraciones.ver', 'integraciones.gestionar'], 'active' => true], ['if-match' => '"' . $role['version'] . '"'])[0] === 403, 'not even superadmin puts integration management in a client role');
    $srcNow = fn () => array_values(array_filter(expect(adminRequest($platform, 'GET', '/data-sources'), 200, 'list sources')['data'], fn ($s) => $s['id'] === $src['id']))[0];
    expect(adminRequest($client, 'GET', '/data-sources'), 200, 'client admin still sees the sources');
    $refused = fn (array $r) => $r[0] === 403 && ($r[1]['code'] ?? null) === 'permission_denied';
    check($refused(adminRequest($client, 'POST', '/data-sources', ['name' => 'Fuente propia', 'base_url' => 'https://atacante.example/api'])), 'client admin cannot create a data source');
    check($refused(adminRequest($client, 'PATCH', '/data-sources/' . $src['id'], ['base_url' => 'https://atacante.example/api'], ['if-match' => '"' . $srcNow()['version'] . '"'])), 'client admin cannot edit a data source');
    check($refused(adminRequest($client, 'POST', '/data-sources/' . $src['id'] . '/runs', ['kind' => 'full', 'simulate' => true])), 'client admin cannot run a sync');
    check($refused(adminRequest($client, 'PUT', '/data-domains', ['domains' => [['domain' => 'cargos', 'source_id' => $src['id']]]])), 'client admin cannot assign domain owners');
    check($refused(adminRequest($client, 'POST', '/sync-runs/' . Util::id($blocked['id']) . '/approve')), 'client admin cannot approve a sync run');
    $renamed = expect(adminRequest($platform, 'PATCH', '/data-sources/' . $src['id'], ['name' => 'AZC One (plataforma)'], ['if-match' => '"' . $srcNow()['version'] . '"']), 200, 'superadmin still edits the source');
    check($renamed['name'] === 'AZC One (plataforma)', 'superadmin edit applied');
    expect(adminRequest($platform, 'POST', '/data-sources/' . $src['id'] . '/runs', ['kind' => 'full', 'simulate' => true]), 201, 'superadmin still runs a sync');
    echo 'PASS data sync (' . ($GLOBALS['checks'] - $before) . " assertions)\n";
}
