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
        'holiday-calendars' => [],
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

    // 1) Simulacion: calcula todo y no escribe nada. 12 altas superan el freno de altas (5): la simulacion lo muestra.
    $count = $users();
    $sim = $run('full');
    $counts = json_decode($sim['counts'], true);
    check($sim['status'] === 'blocked' && $sim['blocked_reason'] === 'too_many_creations' && (int) $sim['simulate'] === 1 && $users() === $count && ($counts['personas']['create'] ?? 0) === 12 && ($counts['personas']['link'] ?? 0) === 1, 'simulation computes without writing');
    check(!$db->one("SELECT id FROM org_units WHERE tenant_id=? AND name='Palmetto'", [$tenant]), 'simulation leaves no org units');
    check(adminRequest($platform, 'GET', '/sync-runs/' . Util::id($sim['id']) . '/items')[0] === 200, 'simulation items visible');

    // 2) Real: aplica, vincula por cedula y crea el resto.
    $s1 = expect(adminRequest($platform, 'GET', '/data-sources'), 200, 'list sources')['data'][0];
    expect(adminRequest($platform, 'PATCH', '/data-sources/' . $src['id'], ['mode' => 'live'], ['if-match' => '"' . $s1['version'] . '"']), 200, 'go live after simulation');
    $held = $run('full');
    check($held['status'] === 'blocked' && $held['blocked_reason'] === 'too_many_creations' && $users() === $count, 'first live load waits for approval');
    $live = $run('full', true);
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
    $watermark=$db->one('SELECT updated_since,last_full_at FROM data_sources WHERE tenant_id=? AND id=?',[$tenant,$sid]);
    $world['people'][0]['display_name']='Solo esta persona'; $world['people'][1]['display_name']='No debe cambiar';
    $single=$db->transaction(fn()=>$sync->run($tenant,$sid,'incremental','manual',false,false,$p1['id']));
    check($db->one('SELECT status FROM sync_runs WHERE tenant_id=? AND id=?',[$tenant,$single])['status']==='applied','single source refresh applied');
    check($db->one('SELECT display_name FROM users WHERE tenant_id=? AND id=?',[$tenant,$p1['id']])['display_name']==='Solo esta persona','single source refresh target changed');
    check(!$db->one("SELECT id FROM users WHERE tenant_id=? AND display_name='No debe cambiar'",[$tenant]),'single source refresh leaves other people alone');
    check($db->one('SELECT updated_since,last_full_at FROM data_sources WHERE tenant_id=? AND id=?',[$tenant,$sid])===$watermark,'single source refresh preserves global cursor');
    $savedPerson=$world['people'][0];
    $db->pdo->beginTransaction();
    try {
        $db->run("DELETE FROM data_domain_owners WHERE tenant_id=? AND domain='personas'",[$tenant]);
        $world['people'][0]['display_name']='Not owned'; $world['people'][0]['schedule_external_id']=null;
        $runId=$sync->run($tenant,$sid,'incremental','manual',false,false,$p1['id']);
        $changed=$db->one('SELECT display_name,schedule_id FROM users WHERE tenant_id=? AND id=?',[$tenant,$p1['id']]);
        check($changed['display_name']==='Solo esta persona' && $changed['schedule_id']!==$p1['schedule_id'],'single refresh only changes owned domains');
        check($db->one('SELECT status FROM sync_runs WHERE tenant_id=? AND id=?',[$tenant,$runId])['status']==='applied','schedule-only owner refresh applied');
    } finally { $db->pdo->rollBack(); $world['people'][0]=$savedPerson; }
    $world['people'][1]['display_name']='Persona 2';
    $pid = Util::id($p1['id']);
    $user = expect(adminRequest($platform, 'GET', '/users/' . $pid), 200, 'read synced person');
    check($user['managed_by'] === 'AZC One', 'person shows its source');
    check(count($user['source_owners'])===7,'person exposes domain owners without source credentials');
    expect(adminRequest($platform,'PATCH','/users/'.$pid,['employment_status'=>'retirado'],['if-match'=>'"'.$user['version'].'"']),409,'source owns employment status');
    expect(adminRequest($platform,'POST','/users/'.Util::id($orphan).'/refresh-source',['source_id'=>$src['id']]),404,'refresh only linked person');
    $refresh=expect(adminRequest($platform,'POST','/users/'.$pid.'/refresh-source',['source_id'=>$src['id']]),200,'refresh source endpoint unreachable');
    check($refresh['status']==='failed','unreachable source never deactivates person');
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
    oneBridgeSyncTests($db);
    holidayCalendarSyncTests($db);
    syncPanelTests($db);
}

// Puente One (v4-puente-one-legacy.md §6.1): empresas del grupo (K1), personas sin area ni firma (K2), pais y ciudad de
// las sedes (K3) y un correo repetido que no tumba la vuelta (K4). Festivos y ausencias siguen siendo de Keeper.
function oneBridgeSyncTests(Database $db): void
{
    $before = $GLOBALS['checks'];
    $u = secondTenant($db); $tenant = $u['tenant_id'];
    $platform = sharedPlatform($db); $platform['tenant'] = $tenant;
    // Calendarios por pais con un festivo ya pasado cada uno (la recompilacion por cambio de pais los recorre).
    $cal = [];
    foreach (['CO' => 'Colombia', 'HN' => 'Honduras', 'US' => 'Estados Unidos'] as $code => $name) {
        $db->run('INSERT INTO holiday_calendars (tenant_id,id,name,country_code,is_default) VALUES (?,?,?,?,?)', [$tenant, $cal[$code] = Util::bin(Util::uuid()), $name, $code, (int) ($code === 'CO')]);
        $db->run('INSERT INTO holidays (tenant_id,id,day,name,calendar_id) VALUES (?,?,UTC_DATE()-INTERVAL 3 DAY,?,?)', [$tenant, Util::bin(Util::uuid()), "Festivo $code", $cal[$code]]);
    }
    $src = expect(adminRequest($platform, 'POST', '/data-sources', ['name' => 'AZC One puente', 'base_url' => 'https://127.0.0.1:9/api/keeper/v1', 'company' => 'grupo-azc', 'token' => 't']), 201, 'bridge source');
    $sid = Util::bin($src['id']);
    $owned = ['empresas', 'firmas', 'sedes', 'areas', 'cargos', 'horarios', 'personas', 'jerarquia'];
    $owners = expect(adminRequest($platform, 'PUT', '/data-domains', ['domains' => array_map(fn ($d) => ['domain' => $d, 'source_id' => $src['id']], $owned)]), 200, 'bridge owns catalogs and people');
    check(count($owners['data']) === 10 && count(array_filter($owners['data'], fn ($d) => $d['source_id'] === $src['id'])) === 8, 'empresas is a data domain');

    $person = fn (int $n, array $extra = []) => $extra + ['external_id' => "one-emp-$n", 'document' => (string) (5550000 + $n), 'display_name' => "Empleado $n", 'email' => "e$n@azc.test",
        'company_external_id' => 'one-company-azc-legal', 'firm_external_id' => 'one-firm-1', 'site_external_id' => 'one-sede-1', 'area_external_id' => 'one-area-1',
        'position_external_id' => 'one-cargo-1', 'schedule_external_id' => 'one-hor-1', 'reports_to_external_id' => null, 'status' => 'active', 'hired_on' => null, 'terminated_on' => null];
    $world = [
        'org-units' => [
            ['external_id' => 'one-company-azc-legal', 'kind' => 'company', 'name' => 'AZC Legal', 'parent_external_id' => null, 'active' => true],
            ['external_id' => 'one-company-bilinguelaw', 'kind' => 'company', 'name' => 'BilingueLaw', 'parent_external_id' => null, 'active' => true],
            ['external_id' => 'one-firm-1', 'kind' => 'firm', 'name' => 'Firma Uno', 'parent_external_id' => null, 'active' => true],
            ['external_id' => 'one-sede-1', 'kind' => 'site', 'name' => 'Palmetto Cali', 'parent_external_id' => null, 'active' => true, 'country_code' => 'CO', 'city' => '  Cali  '],
            ['external_id' => 'one-sede-2', 'kind' => 'site', 'name' => 'Granada', 'parent_external_id' => null, 'active' => true, 'country_code' => 'hn', 'city' => 'Tegucigalpa'],
            ['external_id' => 'one-sede-3', 'kind' => 'site', 'name' => 'Sede rara', 'parent_external_id' => null, 'active' => true, 'country_code' => 'COL', 'city' => null],
            ['external_id' => 'one-area-1', 'kind' => 'area', 'name' => 'Litigio', 'parent_external_id' => null, 'active' => true],
            ['external_id' => 'one-cargo-1', 'kind' => 'position', 'name' => 'Abogado', 'parent_external_id' => null, 'active' => true]],
        'schedules' => [['external_id' => 'one-hor-1', 'name' => 'L-V 08:00-16:30 (CO)', 'timezone' => 'America/Bogota', 'active' => true,
            'days' => array_map(fn ($w) => ['weekday' => $w, 'start_local' => '08:00', 'end_local' => '16:30', 'lunch' => null], [1, 2, 3, 4, 5])]],
        'people' => [
            $person(1),
            $person(2, ['firm_external_id' => null, 'area_external_id' => null, 'site_external_id' => 'one-sede-2', 'company_external_id' => 'one-company-bilinguelaw']),
            array_diff_key($person(3, ['schedule_external_id' => null]), ['area_external_id' => true, 'company_external_id' => true]),
            $person(4, ['email' => 'E1@azc.test']),
            $person(5, ['area_external_id' => 'one-area-999']),
            $person(6, ['area_external_id' => 'one-firm-1']),
            $person(7, ['site_external_id' => null]),
            $person(8, ['company_external_id' => 123]),
        ],
    ];
    $called = [];
    $sync = new DataSync($db, function (array $source, string $endpoint, ?string $since) use (&$world, &$called): array {
        $called[$endpoint] = true;
        return ['schema' => DataSync::SCHEMA, 'company' => 'grupo-azc', 'generated_at' => '2026-10-06T13:00:00Z', 'complete' => $since === null, 'next_cursor' => null, 'items' => $world[$endpoint]];
    });
    $run = fn (string $kind, bool $override = false) => $db->one('SELECT * FROM sync_runs WHERE tenant_id=? AND id=?', [$tenant, $db->transaction(fn () => $sync->run($tenant, $sid, $kind, 'manual', $override, false))]);
    $items = fn (array $r, string $action) => $db->run('SELECT external_id,reason FROM sync_items WHERE tenant_id=? AND run_id=? AND action=? ORDER BY external_id', [$tenant, $r['id'], $action])->fetchAll();
    $byExt = fn (string $ext) => $db->one("SELECT u.* FROM users u JOIN entity_links l ON l.tenant_id=u.tenant_id AND l.entity_type='user' AND l.entity_id=u.id WHERE l.tenant_id=? AND l.source_id=? AND l.external_id=?", [$tenant, $sid, $ext]);
    $unit = fn (string $ext) => $db->one("SELECT o.* FROM org_units o JOIN entity_links l ON l.tenant_id=o.tenant_id AND l.entity_type='org_unit' AND l.entity_id=o.id WHERE l.tenant_id=? AND l.source_id=? AND l.external_id=?", [$tenant, $sid, $ext]);
    $name = fn (?string $id) => $id === null ? null : ($db->one('SELECT name FROM org_units WHERE tenant_id=? AND id=?', [$tenant, $id]) ?? $db->one('SELECT name FROM schedules WHERE tenant_id=? AND id=?', [$tenant, $id]))['name'];
    $open = fn (array $p) => $db->one('SELECT * FROM user_assignments WHERE tenant_id=? AND user_id=? AND ends_at IS NULL', [$tenant, $p['id']]);

    // 1) Primera vuelta completa: dominios recien asignados -> espera aprobacion; aprobada, termina aplicada aunque haya
    // un correo repetido y referencias malas.
    $held = $run('full');
    check($held['status'] === 'blocked' && $held['blocked_reason'] === 'domains_changed', 'new domains wait for approval ' . $held['status'] . ' ' . $held['blocked_reason']);
    $first = $run('full', true);
    check($first['status'] === 'applied' && $first['error_code'] === null, 'bridge run applied despite item errors ' . $first['status'] . ' ' . $first['error_code']);
    check(!isset($called['holidays']) && !isset($called['absences']), 'holidays and absences stay with Keeper (not fetched)');
    $counts = json_decode($first['counts'], true);
    check(($counts['empresas']['create'] ?? 0) === 2 && ($counts['personas']['create'] ?? 0) === 3, 'companies and people created ' . $first['counts']);
    $errors = array_column($items($first, 'error'), 'reason', 'external_id');
    check($errors === ['one-emp-4' => 'email_in_use', 'one-emp-5' => 'unknown_reference', 'one-emp-6' => 'unknown_reference', 'one-emp-7' => 'missing_site', 'one-emp-8' => 'invalid_reference', 'one-sede-3' => 'invalid_item'], 'item errors ' . json_encode($errors));
    // K3: pais en mayusculas, ciudad sin espacios de mas.
    $palmetto = $unit('one-sede-1'); $granada = $unit('one-sede-2');
    check($palmetto['country_code'] === 'CO' && $palmetto['city'] === 'Cali' && $granada['country_code'] === 'HN' && $granada['city'] === 'Tegucigalpa', 'site country and city from source');
    check($unit('one-company-azc-legal')['kind'] === 'company', 'company unit created');
    // K1: empresa de la persona y de su asignacion.
    $p1 = $byExt('one-emp-1');
    check($name($p1['company_id']) === 'AZC Legal' && $open($p1)['company_id'] === $p1['company_id'], 'person company from source');
    // K2: sin area ni firma -> "Sin área" / "Sin firma"; sin horario -> "Sin horario"; sin empresa -> vacia.
    $p2 = $byExt('one-emp-2'); $p3 = $byExt('one-emp-3');
    check($name($p2['area_id']) === 'Sin área' && $name($p2['firm_id']) === 'Sin firma' && $name($p2['company_id']) === 'BilingueLaw' && $p2['site_id'] === $granada['id'], 'person without area and firm accepted');
    check($name($p3['schedule_id']) === 'Sin horario' && $name($p3['area_id']) === 'Sin área' && $p3['company_id'] === null && $name($p3['position_id']) === 'Abogado', 'person without schedule, area or company accepted');
    check(!$byExt('one-emp-4') && !$byExt('one-emp-7'), 'rejected people not created');
    $effective = fn (array $p) => $db->one('SELECT ' . Keeper\HolidayCalendars::effective('a') . ' c FROM user_assignments a WHERE a.tenant_id=? AND a.user_id=? AND a.ends_at IS NULL', [$tenant, $p['id']])['c'];
    check($effective($p2) === $cal['HN'] && $effective($p1) === $cal['CO'], 'Granada people use the HN calendar');
    // Lo que gestiona la fuente no se edita en Keeper: tampoco las empresas.
    $company = expect(adminRequest($platform, 'GET', '/organization/' . Util::id($unit('one-company-azc-legal')['id'])), 200, 'read synced company');
    check(adminRequest($platform, 'PATCH', '/organization/' . $company['id'], ['kind' => 'company', 'name' => 'Otra', 'parent_id' => null, 'active' => true], ['if-match' => '"' . $company['version'] . '"'])[1]['detail'] === 'managed_by_source', 'synced company locked');

    // 2) Incremental: cambio de empresa (nueva asignacion), el area llega, se corrige el correo y una sede cambia de pais.
    $world['people'][0]['company_external_id'] = 'one-company-bilinguelaw';
    $world['people'][1]['area_external_id'] = 'one-area-1';
    $world['people'][3]['email'] = 'e4@azc.test';
    $world['org-units'][3]['country_code'] = 'US';
    $second = $run('incremental');
    check($second['status'] === 'applied', 'second run applied');
    $p1b = $byExt('one-emp-1');
    check($name($p1b['company_id']) === 'BilingueLaw' && $open($p1b)['company_id'] === $p1b['company_id'] && (int) $db->one('SELECT COUNT(*) n FROM user_assignments WHERE tenant_id=? AND user_id=?', [$tenant, $p1['id']])['n'] === 2, 'company change opens a new assignment');
    check($name($byExt('one-emp-2')['area_id']) === 'Litigio', 'area filled later in One reaches Keeper');
    check((bool) $byExt('one-emp-4'), 'person created once the email no longer collides');
    check($unit('one-sede-1')['country_code'] === 'US' && $effective($p1b) === $cal['US'], 'site country change moves its people to that calendar');
    // 3) Sin dueño de "empresas", la empresa de la persona se conserva aunque la fuente mande otra.
    expect(adminRequest($platform, 'PUT', '/data-domains', ['domains' => [['domain' => 'empresas', 'source_id' => null]]]), 200, 'companies back to Keeper');
    $world['people'][0]['company_external_id'] = 'one-company-azc-legal';
    $world['people'][0]['display_name'] = 'Empleado Uno';
    $run('incremental');
    $p1c = $byExt('one-emp-1');
    check($p1c['display_name'] === 'Empleado Uno' && $p1c['company_id'] === $p1b['company_id'], 'company kept when Keeper owns empresas');
    echo 'PASS one bridge sync (' . ($GLOBALS['checks'] - $before) . " assertions)\n";
    syncBrakesTests($db);
}

function holidayCalendarSyncTests(Database $db): void
{
    $before = $GLOBALS['checks'];
    $world = [
        'org-units' => [['external_id' => 'sede-1', 'kind' => 'site', 'name' => 'Sede One', 'active' => true, 'country_code' => 'CO']],
        'schedules' => [['external_id' => 'horario-1', 'name' => 'Jornada One', 'timezone' => 'America/Bogota', 'active' => true,
            'days' => array_map(fn ($w) => ['weekday' => $w, 'start_local' => '08:00', 'end_local' => '17:00', 'lunch' => ['start_local' => '12:00', 'end_local' => '13:00']], [1, 2, 3, 4, 5])]],
        'holiday-calendars' => [['external_id' => 'festcal-0', 'name' => 'Sin festivos', 'active' => true],
            ['external_id' => 'festcal-1', 'name' => 'Calendario A', 'active' => true], ['external_id' => 'festcal-2', 'name' => 'Calendario B', 'active' => true]],
        'holidays' => [['external_id' => 'fest-1', 'day' => '2026-09-07', 'name' => 'Solo A', 'calendar_external_id' => 'festcal-1'],
            ['external_id' => 'fest-2', 'day' => '2026-09-08', 'name' => 'Solo B', 'calendar_external_id' => 'festcal-2'],
            ['external_id' => 'fest-3', 'day' => '2026-09-09', 'name' => 'Compartido A', 'calendar_external_id' => 'festcal-1'],
            ['external_id' => 'fest-4-comp', 'day' => '2026-09-09', 'name' => 'Compartido B compensatorio', 'calendar_external_id' => 'festcal-2']],
        'people' => [],
    ];
    foreach (['festcal-1', 'festcal-2', 'festcal-0', null, null] as $i => $cal) {
        $world['people'][] = ['external_id' => 'emp-' . ($i + 1), 'document' => '901000' . $i, 'display_name' => 'Persona calendario ' . $i,
            'site_external_id' => 'sede-1', 'schedule_external_id' => 'horario-1', 'status' => 'active', 'holiday_calendar_external_id' => $cal];
    }
    unset($world['people'][4]['holiday_calendar_external_id']);
    $brokenPage = false; $calls = [];
    $sync = new DataSync($db, function (array $source, string $endpoint, ?string $since, ?string $cursor) use (&$world, &$brokenPage, &$calls): array {
        $calls[] = [$endpoint, $cursor];
        check(DataSync::open($source['config_ciphertext'])['company'] === 'grupo', 'calendar source uses the group scope');
        $offset = $cursor === null ? 0 : (int) $cursor;
        $items = $world[$endpoint] ?? [];
        return ['schema' => DataSync::SCHEMA, 'company' => 'grupo', 'generated_at' => '2026-11-03T12:00:00Z',
            'complete' => $since === null && !($brokenPage && $endpoint === 'holiday-calendars' && $offset > 0),
            'next_cursor' => $offset + 2 < count($items) ? (string) ($offset + 2) : null, 'items' => array_slice($items, $offset, 2)];
    });
    $run = fn (array $f, string $kind = 'full', bool $simulate = false) => $db->one('SELECT * FROM sync_runs WHERE tenant_id=? AND id=?',
        [$f['tenant'], $db->transaction(fn () => $sync->run($f['tenant'], $f['source'], $kind, 'manual', true, $simulate))]);
    $linked = fn (array $f, string $type, string $ext) => $db->one('SELECT entity_id FROM entity_links WHERE tenant_id=? AND source_id=? AND entity_type=? AND external_id=?', [$f['tenant'], $f['source'], $type, $ext])['entity_id'] ?? null;
    $user = fn (array $f, int $n) => $db->one('SELECT * FROM users WHERE tenant_id=? AND id=?', [$f['tenant'], $linked($f, 'user', 'emp-' . $n)]);
    $snapshot = fn (array $f) => array_map(fn ($table) => $db->run("SELECT * FROM $table WHERE tenant_id=? ORDER BY id", [$f['tenant']])->fetchAll(), ['users', 'holiday_calendars', 'holidays']);
    $fixtures = [];
    for ($n = 0; $n < 2; $n++) {
        $u = secondTenant($db); $tenant = $u['tenant_id'];
        $platform = sharedPlatform($db); $platform['tenant'] = $tenant;
        $src = expect(adminRequest($platform, 'POST', '/data-sources', ['name' => 'One grupo', 'base_url' => 'https://127.0.0.1:9/api/keeper/v1', 'company' => 'grupo', 'token' => 'test']), 201, 'group calendar source');
        $f = ['tenant' => $tenant, 'source' => Util::bin($src['id']), 'platform' => $platform];
        $domains = ['sedes', 'horarios', 'personas'];
        expect(adminRequest($platform, 'PUT', '/data-domains', ['domains' => array_map(fn ($d) => ['domain' => $d, 'source_id' => $src['id']], $domains)]), 200, 'people and organization first');
        $calls = [];
        check($run($f)['status'] === 'applied', 'people load before calendar ownership');
        check(!in_array('holiday-calendars', array_column($calls, 0), true) && $user($f, 1)['holiday_calendar_id'] === null, 'calendar assignment untouched while Keeper owns holidays');
        $manual = Util::bin(Util::uuid()); $manualHoliday = Util::bin(Util::uuid());
        $db->run("INSERT INTO holiday_calendars (tenant_id,id,name,country_code,is_default) VALUES (?,?,'Pais manual','CO',TRUE)", [$tenant, $manual]);
        $db->run("INSERT INTO holidays (tenant_id,id,calendar_id,day,name) VALUES (?,?,?,'2026-09-07','Manual misma fecha')", [$tenant, $manualHoliday, $manual]);
        $f += ['manual' => $manual, 'manualHoliday' => $manualHoliday];
        $db->run("UPDATE user_assignments SET starts_at='2026-09-01' WHERE tenant_id=?", [$tenant]);
        foreach ([1, 2, 3] as $p) {
            $person = $user($f, $p);
            $assignment = $db->one('SELECT id FROM user_assignments WHERE tenant_id=? AND user_id=? AND ends_at IS NULL', [$tenant, $person['id']])['id'];
            foreach (['2026-09-07', '2026-09-08', '2026-09-09', '2026-09-11'] as $day) {
                $db->run("INSERT INTO day_summary (tenant_id,day,user_id,user_assignment_id,active_seconds,idle_seconds,productive_seconds,expected_seconds,coverage_percent,productivity_percent,calculation_version,timezone,calculated_at,data_through,window_active_seconds)
                    VALUES (?,?,?,?,14400,0,14400,32400,0,NULL,'calendar-sync','America/Bogota',UTC_TIMESTAMP(6),UTC_TIMESTAMP(6),14400)", [$tenant, $day, $person['id'], $assignment]);
            }
        }
        expect(adminRequest($platform, 'PUT', '/data-domains', ['domains' => [['domain' => 'festivos', 'source_id' => $src['id']]]]), 200, 'holidays enabled after people');
        $previous = $snapshot($f);
        check($run($f, 'full', true)['status'] === 'simulated' && $snapshot($f) === $previous, 'calendar simulation rolls back calendars holidays and assignments');
        check($run($f)['status'] === 'applied', 'calendar full sync applied');
        foreach ([1 => 'festcal-1', 2 => 'festcal-2', 3 => 'festcal-0'] as $p => $ext) {
            check($user($f, $p)['holiday_calendar_id'] === $linked($f, 'holiday_calendar', $ext), 'person assigned to ' . $ext);
        }
        check($user($f, 4)['holiday_calendar_id'] === null && $user($f, 5)['holiday_calendar_id'] === null, 'null and omitted person calendar retain country fallback');
        check((int) $db->one("SELECT COUNT(DISTINCT calendar_id) n FROM holidays WHERE tenant_id=? AND day='2026-09-09'", [$tenant])['n'] === 2, 'shared day exists in two distinct calendars');
        check($linked($f, 'holiday', 'fest-1') !== $manualHoliday && $db->one('SELECT name FROM holidays WHERE tenant_id=? AND id=?', [$tenant, $manualHoliday])['name'] === 'Manual misma fecha', 'manual same-day holiday is never adopted');
        $stable = $snapshot($f);
        check($run($f)['status'] === 'applied' && $snapshot($f) === $stable, 'full calendar resync is idempotent including versions');
        check($run($f, 'incremental')['status'] === 'applied' && $snapshot($f) === $stable, 'incremental calendar resync is idempotent');
        $fixtures[] = $f;
    }
    [$f, $other] = $fixtures; $tenant = $f['tenant'];
    check($linked($f, 'holiday_calendar', 'festcal-1') !== $linked($other, 'holiday_calendar', 'festcal-1'), 'same external ids resolve separately in two tenants');
    $otherBefore = $snapshot($other);
    Keeper\ActivityCalendar::create($db, $tenant, '2026-09-07', '2026-09-10');
    try {
        foreach ([1 => [0, 32400, 0], 2 => [32400, 0, 0], 3 => [32400, 32400, 32400], 4 => [0, 32400, 32400], 5 => [0, 32400, 32400]] as $p => $expected) {
            $rows = $db->run('SELECT c.day,' . Keeper\ActivityCalendar::expected() . ' expected,' . Keeper\ActivityCalendar::workday() . ' workday FROM user_assignments a JOIN activity_calendar c ON c.schedule_id=a.schedule_id WHERE a.tenant_id=? AND a.user_id=? ORDER BY c.day', [$tenant, $user($f, $p)['id']])->fetchAll();
            check(array_map('intval', array_column($rows, 'expected')) === $expected, 'expected schedule follows calendar for person ' . $p);
            check(array_map('intval', array_column($rows, 'workday')) === array_map(fn ($s) => $s ? 28800 : 0, $expected), 'productivity workday follows calendar for person ' . $p);
        }
    } finally { $db->run('DROP TEMPORARY TABLE IF EXISTS activity_calendar'); }
    $expected = fn (int $p, string $day) => (int) $db->one('SELECT expected_seconds FROM day_summary WHERE tenant_id=? AND user_id=? AND day=?', [$tenant, $user($f, $p)['id'], $day])['expected_seconds'];
    check($expected(1, '2026-09-07') === 0 && $expected(2, '2026-09-07') === 32400 && $expected(3, '2026-09-09') === 32400, 'stored day summaries refreshed after assignment');
    foreach ([1 => null, 2 => 50] as $p => $percent) {
        $report = expect(adminRequest($f['platform'], 'GET', '/users/' . Util::id($user($f, $p)['id']) . '/activity?from=2026-09-07&to=2026-09-08'), 200, 'synced calendar productivity API');
        check($report['totals']['productivity_percent'] === $percent || ($percent !== null && $report['totals']['productivity_percent'] == $percent), 'API productivity uses personal calendar ' . $p);
    }
    $world['holidays'][0]['day'] = '2026-09-11';
    $world['holiday-calendars'][1]['name'] = 'Calendario A actualizado';
    check($run($f, 'incremental')['status'] === 'applied' && $expected(1, '2026-09-07') === 32400 && $expected(1, '2026-09-11') === 0, 'moving a holiday refreshes both old and new dates');
    check($db->one('SELECT name FROM holiday_calendars WHERE tenant_id=? AND id=?', [$tenant, $linked($f, 'holiday_calendar', 'festcal-1')])['name'] === 'Calendario A actualizado', 'calendar renamed through stable link');
    $world['people'][0]['holiday_calendar_external_id'] = 'festcal-2';
    check($run($f, 'incremental')['status'] === 'applied' && $expected(1, '2026-09-08') === 0 && $expected(1, '2026-09-11') === 32400, 'changing personal calendar refreshes old and new holiday dates');
    $world['people'][0]['holiday_calendar_external_id'] = null;
    check($run($f, 'incremental')['status'] === 'applied' && $expected(1, '2026-09-07') === 0 && $expected(1, '2026-09-08') === 32400, 'explicit null returns to country fallback');
    $world['people'][0]['holiday_calendar_external_id'] = 'festcal-1';
    $saved = $snapshot($f); $brokenPage = true;
    check($run($f)['error_code'] === 'incomplete_list' && $snapshot($f) === $saved, 'incomplete calendar pagination cannot delete or assign');
    $brokenPage = false;
    $world['holidays'][0]['calendar_external_id'] = 'festcal-404';
    check($run($f)['error_code'] === 'unknown_holiday_calendar' && $snapshot($f) === $saved, 'unknown holiday calendar rolls back the full sync');
    $world['holidays'][0]['calendar_external_id'] = 'festcal-1';
    $world['people'][0]['holiday_calendar_external_id'] = 'festcal-404';
    check($run($f)['error_code'] === 'unknown_holiday_calendar' && $snapshot($f) === $saved, 'unknown person calendar rolls back the full sync');
    $world['people'][0]['holiday_calendar_external_id'] = 'festcal-1';
    $world['holiday-calendars'][1]['name'] = 'Pais manual';
    check($run($f)['error_code'] === 'holiday_calendar_name_conflict' && $snapshot($f) === $saved, 'same-name manual calendar is never adopted or overwritten');
    $world['holiday-calendars'][1]['name'] = 'Calendario A actualizado';
    $manualInside = Util::bin(Util::uuid()); $removedCal = $linked($f, 'holiday_calendar', 'festcal-2');
    $db->run("INSERT INTO holidays (tenant_id,id,calendar_id,day,name) VALUES (?,?,?,'2026-09-10','Manual dentro de One')", [$tenant, $manualInside, $removedCal]);
    $world['holiday-calendars'] = array_slice($world['holiday-calendars'], 0, 2);
    $world['holidays'] = [$world['holidays'][0]];
    $world['people'][1]['holiday_calendar_external_id'] = 'festcal-0';
    check($run($f)['status'] === 'applied', 'full reconciliation removes missing source holidays');
    check($linked($f, 'holiday', 'fest-2') === null && $linked($f, 'holiday', 'fest-3') === null && $linked($f, 'holiday', 'fest-4-comp') === null, 'all missing source holiday links removed');
    check(!(bool) $db->one('SELECT active FROM holiday_calendars WHERE tenant_id=? AND id=?', [$tenant, $removedCal])['active'], 'missing source calendar deactivated');
    check((bool) $db->one('SELECT id FROM holidays WHERE tenant_id=? AND id=?', [$tenant, $manualInside]) && (bool) $db->one('SELECT id FROM holidays WHERE tenant_id=? AND id=?', [$tenant, $f['manualHoliday']]), 'full sync preserves manual holidays inside and outside source calendars');
    check((bool) $db->one('SELECT active FROM holiday_calendars WHERE tenant_id=? AND id=?', [$tenant, $f['manual']])['active'], 'manual calendar stays active');
    check($expected(1, '2026-09-09') === 32400 && $expected(2, '2026-09-08') === 32400, 'removal restores stored expected time');
    check($snapshot($other) === $otherBefore, 'group reconciliation in one tenant leaves the other tenant unchanged');
    // El dominio festivos puede actualizar personas ya vinculadas sin ser dueno de personas.
    expect(adminRequest($f['platform'], 'PUT', '/data-domains', ['domains' => [['domain' => 'personas', 'source_id' => null]]]), 200, 'release people ownership');
    $world['people'][0]['display_name'] = 'No importar nombre';
    $world['people'][0]['holiday_calendar_external_id'] = 'festcal-0';
    check($run($f)['status'] === 'applied' && $user($f, 1)['holiday_calendar_id'] === $linked($f, 'holiday_calendar', 'festcal-0') && $user($f, 1)['display_name'] !== 'No importar nombre', 'holiday owner changes calendar only on linked people');
    echo 'PASS holiday calendar sync (' . ($GLOBALS['checks'] - $before) . " assertions)\n";
}

// Frenos antes de los datos reales (revision one-keeper-changes-review.md): altas masivas y dominios recien asignados
// (K5/I4), referencias que bajan a "Sin ..." (I1), SAVEPOINT por persona (I2), bloqueo por dominio (I3), placeholders por
// marca (M1) y correo de una persona retirada (M3).
function syncBrakesTests(Database $db): void
{
    $before = $GLOBALS['checks'];
    $u = secondTenant($db); $tenant = $u['tenant_id'];
    $platform = sharedPlatform($db); $platform['tenant'] = $tenant;
    $src = expect(adminRequest($platform, 'POST', '/data-sources', ['name' => 'Fuente con frenos', 'base_url' => 'https://127.0.0.1:9/api/keeper/v1', 'company' => 'grupo-azc', 'token' => 't']), 201, 'brakes source');
    $sid = Util::bin($src['id']);
    $srcNow = fn () => array_values(array_filter(expect(adminRequest($platform, 'GET', '/data-sources'), 200, 'list sources')['data'], fn ($s) => $s['id'] === $src['id']))[0];
    check($srcNow()['approval_required'] === false, 'new source has nothing to approve');
    $owned = ['firmas', 'sedes', 'areas', 'cargos', 'horarios', 'personas'];
    expect(adminRequest($platform, 'PUT', '/data-domains', ['domains' => array_map(fn ($d) => ['domain' => $d, 'source_id' => $src['id']], $owned)]), 200, 'brakes source owns people');
    check($srcNow()['approval_required'] === true, 'assigning domains requires an approved run');
    $person = fn (int $n, array $extra = []) => $extra + ['external_id' => "brk-$n", 'document' => (string) (8880000 + $n), 'display_name' => "Freno $n", 'email' => "f$n@azc.test",
        'firm_external_id' => 'brk-f', 'site_external_id' => 'brk-s', 'area_external_id' => 'brk-a1', 'position_external_id' => 'brk-c1', 'schedule_external_id' => 'brk-h', 'status' => 'active'];
    $world = [
        'org-units' => [['external_id' => 'brk-f', 'kind' => 'firm', 'name' => 'Firma F', 'parent_external_id' => null, 'active' => true],
            ['external_id' => 'brk-s', 'kind' => 'site', 'name' => 'Sede F', 'parent_external_id' => null, 'active' => true, 'country_code' => 'CO', 'city' => 'Cali'],
            ['external_id' => 'brk-a1', 'kind' => 'area', 'name' => 'Litigio F', 'parent_external_id' => null, 'active' => true],
            ['external_id' => 'brk-c1', 'kind' => 'position', 'name' => 'Abogado F', 'parent_external_id' => null, 'active' => true],
            ['external_id' => 'brk-cd', 'kind' => 'position', 'name' => 'Director F', 'parent_external_id' => null, 'active' => true]],
        'schedules' => [['external_id' => 'brk-h', 'name' => 'L-V F', 'timezone' => 'America/Bogota', 'active' => true,
            'days' => array_map(fn ($w) => ['weekday' => $w, 'start_local' => '08:00', 'end_local' => '17:00', 'lunch' => null], [1, 2, 3, 4, 5])]],
        'people' => array_merge(array_map(fn ($n) => $person($n), range(1, 7)), [$person(8, ['position_external_id' => 'brk-cd'])]),
    ];
    $sync = new DataSync($db, function (array $source, string $endpoint, ?string $since) use (&$world): array {
        return ['schema' => DataSync::SCHEMA, 'company' => 'grupo-azc', 'generated_at' => '2026-10-06T15:00:00Z', 'complete' => $since === null, 'next_cursor' => null, 'items' => $world[$endpoint] ?? []];
    });
    $run = fn (string $kind, bool $override = false, ?bool $simulate = null) => $db->one('SELECT * FROM sync_runs WHERE tenant_id=? AND id=?', [$tenant, $db->transaction(fn () => $sync->run($tenant, $sid, $kind, $override ? 'approval' : 'schedule', $override, $simulate))]);
    $linkedActive = fn () => (int) $db->one("SELECT COUNT(*) n FROM entity_links l JOIN users u ON u.tenant_id=l.tenant_id AND u.id=l.entity_id WHERE l.tenant_id=? AND l.source_id=? AND l.entity_type='user' AND u.status='active'", [$tenant, $sid])['n'];
    $byExt = fn (string $ext) => $db->one("SELECT u.* FROM users u JOIN entity_links l ON l.tenant_id=u.tenant_id AND l.entity_type='user' AND l.entity_id=u.id WHERE l.tenant_id=? AND l.source_id=? AND l.external_id=?", [$tenant, $sid, $ext]);
    $name = fn (?string $id) => $id === null ? null : $db->one('SELECT name FROM org_units WHERE tenant_id=? AND id=?', [$tenant, $id])['name'];
    $fields = fn (array $r, string $ext) => json_decode((string) ($db->one("SELECT fields FROM sync_items WHERE tenant_id=? AND run_id=? AND external_id=? AND action='update'", [$tenant, $r['id'], $ext])['fields'] ?? 'null'), true);

    // 1) K5: la primera carga (8 altas, base vinculada 0 -> limite 5) no se aplica sola, ni simulada ni en vivo.
    $sim = $run('full', false, true);
    check($sim['status'] === 'blocked' && $sim['blocked_reason'] === 'too_many_creations' && (int) $sim['simulate'] === 1, 'simulated first load shows the creation brake ' . $sim['status'] . ' ' . $sim['blocked_reason']);
    expect(adminRequest($platform, 'PATCH', '/data-sources/' . $src['id'], ['mode' => 'live'], ['if-match' => '"' . $srcNow()['version'] . '"']), 200, 'brakes source live');
    $first = $run('full');
    check($first['status'] === 'blocked' && $first['blocked_reason'] === 'too_many_creations' && $linkedActive() === 0, 'live first load blocked, nothing applied');
    check(!$db->one("SELECT id FROM org_units WHERE tenant_id=? AND name='Litigio F'", [$tenant]), 'blocked first load leaves no org units');
    check((int) $db->one("SELECT COUNT(*) n FROM sync_items WHERE tenant_id=? AND run_id=? AND domain='personas' AND action='create'", [$tenant, $first['id']])['n'] === 8, 'blocked run lists the 8 creations for review');
    // Aprobar (approveSyncRun = misma vuelta con override) la aplica completa y limpia la marca de aprobacion.
    $approved = $run($first['kind'], true);
    check($approved['status'] === 'applied' && $approved['kind'] === 'full' && $linkedActive() === 8, 'approval applies the first load');
    check($srcNow()['approval_required'] === false, 'approved run clears the approval mark');

    // 2) Un dominio recien asignado: la siguiente vuelta del cron es completa y espera aprobacion aunque cambie poco.
    expect(adminRequest($platform, 'PUT', '/data-domains', ['domains' => [['domain' => 'jerarquia', 'source_id' => $src['id']]]]), 200, 'hierarchy newly assigned');
    $world['people'][1]['reports_to_external_id'] = 'brk-1';
    $gate = $run('incremental');
    check($gate['status'] === 'blocked' && $gate['blocked_reason'] === 'domains_changed' && $gate['kind'] === 'full', 'newly assigned domain blocks the next run ' . $gate['status'] . ' ' . $gate['blocked_reason'] . ' ' . $gate['kind']);
    check($byExt('brk-2')['reports_to_user_id'] === null, 'nothing applied before approval');
    check($run('incremental', false, true)['status'] === 'simulated', 'simulation is not held by the approval mark');
    $ok = $run('full', true);
    check($ok['status'] === 'applied' && $byExt('brk-2')['reports_to_user_id'] === $byExt('brk-1')['id'], 'approval applies the new domain ' . $ok['status'] . ' ' . $ok['counts'] . ' ' . json_encode($db->run('SELECT external_id,action,reason FROM sync_items WHERE tenant_id=? AND run_id=?', [$tenant, $ok['id']])->fetchAll()));
    check($run('incremental')['status'] === 'applied', 'after approval the cron applies again');
    // Quitar un dominio no pide aprobacion.
    expect(adminRequest($platform, 'PUT', '/data-domains', ['domains' => [['domain' => 'jerarquia', 'source_id' => null]]]), 200, 'hierarchy back to Keeper');
    check($srcNow()['approval_required'] === false, 'removing a domain needs no approval');

    // 3) I1: una referencia que pasa de un valor real a "Sin ..." se registra con nombres, cuenta para el freno y no
    // cambia el monitoreo (un directivo excluido sigue excluido aunque su cargo pase a "Sin cargo").
    $director = $byExt('brk-8');
    $db->run('INSERT INTO monitoring_excluded_positions (tenant_id,position_id) VALUES (?,?)', [$tenant, $director['position_id']]);
    $world['people'][0]['area_external_id'] = null;
    $world['people'][7]['position_external_id'] = null;
    $down = $run('incremental');
    check($down['status'] === 'applied', 'two downgrades fit the brake');
    check($fields($down, 'brk-1')['area_id'] === ['Litigio F', 'Sin área'], 'downgrade logged with old and new unit ' . json_encode($fields($down, 'brk-1')));
    $d2 = $byExt('brk-8');
    check($name($d2['position_id']) === 'Sin cargo' && Keeper\RoleRules::monitoring($db, $d2) === 'excluded', 'director moved to "Sin cargo" stays excluded from monitoring');
    $p3 = $byExt('brk-3');
    foreach (range(1, 6) as $i) { $world['people'][$i]['area_external_id'] = null; }
    $mass = $run('incremental');
    check($mass['status'] === 'blocked' && $mass['blocked_reason'] === 'too_many_org_changes', 'mass downgrade to "Sin área" blocked ' . $mass['status'] . ' ' . $mass['blocked_reason']);
    check($byExt('brk-3')['area_id'] === $p3['area_id'] && $fields($mass, 'brk-3')['area_id'] === ['Litigio F', 'Sin área'], 'blocked downgrade not applied, visible with names');
    foreach (range(1, 6) as $i) { $world['people'][$i]['area_external_id'] = 'brk-a1'; }

    // 4) M1: una unidad real de la fuente llamada "Sin área" no se confunde con el placeholder (marca, no nombre).
    $placeholder = $byExt('brk-1')['area_id'];
    $world['org-units'][] = ['external_id' => 'brk-a-sin', 'kind' => 'area', 'name' => 'Sin área', 'parent_external_id' => null, 'active' => true];
    $world['people'][2]['area_external_id'] = 'brk-a-sin';
    $coll = $run('incremental');
    $oneSin = $db->one("SELECT o.* FROM org_units o JOIN entity_links l ON l.tenant_id=o.tenant_id AND l.entity_id=o.id WHERE l.tenant_id=? AND l.source_id=? AND l.external_id='brk-a-sin'", [$tenant, $sid]);
    check($coll['status'] === 'applied' && $oneSin && $oneSin['id'] !== $placeholder && !$oneSin['placeholder'], 'source unit named like a placeholder is created apart, not matched by name');
    check($byExt('brk-3')['area_id'] === $oneSin['id'] && $byExt('brk-1')['area_id'] === $placeholder, 'people keep their own unit and the placeholder');
    $dto = expect(adminRequest($platform, 'GET', '/organization/' . Util::id($placeholder)), 200, 'read placeholder unit');
    check($dto['placeholder'] === true && expect(adminRequest($platform, 'GET', '/organization/' . Util::id($oneSin['id'])), 200, 'read source unit')['placeholder'] === false, 'placeholder flag in the unit DTO');

    // 5) I3: bloqueo por dominio. Con "areas" de vuelta en Keeper, el area de una persona vinculada se edita; nombre y cargo no.
    expect(adminRequest($platform, 'PUT', '/data-domains', ['domains' => [['domain' => 'areas', 'source_id' => null]]]), 200, 'areas back to Keeper');
    $p1 = $byExt('brk-1'); $pid = Util::id($p1['id']);
    $dto = expect(adminRequest($platform, 'GET', '/users/' . $pid), 200, 'read linked person');
    $areaEdit = adminRequest($platform, 'PATCH', '/users/' . $pid, ['area_id' => Util::id($u['area_id'])], ['if-match' => '"' . $dto['version'] . '"']);
    check($areaEdit[0] === 200 && $byExt('brk-1')['area_id'] === $u['area_id'], 'area editable when the source does not own areas ' . $areaEdit[0] . ' ' . ($areaEdit[1]['detail'] ?? ''));
    $dto = expect(adminRequest($platform, 'GET', '/users/' . $pid), 200, 'read linked person again');
    check(adminRequest($platform, 'PATCH', '/users/' . $pid, ['display_name' => 'Otro'], ['if-match' => '"' . $dto['version'] . '"'])[1]['detail'] === 'managed_by_source', 'name still locked (personas)');
    check(adminRequest($platform, 'PATCH', '/users/' . $pid, ['position_id' => Util::id($u['position_id'])], ['if-match' => '"' . $dto['version'] . '"'])[1]['detail'] === 'managed_by_source', 'position still locked (cargos)');
    check(adminRequest($platform, 'PATCH', '/users/' . $pid, ['area_id' => Util::id($u['area_id']), 'email' => 'x@azc.test'], ['if-match' => '"' . $dto['version'] . '"'])[1]['detail'] === 'managed_by_source', 'mixed patch with an owned field locked');
    $run('incremental');
    check($byExt('brk-1')['area_id'] === $u['area_id'], 'sync keeps the area edited in Keeper');

    // 6) I2: un 1062 que emailTaken no ve (choque forzado al vincular) revierte solo a esa persona; el resto se aplica.
    $world['people'][] = $person(20, ['display_name' => 'Persona choque']);
    $world['people'][] = $person(21);
    $db->run("CREATE TRIGGER sync_dup_probe BEFORE INSERT ON entity_links FOR EACH ROW BEGIN IF NEW.external_id='brk-20' THEN SIGNAL SQLSTATE '23000' SET MYSQL_ERRNO=1062, MESSAGE_TEXT='Duplicate entry (prueba)'; END IF; END");
    try {
        foreach ([true, false] as $simulate) {
            $dup = $run('incremental', false, $simulate);
            $errors = $db->run("SELECT external_id,reason FROM sync_items WHERE tenant_id=? AND run_id=? AND action='error'", [$tenant, $dup['id']])->fetchAll();
            $counts = json_decode($dup['counts'], true);
            check($dup['status'] === ($simulate ? 'simulated' : 'applied') && $errors === [['external_id' => 'brk-20', 'reason' => 'duplicate_value']] && ($counts['personas']['create'] ?? 0) === 1 && !isset($counts['personas']['link']),
                'duplicate_value isolates one person ' . ($simulate ? '(simulated) ' : '') . $dup['status'] . ' ' . $dup['counts']);
        }
        check(!$db->one("SELECT id FROM users WHERE tenant_id=? AND display_name='Persona choque'", [$tenant]) && !$db->one("SELECT 1 FROM user_external_refs WHERE tenant_id=? AND external_ref='8880020'", [$tenant]), 'rolled back person leaves no rows');
        check((bool) $byExt('brk-21'), 'rest of the run applied');
    } finally { $db->run('DROP TRIGGER IF EXISTS sync_dup_probe'); }
    array_splice($world['people'], 8, 1);

    // 7) M3: el correo de una persona retirada se libera para una persona activa nueva (y queda registrado).
    $world['people'][6]['status'] = 'inactive';
    $run('incremental');
    $retired = $byExt('brk-7');
    check($retired['status'] === 'inactive' && $retired['email'] === 'f7@azc.test', 'retired person keeps the email until someone needs it');
    $world['people'][] = $person(22, ['email' => 'F7@azc.test']);
    $reuse = $run('incremental');
    check($reuse['status'] === 'applied' && $byExt('brk-22') && $byExt('brk-22')['email'] === 'F7@azc.test' && $byExt('brk-7')['email'] === null, 'email of a retired person goes to the new active person');
    check($db->one("SELECT reason FROM sync_items WHERE tenant_id=? AND run_id=? AND external_id='brk-7' AND action='update'", [$tenant, $reuse['id']])['reason'] === 'email_released', 'release logged on the retired person');

    // 8) Retiradas en la fuente: entran como retiradas (vinculadas, sin plan, "Sin sede"/"Sin ..." si no resuelven), sin
    // quitarle el correo a nadie; cuentan como altas para el freno; solo la fuente las reactiva.
    // Plan por defecto del cliente (planes de persona; los tiers se retiraron en 0049): la sincronizacion no asigna plan propio.
    $defaultPlan = $db->one("SELECT id FROM person_plans WHERE tenant_id=0x00000000000040008000000000000001 AND name='Completo'")['id'];
    $db->run('UPDATE tenants SET default_plan_tenant_id=0x00000000000040008000000000000001,default_plan_id=? WHERE tenant_id=?', [$defaultPlan, $tenant]);
    $world['people'][] = $person(30, ['status' => 'inactive', 'site_external_id' => null, 'area_external_id' => 'brk-no-existe', 'email' => 'f1@azc.test']);
    $i30 = count($world['people']) - 1;
    $ret = $run('incremental');
    $r30 = $byExt('brk-30');
    check($ret['status'] === 'applied' && $r30 && $r30['status'] === 'inactive' && $r30['email'] === null && $byExt('brk-1')['email'] === 'f1@azc.test', 'retired person loaded as retired without taking an email ' . $ret['status'] . ' ' . $ret['counts']);
    check($name($r30['site_id']) === 'Sin sede' && (bool) $db->one('SELECT placeholder FROM org_units WHERE tenant_id=? AND id=?', [$tenant, $r30['site_id']])['placeholder'] && $name($r30['area_id']) === 'Sin área', 'retired person without site goes to the "Sin sede" placeholder');
    check(\Keeper\PersonPlans::functions($db, $tenant, $r30['id']) === [] && $r30['plan_id'] === null && $db->one("SELECT reason FROM sync_items WHERE tenant_id=? AND run_id=? AND external_id='brk-30' AND action='create'", [$tenant, $ret['id']])['reason'] === 'inactive_in_source', 'retired person gets no plan and is logged as a retired creation');
    $run('incremental');
    check($byExt('brk-30')['status'] === 'inactive', 'later runs do not reactivate a retired person');
    foreach (range(31, 36) as $n) { $world['people'][] = $person($n, ['status' => 'inactive']); }
    $many = $run('incremental');
    check($many['status'] === 'blocked' && $many['blocked_reason'] === 'too_many_creations' && !$byExt('brk-31'), 'retired creations count for the creation brake');
    array_splice($world['people'], -6);
    $world['people'][$i30] = $person(30);
    $back = $run('incremental');
    $r30 = $byExt('brk-30');
    check($back['status'] === 'applied' && $r30['status'] === 'active' && $name($r30['site_id']) === 'Sede F' && $r30['plan_id'] === null && \Keeper\PersonPlans::effectivePlan($db, $tenant, $r30['id']) === $defaultPlan, 'source reactivation restores site and the client default plan');
    echo 'PASS sync brakes (' . ($GLOBALS['checks'] - $before) . " assertions)\n";
}

// Decisiones del panel: lote atómico, coincidencias y huérfanas entre empresas.
function syncPanelTests(Database $db): void
{
    $before = $GLOBALS['checks']; $fixtures = [];
    $schedule = static fn ($external, $name) => ['external_id' => $external, 'name' => $name, 'timezone' => 'America/Bogota', 'days' => [['weekday' => 1, 'start_local' => '08:00', 'end_local' => '17:00']]];
    $world = ['org-units' => [
        ['external_id' => 'site', 'kind' => 'site', 'name' => 'SEDE PRINCIPAL', 'active' => true],
        ['external_id' => 'firm', 'kind' => 'firm', 'name' => 'Firma Principal', 'active' => true],
        ['external_id' => 'firm-duplicate', 'kind' => 'firm', 'name' => 'Firma Principal', 'active' => true],
        ['external_id' => 'near-site', 'kind' => 'site', 'name' => 'Centro Empresarix', 'active' => true],
        ['external_id' => 'near-firm', 'kind' => 'firm', 'name' => 'Juridica Regiona', 'active' => true],
        ['external_id' => 'short', 'kind' => 'site', 'name' => 'Calo', 'active' => true],
        ['external_id' => 'different-kind', 'kind' => 'position', 'name' => 'Oficina Especia', 'active' => true]],
        'schedules' => [$schedule('hours', 'HORARIO CENTRAL'), $schedule('near-hours', 'Jornada Nocturn'), $schedule('reject-hours', 'Horario Flexibl')],
        'people' => []];
    $sync = new DataSync($db, static function ($source, $endpoint, $since, $cursor) use (&$world): array {
        return ['schema' => DataSync::SCHEMA, 'generated_at' => '2026-11-04T12:00:00Z', 'complete' => true, 'next_cursor' => null, 'items' => $world[$endpoint] ?? []];
    });
    $run = fn ($f, $simulate = false) => $db->one('SELECT * FROM sync_runs WHERE tenant_id=? AND id=?', [$f['tenant'], $db->transaction(fn () => $sync->run($f['tenant'], $f['source'], 'full', 'manual', true, $simulate))]);
    $pending = fn ($f) => expect(adminRequest($f['platform'], 'GET', '/sync-items/pending'), 200, 'panel pending')['data'];
    $linked = fn ($f, $type, $ext) => $db->one('SELECT entity_id FROM entity_links WHERE tenant_id=? AND source_id=? AND entity_type=? AND external_id=?', [$f['tenant'], $f['source'], $type, $ext])['entity_id'] ?? null;
    $batch = fn ($f, $ids) => adminRequest($f['platform'], 'POST', '/sync-items/resolve-matched', ['ids' => $ids]);
    for ($n = 0; $n < 2; $n++) {
        $u = secondTenant($db); $tenant = $u['tenant_id']; $platform = sharedPlatform($db); $platform['tenant'] = $tenant;
        $source = expect(adminRequest($platform, 'POST', '/data-sources', ['name' => 'One panel', 'base_url' => 'https://127.0.0.1:9']), 201, 'panel source');
        expect(adminRequest($platform, 'PUT', '/data-domains', ['domains' => array_map(fn ($d) => ['domain' => $d, 'source_id' => $source['id']], ['sedes', 'firmas', 'cargos', 'horarios', 'personas'])]), 200, 'panel owners');
        $f = ['tenant' => $tenant, 'source' => Util::bin($source['id']), 'platform' => $platform, 'user' => $u['id'], 'locals' => []];
        foreach ([['site', 'Sede Principal'], ['site', 'Sede Principalx'], ['firm', 'Firma Principal'], ['site', 'Centro Empresarial'], ['firm', 'Jurídica Regional'], ['site', 'Cali'], ['area', 'Oficina Especial']] as [$kind, $name]) {
            $id = Util::bin(Util::uuid()); $f['locals'][$name] = $id;
            $db->run('INSERT INTO org_units (tenant_id,id,kind,name) VALUES (?,?,?,?)', [$tenant, $id, $kind, $name]);
        }
        foreach (['Horario Central', 'Jornada Nocturna', 'Horario Flexible'] as $name) {
            $id = Util::bin(Util::uuid()); $f['locals'][$name] = $id;
            $db->run("INSERT INTO schedules (tenant_id,id,name,timezone,start_local,end_local) VALUES (?,?,?,'America/Bogota','08:00','17:00')", [$tenant, $id, $name]);
        }
        $db->run("INSERT INTO user_external_refs (tenant_id,user_id,source,external_ref) VALUES (?,?,?,'8912345')", [$tenant, $u['id'], $n === 0 ? 'k3:cc' : 'document']);
        $simulation = $run($f, true);
        $simulatedItem = $db->one("SELECT id FROM sync_items WHERE tenant_id=? AND run_id=? AND reason='matched_name' LIMIT 1", [$tenant, $simulation['id']]);
        expect($batch($f, [Util::id($simulatedItem['id'])]), 404, 'simulation candidates cannot be resolved in batch');
        $first = $run($f);
        check($first['status'] === 'applied', 'panel initial run applied');
        $f['pending'] = $pending($f);
        $f['byExt'] = array_column($f['pending'], null, 'external_id');
        $f['orphan'] = array_values(array_filter($f['pending'], fn ($i) => $i['reason'] === 'orphan'))[0];
        check(count(array_filter($f['pending'], fn ($i) => $i['reason'] === 'matched_name')) === 4, 'exact candidates include org units and schedules');
        check(count(array_filter($f['pending'], fn ($i) => $i['reason'] === 'similar_name')) === 4, 'similar candidates include org units and schedules');
        check($f['byExt']['site']['entity_id'] === Util::id($f['locals']['Sede Principal']), 'exact match takes priority over similar name');
        check($f['byExt']['near-site']['local_name'] === 'Centro Empresarial' && $f['byExt']['near-site']['label'] === 'Centro Empresarix', 'distance two accepted and both names exposed for comparison');
        check($linked($f, 'org_unit', 'short') !== $f['locals']['Cali'] && $linked($f, 'org_unit', 'different-kind') !== $f['locals']['Oficina Especial'], 'short names and other kinds are not proposed');
        $fixtures[] = $f;
    }
    [$f, $other] = $fixtures;
    $world['people'] = [['external_id' => 'person', 'document' => '8.912-345', 'display_name' => 'Persona del panel', 'status' => 'active', 'site_external_id' => 'site', 'firm_external_id' => 'firm']];
    foreach ($fixtures as $fixture) {
        // Quitar el pendiente anterior permite probar que no se vuelve a generar por unknown_reference.
        $db->run('DELETE FROM sync_items WHERE tenant_id=? AND id=?', [$fixture['tenant'], Util::bin($fixture['orphan']['id'])]);
        $round = $run($fixture);
        check((int) $db->one("SELECT COUNT(*) n FROM sync_items WHERE tenant_id=? AND run_id=? AND reason='unknown_reference'", [$fixture['tenant'], $round['id']])['n'] === 1, 'person blocked on unconfirmed references');
        check(!$db->one("SELECT 1 FROM sync_items WHERE tenant_id=? AND run_id=? AND reason='orphan'", [$fixture['tenant'], $round['id']]), 'known document never becomes an orphan for either document namespace');
        check(count($pending($fixture)) === 8, 'repeat round does not repeat exact or similar questions');
        // Reconstruir un pendiente antiguo como los existentes antes de esta corrección.
        $db->run("INSERT INTO sync_items (tenant_id,id,run_id,domain,entity_id,action,reason,label,payload) VALUES (?,?,?,'personas',?,'pending','orphan','Persona anterior',?)", [$fixture['tenant'], Util::bin($fixture['orphan']['id']), $round['id'], $fixture['user'], Util::json(['type' => 'orphan'])]);
    }
    $ids = array_column(array_filter($f['pending'], fn ($i) => $i['reason'] === 'matched_name'), 'id');
    expect($batch($f, []), 422, 'empty batch rejected');
    expect($batch($f, [$ids[0], $ids[0]]), 422, 'duplicate IDs rejected');
    expect($batch($f, [$other['byExt']['site']['id']]), 404, 'other tenant ID hidden');
    expect($batch($f, [$ids[0], $other['byExt']['site']['id']]), 404, 'mixed tenant batch rejected');
    foreach ([$f['orphan']['id'], $f['byExt']['near-site']['id'], $f['byExt']['near-hours']['id']] as $excluded) {
        expect($batch($f, [$ids[0], $excluded]), 409, 'orphan and similar names excluded from batch');
    }
    $client = adminFixture($db, $db->one('SELECT * FROM users WHERE tenant_id=? AND id=?', [$f['tenant'], $f['user']]), ['integraciones.ver']); $client['tenant'] = $f['tenant'];
    expect($batch(['platform' => $client], $ids), 403, 'same platform-only permission as individual resolution');
    expect($batch($f, $ids), 409, 'conflicting matches roll back the whole batch');
    check($linked($f, 'org_unit', 'firm') === null && $linked($f, 'org_unit', 'firm-duplicate') === null && $linked($f, 'org_unit', 'site') === null && $linked($f, 'schedule', 'hours') === null, 'no partial links after conflict');
    check(count($pending($f)) === 9, 'no partial resolutions after conflict');
    check(!$db->one("SELECT 1 FROM audit_log WHERE tenant_id=? AND action='resolveMatchedSyncItems'", [$f['tenant']]), 'no success audits survive rollback');
    expect(adminRequest($f['platform'], 'POST', '/sync-items/' . $f['byExt']['firm-duplicate']['id'] . '/resolve', ['approve' => false]), 200, 'reject duplicate firm');
    $ids = array_values(array_diff($ids, [$f['byExt']['firm-duplicate']['id']]));
    $result = expect($batch($f, $ids), 200, 'batch links exact org units and schedule');
    check($result['resolved'] === 3 && $linked($f, 'org_unit', 'site') === $f['locals']['Sede Principal'] && $linked($f, 'schedule', 'hours') === $f['locals']['Horario Central'], 'batch reports real count and correct targets');
    check((int) $db->one("SELECT COUNT(*) n FROM audit_log WHERE tenant_id=? AND action='resolveMatchedSyncItems'", [$f['tenant']])['n'] === 3, 'batch audited per item');
    expect($batch($f, $ids), 409, 'resolved items cannot be replayed');
    check($linked($other, 'org_unit', 'site') === null && count($pending($other)) === 9, 'other tenant untouched by batch');
    foreach (['near-site' => true, 'near-firm' => false, 'near-hours' => true, 'reject-hours' => false] as $ext => $approve) {
        expect(adminRequest($f['platform'], 'POST', '/sync-items/' . $f['byExt'][$ext]['id'] . '/resolve', ['approve' => $approve]), 200, 'resolve similar ' . $ext);
    }
    check($linked($f, 'org_unit', 'near-site') === $f['locals']['Centro Empresarial'] && $linked($f, 'schedule', 'near-hours') === $f['locals']['Jornada Nocturna'], 'similar approvals link the chosen records');
    $run($f, true);
    check($linked($f, 'user', 'person') === null && $db->one('SELECT resolution FROM sync_items WHERE tenant_id=? AND id=?', [$f['tenant'], Util::bin($f['orphan']['id'])])['resolution'] === null, 'simulation rolls back orphan closure and person link');
    $run($f);
    check($linked($f, 'user', 'person') === $f['user'], 'person links after approving dependencies');
    $closed = $db->one('SELECT resolution,resolved_at FROM sync_items WHERE tenant_id=? AND id=?', [$f['tenant'], Util::bin($f['orphan']['id'])]);
    check($closed['resolution'] === 'rejected' && $closed['resolved_at'] !== null, 'old orphan closed automatically without retirement');
    check($db->one('SELECT status FROM users WHERE tenant_id=? AND id=?', [$f['tenant'], $f['user']])['status'] === 'active', 'linked person remains active');
    expect(adminRequest($f['platform'], 'POST', '/sync-items/' . $f['orphan']['id'] . '/resolve', ['approve' => true]), 409, 'obsolete retirement button cannot retire linked person');
    check($db->one('SELECT resolution FROM sync_items WHERE tenant_id=? AND id=?', [$other['tenant'], Util::bin($other['orphan']['id'])])['resolution'] === null, 'orphan closure respects tenant boundary');
    $createdFirm = $linked($f, 'org_unit', 'near-firm'); $createdHours = $linked($f, 'schedule', 'reject-hours');
    check($createdFirm !== null && $createdFirm !== $f['locals']['Jurídica Regional'] && $createdHours !== null && $createdHours !== $f['locals']['Horario Flexible'], 'similar rejections create separate units and schedules next round');
    $run($f);
    check($linked($f, 'org_unit', 'near-firm') === $createdFirm && $linked($f, 'schedule', 'reject-hours') === $createdHours && $pending($f) === [], 'later round respects all answers without duplicate records');
    $world['people'][0]['document'] = '8912346';
    $run($f);
    $doc = array_values(array_filter($pending($f), fn ($i) => $i['reason'] === 'document_change'))[0];
    expect($batch($f, [$doc['id']]), 409, 'document changes excluded from batch');
    expect(adminRequest($other['platform'], 'POST', '/sync-items/' . $f['byExt']['near-site']['id'] . '/resolve', ['approve' => true]), 404, 'individual similar resolution also respects tenant boundary');
    foreach (range(1, 91) as $n) {
        $name = 'Unidad lote ' . Util::uuid();
        $db->run("INSERT INTO org_units (tenant_id,id,kind,name) VALUES (?,?,'firm',?)", [$f['tenant'], Util::bin(Util::uuid()), $name]);
        $world['org-units'][] = ['external_id' => 'bulk-' . $n, 'kind' => 'firm', 'name' => $name, 'active' => true];
    }
    $run($f);
    $bulk = array_values(array_filter($pending($f), fn ($i) => $i['reason'] === 'matched_name'));
    check(count($bulk) === 91, 'first-connection fixture has 91 exact matches');
    check(expect($batch($f, array_column($bulk, 'id')), 200, 'link 91 pending units')['resolved'] === 91, 'all 91 units linked atomically');
    check(count($pending($f)) === 1, 'document decision remains pending after 91-item batch');
    echo 'PASS sync panel (' . ($GLOBALS['checks'] - $before) . " assertions)\n";
}
