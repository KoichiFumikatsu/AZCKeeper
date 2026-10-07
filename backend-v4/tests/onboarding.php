<?php
declare(strict_types=1);
use Keeper\{AdminAuth,Database,Util};

// Fase 4 B1/B2: puesta en marcha persistida (piloto, decisiones, cierre) y resumen autorizado, por cliente.
function onboardingTests(Database $db): void
{
    $before = $GLOBALS['checks'];
    $u = secondTenant($db); $tenant = $u['tenant_id']; $other = secondTenant($db);
    $platform = sharedPlatform($db); $platform['tenant'] = $tenant;
    $pid = Util::id($u['id']);
    $clean = function (array $r, string $label): void {
        $raw = strtolower((string) $r[3]);
        foreach (['@example.test', 'password', 'secret', 'ciphertext', 'shared_password', 'token', 'email'] as $bad) { check(!str_contains($raw, $bad), "$label leaks nothing: $bad"); }
    };
    $codes = fn (array $r): array => array_column($r[1]['errors'] ?? [], 'code');

    // El cliente de la plataforma no tiene puesta en marcha.
    $own = sharedPlatform($db);
    check(adminRequest($own, 'GET', '/onboarding')[0] === 404, 'platform tenant has no onboarding');

    // Nada guardado: version 0, abierto, sin piloto.
    $s = expect(adminRequest($platform, 'GET', '/onboarding'), 200, 'empty onboarding state');
    check($s['version'] === 0 && $s['status'] === 'open' && $s['pilot'] === null && $s['steps'] === [] && $s['closed'] === null && $s['criteria_version'] === 1, 'empty state shape');
    $r = adminRequest($platform, 'POST', '/onboarding/close', ['version' => 0]);
    check($r[0] === 409 && $r[1]['detail'] === 'onboarding_not_ready' && in_array('pilot_missing', $codes($r), true) && in_array('admin_missing', $codes($r), true), 'closing an empty onboarding is refused with reasons');
    check(!$db->one('SELECT 1 x FROM onboarding_states WHERE tenant_id=?', [$tenant]), 'refused close writes nothing');

    // Piloto: persona activa y su equipo activo. El reporte anterior a elegirlo no cuenta.
    $device = Util::bin(Util::uuid());
    $db->run("INSERT INTO devices (tenant_id,id,user_id,hostname,agent_version,os_edition,cpu,ram_bytes,capabilities,last_seen_at) VALUES (?,?,?,'onboarding-pilot','4.0','test','test',1024,'[]',UTC_TIMESTAMP(6)-INTERVAL 1 HOUR)", [$tenant, $device, $u['id']]);
    $member = adminMember($db, $u);
    $alien = Util::bin(Util::uuid());
    $db->run("INSERT INTO devices (tenant_id,id,user_id,hostname,agent_version,os_edition,cpu,ram_bytes,capabilities) VALUES (?,?,?,'other-tenant','4.0','test','test',1024,'[]')", [$other['tenant_id'], $alien, $other['id']]);
    check(adminRequest($platform, 'PATCH', '/onboarding', ['version' => 0, 'pilot' => ['user_id' => Util::id($member['id']), 'device_id' => Util::id($device)]])[1]['detail'] === 'pilot_device_invalid', 'pilot device must belong to the pilot person');
    check(adminRequest($platform, 'PATCH', '/onboarding', ['version' => 0, 'pilot' => ['user_id' => $pid, 'device_id' => Util::id($alien)]])[1]['detail'] === 'pilot_device_invalid', 'pilot device of another tenant rejected');
    check(adminRequest($platform, 'PATCH', '/onboarding', ['version' => 0, 'pilot' => ['user_id' => Util::id($other['id']), 'device_id' => null]])[1]['detail'] === 'pilot_person_invalid', 'pilot person of another tenant rejected');
    check(adminRequest($platform, 'PATCH', '/onboarding', ['version' => 0])[1]['detail'] === 'nothing_to_change', 'empty patch rejected');
    $r = adminRequest($platform, 'PATCH', '/onboarding', ['version' => 0, 'pilot' => ['user_id' => $pid, 'device_id' => Util::id($device)], 'responsible' => ['kind' => 'platform']]);
    $s = expect($r, 200, 'platform picks the pilot'); $clean($r, 'state');
    check($s['version'] === 1 && $s['pilot']['user_id'] === $pid && $s['pilot']['hostname'] === 'onboarding-pilot' && $s['pilot']['display_name'] === 'smoke member'
        && $s['pilot']['selected_by'] === ['kind' => 'platform', 'name' => 'AZC Keeper'] && $s['responsible']['kind'] === 'platform', 'pilot and responsible stored with author');
    $same = expect(adminRequest($platform, 'PATCH', '/onboarding', ['version' => 1, 'pilot' => ['user_id' => $pid, 'device_id' => Util::id($device)]]), 200, 'same pilot again');
    check($same['version'] === 1 && $same['pilot']['selected_at'] === $s['pilot']['selected_at'], 'unchanged pilot does not bump the version nor restart the check');

    // Permisos: colaborador sin administración, coordinador con alcance parcial, administrador del cliente.
    $collab = adminFixture($db, adminMember($db, $u), ['usuarios.ver', 'equipos.ver'], false, 'tenant');
    $r = adminRequest($collab, 'GET', '/onboarding'); check($r[0] === 403 && $r[1]['code'] === 'permission_denied', 'collaborator without admin permission denied');
    check(adminRequest($collab, 'GET', '/onboarding/summary')[0] === 403, 'collaborator gets no summary');
    $coord = adminFixture($db, adminMember($db, $u), ['organizacion.editar', 'usuarios.ver', 'equipos.ver'], false, 'site', [$u['site_id']]);
    foreach ([['GET', '/onboarding', null], ['GET', '/onboarding/summary', null], ['PATCH', '/onboarding', ['version' => 1, 'steps' => [['step' => 2, 'decision' => 'reviewed']]]], ['POST', '/onboarding/close', ['version' => 1]]] as [$m, $p, $body]) {
        $r = adminRequest($coord, $m, $p, $body);
        check($r[0] === 403 && ($r[1]['detail'] ?? '') === 'scope_denied', "coordinator (partial scope) denied: $m $p");
    }
    $adminUser = adminMember($db, $u);
    $admin = adminFixture($db, $adminUser, ['organizacion.editar'], false, 'tenant');
    $s = expect(adminRequest($admin, 'GET', '/onboarding'), 200, 'client admin reads the shared state');
    check($s['version'] === 1 && $s['pilot']['user_id'] === $pid && $s['pilot']['display_name'] === null && $s['pilot']['hostname'] === null, 'names hidden without people/devices permission');
    check(adminRequest($admin, 'PATCH', '/onboarding', ['version' => 1, 'pilot' => null])[0] === 403, 'choosing the pilot needs people and devices permission');
    $it = adminFixture($db, adminMember($db, $u), ['equipos.enrolar', 'equipos.ver'], false, 'tenant');
    expect(adminRequest($it, 'GET', '/onboarding'), 200, 'IT delegate with tenant scope reads onboarding');

    // Concurrencia: dos administradores con la misma versión; el segundo no pisa al primero.
    $s = expect(adminRequest($admin, 'PATCH', '/onboarding', ['version' => 1, 'steps' => [['step' => 2, 'decision' => 'reviewed', 'note' => 'Sede Cali revisada']]]), 200, 'admin A marks step 2 reviewed');
    check($s['version'] === 2 && $s['steps'][0]['decision'] === 'reviewed' && $s['steps'][0]['decided_by']['kind'] === 'client' && $s['steps'][0]['decided_by']['name'] === 'Admin test member' && $s['steps'][0]['stale'] === false, 'step decision with client author');
    $r = adminRequest($platform, 'PATCH', '/onboarding', ['version' => 1, 'steps' => [['step' => 2, 'decision' => 'deferred']]]);
    check($r[0] === 409 && $r[1]['code'] === 'version_conflict', 'stale version gets 409 version_conflict');
    check($db->one('SELECT decision FROM onboarding_step_decisions WHERE tenant_id=? AND step=2', [$tenant])['decision'] === 'reviewed', 'conflict does not overwrite the other admin decision');
    check(adminRequest($platform, 'PATCH', '/onboarding', ['version' => 2, 'steps' => [['step' => 2, 'decision' => null], ['step' => 2, 'decision' => 'reviewed']]])[1]['detail'] === 'duplicate_step', 'duplicate steps rejected');

    // Cerrar revalida: piloto que no reportó tras elegirlo, presencia sin horario, endurecimiento automático sin revisar.
    $db->run("INSERT INTO tenant_hardening_settings (tenant_id,hardening_mode) VALUES (?,'auto')", [$tenant]);
    $r = adminRequest($admin, 'POST', '/onboarding/close', ['version' => 2]);
    $why = $codes($r);
    check($r[0] === 409 && in_array('pilot_not_reporting', $why, true) && in_array('pilot_schedule_missing', $why, true) && in_array('hardening_auto_unreviewed', $why, true) && !in_array('admin_missing', $why, true) && !in_array('pilot_missing', $why, true), 'close revalidates on the server: ' . implode(',', $why));
    $db->run('UPDATE devices SET last_seen_at=UTC_TIMESTAMP(6)+INTERVAL 1 SECOND WHERE tenant_id=? AND id=?', [$tenant, $device]);
    $s = expect(adminRequest($admin, 'PATCH', '/onboarding', ['version' => 2, 'steps' => [['step' => 3, 'decision' => 'deferred', 'note' => 'Jornada después del piloto'], ['step' => 6, 'decision' => 'reviewed']]]), 200, 'defer step 3 and review step 6');
    $r = adminRequest($admin, 'POST', '/onboarding/close', ['version' => $s['version']]);
    $s = expect($r, 200, 'client admin closes onboarding'); $clean($r, 'closed state');
    check($s['status'] === 'closed' && $s['closed']['by']['kind'] === 'client' && $s['closed']['criteria_version'] === 1 && $s['version'] === 4, 'close stored with author, date and criteria version');
    check((int) $db->one("SELECT COUNT(*) n FROM audit_log WHERE tenant_id=? AND action='closeOnboarding'", [$tenant])['n'] === 1, 'close audited');
    $p = expect(adminRequest($platform, 'GET', '/onboarding'), 200, 'second admin resumes the same state');
    check($p['status'] === 'closed' && $p['closed']['at'] === $s['closed']['at'] && $p['pilot']['user_id'] === $pid, 'close and pilot shared across admins');
    check(adminRequest($platform, 'PATCH', '/onboarding', ['version' => 4, 'steps' => [['step' => 1, 'decision' => 'reviewed']]])[1]['detail'] === 'onboarding_closed', 'closed onboarding is read-only until reopened');
    check(adminRequest($platform, 'POST', '/onboarding/close', ['version' => 4])[1]['detail'] === 'already_closed', 'cannot close twice');
    check(adminRequest($platform, 'POST', '/onboarding/reopen', ['version' => 3])[1]['code'] === 'version_conflict', 'reopen also checks the version');
    $o = expect(adminRequest($platform, 'POST', '/onboarding/reopen', ['version' => 4, 'reason' => 'Revisar Protección']), 200, 'platform reopens');
    check($o['status'] === 'open' && $o['closed']['at'] === $s['closed']['at'] && $o['reopened']['by']['kind'] === 'platform' && $o['reopened']['reason'] === 'Revisar Protección', 'reopen keeps the last close date');
    check(adminRequest($platform, 'POST', '/onboarding/reopen', ['version' => 5])[1]['detail'] === 'not_closed', 'reopen only when closed');
    $db->run("UPDATE devices SET status='decommissioned' WHERE tenant_id=? AND id=?", [$tenant, $device]);
    $r = adminRequest($admin, 'POST', '/onboarding/close', ['version' => 5]);
    check($r[0] === 409 && in_array('pilot_device_invalid', $codes($r), true), 'closing again revalidates the pilot device');
    $db->run("UPDATE devices SET status='active' WHERE tenant_id=? AND id=?", [$tenant, $device]);
    $db->run("UPDATE users SET status='inactive' WHERE tenant_id=? AND id=?", [$tenant, $adminUser['id']]);
    $db->run("UPDATE admin_accounts SET active=FALSE WHERE tenant_id=? AND user_id<>?", [$tenant, $adminUser['id']]);
    $r = adminRequest($platform, 'POST', '/onboarding/close', ['version' => 5]);
    check($r[0] === 409 && $codes($r) === ['admin_missing'], 'without an enabled client admin the platform cannot close');
    $db->run("UPDATE users SET status='active' WHERE tenant_id=? AND id=?", [$tenant, $adminUser['id']]);
    $db->run('UPDATE admin_accounts SET active=TRUE WHERE tenant_id=?', [$tenant]);
    // Cambiar de piloto reinicia la comprobación de comunicación.
    $s = expect(adminRequest($platform, 'PATCH', '/onboarding', ['version' => 5, 'pilot' => ['user_id' => $pid, 'device_id' => null]]), 200, 'pilot without device');
    check($s['pilot']['device_id'] === null && in_array('pilot_missing', $codes(adminRequest($platform, 'POST', '/onboarding/close', ['version' => 6])), true), 'pilot person without device cannot close');

    // Aislamiento: otro cliente no ve ni comparte nada; un admin no entra a otro cliente.
    $po = $platform; $po['tenant'] = $other['tenant_id'];
    $x = expect(adminRequest($po, 'GET', '/onboarding'), 200, 'other tenant state');
    check($x['version'] === 0 && $x['pilot'] === null && $x['steps'] === [], 'onboarding is per tenant');
    $foreign = $admin; $foreign['tenant'] = $other['tenant_id'];
    check(adminRequest($foreign, 'GET', '/onboarding')[0] === 404 && adminRequest($foreign, 'GET', '/onboarding/summary')[0] === 404, 'client admin cannot read another tenant');

    // B2 resumen: acceso preparado por la plataforma, sin cuentas ni secretos; no amplía los permisos existentes.
    expect(adminRequest($platform, 'POST', '/auth/reauth', ['password' => $platform['password']]), 200, 'reauth for account');
    $role = Util::bin(Util::uuid());
    $db->run("INSERT INTO roles (tenant_id,id,name) VALUES (?,?,'Administración onboarding')", [$tenant, $role]);
    $db->run("INSERT INTO role_permissions (tenant_id,role_id,permission_code) VALUES (?,?,'organizacion.editar')", [$tenant, $role]);
    expect(adminRequest($platform, 'POST', '/panel-accounts', ['kind' => 'company', 'email' => 'onboarding.' . substr(Util::uuid(), 0, 8) . '@example.test', 'display_name' => null, 'user_id' => Util::id($member['id']),
        'access' => ['role_id' => Util::id($role), 'scope' => accessScopeBody('all')]]), 201, 'platform prepares the client admin account');
    $db->run("INSERT INTO data_sources (tenant_id,id,name,kind,config_ciphertext,mode,active) VALUES (?,?,'Fuente secreta','rest','x','live',TRUE)", [$tenant, $source = Util::bin(Util::uuid())]);
    $db->run("INSERT INTO data_domain_owners (tenant_id,domain,source_id) VALUES (?,'personas',?)", [$tenant, $source]);
    $r = adminRequest($admin, 'GET', '/onboarding/summary');
    $m = expect($r, 200, 'client admin reads the authorized summary'); $clean($r, 'summary');
    check(!str_contains($r[3], 'Fuente secreta') && !str_contains($r[3], 'azcadmin'), 'summary has no source or IT account names');
    check($m['access'] === ['status' => 'tested', 'prepared_by' => 'platform', 'managed_by' => 'client'], 'access prepared by the platform: ' . json_encode($m['access']));
    check($m['plan']['default_plan'] === ['name' => 'Completo', 'active' => true] && $m['origin']['mode'] === 'one' && $m['origin']['live'] === true && $m['origin']['owned_domains'] === ['personas'] && $m['origin']['last_full_applied_at'] === null, 'plan and origin summarized');
    check($m['hardening'] === ['configured' => false, 'mode' => 'auto'], 'IT account summarized without password');
    check(adminRequest($admin, 'GET', '/data-sources')[0] === 403 && adminRequest($admin, 'GET', '/panel-accounts')[0] === 403, 'summary does not widen existing permissions');
    $z = expect(adminRequest($po, 'GET', '/onboarding/summary'), 200, 'other tenant summary');
    check($z['origin']['mode'] === 'one_pending' && $z['access']['status'] === 'pending' && $z['access']['prepared_by'] === null, 'summary per tenant: nothing prepared there');

    // Sin autogestión de personas, usuarios.crear no habilita la puesta en marcha (función no contratada).
    $db->run("DELETE FROM tenant_features WHERE tenant_id=? AND feature_code='autogestion_personas'", [$other['tenant_id']]);
    $creator = adminFixture($db, adminMember($db, $other), ['usuarios.crear'], false, 'tenant');
    check(adminRequest($creator, 'GET', '/onboarding')[0] === 403, 'feature not contracted: its permission does not count as admin');
    $enroler = adminFixture($db, adminMember($db, $other), ['usuarios.crear', 'equipos.enrolar'], false, 'tenant');
    expect(adminRequest($enroler, 'GET', '/onboarding'), 200, 'another usable admin permission does');
    echo 'PASS onboarding (' . ($GLOBALS['checks'] - $before) . " assertions)\n";
}
