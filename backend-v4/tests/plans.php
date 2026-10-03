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
    $edited = expect(adminRequest(sharedPlatform($db), 'PATCH', '/person-plans/' . $pro['id'], ['name' => $pro['name'], 'badge_label' => 'Pro', 'features' => ['productividad', 'web'], 'active' => true], ['if-match' => '"' . $pro['version'] . '"']), 200, 'edit plan');
    check($edited['features'] === ['productividad', 'web'], 'plan edited');
    check((bool) $db->one('SELECT tenant_id FROM admin_policy_recompiles WHERE tenant_id=?', [$u['tenant_id']]), 'clients using the plan get recompiled');
    check(adminRequest($admin, 'GET', '/person-plans')[0] === 403, 'plans catalog is platform-only');
    echo 'PASS plan api (' . ($GLOBALS['checks'] - $before) . " assertions)\n";
}
