<?php
declare(strict_types=1);
use Keeper\{Database, Util, AccessGrants, AccessScope};

// Editar/crear un rol (RBAC del panel) NO debe invalidar las sesiones de equipo del cliente: solo se re-autentica a las
// personas realmente afectadas por el rol. Regresion de produccion 2026-10-20: un bump de tenants.auth_version en la ruta
// de rol (y dentro de invalidateUser) forzaba el re-login simultaneo de ~100 equipos por editar un solo rol.
function roleCutoffTests(Database $db): void
{
    $before = $GLOBALS['checks'];
    $platform = sharedPlatform($db);

    // Dos personas del mismo cliente, cada una con su equipo enrolado y sesion valida.
    $member = secondTenant($db); $tenant = $member['tenant_id']; $platform['tenant'] = $tenant;
    $holder = adminMember($db, $member); // segunda persona, mismo cliente

    $enrollDevice = function (array $user) use ($db): array {
        $key = keypair();
        $token = enroll($db, $user, $key); policy($db, $token);
        // El equipo ya puede sincronizar (la asignacion empezo en el pasado), como en smoke.
        $db->run('UPDATE device_assignments SET starts_at=UTC_TIMESTAMP(6)-INTERVAL 2 DAY WHERE tenant_id=? AND device_id=?', [Util::bin($token['tenant_id']), Util::bin($token['device_id'])]);
        return [$key, $token];
    };
    // Una peticion autenticada del equipo: devuelve el status (200 sesion viva, 401 invalidada).
    $seq = 0;
    $ping = function (array $key, string $bearer) use (&$seq): int {
        $seq++;
        return request('POST', '/client/sync', ['protocol_version' => 1, 'sequence' => $seq, 'policy_version' => null, 'release_id' => null], $key, $bearer, Util::uuid())[0];
    };

    [$memberKey, $memberTok] = $enrollDevice($member);
    check($ping($memberKey, $memberTok['access_token']) === 200, 'equipo de la persona sin el rol: sesion valida de entrada');

    // (b) Editar un rol que esta persona/equipo NO tiene (recien creado, sin concesion a nadie todavia).
    $role = expect(adminRequest($platform, 'POST', '/roles', ['name' => 'Rol ajeno al equipo', 'permissions' => ['usuarios.ver'], 'active' => true]), 201, 'crear rol ajeno');
    $tenantAuth = fn () => (int) $db->one('SELECT auth_version FROM tenants WHERE tenant_id=?', [$tenant])['auth_version'];
    $authBefore = $tenantAuth();
    expect(adminRequest($platform, 'PATCH', '/roles/' . $role['id'], ['name' => 'Rol ajeno editado', 'permissions' => ['usuarios.ver', 'equipos.ver'], 'active' => true], ['if-match' => '"' . $role['version'] . '"']), 200, 'editar rol ajeno');

    // (c) La sesion del equipo sigue viva, y el contador del cliente no se movio (ningun equipo re-login).
    check($ping($memberKey, $memberTok['access_token']) === 200, 'editar un rol que el equipo no consume NO invalida su sesion');
    check($tenantAuth() === $authBefore, 'editar un rol no sube tenants.auth_version');

    // (d) Una persona que SI tiene el rol afectado si ve invalidada su sesion; la otra persona sigue viva en la misma edicion.
    AccessGrants::setForUser($db, $tenant, $holder['id'], Util::bin($role['id']), AccessScope::own(), 'manual');
    [$holderKey, $holderTok] = $enrollDevice($holder);
    check($ping($holderKey, $holderTok['access_token']) === 200, 'equipo de la persona con el rol: sesion valida de entrada');

    $edited = expect(adminRequest($platform, 'GET', '/roles/' . $role['id']), 200, 'releer rol');
    $authBefore = $tenantAuth();
    expect(adminRequest($platform, 'PATCH', '/roles/' . $role['id'], ['name' => 'Rol ajeno editado 2', 'permissions' => ['usuarios.ver'], 'active' => true], ['if-match' => '"' . $edited['version'] . '"']), 200, 'editar el rol que ahora tiene el holder');

    check($ping($holderKey, $holderTok['access_token']) === 401, 'la persona que tiene el rol si ve invalidada su sesion');
    check($ping($memberKey, $memberTok['access_token']) === 200, 'la persona sin el rol sigue viva aunque otra sea invalidada');
    check($tenantAuth() === $authBefore, 'invalidar a una persona por un rol no sube tenants.auth_version');

    echo 'PASS role cutoff (' . ($GLOBALS['checks'] - $before) . " assertions)\n";
}
