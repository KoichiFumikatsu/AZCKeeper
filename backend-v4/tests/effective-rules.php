<?php
declare(strict_types=1);
use Keeper\{AccessScope,Database,PolicyComposer,PolicyConflict,Util};

// Pestaña «Reglas» de las fichas (2026-10-19): explicación de la composición (misma resolución que el documento del
// agente) y GET /users/{id}/rules y /devices/{id}/rules con procedencia, plan, contrato, alcance y versión aplicada.
function effectiveRulesComposeTests(): void
{
    $before = $GLOBALS['checks'];
    $rule = fn (string $kind, string $effect, array $targets, int $priority = 0) => (object) ['id' => Util::uuid(), 'kind' => $kind, 'effect' => $effect, 'targets' => $targets, 'schedule_id' => null, 'priority' => $priority];
    $at = fn (string $scope, array $rules) => ['id' => Util::uuid(), 'scope' => $scope, 'priority' => 0, 'rules' => $rules, 'management_hosts' => []];
    $input = fn (array $assignments, array $functions = ['web', 'usb', 'descargas', 'instalaciones']) => ['tenant_id' => Util::uuid(), 'device_id' => Util::uuid(), 'reported_version' => 0, 'functions' => $functions, 'assignments' => $assignments, 'work_schedule' => null, 'rule_schedules' => []];
    $tenant = $at('tenant', [$rule('web', 'deny', ['facebook.com', 'youtube.com']), $rule('usb', 'deny', ['*'])]);
    $person = $at('user', [$rule('web', 'allow', ['facebook.com'])]);
    $device = $at('device', [$rule('web', 'deny', ['facebook.com'])]);
    $both = $input([$tenant, $person]);
    $plain = PolicyComposer::compose($both);
    $same = PolicyComposer::compose($both, $prov, $explain);
    check(PolicyComposer::canonical($plain) === PolicyComposer::canonical($same), 'explaining does not change the composed document');
    $by = []; foreach ($explain['entries'] as $e) { $by[$e['kind'] . ':' . $e['target']] = $e; }
    $c = $explain['candidates'];
    check(count($by) === 3 && $c[$by['web:facebook.com']['winners'][0]]['level'] === 'user' && $c[$by['web:facebook.com']['winners'][0]]['rule']->effect === 'allow', 'facebook: the person exception wins');
    check(array_map(fn ($i) => $c[$i]['level'], $by['web:facebook.com']['others']) === ['tenant'], 'facebook: the company block is what loses');
    check($c[$by['web:youtube.com']['winners'][0]]['level'] === 'tenant' && $by['web:youtube.com']['others'] === [], 'youtube: only the company');
    PolicyComposer::compose($input([$tenant, $person, $device]), $prov, $explain);
    $by = []; foreach ($explain['entries'] as $e) { $by[$e['kind'] . ':' . $e['target']] = $e; } $c = $explain['candidates'];
    check($c[$by['web:facebook.com']['winners'][0]]['level'] === 'device' && count($by['web:facebook.com']['others']) === 2, 'device beats person beats company');
    PolicyComposer::compose($input([$tenant, $person], ['web']), $prov, $explain);
    check(count($explain['excluded']) === 1 && $explain['excluded'][0]['rule']->kind === 'usb' && $explain['excluded'][0]['level'] === 'tenant', 'usb outside the plan is listed as excluded, not composed');
    try { PolicyComposer::compose($input([$tenant, $at('tenant', [$rule('web', 'allow', ['youtube.com'])])])); check(false, 'same-level conflict'); }
    catch (PolicyConflict $e) { check($e->kind === 'web' && $e->target === 'youtube.com' && $e->level === 'tenant' && isset($e->first['assignment'], $e->second['rule']), 'conflict carries kind, target and both rules'); }
    echo 'PASS effective rules compose (' . ($GLOBALS['checks'] - $before) . " assertions)\n";
}

function effectiveRulesApiTests(Database $db): void
{
    $before = $GLOBALS['checks'];
    $o = accessOrg($db); $t = $o['tenant'];
    $platform = sharedPlatform($db); $platform['tenant'] = $t;
    $device = fn (string $key) => $db->one('SELECT id FROM devices WHERE tenant_id=? AND user_id=?', [$t, $o[$key]['id']])['id'];
    $rule = fn (string $kind, string $effect, array $targets) => ['id' => Util::uuid(), 'kind' => $kind, 'effect' => $effect, 'targets' => $targets, 'schedule_id' => null, 'priority' => 0];
    $policy = fn (string $name, string $type, string $target, array $rules) => expect(adminRequest($platform, 'POST', '/policies', ['name' => $name, 'target_type' => $type, 'target_id' => Util::id($target), 'enabled' => true, 'rules' => $rules]), 201, "policy $name");
    $policy('Bloqueos generales', 'tenant', $t, [$rule('web', 'deny', ['facebook.com', 'youtube.com']), $rule('usb', 'deny', ['*'])]);
    $policy('Agentes ven YouTube', 'area', $o['agentes'], [$rule('web', 'allow', ['youtube.com'])]);
    $own = $policy('Excepción Juan', 'user', $o['juan']['id'], [$rule('web', 'allow', ['facebook.com'])]);
    $pc = $policy('PC de Juan sin Facebook', 'device', $device('juan'), [$rule('web', 'deny', ['facebook.com'])]);
    $juan = '/users/' . Util::id($o['juan']['id']) . '/rules';
    $entries = fn (array $r) => array_column(array_map(fn ($e) => ['k' => $e['kind'] . ':' . $e['target']] + $e, $r['entries']), null, 'k');

    $r = expect(adminRequest($platform, 'GET', $juan), 200, 'person rules');
    $e = $entries($r);
    check($r['subject'] === ['type' => 'user', 'user_id' => Util::id($o['juan']['id']), 'device_id' => null, 'display_name' => 'juan', 'hostname' => null, 'active' => true], 'subject: the person');
    check(array_keys($e) === ['web:facebook.com', 'web:youtube.com', 'usb:*'], 'entries ordered web, usb: ' . implode(',', array_keys($e)));
    $fb = $e['web:facebook.com'];
    check($fb['effect'] === 'allow' && $fb['winners'][0]['scope'] === 'user' && $fb['winners'][0]['own'] && $fb['winners'][0]['policy_name'] === 'Excepción Juan' && $fb['winners'][0]['target_name'] === 'juan', 'facebook allowed by her own exception');
    check(count($fb['overridden']) === 1 && $fb['overridden'][0]['scope'] === 'tenant' && $fb['overridden'][0]['policy_name'] === 'Bloqueos generales' && !$fb['overridden'][0]['own'], 'the company block loses');
    check($e['web:youtube.com']['effect'] === 'allow' && $e['web:youtube.com']['winners'][0]['scope'] === 'area' && $e['web:youtube.com']['winners'][0]['target_name'] === 'Agentes de servicio', 'youtube allowed by her area');
    check($e['usb:*']['effect'] === 'deny' && $e['usb:*']['winners'][0]['scope'] === 'tenant', 'usb blocked by the company');
    check(array_column($r['own_policies'], 'id') === [$own['id']] && $r['own_policies'][0]['target_type'] === 'user', 'own policy offered for editing');
    check(count($r['devices']) === 1 && $r['devices'][0]['hostname'] === 'PC-juan' && $r['devices'][0]['own_policy_ids'] === [$pc['id']], 'her device and its own policy');
    check(array_column($r['kinds'], 'in_plan', 'kind') === ['web' => true, 'usb' => true, 'download' => true, 'installation' => true] && $r['excluded'] === [] && $r['conflict'] === null, 'full plan: nothing excluded');

    // Ficha del equipo: la regla del equipo gana a la de la persona; versión compilada frente a la reportada.
    (new Keeper\PolicyCompiler($db))->recompile(Util::id($device('juan')), Util::id($t));
    $d = expect(adminRequest($platform, 'GET', '/devices/' . Util::id($device('juan')) . '/rules'), 200, 'device rules');
    $fb = $entries($d)['web:facebook.com'];
    check($d['subject']['type'] === 'device' && $d['subject']['hostname'] === 'PC-juan' && $d['subject']['user_id'] === Util::id($o['juan']['id']), 'subject: the device and its person');
    check($fb['effect'] === 'deny' && $fb['winners'][0]['scope'] === 'device' && $fb['winners'][0]['own'] && array_column($fb['overridden'], 'scope') === ['user', 'tenant'], 'device beats the person exception and the company');
    check(array_column($d['own_policies'], 'id') === [$pc['id']], 'device own policy');
    check($d['devices'][0]['compiled_version'] >= 1 && $d['devices'][0]['reported_version'] === 0 && $d['devices'][0]['up_to_date'] === false, 'compiled but not yet applied by the agent');
    $db->run('UPDATE devices SET policy_version=? WHERE tenant_id=? AND id=?', [$d['devices'][0]['compiled_version'], $t, $device('juan')]);
    check(expect(adminRequest($platform, 'GET', '/devices/' . Util::id($device('juan')) . '/rules'), 200, 'device applied')['devices'][0]['up_to_date'] === true, 'agent reports the compiled version');

    // Plan: una persona sin las funciones de control no recibe nada; sale como «no aplica por plan».
    $platformTenant = Util::bin('00000000-0000-4000-8000-000000000001'); $basic = Util::bin(Util::uuid());
    $db->run("INSERT INTO person_plans (tenant_id,id,name,badge_label) VALUES (?,?,'Solo medición reglas','M')", [$platformTenant, $basic]);
    $db->run("INSERT INTO person_plan_items (tenant_id,plan_id,feature_code) VALUES (?,?,'productividad')", [$platformTenant, $basic]);
    $db->run('UPDATE users SET plan_tenant_id=?,plan_id=? WHERE tenant_id=? AND id=?', [$platformTenant, $basic, $t, $o['bl1']['id']]);
    $r = expect(adminRequest($platform, 'GET', '/users/' . Util::id($o['bl1']['id']) . '/rules'), 200, 'plan without controls');
    check($r['entries'] === [] && array_unique(array_column($r['excluded'], 'reason')) === ['not_in_plan'] && count($r['excluded']) === 3, 'everything excluded by the plan');
    check(array_column($r['kinds'], 'in_plan', 'kind') === ['web' => false, 'usb' => false, 'download' => false, 'installation' => false] && $r['kinds'][1]['contracted'] === true && $r['kinds'][1]['feature_name'] === 'Bloqueo de USB', 'kinds: contracted but not in the plan');
    $db->run('UPDATE person_plans SET active=FALSE WHERE tenant_id=? AND id=?', [$platformTenant, $basic]);

    // Alcance: el coordinador de Tequendama ve a Juan y su equipo; a ag808 (Medellín) no. Sin reglas.ver, 403.
    accessResetPreloginQuota();
    $coord = accessAccount($db, $o, 'bl1', ['reglas.ver'], new AccessScope('filters', [], [], [$o['teq']]));
    check(expect(adminRequest($coord, 'GET', $juan), 200, 'coordinator sees juan')['entries'] !== [], 'inside the scope');
    check(adminRequest($coord, 'GET', '/users/' . Util::id($o['ag808']['id']) . '/rules')[0] === 404, 'person outside the scope: 404');
    check(adminRequest($coord, 'GET', '/devices/' . Util::id($device('ag808')) . '/rules')[0] === 404, 'device outside the scope: 404');
    check(adminRequest($coord, 'GET', '/devices/' . Util::uuid() . '/rules')[0] === 404, 'unknown device: 404');
    $noPerm = accessAccount($db, $o, 'zafra', ['usuarios.ver'], AccessScope::all());
    check(adminRequest($noPerm, 'GET', $juan)[0] === 403, 'without reglas.ver: 403');

    // USB no contratado: sale como not_contracted. Persona inactiva: nada le aplica.
    $db->run("DELETE FROM tenant_features WHERE tenant_id=? AND feature_code='usb'", [$t]);
    $r = expect(adminRequest($platform, 'GET', $juan), 200, 'usb not contracted');
    check(!isset($entries($r)['usb:*']) && $r['excluded'][0]['kind'] === 'usb' && $r['excluded'][0]['reason'] === 'not_contracted' && $r['excluded'][0]['source']['policy_name'] === 'Bloqueos generales', 'usb not contracted');
    $db->run("UPDATE users SET status='inactive' WHERE tenant_id=? AND id=?", [$t, $o['jur808']['id']]);
    $r = expect(adminRequest($platform, 'GET', '/users/' . Util::id($o['jur808']['id']) . '/rules'), 200, 'inactive person');
    check($r['subject']['active'] === false && $r['entries'] === [], 'inactive person receives no rules');
    echo 'PASS effective rules api (' . ($GLOBALS['checks'] - $before) . " assertions)\n";
}
