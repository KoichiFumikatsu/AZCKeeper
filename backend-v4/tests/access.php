<?php
declare(strict_types=1);
use Keeper\{AccessGrants,AccessScope,Database,Util};

// Fase 3 de v4-clientes-funciones-accesos: 1 rol + 1 alcance por persona (2026-10-05).

/** Vuelve a correr la conversion de 0043 (entre sus marcadores) solo sobre un cliente de prueba. */
function accessConversion(Database $db, string $tenant): void
{
    $sql = file_get_contents(dirname(__DIR__) . '/migrations/0043_access_role_scope.sql');
    $start = strpos($sql, '-- BEGIN conversion'); $end = strpos($sql, '-- END conversion');
    check($start !== false && $end !== false && $end > $start, 'conversion markers present');
    $db->run('SET @access_tenant = ?', [$tenant]);
    foreach (preg_split('/;\s*\R/', substr($sql, $start, $end - $start)) as $statement) {
        $statement = trim(preg_replace('/^\s*--.*$/m', '', $statement));
        if ($statement !== '') { $db->run($statement); }
    }
    $db->run('SET @access_tenant = NULL');
}

function accessMigrationTests(Database $db): void
{
    $before = $GLOBALS['checks'];
    $u = secondTenant($db); $tenant = $u['tenant_id'];
    $legacyRole = function (string $name, string $kind, array $permissions, array $units = [], ?string $seed = null) use ($db, $tenant): string {
        $id = Util::bin(Util::uuid());
        $db->run('INSERT INTO roles (tenant_id,id,name,seed_key,scope_kind) VALUES (?,?,?,?,?)', [$tenant, $id, $name, $seed, $kind]);
        foreach ($permissions as $p) { $db->run('INSERT INTO role_permissions (tenant_id,role_id,permission_code) VALUES (?,?,?)', [$tenant, $id, $p]); }
        foreach ($units as $unit) { $db->run("INSERT INTO role_{$kind}_scopes (tenant_id,role_id,{$kind}_id) VALUES (?,?,?)", [$tenant, $id, $unit]); }
        return $id;
    };
    $area2 = Util::bin(Util::uuid());
    $db->run("INSERT INTO org_units (tenant_id,id,kind,name) VALUES (?,?,'area','Agentes de servicio')", [$tenant, $area2]);
    $colab = $legacyRole('Colaborador', 'self', [], [], 'colaborador');
    $coord = $legacyRole('Coordinación legado', 'area', ['usuarios.ver', 'reportes.ver'], [$u['area_id'], $area2]);
    $sede = $legacyRole('Sede legado', 'site', ['usuarios.ver', 'equipos.ver'], [$u['site_id']]);
    $todo = $legacyRole('Gerencia legado', 'tenant', ['usuarios.ver']);
    $p1 = adminMember($db, $u); $p2 = adminMember($db, $u); $p3 = adminMember($db, $u); $p4 = adminMember($db, $u);
    $bind = fn (array $p, string $role) => $db->run('INSERT INTO user_roles (tenant_id,user_id,role_id) VALUES (?,?,?)', [$tenant, $p['id'], $role]);
    $bind($p1, $colab);                     // solo el Colaborador automatico
    $bind($p2, $coord); $bind($p2, $colab); // Coordinacion + Colaborador: queda Coordinacion, sin perdida
    $bind($p3, $sede); $bind($p3, $todo);   // dos roles reales: queda el de toda la empresa y se reporta lo perdido
    accessConversion($db, $tenant);

    $grant = fn (array $p) => $db->one('SELECT * FROM access_grants WHERE tenant_id=? AND user_id=?', [$tenant, $p['id']]);
    $units = fn (array $g) => array_column($db->run('SELECT unit_id FROM access_grant_units WHERE tenant_id=? AND grant_id=? ORDER BY unit_id', [$tenant, $g['id']])->fetchAll(), 'unit_id');
    $report = fn (array $p) => $db->one('SELECT * FROM access_migration_report WHERE tenant_id=? AND user_id=?', [$tenant, $p['id']]);
    $g1 = $grant($p1);
    check($g1['role_id'] === $colab && $g1['scope_kind'] === 'self' && $g1['source'] === 'default', 'collaborator keeps self as the default access');
    $g2 = $grant($p2); $want = [$u['area_id'], $area2]; sort($want, SORT_STRING);
    check($g2['role_id'] === $coord && $g2['scope_kind'] === 'filters' && $g2['source'] === 'manual' && $units($g2) === $want, 'area role scope copied to the person');
    $g3 = $grant($p3);
    check($g3['role_id'] === $todo && $g3['scope_kind'] === 'all' && $units($g3) === [], 'widest role kept');
    check($grant($p4) === null, 'person without roles gets no access');
    check($report($p1) === null, 'single role not reported');
    $r2 = $report($p2);
    check($r2 !== null && $r2['kept_role_id'] === $coord && $r2['dropped_roles'] === 'Colaborador (self)' && $r2['lost_permissions'] === '', 'lossless merge reported');
    $r3 = $report($p3);
    check($r3 !== null && $r3['dropped_roles'] === 'Sede legado (site)' && $r3['lost_permissions'] === 'equipos.ver', 'lost permissions reported');

    // Cuenta de grupo: en la otra empresa queda un acceso (todo o filtros); si solo tenia equipo/solo lo propio, se reporta.
    $b = secondTenant($db); $tb = $b['tenant_id'];
    $roleB = Util::bin(Util::uuid()); $teamB = Util::bin(Util::uuid());
    $db->run("INSERT INTO roles (tenant_id,id,name,scope_kind) VALUES (?,?,'Directivo','tenant')", [$tb, $roleB]);
    $db->run("INSERT INTO roles (tenant_id,id,name,scope_kind) VALUES (?,?,'Equipo B','team')", [$tb, $teamB]);
    $account = Util::bin(Util::uuid()); $other = Util::bin(Util::uuid());
    $db->run("INSERT INTO admin_accounts (tenant_id,id,user_id,email,password_hash) VALUES (?,?,?,?,'x')", [$tenant, $account, $p1['id'], Util::uuid() . '@example.test']);
    $db->run("INSERT INTO admin_accounts (tenant_id,id,user_id,email,password_hash) VALUES (?,?,?,?,'x')", [$tenant, $other, $p2['id'], Util::uuid() . '@example.test']);
    $db->run('INSERT INTO admin_account_roles (admin_tenant_id,admin_id,tenant_id,role_id) VALUES (?,?,?,?)', [$tenant, $account, $tb, $roleB]);
    $db->run('INSERT INTO admin_account_roles (admin_tenant_id,admin_id,tenant_id,role_id) VALUES (?,?,?,?)', [$tenant, $other, $tb, $teamB]);
    accessConversion($db, $tb);
    $gg = $db->one('SELECT * FROM access_grants WHERE tenant_id=? AND admin_tenant_id=? AND admin_id=?', [$tb, $tenant, $account]);
    check($gg !== null && $gg['role_id'] === $roleB && $gg['scope_kind'] === 'all' && $gg['user_id'] === null, 'group account access in the other company');
    check($db->one('SELECT 1 x FROM access_grants WHERE tenant_id=? AND admin_id=?', [$tb, $other]) === null, 'team-only group role gives no access');
    $rg = $db->one('SELECT * FROM access_migration_report WHERE tenant_id=? AND admin_id=?', [$tb, $other]);
    check($rg !== null && $rg['kept_role_id'] === null && $rg['dropped_roles'] === 'Equipo B (team)', 'group account without usable role reported');

    // Idempotente: correrla otra vez no duplica nada.
    accessConversion($db, $tenant); accessConversion($db, $tb);
    check((int) $db->one('SELECT COUNT(*) n FROM access_grants WHERE tenant_id=?', [$tenant])['n'] === 3, 'conversion is idempotent (grants)');
    check((int) $db->one('SELECT COUNT(*) n FROM access_migration_report WHERE tenant_id IN (?,?)', [$tenant, $tb])['n'] === 3, 'conversion is idempotent (report)');
    echo 'PASS access migration (' . ($GLOBALS['checks'] - $before) . " assertions)\n";
}

/** Cuerpo AccessScope del contrato: las cuatro listas siempre presentes y en el orden del DTO (se compara con ===). */
function accessScopeBody(string $kind, array $lists = []): array
{
    $body = ['kind' => $kind];
    foreach (['company_ids', 'cities', 'site_ids', 'area_ids'] as $key) { $body[$key] = $lists[$key] ?? []; }
    return $body;
}

function accessScopeTests(Database $db): void
{
    $before = $GLOBALS['checks'];
    $u = secondTenant($db); $tenant = $u['tenant_id'];
    $db->run("UPDATE org_units SET city='Medellín' WHERE tenant_id=? AND id=?", [$tenant, $u['site_id']]);
    $company = Util::bin(Util::uuid());
    $db->run("INSERT INTO org_units (tenant_id,id,kind,name) VALUES (?,?,'company','BilingueLaw')", [$tenant, $company]);
    $db->run('UPDATE users SET company_id=? WHERE tenant_id=? AND id=?', [$company, $tenant, $u['id']]);
    $fail = function (array $body, string $detail) use ($db, $tenant): void {
        try { AccessScope::fromInput($db, $tenant, json_decode(json_encode($body))); check(false, "scope rejected: $detail"); }
        catch (Keeper\ApiError $e) { check($e->status === 422 && $e->detail === $detail, "scope rejected: $detail"); }
    };
    $fail(accessScopeBody('all', ['area_ids' => [Util::id($u['area_id'])]]), 'scope_lists_only_for_filters');
    $fail(accessScopeBody('filters'), 'scope_filters_empty');
    $fail(accessScopeBody('filters', ['area_ids' => [Util::id($u['site_id'])]]), 'unknown_area');
    $fail(accessScopeBody('filters', ['cities' => ['Atlántida']]), 'unknown_city');
    $s = AccessScope::fromInput($db, $tenant, json_decode(json_encode(accessScopeBody('filters', ['cities' => ['  MEDELLIN '], 'company_ids' => [Util::id($company), Util::id($company)]]))));
    check($s->kind === 'filters' && $s->companies === [$company] && $s->cities === ['MEDELLIN'], 'duplicates collapsed, city trimmed');

    // Guardar y leer.
    $role = AccessGrants::defaultRole($db, $tenant) ?? (function () use ($db, $tenant) {
        $id = Util::bin(Util::uuid()); $db->run("INSERT INTO roles (tenant_id,id,name,seed_key,scope_kind) VALUES (?,?,'Colaborador','colaborador','self')", [$tenant, $id]); return $id; })();
    AccessGrants::setForUser($db, $tenant, $u['id'], $role, $s, 'manual');
    $g = AccessGrants::forUser($db, $tenant, $u['id']);
    check($g['role_name'] === 'Colaborador' && $g['scope']->kind === 'filters' && $g['scope']->companies === [$company] && $g['scope']->cities === ['MEDELLIN'], 'access round trip');
    check(AccessGrants::dto($g)['scope'] === accessScopeBody('filters', ['company_ids' => [Util::id($company)], 'cities' => ['MEDELLIN']]), 'dto shape');

    // SQL: O dentro de un filtro, Y entre filtros; ciudad sin mayusculas ni tildes; filtros vacios no ven nada.
    $sees = function (AccessScope $scope) use ($db, $tenant, $u): bool {
        [$where, $args] = $scope->filterSql('u');
        return (bool) $db->one("SELECT u.id FROM users u WHERE u.tenant_id=? AND u.id=? AND $where", [$tenant, $u['id'], ...$args]);
    };
    $other = Util::bin(Util::uuid());
    $db->run("INSERT INTO org_units (tenant_id,id,kind,name) VALUES (?,?,'area','Otra área')", [$tenant, $other]);
    check($sees($s), 'company AND city match');
    check($sees(new AccessScope('filters', [], [], [], [$other, $u['area_id']])), 'OR inside a filter');
    check(!$sees(new AccessScope('filters', [$company], [], [], [$other])), 'AND across filters');
    check(!$sees(new AccessScope('filters')), 'empty filters see nothing');
    check($s->matches($db, $tenant, ['company_id' => $company, 'site_id' => $u['site_id'], 'area_id' => $u['area_id']]), 'placement inside');
    check(!$s->matches($db, $tenant, ['company_id' => null, 'site_id' => $u['site_id'], 'area_id' => $u['area_id']]), 'person without company is outside a company filter');

    // Cobertura (delegacion): cada filtro que limito, el otro tambien lo limita a un subconjunto.
    $area = new AccessScope('filters', [], [], [], [$u['area_id']]);
    check(AccessScope::all()->covers($area) && $area->covers(new AccessScope('filters', [], [], [$u['site_id']], [$u['area_id']])), 'narrower filters covered');
    check(!$area->covers(new AccessScope('filters', [], [], [$u['site_id']])), 'dropping my area filter widens');
    check(!$area->covers(AccessScope::all()) && !$area->covers(new AccessScope('filters', [], [], [], [$other])), 'all or other area not covered');
    check((new AccessScope('filters', [], ['Medellín']))->covers(new AccessScope('filters', [], ['MEDELLIN'])), 'cities compared without case or accents');

    // Acceso por defecto: Colaborador, solo lo propio.
    $p = adminMember($db, $u);
    AccessGrants::grantDefault($db, $tenant, $p['id']);
    $d = AccessGrants::forUser($db, $tenant, $p['id']);
    check($d['role_id'] === $role && $d['scope']->kind === 'self' && $d['source'] === 'default', 'default access');
    try { AccessGrants::setForGroupAccount($db, $tenant, Util::bin(Util::uuid()), Util::bin(Util::uuid()), $role, new AccessScope('team')); check(false, 'group scope'); }
    catch (Keeper\ApiError $e) { check($e->detail === 'group_scope_invalid', 'group accounts only get all or filters'); }
    echo 'PASS access scope (' . ($GLOBALS['checks'] - $before) . " assertions)\n";
}

/**
 * Cliente de los casos del §8. Tiene dos empresas (AZC Legal, BilingueLaw), tres sedes en tres ciudades y dos áreas.
 * Las ocho personas tienen equipo y actividad del 2026-09-10.
 * Jerarquía: ag808 reporta a coord2 y jur808 a ag808. También queda «base», la persona de secondTenant() sin empresa.
 */
function accessOrg(Database $db): array
{
    $base = secondTenant($db); $tenant = $base['tenant_id'];
    for ($w = 1; $w <= 7; $w++) { $db->run('INSERT INTO schedule_days (tenant_id,schedule_id,weekday) VALUES (?,?,?)', [$tenant, $base['schedule_id'], $w]); }
    $unit = function (string $kind, string $name, ?string $city = null) use ($db, $tenant): string {
        $id = Util::bin(Util::uuid());
        $db->run('INSERT INTO org_units (tenant_id,id,kind,name,city) VALUES (?,?,?,?,?)', [$tenant, $id, $kind, $name, $city]);
        return $id;
    };
    $o = ['tenant' => $tenant, 'base' => $base, 'azc' => $unit('company', 'AZC Legal'), 'bl' => $unit('company', 'BilingueLaw'),
        'teq' => $unit('site', 'Tequendama', 'Cali'), 'med' => $unit('site', 'Medellín 808', 'Medellín'), 'torre' => $unit('site', 'Torre Bogotá', 'Bogotá'),
        'agentes' => $unit('area', 'Agentes de servicio'), 'juridica' => $unit('area', 'Jurídica')];
    $people = ['zafra' => ['azc', 'torre', 'juridica'], 'bl1' => ['bl', 'teq', 'agentes'], 'bl2' => ['bl', 'torre', 'juridica'], 'juan' => ['azc', 'teq', 'agentes'],
        'ag808' => ['azc', 'med', 'agentes'], 'agTorre' => ['azc', 'torre', 'agentes'], 'jur808' => ['azc', 'med', 'juridica'], 'coord2' => ['azc', 'med', 'agentes']];
    foreach ($people as $key => [$company, $site, $area]) {
        $id = Util::bin(Util::uuid()); $assignment = Util::bin(Util::uuid());
        $db->run('INSERT INTO users (tenant_id,id,display_name,firm_id,site_id,area_id,position_id,schedule_id,company_id) VALUES (?,?,?,?,?,?,?,?,?)',
            [$tenant, $id, $key, $base['firm_id'], $o[$site], $o[$area], $base['position_id'], $base['schedule_id'], $o[$company]]);
        $db->run("INSERT INTO user_assignments (tenant_id,id,user_id,firm_id,site_id,area_id,position_id,schedule_id,company_id,starts_at) VALUES (?,?,?,?,?,?,?,?,?,'2026-09-01')",
            [$tenant, $assignment, $id, $base['firm_id'], $o[$site], $o[$area], $base['position_id'], $base['schedule_id'], $o[$company]]);
        $db->run("INSERT INTO devices (tenant_id,id,user_id,hostname,agent_version,os_edition,cpu,ram_bytes,capabilities,last_seen_at) VALUES (?,?,?,?,'4.0','test','test',1024,'[]',UTC_TIMESTAMP(6))",
            [$tenant, Util::bin(Util::uuid()), $id, 'PC-' . $key]);
        $db->run("INSERT INTO day_summary (tenant_id,day,user_id,user_assignment_id,active_seconds,idle_seconds,productive_seconds,expected_seconds,coverage_percent,productivity_percent,calculation_version,timezone,calculated_at,data_through) VALUES (?,'2026-09-10',?,?,120,30,90,240,50,75,'access-test','America/Bogota','2026-09-11','2026-09-10 23:00:00')",
            [$tenant, $id, $assignment]);
        $o[$key] = $db->one('SELECT * FROM users WHERE tenant_id=? AND id=?', [$tenant, $id]);
    }
    $db->run('UPDATE users SET reports_to_user_id=? WHERE tenant_id=? AND id=?', [$o['coord2']['id'], $tenant, $o['ag808']['id']]);
    $db->run('UPDATE users SET reports_to_user_id=? WHERE tenant_id=? AND id=?', [$o['ag808']['id'], $tenant, $o['jur808']['id']]);
    return $o;
}

/** Cuenta del panel de una persona del cliente de casos, con su rol de prueba y el alcance dado. */
function accessAccount(Database $db, array $o, string $key, array $permissions, AccessScope $scope): array
{
    $a = adminFixture($db, $o[$key], $permissions);
    AccessGrants::setForUser($db, $o['tenant'], $o[$key]['id'], $a['role'], $scope, 'manual');
    return $a;
}

/** Claves de accessOrg (ordenadas) de las personas que devuelve un listado del panel. */
function accessSeen(array $o, array $admin, string $path, string $field = 'id'): array
{
    $byId = [];
    foreach ($o as $key => $v) { if (is_array($v) && isset($v['display_name'], $v['id'])) { $byId[Util::id($v['id'])] = $key; } }
    $seen = array_map(fn ($r) => $byId[$r[$field]] ?? 'otro', expect(adminRequest($admin, 'GET', $path), 200, "list $path")['data']);
    sort($seen); return $seen;
}

function accessEvaluationTests(Database $db): void
{
    $before = $GLOBALS['checks'];
    $o = accessOrg($db); $tid = Util::id($o['tenant']);
    $perms = ['usuarios.ver', 'equipos.ver', 'reglas.ver']; // reglas.ver: el 403 de /policies debe ser por alcance, no por permiso
    $users = '/users?limit=100'; $devices = '/tenants/' . $tid . '/devices?limit=100';
    $all = accessAccount($db, $o, 'zafra', $perms, AccessScope::all());
    $area = accessAccount($db, $o, 'juan', $perms, new AccessScope('filters', [], [], [], [$o['agentes']]));
    $city = accessAccount($db, $o, 'jur808', $perms, new AccessScope('filters', [], ['Medellín']));
    $team = accessAccount($db, $o, 'coord2', $perms, new AccessScope('team'));
    $own = accessAccount($db, $o, 'agTorre', $perms, AccessScope::own());
    $mix = accessAccount($db, $o, 'bl1', $perms, new AccessScope('filters', [$o['bl'], $o['azc']], [], [$o['teq']]));
    $empty = accessAccount($db, $o, 'bl2', $perms, new AccessScope('filters'));
    check(accessSeen($o, $all, $users) === ['ag808', 'agTorre', 'base', 'bl1', 'bl2', 'coord2', 'juan', 'jur808', 'zafra'], 'all sees the whole client');
    check(accessSeen($o, $area, $users) === ['ag808', 'agTorre', 'bl1', 'coord2', 'juan'], 'area filter, every site and company');
    check(accessSeen($o, $area, $devices, 'user_id') === ['ag808', 'agTorre', 'bl1', 'coord2', 'juan'], 'devices follow the same scope');
    check(accessSeen($o, $city, $users) === ['ag808', 'coord2', 'jur808'], 'city filter through the site');
    check(accessSeen($o, $team, $users) === ['ag808', 'coord2', 'jur808'], 'team = self + direct + indirect reports');
    check(accessSeen($o, $own, $users) === ['agTorre'], 'self sees only self');
    check(accessSeen($o, $mix, $users) === ['bl1', 'juan'], 'OR inside company, AND with site');
    check(accessSeen($o, $empty, $users) === [], 'filters without values see nothing');
    check(adminRequest($area, 'GET', '/users/' . Util::id($o['jur808']['id']))[0] === 404, 'outside detail stays 404');
    $full = adminRequest($area, 'GET', '/policies');
    check($full[0] === 403 && $full[1]['detail'] === 'scope_denied', 'whole-client screens need scope all');
    $mine = expect(adminRequest($area, 'GET', '/me/access'), 200, 'my access');
    check(array_column($mine['permissions'], 'scopes', 'code') === ['equipos.ver' => ['filters'], 'reglas.ver' => ['filters'], 'usuarios.ver' => ['filters']], 'my access reports filters');
    echo 'PASS access evaluation (' . ($GLOBALS['checks'] - $before) . " assertions)\n";
}
