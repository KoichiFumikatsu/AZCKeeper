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
