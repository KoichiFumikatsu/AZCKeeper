<?php
declare(strict_types=1);
use Keeper\{Database,Util};

// F5: jerarquia, alcance "mi equipo", reglas cargo/jefatura -> rol y directivos fuera del monitoreo.
function roleRulesTests(Database $db): void
{
    $before = $GLOBALS['checks'];
    $u = secondTenant($db); $tenant = $u['tenant_id']; $boss = Util::id($u['id']);
    $platform = sharedPlatform($db); $platform['tenant'] = $tenant;
    $org = fn (string $col) => Util::id($u[$col]);
    $person = function (string $name, ?string $reportsTo) use ($platform, $org): array {
        return expect(adminRequest($platform, 'POST', '/users', ['display_name' => $name, 'email' => null, 'firm_id' => $org('firm_id'), 'site_id' => $org('site_id'),
            'area_id' => $org('area_id'), 'position_id' => $org('position_id'), 'schedule_id' => $org('schedule_id'), 'reports_to_user_id' => $reportsTo]), 201, "create $name");
    };
    $p1 = $person('Reporta directo 1', $boss); $p2 = $person('Reporta directo 2', $boss);
    $p3 = $person('Reporta indirecto', $p1['id']); $p4 = $person('Fuera del equipo', null);
    check($p3['reports_to_user_id'] === $p1['id'], 'manager stored');
    $cycle = adminRequest($platform, 'PATCH', '/users/' . $boss, ['reports_to_user_id' => $p3['id']], ['if-match' => '"' . $db->one('SELECT version FROM users WHERE tenant_id=? AND id=?', [$tenant, $u['id']])['version'] . '"']);
    check($cycle[0] === 422 && $cycle[1]['detail'] === 'hierarchy_cycle', 'hierarchy cycles rejected');

    // Rol de jefe con alcance "mi equipo", asignable por regla.
    $plain = expect(adminRequest($platform, 'POST', '/roles', ['name' => 'Solo manual', 'permissions' => ['usuarios.ver'], 'active' => true]), 201, 'create manual team role');
    check($plain['assignable_by_rule'] === false, 'manual role');
    $rule = ['name' => 'Jefes ven a su equipo', 'condition_kind' => 'manages', 'position_id' => null, 'area_id' => null, 'role_id' => $plain['id'], 'active' => true];
    check(adminRequest($platform, 'POST', '/role-rules', $rule)[1]['detail'] === 'role_not_assignable', 'rules only use assignable roles');
    $jefe = expect(adminRequest($platform, 'POST', '/roles', ['name' => 'Jefe de equipo', 'permissions' => ['usuarios.ver'], 'active' => true, 'assignable_by_rule' => true]), 201, 'create assignable role');
    $created = expect(adminRequest($platform, 'POST', '/role-rules', ['role_id' => $jefe['id']] + $rule), 201, 'create manages rule');
    check($created['matches'] === 2, 'boss and p1 manage someone');
    $byRule = fn (string $user) => (bool) $db->one("SELECT 1 FROM access_grants WHERE tenant_id=? AND user_id=? AND role_id=? AND source='rule'", [$tenant, Util::bin($user), Util::bin($jefe['id'])]);
    check($byRule($boss) && $byRule($p1['id']) && !$byRule($p2['id']), 'rule assigned the role to managers only');

    // Rol por defecto del cliente: al dejar de cumplirse la regla, la persona vuelve a este rol.
    $db->run("INSERT INTO roles (tenant_id,id,name,seed_key,scope_kind) VALUES (?,?,'Colaborador','colaborador','self')", [$tenant, Util::bin(Util::uuid())]);

    // La regla propone el rol, no el alcance (§4): el jefe queda con «solo lo propio» hasta que alguien le da «su equipo».
    $scopeOf = fn (string $user) => $db->one('SELECT scope_kind FROM access_grants WHERE tenant_id=? AND user_id=?', [$tenant, Util::bin($user)])['scope_kind'];
    check($scopeOf($boss) === 'self', 'rule never sets the scope');
    $bv = $db->one('SELECT version FROM users WHERE tenant_id=? AND id=?', [$tenant, $u['id']])['version'];
    expect(adminRequest($platform, 'PUT', '/users/' . $boss . '/access', ['role_id' => $jefe['id'], 'scope' => accessScopeBody('team')], ['if-match' => '"' . $bv . '"']), 200, 'give the boss his team');
    check($byRule($boss) && $scopeOf($boss) === 'team', 'changing only the scope keeps the rule role');

    // El jefe ve a su equipo (directos e indirectos) y a nadie mas.
    expect(adminRequest($platform, 'POST', '/auth/reauth', ['password' => $platform['password']]), 200, 'reauth');
    $email = 'jefe.' . substr(Util::uuid(), 0, 8) . '@example.test';
    $acct = expect(adminRequest($platform, 'POST', '/panel-accounts', ['kind' => 'company', 'email' => $email, 'display_name' => null, 'user_id' => $boss]), 201, 'boss account');
    $me = panelLogin($email, $acct['temporary_password']); $me['tenant'] = $tenant;
    $me['csrf'] = expect(adminRequest($me, 'POST', '/auth/password', ['current_password' => $acct['temporary_password'], 'new_password' => 'Clave-del-jefe-01']), 200, 'boss password')['csrf_token'];
    $seen = array_column(expect(adminRequest($me, 'GET', '/users'), 200, 'boss lists team')['data'], 'id');
    sort($seen); $want = [$boss, $p1['id'], $p2['id'], $p3['id']]; sort($want);
    check($seen === $want, 'team scope = self + direct + indirect reports');
    expect(adminRequest($me, 'GET', '/users/' . $p4['id']), 404, 'person outside the team invisible');
    // Las cuentas guardan los roles de regla al cambiar sus roles manuales.
    check(adminRequest($platform, 'PATCH', '/panel-accounts/' . $acct['account']['id'], ['active' => true])[0] === 200 && $byRule($boss), 'editing the account keeps the rule role');

    // Si p1 deja de tener a quien dirigir, la regla le quita el rol (y solo ese).
    $v3 = $db->one('SELECT version FROM users WHERE tenant_id=? AND id=?', [$tenant, Util::bin($p3['id'])])['version'];
    expect(adminRequest($platform, 'PATCH', '/users/' . $p3['id'], ['reports_to_user_id' => $boss], ['if-match' => '"' . $v3 . '"']), 200, 'move p3 under boss');
    check(!$byRule($p1['id']) && $byRule($boss), 'rule role removed when condition stops');
    check($scopeOf($boss) === 'team', 'applying rules again keeps the scope');
    check($db->one("SELECT source FROM access_grants WHERE tenant_id=? AND user_id=?", [$tenant, Util::bin($p1['id'])])['source'] === 'default', 'stopped matching: back to the default role');

    // Directivos fuera del monitoreo: por cargo, con excepcion por persona; el alta no los aprueba sola.
    $director = Util::bin(Util::uuid());
    $db->run("INSERT INTO org_units (tenant_id,id,kind,name) VALUES (?,?,'position','Presidente')", [$tenant, $director]);
    expect(adminRequest($platform, 'PUT', '/monitoring-exclusions', ['position_ids' => [Util::id($director)]]), 200, 'exclude director position');
    $vp = $db->one('SELECT version FROM users WHERE tenant_id=? AND id=?', [$tenant, Util::bin($p4['id'])])['version'];
    $ex = expect(adminRequest($platform, 'PATCH', '/users/' . $p4['id'], ['position_id' => Util::id($director), 'document' => '5550001'], ['if-match' => '"' . $vp . '"']), 200, 'make p4 president');
    check($ex['monitoring'] === 'excluded' && $ex['monitoring_override'] === 'auto', 'president excluded by position');
    $exc = expect(adminRequest($platform, 'PATCH', '/users/' . $p4['id'], ['monitoring_override' => 'monitored'], ['if-match' => '"' . $ex['version'] . '"']), 200, 'exception: monitor this president');
    check($exc['monitoring'] === 'monitored', 'per-person exception wins');
    expect(adminRequest($platform, 'PATCH', '/users/' . $p4['id'], ['monitoring_override' => 'auto'], ['if-match' => '"' . $exc['version'] . '"']), 200, 'back to automatic');
    $key = expect(adminRequest($platform, 'POST', '/enrollments/keys'), 201, 'enrollment key');
    expect(adminRequest($platform, 'PUT', '/enrollments/settings', ['auto_approve_serial_match' => true, 'self_identify' => true, 'self_identify_auto_confirm' => true]), 200, 'self identify with auto confirm');
    $asked = expect(intakeAsk($key['key'], keypair(), null, 'DESKTOP-PRESI', '5550001'), 200, 'president PC asks to enroll');
    check($asked['status'] === 'pending', 'excluded person never auto-approved');
    $queue = expect(adminRequest($platform, 'GET', '/enrollments/requests?status=pending'), 200, 'intake queue')['data'];
    $row = array_values(array_filter($queue, fn ($x) => $x['hostname'] === 'DESKTOP-PRESI'))[0];
    check($row['alert'] === 'person_excluded', 'intake shows person_excluded alert');

    // Delegacion (§4): una regla no da un rol con permisos que quien la guarda no tiene; cambiar el cargo tampoco.
    accessResetPreloginQuota();
    $v = secondTenant($db); $t2 = $v['tenant_id'];
    $platform2 = sharedPlatform($db); $platform2['tenant'] = $t2;
    $strong = expect(adminRequest($platform2, 'POST', '/roles', ['name' => 'Gestor por cargo', 'permissions' => ['usuarios.ver', 'usuarios.editar', 'equipos.ver'], 'active' => true, 'assignable_by_rule' => true]), 201, 'strong assignable role');
    $light = expect(adminRequest($platform2, 'POST', '/roles', ['name' => 'Lector por cargo', 'permissions' => ['usuarios.ver'], 'active' => true, 'assignable_by_rule' => true]), 201, 'light assignable role');
    $position = function (string $name) use ($db, $t2): string { $id = Util::bin(Util::uuid()); $db->run("INSERT INTO org_units (tenant_id,id,kind,name) VALUES (?,?,'position',?)", [$t2, $id, $name]); return Util::id($id); };
    $director = $position('Director de sede'); $analyst = $position('Analista');
    $manager = adminFixture($db, adminMember($db, $v), ['usuarios.ver', 'usuarios.editar', 'roles.reglas']); $manager['tenant'] = $t2;
    $exceeds = fn (array $r) => $r[0] === 403 && ($r[1]['detail'] ?? null) === 'delegation_exceeds_own_access';
    $byPosition = fn (string $role, string $pos) => ['name' => 'Por cargo ' . substr(Util::uuid(), 0, 6), 'condition_kind' => 'position', 'position_id' => $pos, 'area_id' => null, 'role_id' => $role, 'active' => true];
    check($exceeds(adminRequest($manager, 'POST', '/role-rules', $byPosition($strong['id'], $director))), 'a rule cannot grant permissions the caller lacks');
    $lightRule = expect(adminRequest($manager, 'POST', '/role-rules', $byPosition($light['id'], $analyst)), 201, 'a rule with a role the caller holds');
    check($exceeds(adminRequest($manager, 'PATCH', '/role-rules/' . $lightRule['id'], $byPosition($strong['id'], $analyst), ['if-match' => '"' . $lightRule['version'] . '"'])), 'editing a rule cannot switch to a larger role');
    expect(adminRequest($platform2, 'POST', '/role-rules', $byPosition($strong['id'], $director)), 201, 'platform creates the larger rule');
    $target = adminMember($db, $v);
    $roleOf = fn () => $db->one('SELECT role_id FROM access_grants WHERE tenant_id=? AND user_id=?', [$t2, $target['id']])['role_id'] ?? null;
    $tv = fn () => '"' . $db->one('SELECT version FROM users WHERE tenant_id=? AND id=?', [$t2, $target['id']])['version'] . '"';
    check($exceeds(adminRequest($manager, 'PATCH', '/users/' . Util::id($target['id']), ['position_id' => $director], ['if-match' => $tv()])), 'changing the position cannot trigger a larger role');
    check($roleOf() !== Util::bin($strong['id']), 'refused position change grants nothing');
    expect(adminRequest($manager, 'PATCH', '/users/' . Util::id($target['id']), ['position_id' => $analyst], ['if-match' => $tv()]), 200, 'position with a role the caller holds');
    check($roleOf() === Util::bin($light['id']), 'rule within the caller access fires');
    expect(adminRequest($platform2, 'PATCH', '/users/' . Util::id($target['id']), ['position_id' => $director], ['if-match' => $tv()]), 200, 'platform changes the position');
    check($roleOf() === Util::bin($strong['id']), 'platform change fires the larger rule');

    // Precision del 403 (revision final, punto 4): solo cuenta lo que cambia para la persona editada (y su jefe con la
    // regla «manages»); el desfase de otras personas no se cobra a quien edita, y el error nombra a la persona y el rol.
    $named = function (string $name) use ($db, $v): array { $p = adminMember($db, $v); $db->run('UPDATE users SET display_name=? WHERE tenant_id=? AND id=?', [$name, $p['tenant_id'], $p['id']]); return $p; };
    $ana = $named('Ana Pérez'); $drift = $named('Desfase previo');
    $db->run('UPDATE users SET position_id=? WHERE tenant_id=? AND id=?', [Util::bin($director), $t2, $drift['id']]); // sin recalcular: desfase
    $uv = fn (array $p) => '"' . $db->one('SELECT version FROM users WHERE tenant_id=? AND id=?', [$t2, $p['id']])['version'] . '"';
    $roleOfP = fn (array $p) => $db->one('SELECT role_id FROM access_grants WHERE tenant_id=? AND user_id=?', [$t2, $p['id']])['role_id'] ?? null;
    expect(adminRequest($manager, 'PATCH', '/role-rules/' . $lightRule['id'], $byPosition($light['id'], $analyst), ['if-match' => '"' . $lightRule['version'] . '"']), 200, 'saving an unrelated rule does not charge the drift');
    expect(adminRequest($manager, 'PATCH', '/users/' . Util::id($ana['id']), ['position_id' => $analyst], ['if-match' => $uv($ana)]), 200, 'drift elsewhere does not block an unrelated edit');
    check($roleOfP($ana) === Util::bin($light['id']), 'the edited person gets her rule role');
    check($roleOfP($drift) !== Util::bin($strong['id']), 'the caller never applies drift they could not give');
    $r = adminRequest($manager, 'PATCH', '/users/' . Util::id($ana['id']), ['position_id' => $director], ['if-match' => $uv($ana)]);
    check($exceeds($r) && ($r[1]['errors'][0]['message'] ?? null) === 'No puedes dar el rol Gestor por cargo a Ana Pérez: tiene permisos que tú no tienes.', 'the 403 names the person and the role');
    check($roleOfP($ana) === Util::bin($light['id']), 'refused change keeps her role');
    // «manages»: poner a alguien de jefe cuenta para ese jefe.
    expect(adminRequest($platform2, 'POST', '/role-rules', ['name' => 'Jefes fuertes', 'condition_kind' => 'manages', 'position_id' => null, 'area_id' => null, 'role_id' => $strong['id'], 'active' => true]), 201, 'platform creates a strong manages rule');
    check($roleOfP($drift) === Util::bin($strong['id']), 'a platform apply settles the drift');
    $boss = $named('Bruno Jefe');
    $r = adminRequest($manager, 'PATCH', '/users/' . Util::id($ana['id']), ['reports_to_user_id' => Util::id($boss['id'])], ['if-match' => $uv($ana)]);
    check($exceeds($r) && ($r[1]['errors'][0]['message'] ?? null) === 'No puedes dar el rol Gestor por cargo a Bruno Jefe: tiene permisos que tú no tienes.', 'the manages rule names the new manager');
    check($db->one('SELECT reports_to_user_id FROM users WHERE tenant_id=? AND id=?', [$t2, $ana['id']])['reports_to_user_id'] === null && $roleOfP($boss) === null, 'refused manager change rolled back');
    expect(adminRequest($platform2, 'PATCH', '/users/' . Util::id($ana['id']), ['reports_to_user_id' => Util::id($boss['id'])], ['if-match' => $uv($ana)]), 200, 'platform sets the manager');
    check($roleOfP($boss) === Util::bin($strong['id']), 'platform change gives the manager his rule role');
    echo 'PASS role rules (' . ($GLOBALS['checks'] - $before) . " assertions)\n";
}
