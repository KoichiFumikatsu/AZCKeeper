<?php
declare(strict_types=1);
use Keeper\{AdminAuth,Database,Util};

// Asistente «Dar de alta un cliente» (G2): POST /tenants/provision crea cliente + funciones/plan + primera sede + persona
// + cuenta del panel + acceso (Q4: Corporativo, todo el cliente) en UNA transaccion, con la auditoria de cada paso.
function tenantProvisionTests(Database $db): void
{
    $before = $GLOBALS['checks'];
    accessResetPreloginQuota();
    $platform = adminFixture($db, [], [], true);
    $plat = Util::bin(AdminAuth::PLATFORM);
    $plan = fn (string $name) => Util::id($db->one('SELECT id FROM person_plans WHERE tenant_id=? AND name=? AND active=TRUE', [$plat, $name])['id']);
    $other = Util::id($db->one("SELECT id FROM person_plans WHERE tenant_id=? AND active=TRUE AND name<>'Completo' ORDER BY name LIMIT 1", [$plat])['id']);
    $pkg = expect(adminRequest($platform, 'POST', '/feature-packages', ['name' => 'Alta completa ' . substr(Util::uuid(), 0, 6), 'features' => ['productividad', 'presencia']]), 201, 'package for provisioning');
    $tag = substr(Util::uuid(), 0, 8);
    $body = fn (string $name, string $email, array $admin = []) => ['name' => $name, 'timezone' => 'America/Bogota', 'country_code' => 'CO', 'site_name' => 'Sede Bogotá',
        'package_id' => $pkg['id'], 'features' => ['productividad', 'presencia', 'usb'], 'default_plan_id' => $other,
        'admin' => $admin + ['display_name' => 'Laura Gómez', 'email' => $email, 'document' => '1.020.304', 'role' => 'corporativo', 'scope_kind' => 'all']];
    $tenantsNamed = fn (string $name) => (int) $db->one('SELECT COUNT(*) n FROM tenants WHERE name=?', [$name])['n'];
    $tenantCount = fn () => (int) $db->one('SELECT COUNT(*) n FROM tenants')['n'];

    // Solo plataforma, y con reautenticacion (crea una cuenta con contraseña temporal).
    $u = secondTenant($db);
    $client = adminFixture($db, $u, ['usuarios.ver', 'usuarios.crear', 'usuarios.editar', 'organizacion.editar']);
    $name = "Cliente Alta $tag";
    check(adminRequest($client, 'POST', '/tenants/provision', $body($name, "laura.$tag@example.test"))[0] === 403, 'only platform provisions a client');
    check(adminRequest($client, 'GET', '/roles/templates')[0] === 403, 'only platform lists role templates');
    $noReauth = adminRequest($platform, 'POST', '/tenants/provision', $body($name, "laura.$tag@example.test"));
    check($noReauth[0] === 403 && $noReauth[1]['code'] === 'reauth_required', 'provisioning requires a recent reauthentication');
    check($tenantsNamed($name) === 0, 'nothing created without permission or reauth');
    expect(adminRequest($platform, 'POST', '/auth/reauth', ['password' => $platform['password']]), 200, 'platform reauth');

    $templates = expect(adminRequest($platform, 'GET', '/roles/templates'), 200, 'role templates')['data'];
    check(in_array('corporativo', array_column($templates, 'seed_key'), true) && in_array('Corporativo', array_column($templates, 'name'), true), 'templates include Corporativo');

    // Alta completa.
    $email = "laura.$tag@example.test";
    $made = expect(adminRequest($platform, 'POST', '/tenants/provision', $body($name, $email)), 201, 'provision a client');
    $t = Util::bin($made['tenant']['id']);
    check($made['tenant']['name'] === $name && $made['tenant']['timezone'] === 'America/Bogota' && $made['tenant']['status'] === 'active' && $made['tenant']['branding']['display_name'] === $name, 'client created with its brand');
    check($made['features']['package_id'] === $pkg['id'] && $made['features']['features'] === ['presencia', 'productividad', 'usb'] && $made['features']['default_plan_id'] === $other, 'package, sale adjustments and default plan');
    $site = $db->one('SELECT name,kind,country_code,placeholder FROM org_units WHERE tenant_id=? AND id=?', [$t, Util::bin($made['site_id'])]);
    check($site['kind'] === 'site' && $site['name'] === 'Sede Bogotá' && $site['country_code'] === 'CO' && !$site['placeholder'], 'first site with the client country');
    check($made['user']['display_name'] === 'Laura Gómez' && $made['user']['document'] === '1020304' && $made['user']['site_id'] === $made['site_id'] && $made['user']['status'] === 'active' && $made['user']['panel_login_enabled'], 'first administrator person');
    check($made['account']['kind'] === 'company' && $made['account']['email'] === $email && $made['account']['user_id'] === $made['user']['id'] && $made['account']['must_change_password'] && strlen($made['temporary_password']) === 16, 'panel account with a temporary password');
    check($made['account']['access']['role_name'] === 'Corporativo' && $made['account']['access']['scope']['kind'] === 'all' && $made['account']['access']['source'] === 'manual', 'Q4: Corporativo with scope all');
    $grant = $db->one('SELECT r.seed_key,g.scope_kind FROM access_grants g JOIN roles r ON r.tenant_id=g.tenant_id AND r.id=g.role_id WHERE g.tenant_id=? AND g.user_id=?', [$t, Util::bin($made['user']['id'])]);
    check($grant['seed_key'] === 'corporativo' && $grant['scope_kind'] === 'all', 'stored grant is Corporativo / all');
    check(count($db->run('SELECT id FROM roles WHERE tenant_id=?', [$t])->fetchAll()) === 5, 'client gets its base roles');
    check(panelLoginStatus($email, $made['temporary_password']) === 200, 'the temporary password logs in');
    $actions = array_column($db->run('SELECT action FROM audit_log WHERE tenant_id=? ORDER BY at,action', [$t])->fetchAll(), 'action');
    foreach (['createTenant', 'putTenantFeatures', 'createOrgUnit', 'createUser', 'createPanelAccount'] as $op) { check(in_array($op, $actions, true), "audit of step $op"); }
    $top = $db->one("SELECT resource_type,resource_id,outcome FROM audit_log WHERE tenant_id=? AND action='provisionTenant' AND resource_id=?", [$plat, $t]);
    check(($top['resource_type'] ?? null) === 'tenant' && $top['outcome'] === 'allowed', 'provisionTenant audited in the platform');

    // Idempotencia: el mismo nombre (aunque cambie mayusculas o espacios) es 409 claro y no crea otro cliente.
    $count = $tenantCount();
    $dup = adminRequest($platform, 'POST', '/tenants/provision', $body('  ' . strtoupper($name) . ' ', "otra.$tag@example.test"));
    check($dup[0] === 409 && ($dup[1]['detail'] ?? null) === 'tenant_name_taken', 'duplicate client name: 409 tenant_name_taken');
    check($tenantCount() === $count, 'duplicate creates nothing');

    // Fallo a mitad: el correo ya tiene cuenta; el cliente, su sede y su persona ya se habian creado y se deshacen.
    $failName = "Cliente Falla $tag"; $users = (int) $db->one('SELECT COUNT(*) n FROM users')['n']; $sites = (int) $db->one("SELECT COUNT(*) n FROM org_units WHERE kind='site'")['n'];
    $audits = (int) $db->one('SELECT COUNT(*) n FROM audit_log')['n'];
    $fail = adminRequest($platform, 'POST', '/tenants/provision', $body($failName, strtoupper($email)));
    check($fail[0] === 409 && ($fail[1]['detail'] ?? null) === 'email_in_use', 'mid-way failure reports the cause');
    check($tenantsNamed($failName) === 0 && $tenantCount() === $count, 'mid-way failure leaves no client');
    check((int) $db->one('SELECT COUNT(*) n FROM users')['n'] === $users && (int) $db->one("SELECT COUNT(*) n FROM org_units WHERE kind='site'")['n'] === $sites, 'mid-way failure leaves no person or site');
    check((int) $db->one('SELECT COUNT(*) n FROM audit_log')['n'] === $audits, 'mid-way failure leaves no audit of undone steps');
    $badRole = adminRequest($platform, 'POST', '/tenants/provision', $body($failName, "rol.$tag@example.test", ['role' => 'inventado']));
    check($badRole[0] === 422 && ($badRole[1]['detail'] ?? null) === 'unknown_role' && $tenantsNamed($failName) === 0, 'unknown role: 422 and nothing created');
    $badPlan = adminRequest($platform, 'POST', '/tenants/provision', ['default_plan_id' => Util::uuid()] + $body($failName, "plan.$tag@example.test"));
    check($badPlan[0] === 422 && ($badPlan[1]['detail'] ?? null) === 'unknown_plan' && $tenantsNamed($failName) === 0, 'unknown plan: 422 and nothing created');
    check(adminRequest($platform, 'POST', '/tenants/provision', ['features' => ['inventada']] + $body($failName, "f.$tag@example.test"))[0] === 422 && $tenantsNamed($failName) === 0, 'unknown function: 422 and nothing created');
    check(adminRequest($platform, 'POST', '/tenants/provision', ['timezone' => 'Marte/Olimpo'] + $body($failName, "tz.$tag@example.test"))[0] === 422 && $tenantsNamed($failName) === 0, 'invalid time zone: 422 and nothing created');

    // El rol y el alcance se pueden cambiar; plan Completo (el de createTenant) no repite putTenantFeatures.
    $edited = expect(adminRequest($platform, 'POST', '/tenants/provision', ['default_plan_id' => $plan('Completo')] + $body("Cliente Gerente $tag", "gerente.$tag@example.test", ['role' => 'gerencia', 'scope_kind' => 'self', 'document' => null])), 201, 'provision with another role and scope');
    check($edited['account']['access']['role_name'] === 'Gerente' && $edited['account']['access']['scope']['kind'] === 'self' && $edited['user']['document'] === null, 'role and scope are editable');
    check(!$db->one("SELECT 1 x FROM audit_log WHERE tenant_id=? AND action='putTenantFeatures'", [Util::bin($edited['tenant']['id'])]), 'default plan Completo needs no extra step');

    // Si la plantilla Corporativo no se ofrece a clientes nuevos, el alta la siembra igual para el primer administrador.
    $db->run("UPDATE role_templates SET offered=FALSE WHERE seed_key='corporativo'");
    try {
        $seeded = expect(adminRequest($platform, 'POST', '/tenants/provision', $body("Cliente Sin Plantilla $tag", "semilla.$tag@example.test")), 201, 'provision when Corporativo is not offered');
        $roles = array_column($db->run('SELECT seed_key FROM roles WHERE tenant_id=? ORDER BY seed_key', [Util::bin($seeded['tenant']['id'])])->fetchAll(), 'seed_key');
        check(in_array('corporativo', $roles, true) && count($roles) === 5 && $seeded['account']['access']['role_name'] === 'Corporativo', 'Corporativo seeded on demand');
        check(!in_array('corporativo', array_column(expect(adminRequest($platform, 'GET', '/roles/templates'), 200, 'templates while not offered')['data'], 'seed_key'), true), 'templates list follows offered');
    } finally { $db->run("UPDATE role_templates SET offered=TRUE WHERE seed_key='corporativo'"); }
    echo 'PASS tenant provision (' . ($GLOBALS['checks'] - $before) . " assertions)\n";
}
