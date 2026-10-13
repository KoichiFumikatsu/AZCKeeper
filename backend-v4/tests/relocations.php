<?php
declare(strict_types=1);
use Keeper\{Config, Database, Util};

// Mudanza de servidor firmada (agente 4.0.24): registro con firma de releases, entrega por sync por version/tenant/exclusion
// y modo mudado (409 device_relocated sin guardar nada; excluidos y agentes anteriores siguen normal).
function relocationTests(Database $db): void
{
    $before = $GLOBALS['checks'];
    accessResetPreloginQuota();
    [$signer, $jwk] = keypair(); $file = Config::get('RELEASE_KEYS_FILE');
    $keys = is_file($file) ? json_decode(file_get_contents($file), true) : []; $keys['relocation-test'] = $jwk; file_put_contents($file, Util::json($keys));
    [$rogue] = keypair();
    $sign = static function (array $payload, $key = null, string $kid = 'relocation-test') use ($signer): string {
        $input = Util::b64(Util::json(['alg' => 'ES256', 'kid' => $kid])) . '.' . Util::b64(Util::json($payload));
        openssl_sign($input, $der, $key ?? $signer, OPENSSL_ALGO_SHA256);
        return $input . '.' . Util::b64(rawSignature($der));
    };
    $a = secondTenant($db); $b = secondTenant($db);
    $keyA = keypair(); $tokenA = enroll($db, $a, $keyA); policy($db, $tokenA);
    $keyX = keypair(); $tokenX = enroll($db, $a, $keyX); policy($db, $tokenX);
    $keyB = keypair(); $tokenB = enroll($db, $b, $keyB); policy($db, $tokenB);
    $origin = rtrim(Config::get('ORIGIN'), '/') . '/';
    $payload = ['type' => 'relocation', 'id' => Util::uuid(), 'sequence' => 1, 'from_base' => $origin, 'to_base' => 'https://keep.azclegal.com/',
        'tenants' => [Util::id($a['tenant_id'])], 'exclude_devices' => [$tokenX['device_id']],
        'not_before' => gmdate('Y-m-d\TH:i:s\Z', time() - 60), 'expires_at' => gmdate('Y-m-d\TH:i:s\Z', time() + 86400)];
    $jws = $sign($payload);

    $platform = adminFixture($db, [], [], true);
    $tenantAdmin = adminFixture($db, $a, ['equipos.ver']);
    expect(adminRequest($tenantAdmin, 'GET', '/relocations'), 403, 'tenant admin cannot list relocations');
    expect(adminRequest($tenantAdmin, 'POST', '/relocations', ['relocation_jws' => $jws]), 403, 'tenant admin cannot register relocation');
    expect(adminRequest($platform, 'POST', '/relocations', ['relocation_jws' => $jws]), 403, 'relocation registration requires reauth');
    expect(adminRequest($platform, 'POST', '/auth/reauth', ['password' => $platform['password']]), 200, 'reauth relocation');
    $parts = explode('.', $jws); $forged = $parts[0] . '.' . $parts[1] . '.' . Util::b64(str_repeat("\1", 64));
    $invalid = ['bad signature' => $forged, 'rogue key' => $sign($payload, $rogue), 'unknown kid' => $sign($payload, null, 'nope'),
        'http destination' => $sign(['to_base' => 'http://keep.azclegal.com/'] + $payload), 'foreign destination' => $sign(['to_base' => 'https://keep.example.com/'] + $payload),
        'suffix trick' => $sign(['to_base' => 'https://keepazclegal.com/'] + $payload), 'path destination' => $sign(['to_base' => 'https://keep.azclegal.com/v1/'] + $payload),
        'other origin' => $sign(['from_base' => 'https://other.azclegal.com/'] + $payload), 'expired' => $sign(['not_before' => gmdate('Y-m-d\TH:i:s\Z', time() - 7200), 'expires_at' => gmdate('Y-m-d\TH:i:s\Z', time() - 60)] + $payload),
        'wrong type' => $sign(['type' => 'release'] + $payload), 'extra field' => $sign($payload + ['note' => 'x']), 'no tenants' => $sign(['tenants' => []] + $payload),
        'uppercase id' => $sign(['id' => strtoupper($payload['id'])] + $payload), 'string sequence' => $sign(['sequence' => '1'] + $payload)];
    foreach ($invalid as $label => $candidate) { expect(adminRequest($platform, 'POST', '/relocations', ['relocation_jws' => $candidate]), 422, 'relocation rejected: ' . $label); }
    check((int) $db->one('SELECT COUNT(*) n FROM relocations')['n'] === 0, 'rejected relocations stored nothing');
    $saved = expect(adminRequest($platform, 'POST', '/relocations', ['relocation_jws' => $jws]), 201, 'register signed relocation');
    check($saved['id'] === $payload['id'] && $saved['sequence'] === 1 && $saved['to_base'] === 'https://keep.azclegal.com/' && $saved['relocation_jws'] === $jws
        && $saved['enforced_at'] === null && $saved['exclude_devices'] === [$tokenX['device_id']] && $saved['key_id'] === 'relocation-test', 'relocation DTO from signed payload');
    check((int) $db->one("SELECT COUNT(*) n FROM audit_log WHERE action='relocation.registered'")['n'] === 1, 'relocation registration audited');
    expect(adminRequest($platform, 'POST', '/relocations', ['relocation_jws' => $sign(['id' => Util::uuid()] + $payload)]), 409, 'relocation sequence must grow');
    $list = expect(adminRequest($platform, 'GET', '/relocations'), 200, 'list relocations');
    check(count($list['data']) === 1 && $list['data'][0]['id'] === $payload['id'], 'relocation listed');

    $sync = static function (array $identity, array $session, int $seq, array $extra = [], int $status = 200) {
        return expect(request('POST', '/client/sync', ['protocol_version' => 1, 'sequence' => $seq, 'policy_version' => 1, 'release_id' => null] + $extra,
            $identity, $session['access_token'], Util::uuid()), $status, 'relocation sync ' . $seq);
    };
    $version = static fn (string $v) => $db->run('UPDATE devices SET agent_version=? WHERE tenant_id IN (?,?)', [$v, $a['tenant_id'], $b['tenant_id']]);
    check(!array_key_exists('relocation', $sync($keyA, $tokenA, 1)), 'agent 4.0.0 receives no relocation');
    $version('4.0.23');
    check(!array_key_exists('relocation', $sync($keyA, $tokenA, 2)), 'agent 4.0.23 receives no relocation');
    $version('4.0.24');
    check(($sync($keyA, $tokenA, 3)['relocation'] ?? null) === $jws, 'agent 4.0.24 of included tenant receives relocation');
    check(!array_key_exists('relocation', $sync($keyX, $tokenX, 1)), 'excluded device receives no relocation');
    check(!array_key_exists('relocation', $sync($keyB, $tokenB, 1)), 'other tenant receives no relocation');

    // Modo mudado.
    $path = '/relocations/' . $payload['id'];
    expect(adminRequest($tenantAdmin, 'PATCH', $path, ['enforced' => true]), 403, 'tenant admin cannot enforce relocation');
    $enforced = expect(adminRequest($platform, 'PATCH', $path, ['enforced' => true]), 200, 'enforce relocation');
    check($enforced['enforced_at'] !== null, 'relocation enforced');
    $episodes = static fn (array $t) => (int) $db->one('SELECT COUNT(*) n FROM episodes WHERE tenant_id=? AND device_id=?', [Util::bin($t['tenant_id']), Util::bin($t['device_id'])])['n'];
    $logs = static fn (array $t) => (int) $db->one('SELECT COUNT(*) n FROM client_logs WHERE tenant_id=? AND device_id=?', [Util::bin($t['tenant_id']), Util::bin($t['device_id'])])['n'];
    $db->run('UPDATE device_assignments SET starts_at=UTC_TIMESTAMP(6)-INTERVAL 2 DAY WHERE tenant_id=?', [$a['tenant_id']]);
    $data = static fn () => ['episodes' => [['event_id' => Util::uuid(), 'started_at' => gmdate('Y-m-d\TH:i:s\Z', time() - 120), 'ended_at' => gmdate('Y-m-d\TH:i:s\Z', time() - 60), 'process_name' => 'editor.exe', 'active_seconds' => 50, 'idle_seconds' => 10]],
        'logs' => [['event_id' => Util::uuid(), 'at' => gmdate('Y-m-d\TH:i:s\Z'), 'level' => 'info', 'component' => 'ServerRelocation', 'code' => 'relocation_failed', 'fields' => ['error_code' => 'http_401']]]];
    [$e0, $l0] = [$episodes($tokenA), $logs($tokenA)];
    $problem = $sync($keyA, $tokenA, 4, $data(), 409);
    check($problem['code'] === 'device_relocated' && ($problem['relocation'] ?? null) === $jws, 'relocated sync answers 409 with the signed relocation');
    $problem = expect(request('POST', '/client/logs', ['entries' => $data()['logs']], $keyA, $tokenA['access_token'], Util::uuid()), 409, 'relocated log upload');
    check($problem['relocation'] === $jws, 'relocated log upload carries relocation');
    check($episodes($tokenA) === $e0 && $logs($tokenA) === $l0, 'relocated mode stores no episodes nor logs');
    $challenge = expect(request('POST', '/client/auth/challenges', ['device_id' => $tokenA['device_id']]), 409, 'relocated challenge');
    check($challenge['code'] === 'device_relocated' && $challenge['relocation'] === $jws, 'relocated challenge carries relocation');
    $login = expect(request('POST', '/client/login', ['device_id' => $tokenA['device_id'], 'public_key' => $keyA[1], 'hostname' => 'smoke-device', 'agent_version' => '4.0.24'], $keyA, null, null, 'unused-nonce'), 409, 'relocated login');
    check($login['relocation'] === $jws, 'relocated login carries relocation');
    $ok = $sync($keyX, $tokenX, 2, $data());
    check($ok['log_acks'][0]['status'] === 'accepted' && $logs($tokenX) === 1, 'excluded device keeps syncing and storing');
    check(!array_key_exists('relocation', $sync($keyB, $tokenB, 2)), 'other tenant keeps syncing');
    $db->run('UPDATE devices SET agent_version=\'4.0.23\' WHERE tenant_id=? AND id=?', [$a['tenant_id'], Util::bin($tokenA['device_id'])]);
    $old = $sync($keyA, $tokenA, 5, $data());
    check(!array_key_exists('relocation', $old) && $logs($tokenA) === $l0 + 1, 'agent before 4.0.24 is not blocked (it could not move nor update)');
    $version('4.0.24');
    $released = expect(adminRequest($platform, 'PATCH', $path, ['enforced' => false]), 200, 'release relocation mode');
    check($released['enforced_at'] === null, 'relocation mode off');
    check(($sync($keyA, $tokenA, 6)['relocation'] ?? null) === $jws, 'without relocated mode sync works and still offers relocation');
    check((int) $db->one("SELECT COUNT(*) n FROM audit_log WHERE action IN ('relocation.enforced','relocation.released')")['n'] === 2, 'relocation mode changes audited');
    $db->run('UPDATE admin_sessions SET reauthenticated_at=UTC_TIMESTAMP(6)-INTERVAL 301 SECOND WHERE tenant_id=? AND admin_id=?', [$platform['tenant'], $platform['id']]);
    expect(adminRequest($platform, 'PATCH', $path, ['enforced' => true]), 403, 'expired reauth cannot enforce relocation');
    expect(adminRequest($platform, 'GET', '/relocations'), 200, 'list does not need reauth');
    // No dejar mudanzas activas para el resto de la suite.
    $db->run('UPDATE relocations SET expires_at=not_before+INTERVAL 1 SECOND');
    accessResetPreloginQuota();
    echo 'PASS relocations (' . ($GLOBALS['checks'] - $before) . " assertions)\n";
}
