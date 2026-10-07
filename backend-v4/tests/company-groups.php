<?php
declare(strict_types=1);
use Keeper\{Database,Util};

// Grupos empresariales y cuentas de grupo (§8.5): un dueño entra a varias empresas con un solo usuario, sin ser superadmin.
function companyGroupTests(Database $db): void
{
    $before = $GLOBALS['checks'];
    $a = secondTenant($db); $b = secondTenant($db); $c = secondTenant($db);
    $ta = $a['tenant_id']; $tb = $b['tenant_id'];
    $platform = sharedPlatform($db); $platform['tenant'] = $ta;
    $role = function (string $tenant, string $permission) use ($db): string {
        $id = Util::bin(Util::uuid());
        $db->run("INSERT INTO roles (tenant_id,id,name) VALUES (?,?,'Directivo')", [$tenant, $id]);
        $db->run('INSERT INTO role_permissions (tenant_id,role_id,permission_code) VALUES (?,?,?)', [$tenant, $id, $permission]);
        return Util::id($id);
    };
    $roleA = $role($ta, 'equipos.ver'); $roleB = $role($tb, 'equipos.ver');

    $group = expect(adminRequest($platform, 'POST', '/company-groups', ['name' => 'Grupo AZC (holding)', 'tenant_ids' => [Util::id($ta), Util::id($tb)]]), 201, 'create company group');
    check(count($group['tenant_ids']) === 2, 'group has two companies');
    check(adminRequest($platform, 'POST', '/company-groups', ['name' => 'Otro', 'tenant_ids' => [Util::id($ta)]])[1]['detail'] === 'company_in_other_group', 'a company belongs to one group');

    expect(adminRequest($platform, 'POST', '/auth/reauth', ['password' => $platform['password']]), 200, 'platform reauth');
    $email = 'dueno.' . substr(Util::uuid(), 0, 8) . '@example.test';
    $body = ['kind' => 'company', 'email' => $email, 'display_name' => null, 'user_id' => Util::id($a['id']), 'access' => ['role_id' => $roleA, 'scope' => accessScopeBody('all')],
        'group_id' => $group['id'], 'group_access' => [['tenant_id' => Util::id($tb), 'role_id' => $roleB, 'scope' => accessScopeBody('all')]]];
    $bad = adminRequest($platform, 'POST', '/panel-accounts', ['group_access' => [['tenant_id' => Util::id($c['tenant_id']), 'role_id' => $roleB, 'scope' => accessScopeBody('all')]]] + $body);
    check($bad[0] === 422 && $bad[1]['detail'] === 'tenant_not_in_group', 'roles only in group companies');
    $created = expect(adminRequest($platform, 'POST', '/panel-accounts', $body), 201, 'create group account');
    check($created['account']['group_name'] === 'Grupo AZC (holding)' && $created['account']['group_access'][0]['role_id'] === $roleB && $created['account']['group_access'][0]['scope']['kind'] === 'all', 'account carries group access');
    $teamBad = adminRequest($platform, 'POST', '/panel-accounts', ['email' => 'otro.' . $email, 'user_id' => Util::id(adminMember($db, $a)['id']), 'group_access' => [['tenant_id' => Util::id($tb), 'role_id' => $roleB, 'scope' => accessScopeBody('team')]]] + $body);
    check($teamBad[0] === 422 && $teamBad[1]['detail'] === 'group_scope_invalid', 'group access only all or filters');
    $twice = ['tenant_id' => Util::id($tb), 'role_id' => $roleB, 'scope' => accessScopeBody('all')];
    $dupBad = adminRequest($platform, 'POST', '/panel-accounts', ['email' => 'dup.' . $email, 'user_id' => Util::id(adminMember($db, $a)['id']), 'group_access' => [$twice, $twice]] + $body);
    check($dupBad[0] === 422 && $dupBad[1]['detail'] === 'duplicate_tenant', 'one access per group company');
    check(adminRequest($platform, 'POST', '/panel-accounts', ['email' => 'mal.' . $email, 'group_access' => [['tenant_id' => Util::id($tb), 'role_id' => $roleB]]] + $body)[0] === 422, 'group access without scope: 422');
    check(adminRequest($platform, 'POST', '/panel-accounts', ['email' => 'mal2.' . $email, 'access' => ['role_id' => $roleA]] + $body)[0] === 422, 'access without scope: 422');
    check(adminRequest($platform, 'POST', '/panel-accounts', ['email' => 'mal3.' . $email, 'role_ids' => [$roleA]] + $body)[0] === 422, 'old role_ids body: 422');

    $owner = panelLogin($email, $created['temporary_password']); $owner['tenant'] = $ta;
    $changed = expect(adminRequest($owner, 'POST', '/auth/password', ['current_password' => $created['temporary_password'], 'new_password' => 'Clave-del-dueno-1']), 200, 'owner sets password');
    $owner['csrf'] = $changed['csrf_token'];
    $ids = array_column($changed['group']['tenants'] ?? [], 'id');
    check(count($ids) === 2 && in_array(Util::id($tb), $ids, true) && $changed['group']['tenants'][0]['name'] !== '', 'session lists group companies with names');

    // Entra a la otra empresa con el rol que se le dio alli, y nada mas; nunca a una empresa fuera del grupo.
    $inB = $owner; $inB['tenant'] = $tb;
    expect(adminRequest($inB, 'GET', '/tenants/' . Util::id($tb) . '/devices'), 200, 'owner reads devices of the other company');
    expect(adminRequest($inB, 'GET', '/users'), 403, 'owner lacks other permissions there');
    $inC = $owner; $inC['tenant'] = $c['tenant_id'];
    expect(adminRequest($inC, 'GET', '/tenants/' . Util::id($c['tenant_id']) . '/devices'), 404, 'company outside the group invisible');
    expect(adminRequest($owner, 'GET', '/company-groups'), 403, 'group account is not platform');

    // Sacar la empresa B del grupo corta el acceso al instante.
    expect(adminRequest($platform, 'PATCH', '/company-groups/' . $group['id'], ['tenant_ids' => [Util::id($ta)]], ['if-match' => '"' . $group['version'] . '"']), 200, 'remove company from group');
    expect(adminRequest($inB, 'GET', '/tenants/' . Util::id($tb) . '/devices'), 404, 'access removed with the company');
    check(!$db->one('SELECT 1 FROM access_grants WHERE tenant_id=? AND admin_id IS NOT NULL', [$tb]), 'group access there removed');
    expect(adminRequest($owner, 'GET', '/tenants/' . Util::id($ta) . '/devices'), 200, 'home company still works');
    // Eliminar un grupo: rechazado mientras tenga cuentas de grupo; sin ellas, sus empresas quedan independientes.
    $g = expect(adminRequest($platform, 'GET', '/company-groups'), 200, 'list groups')['data'];
    $g = array_values(array_filter($g, fn ($x) => $x['id'] === $group['id']))[0];
    $busy = adminRequest($platform, 'DELETE', '/company-groups/' . $g['id'], null, ['if-match' => '"' . $g['version'] . '"']);
    check($busy[0] === 409 && $busy[1]['detail'] === 'group_has_accounts:1', 'group with accounts cannot be deleted');
    $empty = expect(adminRequest($platform, 'POST', '/company-groups', ['name' => 'Grupo vacío', 'tenant_ids' => [Util::id($c['tenant_id'])]]), 201, 'group without accounts');
    check(adminRequest($platform, 'DELETE', '/company-groups/' . $empty['id'], null, ['if-match' => '"0"'])[0] === 412, 'delete needs current version');
    check(adminRequest($platform, 'DELETE', '/company-groups/' . $empty['id'], null, ['if-match' => '"' . $empty['version'] . '"'])[0] === 204, 'delete group');
    check(!$db->one('SELECT 1 FROM company_group_members WHERE tenant_id=?', [$c['tenant_id']]) && $db->one('SELECT 1 FROM tenants WHERE tenant_id=?', [$c['tenant_id']]), 'its company stays, now independent');
    check(adminRequest($owner, 'DELETE', '/company-groups/' . $g['id'], null, ['if-match' => '"' . $g['version'] . '"'])[0] === 403, 'only platform deletes groups');
    echo 'PASS company groups (' . ($GLOBALS['checks'] - $before) . " assertions)\n";
}
