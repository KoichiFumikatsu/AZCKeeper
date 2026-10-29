<?php
declare(strict_types=1);
use Keeper\{Database,Util};

// Integraciones (API) desde el panel (2026-10-20): crear, listar, rotar y revocar credenciales de la API externa.
function integrationsTests(Database $db): void
{
    $before = $GLOBALS['checks'];
    $u = secondTenant($db); $v = secondTenant($db); $tenant = $u['tenant_id'];
    $platform = adminFixture($db, [], [], true); $platform['tenant'] = $tenant;
    $apiBody = ['name' => 'App Lovable', 'auth_type' => 'api_key', 'scopes' => ['organization:read', 'plans:read'], 'expires_in_days' => 90];

    // CSRF y reautenticacion antes de emitir un secreto.
    expect(adminRequest($platform, 'POST', '/integrations', $apiBody, ['x-csrf-token' => '']), 403, 'create integration needs CSRF');
    check(expect(adminRequest($platform, 'POST', '/integrations', $apiBody), 403, 'create integration needs reauth')['code'] === 'reauth_required', 'reauth_required code');
    expect(adminRequest($platform, 'POST', '/auth/reauth', ['password' => $platform['password']]), 200, 'platform reauth for integrations');

    // Validacion: caducidad por tipo, scopes, dependencias, nombre y funciones contratadas.
    check((expect(adminRequest($platform, 'POST', '/integrations', ['expires_in_days' => 180] + $apiBody), 422, 'API key over 90 days')['detail'] ?? null) === 'api_key_max_90_days', 'API key max 90 days');
    expect(adminRequest($platform, 'POST', '/integrations', ['scopes' => ['tiers:read']] + $apiBody), 422, 'unknown scope rejected');
    expect(adminRequest($platform, 'POST', '/integrations', ['scopes' => []] + $apiBody), 422, 'empty scopes rejected');
    check((expect(adminRequest($platform, 'POST', '/integrations', ['scopes' => ['activity-titles:read']] + $apiBody), 422, 'titles without activity')['detail'] ?? null) === 'scope_requires:activity:read', 'scope dependency enforced');
    expect(adminRequest($platform, 'POST', '/integrations', ['name' => str_repeat('x', 101)] + $apiBody), 422, 'name over 100 chars');
    expect(adminRequest($platform, 'POST', '/integrations', ['name' => '   '] + $apiBody), 422, 'blank name');
    expect(adminRequest($platform, 'POST', '/integrations', ['secret' => 'mine'] + $apiBody), 422, 'client cannot choose the secret');

    // Crear API key: secreto una vez, solo hash en la base.
    $created = adminRequest($platform, 'POST', '/integrations', $apiBody);
    $key = expect($created, 201, 'create API key');
    check(($created[2]['cache-control'] ?? '') === 'no-store', 'secret response is no-store');
    check(strlen($key['secret']) === 43 && $key['api_key'] === $key['credential_id'] . '.' . $key['secret'] && $key['token_url'] === null, 'API key secret and full header value');
    check($key['integration']['auth_type'] === 'api_key' && $key['integration']['scopes'] === ['organization:read', 'plans:read'] && $key['integration']['status'] === 'active' && $key['integration']['created_by'] !== null, 'integration DTO');
    $iid = Util::bin($key['integration']['id']);
    $row = $db->one('SELECT * FROM api_keys WHERE tenant_id=? AND integration_id=?', [$tenant, $iid]);
    check($row['secret_hash'] === hash('sha256', $key['secret'], true) && $row['public_prefix'] === $key['credential_id'], 'only sha256 of the secret stored');
    $days = $db->one('SELECT TIMESTAMPDIFF(DAY,created_at,expires_at) d FROM integrations WHERE tenant_id=? AND id=?', [$tenant, $iid])['d'];
    check($days === 90, 'integration expires in the chosen days');
    check($db->one("SELECT COUNT(*) n FROM principals WHERE tenant_id=? AND integration_id=? AND kind='integration'", [$tenant, $iid])['n'] === 1, 'integration principal created');
    check((expect(adminRequest($platform, 'POST', '/integrations', $apiBody), 409, 'duplicate active name')['detail'] ?? null) === 'name_in_use', 'name unique among active credentials');

    // La lista nunca trae el secreto.
    $listed = adminRequest($platform, 'GET', '/integrations'); $list = expect($listed, 200, 'list integrations');
    check(!str_contains($listed[3], $key['secret']) && !str_contains($listed[3], 'secret_hash') && !str_contains($listed[3], '"secret"'), 'list never returns secrets');
    check(count($list['data']) === 1 && $list['data'][0]['credential_id'] === $key['credential_id'] && $list['data'][0]['last_used_at'] === null, 'list shows public id and no use yet');
    check(in_array('organization:read', $list['available_scopes'], true) && in_array('check-ins:write', $list['available_scopes'], true) && $list['expiry_days']['api_key'] === [30, 90], 'available scopes and expiry options');

    // La key funciona contra la API externa, con sus scopes exactos.
    $headers = ['X-API-Key' => $key['api_key']];
    expect(externalRequest('/ext/v1/organization', $headers), 200, 'created API key works');
    check(expect(externalRequest('/ext/v1/dashboard?from=2026-09-10&to=2026-09-11', $headers), 403, 'scope not granted')['code'] === 'insufficient_scope', 'scopes enforced');
    check(expect(adminRequest($platform, 'GET', '/integrations'), 200, 'list after use')['data'][0]['last_used_at'] !== null, 'last use tracked');

    // Cliente OAuth: token, uso, revocacion inmediata (tambien del token ya emitido).
    $oauth = expect(adminRequest($platform, 'POST', '/integrations', ['name' => 'Portal cliente', 'auth_type' => 'oauth2', 'scopes' => ['plans:read'], 'expires_in_days' => 365]), 201, 'create OAuth client');
    check($oauth['api_key'] === null && str_ends_with($oauth['token_url'], '/v1/oauth/token') && strlen($oauth['secret']) === 43, 'OAuth secret and token URL');
    $basic = ['Authorization' => 'Basic ' . base64_encode(urlencode($oauth['credential_id']) . ':' . urlencode($oauth['secret'])), 'Content-Type' => 'application/x-www-form-urlencoded'];
    $token = expect(externalRequest('/v1/oauth/token', $basic, 'POST', 'grant_type=client_credentials'), 200, 'created OAuth client issues token');
    $bearer = ['Authorization' => 'Bearer ' . $token['access_token']];
    expect(externalRequest('/ext/v1/plans', $bearer), 200, 'OAuth token works');
    expect(externalRequest('/ext/v1/organization', $bearer), 403, 'OAuth scope enforced');

    // Rotar la API key: la anterior vale hasta 24 h; una segunda rotacion revoca la que ya estaba en solapamiento.
    $rot = adminRequest($platform, 'POST', '/integrations/' . $key['integration']['id'] . '/rotate', ['expires_in_days' => 30]);
    $rotated = expect($rot, 200, 'rotate API key'); check(($rot[2]['cache-control'] ?? '') === 'no-store', 'rotate response is no-store');
    check($rotated['secret'] !== $key['secret'] && $rotated['credential_id'] !== $key['credential_id'] && $rotated['previous_valid_until'] !== null, 'rotation returns a new secret once');
    $overlap = $db->one('SELECT TIMESTAMPDIFF(MINUTE,UTC_TIMESTAMP(6),expires_at) m FROM api_keys WHERE tenant_id=? AND public_prefix=?', [$tenant, $key['credential_id']])['m'];
    check($overlap <= 24 * 60 && $overlap > 23 * 60, 'old key overlap at most 24 hours');
    expect(externalRequest('/ext/v1/organization', $headers), 200, 'old key valid during overlap');
    expect(externalRequest('/ext/v1/organization', ['X-API-Key' => $rotated['api_key']]), 200, 'new key works');
    $again = expect(adminRequest($platform, 'POST', '/integrations/' . $key['integration']['id'] . '/rotate'), 200, 'second rotation without body');
    expect(externalRequest('/ext/v1/organization', $headers), 401, 'key already in overlap revoked by next rotation');
    expect(externalRequest('/ext/v1/organization', ['X-API-Key' => $rotated['api_key']]), 200, 'previous key keeps its overlap');
    expect(externalRequest('/ext/v1/organization', ['X-API-Key' => $again['api_key']]), 200, 'latest key works');
    $oauthRot = expect(adminRequest($platform, 'POST', '/integrations/' . $oauth['integration']['id'] . '/rotate', ['expires_in_days' => 365]), 200, 'rotate OAuth client');
    check($oauthRot['credential_id'] !== $oauth['credential_id'] && $oauthRot['token_url'] !== null, 'OAuth rotation issues a new client_id');
    expect(externalRequest('/ext/v1/plans', $bearer), 200, 'token of the previous client valid during overlap');

    // Otra empresa no ve ni revoca la integracion (404), aunque sea la plataforma con otro selector.
    $foreign = $platform; $foreign['tenant'] = $v['tenant_id'];
    check(expect(adminRequest($foreign, 'GET', '/integrations'), 200, 'other tenant list')['data'] === [], 'integrations are per tenant');
    expect(adminRequest($foreign, 'DELETE', '/integrations/' . $oauth['integration']['id']), 404, 'foreign tenant cannot revoke');

    // Revocar: CSRF, efecto inmediato en key y tokens OAuth, idempotente y auditado una vez.
    expect(adminRequest($platform, 'DELETE', '/integrations/' . $oauth['integration']['id'], null, ['x-csrf-token' => '']), 403, 'revoke needs CSRF');
    expect(adminRequest($platform, 'DELETE', '/integrations/' . $oauth['integration']['id']), 204, 'revoke OAuth integration');
    expect(externalRequest('/ext/v1/plans', $bearer), 401, 'OAuth token issued before revocation fails');
    expect(externalRequest('/v1/oauth/token', ['Authorization' => 'Basic ' . base64_encode($oauthRot['credential_id'] . ':' . $oauthRot['secret']), 'Content-Type' => 'application/x-www-form-urlencoded'], 'POST', 'grant_type=client_credentials'), 401, 'revoked OAuth client cannot get tokens');
    $o = $db->one('SELECT active,auth_version,revoked_at FROM integrations WHERE tenant_id=? AND id=?', [$tenant, Util::bin($oauth['integration']['id'])]);
    check(!$o['active'] && $o['auth_version'] === 2 && $o['revoked_at'] !== null, 'revocation disables and bumps auth_version');
    check($db->one('SELECT COUNT(*) n FROM oauth_access_tokens WHERE tenant_id=? AND integration_id=? AND revoked_at IS NULL', [$tenant, Util::bin($oauth['integration']['id'])])['n'] === 0, 'issued tokens revoked');
    expect(adminRequest($platform, 'DELETE', '/integrations/' . $key['integration']['id']), 204, 'revoke API key integration');
    expect(externalRequest('/ext/v1/organization', ['X-API-Key' => $again['api_key']]), 401, 'revoked API key fails immediately');
    expect(externalRequest('/ext/v1/organization', ['X-API-Key' => $rotated['api_key']]), 401, 'overlapping key revoked too');
    expect(adminRequest($platform, 'DELETE', '/integrations/' . $key['integration']['id']), 204, 'revoke twice is a no-op');
    expect(adminRequest($platform, 'POST', '/integrations/' . $key['integration']['id'] . '/rotate'), 409, 'revoked integration cannot rotate');
    $states = array_column(expect(adminRequest($platform, 'GET', '/integrations'), 200, 'list after revocation')['data'], 'status', 'name');
    check($states === ['Portal cliente' => 'revoked', 'App Lovable' => 'revoked'], 'list shows revoked status');
    expect(adminRequest($platform, 'POST', '/integrations', $apiBody), 201, 'name reusable after revocation');

    // Auditoria: crear, rotar y revocar, sin secretos.
    $audit = $db->run("SELECT action,changed_fields FROM audit_log WHERE tenant_id=? AND resource_type='integration' AND resource_id=? ORDER BY at", [$tenant, $iid])->fetchAll();
    check(array_column($audit, 'action') === ['createIntegration', 'rotateIntegration', 'rotateIntegration', 'revokeIntegration'], 'audit create, rotations and one revocation');
    check(!str_contains(json_encode($audit), $key['secret']) && !str_contains(json_encode($audit), $rotated['secret']), 'audit has no secret');
    check(in_array('scope:organization:read', json_decode($audit[0]['changed_fields'], true), true), 'audit records granted scopes');

    // Funcion no contratada: sin "api" no hay scopes de lectura; con "puertas" queda check-ins:write.
    $db->run("DELETE FROM tenant_features WHERE tenant_id=? AND feature_code='api'", [$v['tenant_id']]);
    expect(adminRequest($foreign, 'POST', '/auth/reauth', ['password' => $platform['password']]), 200, 'reauth in other tenant');
    check((expect(adminRequest($foreign, 'POST', '/integrations', $apiBody), 422, 'scope of an uncontracted function')['detail'] ?? null) === 'feature_not_contracted:api', 'feature gate on create');
    check(expect(adminRequest($foreign, 'GET', '/integrations'), 200, 'list without api')['available_scopes'] === ['check-ins:write'], 'available scopes follow functions');
    expect(adminRequest($foreign, 'POST', '/integrations', ['name' => 'Puertas', 'scopes' => ['check-ins:write']] + $apiBody), 201, 'doors scope with puertas only');

    // Sin plataforma: 403 aunque el rol tenga el permiso (platform_only); sin el permiso tambien.
    $client = adminFixture($db, adminMember($db, $u), ['usuarios.ver']);
    expect(adminRequest($client, 'GET', '/integrations'), 403, 'client admin without permission cannot list');
    // Un rol de cliente ni siquiera puede tener el permiso (trigger de 0007 sobre permisos platform_only).
    try { $db->run("INSERT INTO role_permissions (tenant_id,role_id,permission_code) VALUES (?,?,'integraciones.gestionar')", [$tenant, $client['role']]); check(false, 'platform permission in a client role must fail'); }
    catch (PDOException $e) { check(($e->errorInfo[1] ?? 0) === 1644, 'client role cannot hold integraciones.gestionar'); }
    expect(adminRequest($client, 'POST', '/auth/reauth', ['password' => $client['password']]), 200, 'client reauth');
    expect(adminRequest($client, 'POST', '/integrations', ['name' => 'Cliente'] + $apiBody), 403, 'client admin cannot create');
    echo 'PASS integrations (' . ($GLOBALS['checks'] - $before) . " assertions)\n";
}
