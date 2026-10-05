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
