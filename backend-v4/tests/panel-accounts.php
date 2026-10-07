<?php
declare(strict_types=1);
use Keeper\{AccessGrants,AccessScope,Database,Util};

// Cuentas del panel: superadmin y cuentas de empresa con contraseña temporal obligatoria de cambiar.
function panelLogin(string $email, string $password): array
{
    $a = adminLogin(['email' => $email, 'password' => $password, 'tenant' => null]);
    return $a;
}
function panelLoginStatus(string $email, string $password): int
{
    $pre = request('GET', '/auth/csrf'); $csrf = $pre[1]['csrf_token']; $cookie = explode(';', $pre[2]['set-cookie'])[0];
    return request('POST', '/auth/login', ['email' => $email, 'password' => $password], null, null, null, null, ['cookie' => $cookie, 'x-csrf-token' => $csrf, 'origin' => Keeper\Config::get('ORIGIN')])[0];
}
function panelAccountsTests(Database $db): void
{
    $before = $GLOBALS['checks'];
    $u = secondTenant($db); $other = secondTenant($db);
    $tenant = $u['tenant_id']; $tid = Util::id($tenant);
    $platform = adminFixture($db, [], [], true); $platform['tenant'] = $tenant;
    // Rol de empresa con un solo permiso (ver equipos) para comprobar que la cuenta ve solo eso.
    $role = Util::bin(Util::uuid());
    $db->run("INSERT INTO roles (tenant_id,id,name) VALUES (?,?,'Solo equipos')", [$tenant, $role]);
    $db->run("INSERT INTO role_permissions (tenant_id,role_id,permission_code) VALUES (?,?,'equipos.ver')", [$tenant, $role]);
    $email = 'persona.' . substr(Util::uuid(), 0, 8) . '@example.test';
    $body = ['kind' => 'company', 'email' => $email, 'display_name' => null, 'user_id' => Util::id($u['id']), 'access' => ['role_id' => Util::id($role), 'scope' => accessScopeBody('all')]];

    expect(adminRequest($platform, 'POST', '/panel-accounts', $body), 403, 'create account needs reauth');
    expect(adminRequest($platform, 'POST', '/auth/reauth', ['password' => $platform['password']]), 200, 'platform reauth');
    $created = expect(adminRequest($platform, 'POST', '/panel-accounts', $body), 201, 'create company account');
    check($created['account']['kind'] === 'company' && $created['account']['must_change_password'] && strlen($created['temporary_password']) === 16, 'company account with temporary password');
    check($created['account']['access']['role_id'] === Util::id($role) && $created['account']['access']['scope']['kind'] === 'all' && $created['account']['user_name'] === $u['display_name'], 'account linked to person and access');
    check(!str_contains(json_encode($db->one('SELECT password_hash FROM admin_accounts WHERE email=?', [$email])), $created['temporary_password']), 'only the hash is stored');
    $dup = adminRequest($platform, 'POST', '/panel-accounts', $body);
    check($dup[0] === 409 && ($dup[1]['detail'] ?? null) === 'email_in_use', 'email unique across the system');
    $again = adminRequest($platform, 'POST', '/panel-accounts', ['email' => 'otra.' . $email] + $body);
    check($again[0] === 409 && ($again[1]['detail'] ?? null) === 'person_has_account', 'one account per person');

    // Primer ingreso: solo puede cambiar la contraseña.
    $person = panelLogin($email, $created['temporary_password']); $person['tenant'] = $tenant;
    check(expect(adminRequest($person, 'GET', '/tenants/' . $tid . '/devices'), 403, 'temporary password blocks panel')['code'] === 'password_change_required', 'password change required code');
    expect(adminRequest($person, 'POST', '/auth/password', ['current_password' => 'incorrecta-123456', 'new_password' => 'Nueva-clave-segura-1']), 401, 'wrong current password');
    check(expect(adminRequest($person, 'POST', '/auth/password', ['current_password' => $created['temporary_password'], 'new_password' => $created['temporary_password']]), 422, 'reuse temporary')['code'] === 'weak_password', 'new password must differ');
    $changed = expect(adminRequest($person, 'POST', '/auth/password', ['current_password' => $created['temporary_password'], 'new_password' => 'Nueva-clave-segura-1']), 200, 'change password');
    check($changed['must_change_password'] === false, 'flag cleared');
    $person['csrf'] = $changed['csrf_token'];

    // Ve su empresa segun su rol, nunca otra.
    expect(adminRequest($person, 'GET', '/tenants/' . $tid . '/devices'), 200, 'role permission works');
    expect(adminRequest($person, 'GET', '/users'), 403, 'permission not granted');
    expect(adminRequest($person, 'GET', '/tenants/' . Util::id($other['tenant_id']) . '/devices', null, ['x-tenant-id' => Util::id($other['tenant_id'])]), 404, 'other company invisible');
    expect(adminRequest($person, 'GET', '/panel-accounts'), 403, 'company account without usuarios.editar cannot list accounts');

    // Superadmin nuevo (plataforma): lo crea solo la plataforma.
    $superEmail = 'super.' . substr(Util::uuid(), 0, 8) . '@example.test';
    $super = expect(adminRequest($platform, 'POST', '/panel-accounts', ['kind' => 'platform', 'email' => $superEmail, 'display_name' => 'Superadmin de prueba', 'user_id' => null]), 201, 'create platform account');
    check($super['account']['kind'] === 'platform' && $super['account']['user_id'] === null, 'platform account without person');
    $list = expect(adminRequest($platform, 'GET', '/panel-accounts'), 200, 'list accounts');
    $emails = array_column($list['data'], 'email');
    check(in_array($email, $emails, true) && in_array($superEmail, $emails, true), 'platform sees company and platform accounts');
    $row = array_values(array_filter($list['data'], fn ($x) => $x['email'] === $email))[0];
    check($row['tenant_id'] === $tid && $row['tenant_name'] !== '', 'account carries its company');

    // Logo de la empresa: solo imagen real y del tipo declarado; queda en su marca.
    $png = 'iVBORw0KGgoAAAANSUhEUgAAABAAAAAQCAIAAACQkWg2AAAAFklEQVR4nGOwcWgiCTGMahjVMHw1AADDzP4BEW/mBgAAAABJRU5ErkJggg==';
    $t = expect(adminRequest($platform, 'GET', '/tenants/' . $tid), 200, 'read tenant');
    check(adminRequest($platform, 'POST', '/tenants/' . $tid . '/logo', ['content_type' => 'image/jpeg', 'data' => $png], ['if-match' => '"' . $t['version'] . '"'])[0] === 422, 'logo type must match content');
    check(adminRequest($platform, 'POST', '/tenants/' . $tid . '/logo', ['content_type' => 'image/png', 'data' => base64_encode('<svg onload=alert(1)></svg>')], ['if-match' => '"' . $t['version'] . '"'])[0] === 422, 'non-image rejected');
    $logo = expect(adminRequest($platform, 'POST', '/tenants/' . $tid . '/logo', ['content_type' => 'image/png', 'data' => $png], ['if-match' => '"' . $t['version'] . '"']), 200, 'upload logo');
    $file = dirname(__DIR__) . '/public' . parse_url($logo['branding']['logo_url'], PHP_URL_PATH);
    check(str_contains($logo['branding']['logo_url'], '/uploads/brand/') && is_file($file) && $logo['version'] > $t['version'], 'logo stored and linked');
    @unlink($file);

    // Desactivar corta la sesion; restablecer da una temporal nueva.
    $acct = $created['account']['id'];
    expect(adminRequest($platform, 'PATCH', '/panel-accounts/' . $acct, ['active' => false]), 200, 'disable account');
    expect(adminRequest($person, 'GET', '/tenants/' . $tid . '/devices'), 401, 'disabled account session revoked');
    expect(adminRequest($platform, 'PATCH', '/panel-accounts/' . $acct, ['active' => true]), 200, 'enable account keeps its access');
    $a2 = expect(adminRequest($platform, 'GET', '/panel-accounts'), 200, 'list again')['data'];
    check(array_values(array_filter($a2, fn ($x) => $x['id'] === $acct))[0]['access']['role_id'] === Util::id($role), 'access survives enable/disable');
    check(adminRequest($platform, 'POST', '/panel-accounts', ['kind' => 'platform', 'email' => 'x.' . $superEmail, 'display_name' => 'X', 'user_id' => null, 'access' => ['role_id' => Util::id($role), 'scope' => accessScopeBody('all')]])[0] === 422, 'platform accounts have no access');
    $reset = expect(adminRequest($platform, 'POST', '/panel-accounts/' . $acct . '/reset-password'), 200, 'reset password');
    check($reset['account']['must_change_password'] && $reset['temporary_password'] !== $created['temporary_password'], 'new temporary password');
    check(panelLoginStatus($email, 'Nueva-clave-segura-1') === 401 && panelLoginStatus($email, $reset['temporary_password']) === 200, 'only the new temporary password logs in');
    // Personas con cedula: normalizada, unica por empresa y visible en la ficha.
    $src = $db->one('SELECT firm_id,site_id,area_id,position_id,schedule_id FROM users WHERE tenant_id=? AND id=?', [$tenant, $u['id']]);
    $new = ['display_name' => 'Persona con cédula', 'email' => null, 'document' => '1.234.567-8'] + array_map(fn ($v) => Util::id($v), $src);
    $made = expect(adminRequest($platform, 'POST', '/users', $new), 201, 'create person with document');
    check($made['document'] === '12345678', 'document normalized');
    $dupDoc = adminRequest($platform, 'POST', '/users', ['display_name' => 'Otra'] + $new);
    check($dupDoc[0] === 409 && ($dupDoc[1]['detail'] ?? null) === 'document_in_use', 'document unique in company');
    $cleared = expect(adminRequest($platform, 'PATCH', '/users/' . $made['id'], ['document' => null], ['if-match' => '"' . $made['version'] . '"']), 200, 'clear document');
    check($cleared['document'] === null && $cleared['version'] > $made['version'], 'document cleared');
    // No puede desactivarse a si misma la cuenta de plataforma.
    $selfId = Util::id($platform['id']);
    check(adminRequest($platform, 'PATCH', '/panel-accounts/' . $selfId, ['active' => false])[1]['detail'] === 'cannot_disable_self', 'cannot disable self');

    // Delegacion (§4): quien no es plataforma no toma una cuenta con mas acceso que el suyo, ni una cuenta de grupo.
    accessResetPreloginQuota();
    $editor = adminFixture($db, adminMember($db, $u), ['usuarios.ver', 'usuarios.editar']); $editor['tenant'] = $tenant;
    expect(adminRequest($editor, 'POST', '/auth/reauth', ['password' => $editor['password']]), 200, 'editor reauth');
    $exceeds = fn (array $r) => $r[0] === 403 && ($r[1]['detail'] ?? null) === 'delegation_exceeds_own_access' && !isset($r[1]['temporary_password']);
    $mkRole = function (string $name, array $permissions) use ($db, $tenant): string {
        $id = Util::bin(Util::uuid());
        $db->run("INSERT INTO roles (tenant_id,id,name) VALUES (?,?,?)", [$tenant, $id, $name]);
        foreach ($permissions as $p) { $db->run('INSERT INTO role_permissions (tenant_id,role_id,permission_code) VALUES (?,?,?)', [$tenant, $id, $p]); }
        return $id;
    };
    $strong = $mkRole('Gestor de accesos', ['usuarios.ver', 'usuarios.editar', 'roles.gestionar']); $weak = $mkRole('Solo personas', ['usuarios.ver']);
    $big = adminMember($db, $u); AccessGrants::setForUser($db, $tenant, $big['id'], $strong, AccessScope::own(), 'manual');
    $small = adminMember($db, $u); AccessGrants::setForUser($db, $tenant, $small['id'], $weak, AccessScope::own(), 'default');
    $acctBody = fn (array $who) => ['kind' => 'company', 'email' => 'deleg.' . substr(Util::uuid(), 0, 8) . '@example.test', 'display_name' => null, 'user_id' => Util::id($who['id'])];
    $bigBody = $acctBody($big);
    check($exceeds(adminRequest($editor, 'POST', '/panel-accounts', $bigBody)), 'no account for a person with more access than the caller');
    check(!$db->one('SELECT id FROM admin_accounts WHERE email=?', [$bigBody['email']]), 'refused account not created');
    $smallAcct = expect(adminRequest($editor, 'POST', '/panel-accounts', $acctBody($small)), 201, 'account for a person with less access');
    check(strlen($smallAcct['temporary_password']) === 16 && $smallAcct['account']['access']['role_id'] === Util::id($weak), 'existing smaller access kept');
    $bigAcct = expect(adminRequest($platform, 'POST', '/panel-accounts', $bigBody), 201, 'platform creates the larger account');
    $hash = fn (string $id) => $db->one('SELECT password_hash FROM admin_accounts WHERE id=?', [Util::bin($id)])['password_hash'];
    $bigHash = $hash($bigAcct['account']['id']);
    check($exceeds(adminRequest($editor, 'POST', '/panel-accounts/' . $bigAcct['account']['id'] . '/reset-password')), 'no reset of an account with roles.gestionar');
    check($hash($bigAcct['account']['id']) === $bigHash, 'refused reset keeps the password');
    check(strlen(expect(adminRequest($editor, 'POST', '/panel-accounts/' . $smallAcct['account']['id'] . '/reset-password'), 200, 'reset smaller account')['temporary_password']) === 16, 'smaller account reset');
    // Cuenta de grupo: tiene accesos en otras empresas; solo la plataforma la restablece o la edita.
    $group = Util::bin(Util::uuid());
    $db->run('INSERT INTO company_groups (id,name) VALUES (?,?)', [$group, 'Grupo ' . substr(Util::uuid(), 0, 8)]);
    $db->run('UPDATE admin_accounts SET group_id=?,group_tenant_id=? WHERE id=?', [$group, Util::bin(Keeper\AdminAuth::PLATFORM), Util::bin($smallAcct['account']['id'])]);
    $smallHash = $hash($smallAcct['account']['id']);
    check($exceeds(adminRequest($editor, 'POST', '/panel-accounts/' . $smallAcct['account']['id'] . '/reset-password')), 'no reset of a group account');
    check($hash($smallAcct['account']['id']) === $smallHash, 'group account password kept');
    check($exceeds(adminRequest($editor, 'PATCH', '/panel-accounts/' . $smallAcct['account']['id'], ['active' => false])), 'no edit of a group account');
    expect(adminRequest($platform, 'POST', '/panel-accounts/' . $smallAcct['account']['id'] . '/reset-password'), 200, 'platform resets a group account');
    echo 'PASS panel accounts (' . ($GLOBALS['checks'] - $before) . " assertions)\n";
}
