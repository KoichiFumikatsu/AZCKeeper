<?php
declare(strict_types=1);
use Keeper\{AccessScope,Database,Util};

// POST /users/batch: cada persona por el mismo camino que su PATCH/plan/access, con resultado por persona y savepoint propio.
function batchUsersTests(Database $db): void
{
    $before = $GLOBALS['checks'];
    $o = accessOrg($db); $t = $o['tenant'];
    $db->run('UPDATE tenants SET rbac_self_management=TRUE WHERE tenant_id=?', [$t]);
    $platform = sharedPlatform($db); $platform['tenant'] = $t;
    $id = fn (string $k) => Util::id($o[$k]['id']);
    $row = fn (string $k) => $db->one('SELECT * FROM users WHERE tenant_id=? AND id=?', [$t, $o[$k]['id']]);
    $item = fn (string $k) => ['id' => $id($k), 'version' => (int) $row($k)['version']];
    $batch = fn (array $a, array $body) => adminRequest($a, 'POST', '/users/batch', $body);
    $byId = fn (array $r) => array_column($r[1]['results'], null, 'id');
    $audits = fn (string $action) => (int) $db->one('SELECT COUNT(*) n FROM audit_log WHERE tenant_id=? AND action=?', [$t, $action])['n'];

    // Forma: sin cambios, ids repetidos o mas de 500 son 422 para todo el lote.
    check($batch($platform, ['users' => [$item('juan')]])[1]['detail'] === 'nothing_to_change', 'batch without changes is rejected');
    check($batch($platform, ['users' => [$item('juan')], 'changes' => new stdClass()])[1]['detail'] === 'nothing_to_change', 'empty changes is rejected');
    check($batch($platform, ['users' => [['id' => $id('juan')], ['id' => strtoupper($id('juan'))]], 'changes' => ['area_id' => Util::id($o['juridica'])]])[0] === 422, 'repeated ids are rejected');
    $many = array_map(fn () => ['id' => Util::uuid()], range(1, 501));
    check($batch($platform, ['users' => $many, 'changes' => ['area_id' => Util::id($o['juridica'])]])[0] === 422, 'more than 500 people are rejected by the contract');
    check($batch($platform, ['users' => [$item('juan')], 'changes' => ['display_name' => 'x']])[0] === 422, 'only batch fields are accepted');

    // Vista previa: valida y deshace.
    $move = ['users' => [$item('juan'), $item('ag808'), $item('agTorre')], 'changes' => ['area_id' => Util::id($o['juridica'])]];
    $patches = $audits('patchUser');
    $dry = expect($batch($platform, $move + ['dry_run' => true]), 200, 'batch dry run');
    check($dry['dry_run'] === true && $dry['applied'] === 3 && $dry['failed'] === 0, 'dry run reports every person');
    check($row('juan')['area_id'] === $o['agentes'] && $audits('patchUser') === $patches, 'dry run changes nothing and audits nothing');
    $done = expect($batch($platform, $move), 200, 'batch apply');
    check($done['applied'] === 3 && $row('juan')['area_id'] === $o['juridica'] && $row('agTorre')['area_id'] === $o['juridica'], 'batch moves every person');
    check($audits('patchUser') === $patches + 3, 'one patchUser audit entry per person');
    check((int) $db->one('SELECT COUNT(*) n FROM user_assignments WHERE tenant_id=? AND user_id=? AND ends_at IS NULL AND area_id=?', [$t, $o['juan']['id'], $o['juridica']])['n'] === 1, 'assignment history follows the move');

    // Version vista: si cambio, esa persona no se toca; las demas si.
    $stale = expect($batch($platform, ['users' => [['id' => $id('juan'), 'version' => 0], $item('ag808')], 'changes' => ['area_id' => Util::id($o['agentes'])]]), 200, 'stale version');
    check($byId([0, $stale])[$id('juan')]['error_code'] === 'version_conflict' && $byId([0, $stale])[$id('ag808')]['ok'] && $row('juan')['area_id'] === $o['juridica'], 'stale version fails alone');

    // Alcance: Juan (solo Agentes) no ve a quien esta fuera (404 como resource_not_found) ni saca a nadie de su alcance.
    accessResetPreloginQuota();
    $juan = accessAccount($db, $o, 'bl1', ['usuarios.ver', 'usuarios.editar'], new AccessScope('filters', [], [], [], [$o['agentes']]));
    $r = expect($batch($juan, ['users' => [$item('ag808'), $item('jur808'), $item('coord2')], 'changes' => ['area_id' => Util::id($o['juridica'])]]), 200, 'scoped batch');
    $res = $byId([0, $r]);
    check($res[$id('jur808')]['error_code'] === 'resource_not_found', 'outside the scope: resource_not_found');
    check($res[$id('ag808')]['detail'] === 'scope_denied' && $res[$id('coord2')]['detail'] === 'scope_denied' && $r['applied'] === 0, 'moving out of the scope: scope_denied');
    $hol = expect($batch($juan, ['users' => [$item('ag808'), $item('coord2')], 'changes' => ['schedule_id' => null]]), 200, 'scoped schedule batch');
    check($hol['applied'] === 2 && $row('coord2')['schedule_id'] !== $o['base']['schedule_id'], 'in-scope people change ("Sin horario")');
    // Plan y acceso exigen sus permisos: sin ellos, 403 para todo el lote.
    check($batch($juan, ['users' => [$item('ag808')], 'plan' => ['plan_id' => null]])[0] === 403, 'plan needs planes.asignar');
    check($batch($juan, ['users' => [$item('ag808')], 'changes' => ['schedule_id' => null], 'access' => ['role_id' => Util::id($o['base']['id']), 'scope' => accessScopeBody('self')]])[0] === 403, 'access needs roles.gestionar');

    // Acceso en lote con delegacion: Juan con roles.gestionar da su mismo alcance; a si mismo no se amplia.
    foreach (['roles.ver', 'roles.gestionar'] as $p) { $db->run('INSERT INTO role_permissions (tenant_id,role_id,permission_code) VALUES (?,?,?)', [$t, $juan['role'], $p]); }
    $giver = $juan;
    $viewer = Util::bin(Util::uuid());
    $db->run("INSERT INTO roles (tenant_id,id,name) VALUES (?,?,'Solo ver personas lote')", [$t, $viewer]);
    $db->run("INSERT INTO role_permissions (tenant_id,role_id,permission_code) VALUES (?,?,'usuarios.ver')", [$t, $viewer]);
    $grants = $audits('setUserAccess');
    $agentes = accessScopeBody('filters', ['area_ids' => [Util::id($o['agentes'])]]);
    $acc = expect($batch($giver, ['users' => [$item('ag808'), $item('bl1')], 'access' => ['role_id' => Util::id($viewer), 'scope' => $agentes]]), 200, 'access batch');
    check($acc['applied'] === 1 && $byId([0, $acc])[$id('bl1')]['detail'] === 'delegation_exceeds_own_access', 'delegation checked per person (cannot touch a wider access)');
    check(Keeper\AccessGrants::forUser($db, $t, $o['ag808']['id'])['role_id'] === $viewer && $audits('setUserAccess') === $grants + 1, 'access given within own scope and audited as setUserAccess');
    $wide = expect($batch($giver, ['users' => [$item('coord2')], 'access' => ['role_id' => Util::id($viewer), 'scope' => accessScopeBody('all')]]), 200, 'access batch too wide');
    check($wide['applied'] === 0 && $wide['results'][0]['detail'] === 'delegation_exceeds_own_access', 'cannot give a wider scope than own');

    // Plan en lote (superadmin), auditado como setUserPlan.
    $plans = $audits('setUserPlan');
    $plan = expect($batch($platform, ['users' => [$item('juan'), $item('zafra')], 'plan' => ['plan_id' => null], 'changes' => ['holiday_calendar_id' => null]]), 200, 'plan batch');
    check($plan['applied'] === 2 && $audits('setUserPlan') === $plans + 2, 'plan applied and audited per person');

    // Fuente duena de "sedes": la persona vinculada no cambia de sede (managed_by_source); las demas si, y lo propio de Keeper tambien.
    $src = expect(adminRequest($platform, 'POST', '/data-sources', ['name' => 'One lote', 'base_url' => 'https://127.0.0.1:9/x']), 201, 'batch source');
    expect(adminRequest($platform, 'PUT', '/data-domains', ['domains' => [['domain' => 'sedes', 'source_id' => $src['id']]]]), 200, 'batch domain owner');
    $db->run("INSERT INTO entity_links (tenant_id,entity_type,entity_id,source_id,external_id,link_method) VALUES (?,'user',?,?,'lote-1','manual')", [$t, $o['zafra']['id'], Util::bin($src['id'])]);
    $site = expect($batch($platform, ['users' => [$item('zafra'), $item('juan')], 'changes' => ['site_id' => Util::id($o['med'])]]), 200, 'managed batch');
    check($byId([0, $site])[$id('zafra')]['detail'] === 'managed_by_source' && $byId([0, $site])[$id('juan')]['ok'] && $row('zafra')['site_id'] === $o['torre'], 'source-owned field refused only for the linked person');
    $sched = expect($batch($platform, ['users' => [$item('zafra')], 'changes' => ['holiday_calendar_id' => null]]), 200, 'keeper-owned field on linked person');
    check($sched['applied'] === 1, 'keeper-owned fields still change for linked people');

    // Retirar en lote revoca sus equipos, como el PATCH individual.
    $off = expect($batch($platform, ['users' => [$item('jur808')], 'changes' => ['status' => 'inactive']]), 200, 'batch retire');
    check($off['applied'] === 1 && !$db->one("SELECT id FROM devices WHERE tenant_id=? AND user_id=? AND status='active'", [$t, $o['jur808']['id']]), 'retiring in batch revokes devices');
    echo 'PASS batch users (' . ($GLOBALS['checks'] - $before) . " assertions)\n";
}
