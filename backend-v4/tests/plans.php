<?php
declare(strict_types=1);
use Keeper\{Database,Features,PersonPlans,Util};

// Fase 2a: planes de la plataforma por persona (2026-10-03).
function planResolutionTests(Database $db): void
{
    $before = $GLOBALS['checks'];
    $platform = Util::bin('00000000-0000-4000-8000-000000000001');
    $u = secondTenant($db); $tenant = $u['tenant_id']; $user = $u['id'];
    $full = $db->one("SELECT id FROM person_plans WHERE tenant_id=? AND name='Completo'", [$platform])['id'];
    $person = array_values(array_filter(Features::codes(), fn ($c) => Features::CATALOG[$c]['level'] === 'person'));
    sort($person);
    // El fixture deja el plan "Completo" por defecto: la persona recibe todas las funciones por persona del cliente.
    check(PersonPlans::effectivePlan($db, $tenant, $user) === $full, 'default plan of the client applies');
    check(PersonPlans::functions($db, $tenant, $user) === $person, 'full plan = every person-level function');
    // Plan propio de la persona: manda sobre el por defecto.
    $basic = Util::bin(Util::uuid());
    $db->run("INSERT INTO person_plans (tenant_id,id,name,badge_label) VALUES (?,?,'Básico prueba','B')", [$platform, $basic]);
    foreach (['productividad', 'usb'] as $f) { $db->run('INSERT INTO person_plan_items (tenant_id,plan_id,feature_code) VALUES (?,?,?)', [$platform, $basic, $f]); }
    $db->run('UPDATE users SET plan_tenant_id=?,plan_id=? WHERE tenant_id=? AND id=?', [$platform, $basic, $tenant, $user]);
    check(PersonPlans::functions($db, $tenant, $user) === ['productividad', 'usb'], 'own plan wins');
    // Solo lo que el cliente compro.
    $db->run("DELETE FROM tenant_features WHERE tenant_id=? AND feature_code='usb'", [$tenant]);
    check(PersonPlans::functions($db, $tenant, $user) === ['productividad'], 'client must have bought it');
    // Plan inactivo: vuelve al por defecto del cliente.
    $db->run('UPDATE person_plans SET active=FALSE WHERE tenant_id=? AND id=?', [$platform, $basic]);
    check(PersonPlans::effectivePlan($db, $tenant, $user) === $full, 'inactive own plan falls back to default');
    // Sin plan propio ni por defecto: nada.
    $db->run('UPDATE users SET plan_tenant_id=NULL,plan_id=NULL WHERE tenant_id=? AND id=?', [$tenant, $user]);
    $db->run('UPDATE tenants SET default_plan_tenant_id=NULL,default_plan_id=NULL WHERE tenant_id=?', [$tenant]);
    check(PersonPlans::effectivePlan($db, $tenant, $user) === null && PersonPlans::functions($db, $tenant, $user) === [], 'no plan, no functions');
    // Persona inactiva: nada.
    $db->run('UPDATE tenants SET default_plan_tenant_id=?,default_plan_id=? WHERE tenant_id=?', [$platform, $full, $tenant]);
    $db->run("UPDATE users SET status='inactive' WHERE tenant_id=? AND id=?", [$tenant, $user]);
    check(PersonPlans::functions($db, $tenant, $user) === [], 'inactive person gets nothing');
    // La migracion: plan Completo con todas las funciones por persona y permiso nuevo.
    $items = array_column($db->run('SELECT feature_code FROM person_plan_items WHERE tenant_id=? AND plan_id=? ORDER BY feature_code', [$platform, $full])->fetchAll(), 'feature_code');
    check($items === $person, 'seeded full plan has every person-level function');
    check((bool) $db->one("SELECT code FROM permissions WHERE code='planes.asignar'"), 'permission planes.asignar exists');
    PersonPlans::markRecompile($db, $tenant);
    check((bool) $db->one('SELECT tenant_id FROM admin_policy_recompiles WHERE tenant_id=?', [$tenant]), 'recompile marked');
    echo 'PASS plan resolution (' . ($GLOBALS['checks'] - $before) . " assertions)\n";
}

// El equipo de una persona solo recibe reglas de funciones que su cliente compro y su plan incluye.
function planCompileTests(Database $db): void
{
    $before = $GLOBALS['checks'];
    check(Keeper\PolicyComposer::ruleAllowed('schedule', []) && Keeper\PolicyComposer::ruleAllowed('os', []), 'core rule kinds always pass');
    check(!Keeper\PolicyComposer::ruleAllowed('usb', ['web']) && Keeper\PolicyComposer::ruleAllowed('usb', ['usb']), 'sellable rule kinds need their function');
    $rules = [(object) ['id' => Util::uuid(), 'kind' => 'web', 'effect' => 'deny', 'targets' => ['facebook.com'], 'schedule_id' => null, 'priority' => 0],
        (object) ['id' => Util::uuid(), 'kind' => 'usb', 'effect' => 'deny', 'targets' => ['*'], 'schedule_id' => null, 'priority' => 0]];
    $input = fn (array $functions) => ['tenant_id' => Util::uuid(), 'device_id' => Util::uuid(), 'functions' => $functions,
        'assignments' => [['id' => Util::uuid(), 'scope' => 'tenant', 'priority' => 0, 'rules' => $rules, 'management_hosts' => []]], 'work_schedule' => null, 'rule_schedules' => []];
    $kinds = fn (object $doc) => array_values(array_unique(array_map(fn ($r) => $r->kind, $doc->rules)));
    check($kinds(Keeper\PolicyComposer::compose($input(['web', 'usb']))) === ['usb', 'web'] || $kinds(Keeper\PolicyComposer::compose($input(['web', 'usb']))) === ['web', 'usb'], 'both rules with both functions');
    check($kinds(Keeper\PolicyComposer::compose($input(['web']))) === ['web'], 'usb dropped without its function');
    check(Keeper\PolicyComposer::compose($input([]))->rules === [], 'no functions, no sellable rules');
    echo 'PASS plan compile (' . ($GLOBALS['checks'] - $before) . " assertions)\n";
}

function planApiTests(Database $db): void
{
    $before = $GLOBALS['checks'];
    $u = secondTenant($db); $tid = Util::id($u['tenant_id']);
    $platform = sharedPlatform($db); $platform['tenant'] = $u['tenant_id'];
    $pro = expect(adminRequest($platform, 'POST', '/person-plans', ['name' => 'Pro ' . substr(Util::uuid(), 0, 6), 'badge_label' => 'Pro', 'features' => ['productividad', 'usb', 'web'], 'active' => true]), 201, 'create plan');
    check($pro['features'] === ['productividad', 'usb', 'web'] && $pro['people'] === 0, 'plan stored');
    check(adminRequest($platform, 'POST', '/person-plans', ['name' => 'Malo', 'badge_label' => '', 'features' => ['festivos'], 'active' => true])[1]['detail'] === 'not_person_feature:festivos', 'client-level functions rejected in plans');
    $features = expect(adminRequest($platform, 'GET', '/tenants/' . $tid . '/features'), 200, 'client functions');
    check($features['default_plan_id'] !== null, 'client has a default plan');
    $set = expect(adminRequest($platform, 'PUT', '/tenants/' . $tid . '/features', ['package_id' => null, 'features' => $features['features'], 'default_plan_id' => $pro['id']]), 200, 'set default plan');
    check($set['default_plan_id'] === $pro['id'], 'default plan stored');
    $usage = expect(adminRequest($platform, 'GET', '/tenants/' . $tid . '/plan-usage'), 200, 'usage');
    check($usage['default_plan_id'] === $pro['id'] && $usage['data'][0]['plan_id'] === $pro['id'] && $usage['data'][0]['people'] === 1, 'person counted under the default plan');
    // Plan propio de una persona, con permiso planes.asignar.
    $admin = adminFixture($db, $u, ['usuarios.ver', 'planes.asignar'], false);
    $basic = expect(adminRequest($platform, 'POST', '/person-plans', ['name' => 'Básico ' . substr(Util::uuid(), 0, 6), 'badge_label' => 'B', 'features' => ['productividad'], 'active' => true]), 201, 'second plan');
    $person = expect(adminRequest($admin, 'GET', '/users/' . Util::id($u['id'])), 200, 'person');
    $moved = expect(adminRequest($admin, 'PUT', '/users/' . $person['id'] . '/plan', ['plan_id' => $basic['id']], ['if-match' => '"' . $person['version'] . '"']), 200, 'assign own plan');
    check($moved['plan_id'] === $basic['id'], 'person shows own plan');
    check(Keeper\PersonPlans::functions($db, $u['tenant_id'], $u['id']) === ['productividad'], 'own plan applies');
    $back = expect(adminRequest($admin, 'PUT', '/users/' . $person['id'] . '/plan', ['plan_id' => null], ['if-match' => '"' . $moved['version'] . '"']), 200, 'back to default');
    check($back['plan_id'] === null, 'back to client default');
    $noPerm = adminFixture($db, adminMember($db, $u), ['usuarios.ver'], false);
    check(adminRequest($noPerm, 'PUT', '/users/' . $person['id'] . '/plan', ['plan_id' => $basic['id']], ['if-match' => '"' . $back['version'] . '"'])[0] === 403, 'assigning plans needs planes.asignar');
    // Lista de planes asignables para quien tiene planes.asignar (sin ser plataforma): solo activos y con plan ∩ cliente.
    $avail = expect(adminRequest($admin, 'GET', '/person-plans/available'), 200, 'available plans');
    $bought = Features::enabled($db, $u['tenant_id']);
    $codes = array_column($avail['features'], 'code');
    check($avail['default_plan_id'] === $pro['id'], 'available plans report the client default');
    check($codes !== [] && array_diff($codes, $bought) === [] && array_filter($codes, fn ($c) => Features::CATALOG[$c]['level'] !== 'person') === [], 'available features are the client person-level functions');
    $basicRow = array_values(array_filter($avail['data'], fn ($p) => $p['id'] === $basic['id']))[0] ?? null;
    check($basicRow !== null && $basicRow['features'] === array_values(array_intersect(['productividad'], $bought)), 'available plan lists plan ∩ client functions');
    check(array_filter($avail['data'], fn ($p) => array_diff($p['features'], $codes) !== []) === [], 'no available plan offers functions the client lacks');
    check(adminRequest($noPerm, 'GET', '/person-plans/available')[0] === 403, 'available plans need planes.asignar');
    check(adminRequest($platform, 'GET', '/person-plans/available')[0] === 200, 'platform reads available plans of a client');
    $edited = expect(adminRequest(sharedPlatform($db), 'PATCH', '/person-plans/' . $pro['id'], ['name' => $pro['name'], 'badge_label' => 'Pro', 'features' => ['productividad', 'web'], 'active' => true], ['if-match' => '"' . $pro['version'] . '"']), 200, 'edit plan');
    check($edited['features'] === ['productividad', 'web'], 'plan edited');
    check((bool) $db->one('SELECT tenant_id FROM admin_policy_recompiles WHERE tenant_id=?', [$u['tenant_id']]), 'clients using the plan get recompiled');
    check(adminRequest($admin, 'GET', '/person-plans')[0] === 403, 'plans catalog is platform-only');
    // El conteo para facturar usa el mismo plan efectivo que los equipos (plan inactivo no cuenta).
    $plat = sharedPlatform($db);
    $patch = fn (array $p, bool $active) => expect(adminRequest($plat, 'PATCH', '/person-plans/' . $p['id'], ['name' => $p['name'], 'badge_label' => $p['badge_label'] ?? '', 'features' => $p['features'], 'active' => $active], ['if-match' => '"' . $p['version'] . '"']), 200, 'toggle plan');
    $rowOf = fn (array $us, ?string $plan) => array_values(array_filter($us['data'], fn ($r) => $r['plan_id'] === $plan))[0] ?? null;
    $person = expect(adminRequest($admin, 'GET', '/users/' . Util::id($u['id'])), 200, 'person again');
    $own = expect(adminRequest($admin, 'PUT', '/users/' . $person['id'] . '/plan', ['plan_id' => $basic['id']], ['if-match' => '"' . $person['version'] . '"']), 200, 'own plan again');
    $usage = expect(adminRequest($platform, 'GET', '/tenants/' . $tid . '/plan-usage'), 200, 'usage own');
    check(($rowOf($usage, $basic['id'])['people'] ?? 0) === 1, 'person counted under own active plan');
    $proBefore = $rowOf($usage, $pro['id'])['people'] ?? 0;
    $basicOff = $patch($basic, false);
    $usage = expect(adminRequest($platform, 'GET', '/tenants/' . $tid . '/plan-usage'), 200, 'usage own inactive');
    check(($rowOf($usage, $pro['id'])['people'] ?? 0) === $proBefore + 1 && $rowOf($usage, $basic['id']) === null, 'inactive own plan: person counted under the client default');
    check($basicOff['people'] === 0 && $basicOff['active'] === false, 'inactive own plan reports 0 people');
    check(!in_array($basic['id'], array_column(expect(adminRequest($admin, 'GET', '/person-plans/available'), 200, 'available after deactivation')['data'], 'id'), true), 'inactive plans are not assignable');
    $own = expect(adminRequest($admin, 'PUT', '/users/' . $person['id'] . '/plan', ['plan_id' => null], ['if-match' => '"' . $own['version'] . '"']), 200, 'clear own plan');
    $proNow = $rowOf(expect(adminRequest($platform, 'GET', '/tenants/' . $tid . '/plan-usage'), 200, 'usage before'), $pro['id'])['people'];
    $proOff = $patch($edited, false);
    $usage = expect(adminRequest($platform, 'GET', '/tenants/' . $tid . '/plan-usage'), 200, 'usage no plan');
    $none = $rowOf($usage, null);
    check($none !== null && $none['name'] === 'Sin plan' && $none['people'] === $proNow && $rowOf($usage, $pro['id']) === null, 'inactive client default: person counted as Sin plan');
    $bad = adminRequest($platform, 'PUT', '/tenants/' . $tid . '/features', ['package_id' => null, 'features' => $features['features'], 'default_plan_id' => $pro['id']]);
    check($bad[0] === 422 && $bad[1]['detail'] === 'unknown_plan', 'inactive plan rejected as client default');
    $patch($proOff, true);
    echo 'PASS plan api (' . ($GLOBALS['checks'] - $before) . " assertions)\n";
}

function planJobsTests(Database $db): void
{
    $before = $GLOBALS['checks'];
    $u = secondTenant($db); $tenant = $u['tenant_id'];
    // Fuente REST nunca corrida: siempre vencida mientras el cliente tenga One.
    $source = Util::bin(Util::uuid());
    $db->run("INSERT INTO data_sources (tenant_id,id,name,kind,config_ciphertext,interval_minutes,active) VALUES (?,?,'plan jobs','rest','x',5,TRUE)", [$tenant, $source]);
    $dueIds = fn () => array_map(fn ($d) => $d[0]['id'], (new Keeper\DataSync($db))->due());
    check(in_array($source, $dueIds(), true), 'source is due while the client has One');
    $db->run("DELETE FROM tenant_features WHERE tenant_id=? AND feature_code='one'", [$tenant]);
    check(!in_array($source, $dueIds(), true), 'source is not due without One');
    $db->run("INSERT INTO tenant_features (tenant_id,feature_code) VALUES (?,'one')", [$tenant]);
    check(in_array($source, $dueIds(), true), 'source is due again with One');
    // Cumplimiento: un dia con 2 h registradas y 80 % inactivo genera la senal suspicious_idle.
    $day = gmdate('Y-m-d');
    $assignment = $db->one('SELECT id FROM user_assignments WHERE tenant_id=? AND user_id=?', [$tenant, $u['id']])['id'];
    $db->run("INSERT INTO day_summary (tenant_id,day,user_id,user_assignment_id,active_seconds,idle_seconds,productive_seconds,expected_seconds,coverage_percent,productivity_percent,calculation_version,timezone,calculated_at,data_through) VALUES (?,?,?,?,1200,6000,0,0,0,0,'plan-jobs','America/Bogota',UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))", [$tenant, $day, $u['id'], $assignment]);
    $count = fn () => (int) $db->one('SELECT COUNT(*) c FROM dual_job_alerts WHERE tenant_id=?', [$tenant])['c'];
    // El worker de productividad deja listo este calendario temporal antes de llamar a cumplimiento.
    Keeper\ActivityCalendar::create($db, $tenant, $day, gmdate('Y-m-d', time() + 86400));
    $worker = new Keeper\ComplianceWorker($db);
    $db->run("DELETE FROM tenant_features WHERE tenant_id=? AND feature_code='cumplimiento'", [$tenant]);
    $worker->calculate($tenant, $day);
    check($count() === 0, 'no dual-job alerts without cumplimiento');
    $db->run("INSERT INTO tenant_features (tenant_id,feature_code) VALUES (?,'cumplimiento')", [$tenant]);
    $worker->calculate($tenant, $day);
    check($count() === 1, 'dual-job alert is created with cumplimiento');
    echo 'PASS plan jobs (' . ($GLOBALS['checks'] - $before) . " assertions)\n";
}

// Fase 2b: el servidor no guarda lo que la persona no tiene contratado, pero acusa recibo igual (el agente no reintenta).
// Un cliente nuevo nace con el plan Completo por defecto (las funciones reales las limita lo que compro).
function newTenantDefaultPlanTests(Database $db): void
{
    $before = $GLOBALS['checks'];
    $platform = sharedPlatform($db);
    $created = expect(adminRequest($platform, 'POST', '/tenants', ['name' => 'Cliente Plan ' . substr(Util::uuid(), 0, 6), 'timezone' => 'America/Bogota', 'branding' => ['display_name' => 'Cliente Plan', 'logo_url' => 'https://example.test/logo.svg', 'primary_color' => '#3C4082', 'accent_color' => '#5A61B3']]), 201, 'create client for default plan');
    $plan = $db->one('SELECT p.name FROM tenants t JOIN person_plans p ON p.tenant_id=t.default_plan_tenant_id AND p.id=t.default_plan_id WHERE t.tenant_id=?', [Util::bin($created['id'])]);
    check(($plan['name'] ?? null) === Keeper\PersonPlans::FULL_PLAN, 'new client default plan is Completo');
    echo 'PASS new client default plan (' . ($GLOBALS['checks'] - $before) . " assertions)\n";
}

function captureIngestTests(Database $db): void
{
    $before = $GLOBALS['checks'];
    $u = secondTenant($db); $tenant = $u['tenant_id'];
    $key = keypair(); $token = enroll($db, $u, $key); policy($db, $token);
    $device = Util::bin($token['device_id']); $bearer = $token['access_token'];
    $db->run('UPDATE device_assignments SET starts_at=UTC_TIMESTAMP(6)-INTERVAL 2 DAY WHERE tenant_id=? AND device_id=?', [$tenant, $device]);
    $n = 0;
    $send = function (string $process, ?string $title) use ($key, $bearer, &$n): array {
        $n++;
        $e = ['event_id' => Util::uuid(), 'started_at' => gmdate('Y-m-d\TH:i:s\Z', time() - 3600 + $n * 120), 'ended_at' => gmdate('Y-m-d\TH:i:s\Z', time() - 3600 + $n * 120 + 60), 'process_name' => $process, 'active_seconds' => 50, 'idle_seconds' => 10];
        if ($title !== null) { $e['window_title'] = $title; }
        return [$e['event_id'], expect(request('POST', '/client/episodes:batch', ['episodes' => [$e]], $key, $bearer, Util::uuid()), 200, 'capture episode')['acks'][0]];
    };
    $row = fn (string $id) => $db->one('SELECT site FROM episodes WHERE tenant_id=? AND device_id=? AND event_id=?', [$tenant, $device, Util::bin($id)]);
    $accepted = fn (array $ack, string $id) => $ack === ['event_id' => $id, 'status' => 'accepted', 'retryable' => false];
    // (a) Plan Completo: el sitio del navegador se guarda.
    [$id, $ack] = $send('chrome.exe', 'Recibidos - Gmail - Google Chrome');
    check($accepted($ack, $id) && ($row($id)['site'] ?? null) !== null, 'full plan: browser episode stored with its site');
    // (b) Sin sitios: el episodio se guarda, el sitio no.
    $db->run("DELETE FROM tenant_features WHERE tenant_id=? AND feature_code IN ('sitios')", [$tenant]);
    [$id, $ack] = $send('chrome.exe', 'Recibidos - Gmail - Google Chrome');
    $stored = $row($id);
    check($accepted($ack, $id) && $stored !== null && $stored['site'] === null, 'without sitios: episode stored, site NULL');
    // (c) Sin productividad: se acusa igual que uno guardado, pero no queda fila.
    $db->run("DELETE FROM tenant_features WHERE tenant_id=? AND feature_code IN ('productividad','cumplimiento')", [$tenant]);
    [$id, $ack] = $send('editor.exe', null);
    check($accepted($ack, $id) && $row($id) === null, 'without productividad: episode acknowledged as accepted, not stored');
    check(!$db->one('SELECT 1 FROM episode_ingest_keys WHERE tenant_id=? AND device_id=? AND event_id=?', [$tenant, $device, Util::bin($id)]), 'without productividad: no ledger row either');
    // (c2) Solo cumplimiento (sin productividad): el episodio se guarda, el doble empleo lo necesita.
    $db->run("INSERT INTO tenant_features (tenant_id,feature_code) VALUES (?,'cumplimiento')", [$tenant]);
    [$id, $ack] = $send('editor.exe', null);
    check($accepted($ack, $id) && $row($id) !== null, 'cumplimiento without productividad: episode stored');
    $db->run("DELETE FROM tenant_features WHERE tenant_id=? AND feature_code='cumplimiento'", [$tenant]);
    // (d) Sin productividad ni presencia: el snapshot se acusa como aceptado sin guardarse.
    $db->run("DELETE FROM tenant_features WHERE tenant_id=? AND feature_code IN ('presencia')", [$tenant]);
    $snapshots = fn () => (int) $db->one('SELECT COUNT(*) n FROM activity_snapshots WHERE tenant_id=? AND device_id=?', [$tenant, $device])['n'];
    $day = (new DateTimeImmutable('yesterday', new DateTimeZone('America/Bogota')))->format('Y-m-d');
    $sync = ['protocol_version' => 1, 'sequence' => 1, 'policy_version' => null, 'release_id' => null,
        'activity' => ['snapshot_id' => Util::uuid(), 'sequence' => 1, 'day' => $day, 'active_seconds' => 50, 'idle_seconds' => 10]];
    $s = expect(request('POST', '/client/sync', $sync, $key, $bearer, Util::uuid()), 200, 'capture snapshot without functions');
    check($s['activity_ack'] === ['event_id' => $sync['activity']['snapshot_id'], 'status' => 'accepted', 'retryable' => false] && $snapshots() === 0, 'without productividad and presencia: snapshot acknowledged, not stored');
    // (e) Con presencia (sin productividad): el snapshot se guarda.
    $db->run("INSERT INTO tenant_features (tenant_id,feature_code) VALUES (?,'presencia')", [$tenant]);
    $sync['sequence'] = 2; $sync['activity']['snapshot_id'] = Util::uuid(); $sync['activity']['sequence'] = 2;
    $s = expect(request('POST', '/client/sync', $sync, $key, $bearer, Util::uuid()), 200, 'capture snapshot with presencia');
    check($s['activity_ack']['status'] === 'accepted' && $snapshots() === 1, 'with presencia only: snapshot stored');
    echo 'PASS capture by contracted functions (' . ($GLOBALS['checks'] - $before) . " assertions)\n";
}

// La politica le dice al agente 4.0.15+ que capturar segun las funciones de la persona; a los viejos no se les envia.
function captureFlagsTests(Database $db): void
{
    $before = $GLOBALS['checks'];
    $input = fn (?array $capture) => ['tenant_id' => Util::uuid(), 'device_id' => Util::uuid(), 'functions' => [], 'capture' => $capture,
        'assignments' => [['id' => Util::uuid(), 'scope' => 'tenant', 'priority' => 0, 'rules' => [], 'management_hosts' => []]], 'work_schedule' => null, 'rule_schedules' => []];
    $doc = Keeper\PolicyComposer::compose($input(['windows' => false, 'window_titles' => false, 'activity' => true]));
    check(isset($doc->capture) && $doc->capture->windows === false && $doc->capture->window_titles === false && $doc->capture->activity === true, 'composer emits capture when given');
    check(!property_exists(Keeper\PolicyComposer::compose($input(null)), 'capture'), 'composer omits capture when null');
    $u = secondTenant($db); $tenant = $u['tenant_id'];
    $compiler = new Keeper\PolicyCompiler($db);
    $compile = function (string $version) use ($db, $u, $tenant, $compiler): array {
        $token = enroll($db, $u, keypair());
        $db->run('UPDATE devices SET agent_version=? WHERE tenant_id=? AND id=?', [$version, $tenant, Util::bin($token['device_id'])]);
        return [$token['device_id'], $compiler->recompile($token['device_id'])];
    };
    [, $old] = $compile('4.0.14');
    check(!property_exists(compilerDocument($db, $old), 'capture'), 'agent 4.0.14 gets no capture');
    [$deviceId, $new] = $compile('4.0.15');
    $capture = compilerDocument($db, $new)->capture ?? null;
    check($capture !== null && $capture->windows === true && $capture->window_titles === true && $capture->activity === true, 'agent 4.0.15 gets capture, full plan captures everything');
    // Quitar sitios cambia la captura y por tanto la version de la politica.
    $db->run("DELETE FROM tenant_features WHERE tenant_id=? AND feature_code='sitios'", [$tenant]);
    $next = $compiler->recompile($deviceId);
    $capture = compilerDocument($db, $next)->capture ?? null;
    check($next['changed'] && $next['policy_version'] > $new['policy_version'] && $capture !== null && $capture->windows === true && $capture->window_titles === false, 'capture change publishes a new policy version');
    // Solo presencia: actividad sin ventanas.
    $db->run("DELETE FROM tenant_features WHERE tenant_id=? AND feature_code IN ('productividad','cumplimiento')", [$tenant]);
    $capture = compilerDocument($db, $compiler->recompile($deviceId))->capture ?? null;
    check($capture !== null && $capture->windows === false && $capture->window_titles === false && $capture->activity === true, 'presencia only: activity without windows');
    // Solo cumplimiento: ventanas (para el doble empleo) sin titulos ni actividad.
    $db->run("INSERT INTO tenant_features (tenant_id,feature_code) VALUES (?,'cumplimiento')", [$tenant]);
    $db->run("DELETE FROM tenant_features WHERE tenant_id=? AND feature_code='presencia'", [$tenant]);
    $capture = compilerDocument($db, $compiler->recompile($deviceId))->capture ?? null;
    check($capture !== null && $capture->windows === true && $capture->window_titles === false && $capture->activity === false, 'cumplimiento only: windows without titles');
    // Persona inactiva: nada.
    $db->run("UPDATE users SET status='inactive' WHERE tenant_id=? AND id=?", [$tenant, $u['id']]);
    $capture = compilerDocument($db, $compiler->recompile($deviceId))->capture ?? null;
    check($capture !== null && $capture->windows === false && $capture->window_titles === false && $capture->activity === false, 'inactive person captures nothing');
    echo 'PASS capture flags (' . ($GLOBALS['checks'] - $before) . " assertions)\n";
}

// Al cambiar la version del agente (login de equipo ya registrado) se recompila ese equipo en el acto: al subir a 4.0.15
// recibe la captura y al volver a 4.0.14 deja de recibirla (el agente viejo rechaza campos desconocidos).
function agentUpgradeRecompileTests(Database $db): void
{
    $before = $GLOBALS['checks'];
    $u = secondTenant($db); $tenant = $u['tenant_id'];
    $key = keypair(); $token = enroll($db, $u, $key);
    $login = function (string $version) use ($token, $key): void {
        $challenge = expect(request('POST', '/client/auth/challenges', ['device_id' => $token['device_id']]), 200, 'upgrade challenge');
        expect(request('POST', '/client/login', ['device_id' => $token['device_id'], 'public_key' => $key[1], 'agent_version' => $version, 'hostname' => 'smoke-device'], $key, null, null, $challenge['nonce']), 200, 'upgrade login ' . $version);
    };
    $latest = function () use ($db, $tenant, $token): array {
        $row = $db->one('SELECT policy_version,document FROM effective_policies WHERE tenant_id=? AND device_id=? ORDER BY policy_version DESC LIMIT 1', [$tenant, Util::bin($token['device_id'])]);
        return $row ? [(int) $row['policy_version'], json_decode($row['document'], false, 64, JSON_THROW_ON_ERROR)] : [0, null];
    };
    $rows = fn () => (int) $db->one('SELECT COUNT(*) n FROM admin_policy_recompiles WHERE tenant_id=?', [$tenant])['n'];
    $db->run('DELETE FROM admin_policy_recompiles WHERE tenant_id=?', [$tenant]);
    $login('4.0.0');
    [$v0] = $latest();
    check($rows() === 0 && $latest()[0] === $v0, 'login with the same agent version recompiles nothing');
    $login('4.0.15');
    [$v1, $doc] = $latest();
    check($doc !== null && isset($doc->capture), 'login at 4.0.15 publishes a policy with capture right away');
    check($rows() === 0, 'a successful per-device compile requests no tenant-wide recompile');
    $login('4.0.15');
    check($latest()[0] === $v1, 'repeated login at the same version keeps the policy version');
    $login('4.0.14');
    [$v2, $doc] = $latest();
    check($doc !== null && $v2 > $v1 && !property_exists($doc, 'capture'), 'rollback to 4.0.14 publishes a policy without capture right away');
    echo 'PASS agent upgrade recompile (' . ($GLOBALS['checks'] - $before) . " assertions)\n";
}
