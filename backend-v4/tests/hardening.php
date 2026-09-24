<?php
declare(strict_types=1);
use Keeper\{Database, Util, Config, Validator};

function hardeningTests(Database $db): void
{
    $before = $GLOBALS['checks'];
    $a = secondTenant($db); $b = secondTenant($db);
    $tenant = $a['tenant_id']; $tid = Util::id($tenant); $bid = Util::id($b['tenant_id']);
    $admin = adminFixture($db, $a, ['hardening.gestionar']);
    $other = adminFixture($db, $b, ['hardening.gestionar']);
    $reader = adminFixture($db, adminMember($db, $a), ['equipos.ver']);
    $scoped = adminFixture($db, adminMember($db, $a), ['hardening.gestionar'], false, 'self');
    $key = keypair(); $token = enroll($db, $a, $key);
    $otherKey = keypair(); $otherToken = enroll($db, $b, $otherKey);
    $device = Util::bin($token['device_id']);
    $path = '/admin/tenants/' . $tid . '/hardening';
    $devicePath = '/admin/devices/' . $token['device_id'] . '/hardening';
    $foreignPath = '/admin/devices/' . $otherToken['device_id'] . '/hardening';
    $password = 'Hardening-A-' . Util::b64(random_bytes(24));
    $otherPassword = 'Hardening-B-' . Util::b64(random_bytes(24));
    $input = ['admin_name' => 'azcadmin', 'hardening_mode' => 'panel', 'deny_network_logon' => true, 'shared_password' => $password];
    $default = expect(adminRequest($admin, 'GET', $path), 200, 'hardening defaults');
    check($default['shared_password'] === null && $default['admin_name'] === 'azcadmin' && $default['hardening_mode'] === 'panel' && $default['deny_network_logon'], 'safe unconfigured defaults');
    expect(request('GET', '/client/hardening', null, $key, $token['access_token']), 409, 'unconfigured agent fails closed');
    expect(adminRequest($admin, 'POST', $devicePath . '/command', ['action' => 'harden'], ['idempotency-key' => Util::uuid()]), 409, 'cannot harden without recovery password');
    $unconfigured = $input; unset($unconfigured['shared_password']);
    expect(adminRequest($admin, 'PUT', $path, $unconfigured), 409, 'initial settings require recovery password');
    expect(adminRequest($admin, 'PUT', $path, $input, ['x-csrf-token' => '']), 403, 'set requires CSRF');
    $set = adminRequest($admin, 'PUT', $path, $input);
    check(expect($set, 200, 'set hardening')['shared_password'] === '********' && !str_contains($set[3], $password), 'write response masks password');
    $row = $db->one('SELECT * FROM tenant_hardening_settings WHERE tenant_id=?', [$tenant]);
    check(!str_contains($row['shared_password_enc'], $password) && $row['shared_password_enc'][0] === "\x01", 'password stored as versioned ciphertext');
    check($row['updated_by'] !== null, 'settings record actor');
    $read = adminRequest($admin, 'GET', $path);
    check(expect($read, 200, 'read hardening')['shared_password'] === '********' && !str_contains($read[3], $password) && !str_contains($read[3], 'shared_password_enc'), 'GET never exposes plaintext or ciphertext');
    check($read[2]['cache-control'] === 'no-store', 'settings not cached');
    expect(adminRequest($admin, 'POST', $path . '/reveal', null, ['x-csrf-token' => '']), 403, 'reveal requires CSRF');
    expect(adminRequest($admin, 'POST', $path . '/reveal', null, ['origin' => 'https://attacker.invalid']), 403, 'reveal requires same origin');
    for ($i = 0; $i < 2; $i++) {
        $reveal = adminRequest($admin, 'POST', $path . '/reveal');
        check(expect($reveal, 200, 'audited reveal')['shared_password'] === $password && $reveal[2]['cache-control'] === 'no-store', 'reveal returns original secret without caching');
    }
    check((int) $db->one("SELECT COUNT(*) n FROM audit_log WHERE tenant_id=? AND action='hardening.password.reveal'", [$tenant])['n'] === 2, 'every reveal is audited');
    check((int) $db->one("SELECT COUNT(*) n FROM audit_log WHERE tenant_id=? AND action='hardening.settings.set'", [$tenant])['n'] === 1, 'set audited');
    foreach ([['GET', $path, null], ['PUT', $path, $input], ['POST', $path . '/reveal', null], ['GET', $devicePath, null], ['POST', $devicePath . '/command', ['action' => 'harden']]] as [$method, $route, $body]) {
        expect(adminRequest($reader, $method, $route, $body, ['idempotency-key' => Util::uuid()]), 403, 'hardening permission required: ' . $method);
    }
    expect(adminRequest($scoped, 'GET', $path), 403, 'fleet secret settings require tenant scope');
    expect(adminRequest($scoped, 'POST', $path . '/reveal'), 403, 'scoped grant cannot reveal fleet secret');
    expect(adminRequest($scoped, 'GET', $devicePath), 404, 'device role scope enforced');
    foreach ([['GET', null], ['PUT', $input], ['POST', null]] as [$method, $body]) {
        $suffix = $method === 'POST' ? '/reveal' : '';
        expect(adminRequest($admin, $method, '/admin/tenants/' . $bid . '/hardening' . $suffix, $body), 404, 'foreign tenant path rejected');
    }
    expect(adminRequest($admin, 'GET', $path, null, ['x-tenant-id' => $bid]), 404, 'conflicting tenant selector rejected');
    expect(adminRequest($admin, 'GET', $foreignPath), 404, 'foreign device status rejected');
    expect(adminRequest($admin, 'POST', $foreignPath . '/command', ['action' => 'harden'], ['idempotency-key' => Util::uuid()]), 404, 'foreign command rejected');
    expect(adminRequest($other, 'PUT', '/admin/tenants/' . $bid . '/hardening', array_replace($input, ['shared_password' => $otherPassword, 'hardening_mode' => 'auto'])), 200, 'configure tenant B');
    $agent = request('GET', '/client/hardening', null, $key, $token['access_token']);
    $settings = expect($agent, 200, 'signed agent hardening');
    (new Validator())->named('ClientHardeningSettings', json_decode($agent[3]));
    check($settings['shared_password'] === $password && $settings['hardening_mode'] === 'panel' && !str_contains($agent[3], $otherPassword), 'agent A receives only tenant A');
    check(expect(request('GET', '/client/hardening', null, $otherKey, $otherToken['access_token']), 200, 'agent B settings')['shared_password'] === $otherPassword, 'agent B receives only tenant B');
    expect(request('GET', '/client/hardening', null, $key, $token['access_token'], null, null, ['x-tenant-id' => $bid]), 400, 'agent tenant header rejected');
    expect(request('GET', '/client/hardening?tenant_id=' . $bid, null, $key, $token['access_token']), 422, 'agent tenant query rejected');
    expect(request('GET', '/client/hardening', null, null, $token['access_token']), 401, 'unsigned agent rejected');
    expect(request('GET', '/client/hardening', null, $key), 401, 'bearer required');
    expect(request('GET', '/client/hardening', null, $otherKey, $token['access_token']), 401, 'foreign signing key rejected');
    $nonce = Util::b64(random_bytes(24));
    expect(request('GET', '/client/hardening', null, $key, $token['access_token'], null, $nonce), 200, 'fresh hardening proof');
    expect(request('GET', '/client/hardening', null, $key, $token['access_token'], null, $nonce), 401, 'hardening proof replay rejected');
    $savedB = $db->one('SELECT shared_password_enc FROM tenant_hardening_settings WHERE tenant_id=?', [$b['tenant_id']]);
    $db->run('UPDATE tenant_hardening_settings SET shared_password_enc=? WHERE tenant_id=?', [$row['shared_password_enc'], $b['tenant_id']]);
    expect(request('GET', '/client/hardening', null, $otherKey, $otherToken['access_token']), 500, 'ciphertext cannot move across tenants');
    $db->run('UPDATE tenant_hardening_settings SET shared_password_enc=? WHERE tenant_id=?', [$savedB['shared_password_enc'], $b['tenant_id']]);
    $status = expect(adminRequest($admin, 'GET', $devicePath), 200, 'initial device status');
    check($status['state'] === 'none' && $status['reported_at'] === null, 'unreported device has none state');
    $db->pdo->beginTransaction();
    try {
        $db->one('SELECT id FROM devices WHERE tenant_id=? AND id=? FOR UPDATE', [$tenant, $device]);
        check(expect(adminRequest($admin, 'GET', $devicePath), 200, 'read while another connection locks device') === $status, 'hardening GET does not wait for device row lock');
    } finally { $db->pdo->rollBack(); }
    $hardenedAt = null;
    foreach (['panel_wait', 'pending', 'hardened', 'panel_wait', 'hardened', 'recovery_required', 'none'] as $state) {
        $report = ['state' => $state, 'last_step' => 4, 'detail' => 'sanitized_status'];
        $idem = Util::uuid();
        $r = request('POST', '/client/hardening/report', $report, $key, $token['access_token'], $idem);
        $reported = expect($r, 200, 'report ' . $state);
        if ($state === 'hardened' && $hardenedAt === null) {
            check($reported['hardened_at'] !== null, 'first hardened report sets timestamp');
            $hardenedAt = $reported['hardened_at'];
        }
        check($reported['state'] === $state && $reported['last_step'] === '4' && $reported['reported_at'] !== null && $reported['hardened_at'] === $hardenedAt, 'report preserves first hardened timestamp and step: ' . $state);
        $replay = request('POST', '/client/hardening/report', $report, $key, $token['access_token'], $idem);
        check(expect($replay, 200, 'report replay') === $reported, 'report replay stable');
        $observed = expect(adminRequest($admin, 'GET', $devicePath), 200, 'read reported state');
        check($observed === $reported, 'panel sees exact agent status');
        if ($state === 'recovery_required') {
            expect(adminRequest($admin, 'POST', $devicePath . '/command', ['action' => 'harden'], ['idempotency-key' => Util::uuid()]), 409, 'recovery blocks harden');
            expect(adminRequest($admin, 'POST', $devicePath . '/command', ['action' => 'unharden'], ['idempotency-key' => Util::uuid()]), 202, 'recovery permits unharden');
        }
    }
    $failure = expect(request('POST', '/client/hardening/report', ['state' => 'recovery_required', 'last_step' => 'demote_user', 'detail' => 'sanitized_failure'], $key, $token['access_token'], Util::uuid()), 200, 'report failure diagnostic');
    foreach ([['state' => 'panel_wait'], ['state' => 'panel_wait', 'last_step' => null, 'detail' => null]] as $heartbeat) {
        $reported = expect(request('POST', '/client/hardening/report', $heartbeat, $key, $token['access_token'], Util::uuid()), 200, 'status-only heartbeat');
        check($reported['state'] === 'panel_wait' && $reported['last_step'] === $failure['last_step'] && $reported['detail'] === $failure['detail'] && $reported['hardened_at'] === $hardenedAt, 'omitted or null diagnostic preserves prior failure and timestamp');
        check(expect(adminRequest($admin, 'GET', $devicePath), 200, 'read preserved diagnostic') === $reported, 'preserved diagnostic persisted');
    }
    expect(request('POST', '/client/hardening/report', ['state' => 'hardened', 'tenant_id' => $bid], $key, $token['access_token'], Util::uuid()), 422, 'report rejects tenant override');
    expect(request('POST', '/client/hardening/report', ['state' => 'hardened', 'device_id' => $otherToken['device_id']], $key, $token['access_token'], Util::uuid()), 422, 'report rejects device override');
    check(expect(adminRequest($other, 'GET', $foreignPath), 200, 'tenant B status unchanged')['state'] === 'none', 'reports cannot cross tenant');
    foreach (['harden', 'unharden'] as $action) {
        $idem = ['idempotency-key' => Util::uuid()];
        $r = adminRequest($admin, 'POST', $devicePath . '/command', ['action' => $action], $idem);
        $queued = expect($r, 202, 'enqueue ' . $action);
        check(expect(adminRequest($admin, 'POST', $devicePath . '/command', ['action' => $action], $idem), 202, 'command replay') === $queued, 'command replay returns same ID');
        $command = $db->one('SELECT * FROM device_command WHERE tenant_id=? AND id=?', [$tenant, Util::bin($queued['command_id'])]);
        check($command['device_id'] === $device && $command['type'] === $action && $command['status'] === 'pending' && $command['envelope_jws'] === null, 'pending command has no secret');
        check((int) $db->one('SELECT COUNT(*) n FROM audit_log WHERE tenant_id=? AND resource_id=? AND action=?', [$tenant, Util::bin($queued['command_id']), 'hardening.command.' . $action])['n'] === 1, 'command audited once');
    }
    $db->run("UPDATE roles SET scope_kind='area' WHERE tenant_id=? AND id=?", [$tenant, $admin['role']]);
    expect(adminRequest($admin, 'POST', $devicePath . '/command', ['action' => 'unharden'], $idem), 404, 'cached command still checks current device scope');
    $db->run("UPDATE roles SET scope_kind='tenant' WHERE tenant_id=? AND id=?", [$tenant, $admin['role']]);
    // The existing command cursor takes a snapshot with second precision.
    $db->run('UPDATE device_command SET created_at=UTC_TIMESTAMP(6)-INTERVAL 1 SECOND WHERE tenant_id=? AND device_id=?', [$tenant, $device]);
    $commands = request('GET', '/client/commands', null, $key, $token['access_token']);
    $queue = expect($commands, 200, 'agent pulls hardening queue');
    foreach ($queue['data'] as $command) { (new Validator())->named('Command', (object) $command); }
    check(in_array('harden', array_column($queue['data'], 'type'), true) && !str_contains($commands[3], $password), 'pull includes hardening without secret');
    $db->run("UPDATE devices SET status='revoked' WHERE tenant_id=? AND id=?", [$tenant, $device]);
    expect(adminRequest($admin, 'POST', $devicePath . '/command', ['action' => 'unharden'], ['idempotency-key' => Util::uuid()]), 409, 'inactive device rejects commands');
    $settingsOnly = $input; unset($settingsOnly['shared_password']); $settingsOnly['deny_network_logon'] = false;
    expect(adminRequest($admin, 'PUT', $path, $settingsOnly), 200, 'update preserves secret');
    check(expect(adminRequest($admin, 'POST', $path . '/reveal'), 200, 'reveal preserved secret')['shared_password'] === $password, 'omitted password unchanged');
    $trimmed = expect(adminRequest($admin, 'PUT', $path, array_replace($settingsOnly, ['admin_name' => ' azcadmin '])), 200, 'trim Windows admin name');
    check($trimmed['admin_name'] === 'azcadmin', 'write response contains trimmed admin name');
    check($db->one('SELECT admin_name FROM tenant_hardening_settings WHERE tenant_id=?', [$tenant])['admin_name'] === 'azcadmin', 'trimmed admin name persisted');
    foreach (['bad/name', 'bad.', 'bad. ', '   '] as $name) { expect(adminRequest($admin, 'PUT', $path, array_replace($input, ['admin_name' => $name])), 422, 'invalid Windows admin name'); }
    expect(adminRequest($admin, 'PUT', $path, array_replace($input, ['shared_password' => '********'])), 422, 'mask cannot replace password');
    expect(adminRequest($admin, 'PUT', $path, array_replace($input, ['deny_network_logon' => 1])), 422, 'deny flag requires boolean');
    expect(request('POST', '/client/hardening/report', ['state' => 'invalid'], $otherKey, $otherToken['access_token'], Util::uuid()), 422, 'report state validated');
    $rotated = 'Rotated-' . Util::b64(random_bytes(24));
    expect(adminRequest($admin, 'PUT', $path, array_replace($input, ['shared_password' => $rotated])), 200, 'rotate shared password');
    check(expect(adminRequest($admin, 'POST', $path . '/reveal'), 200, 'reveal rotated password')['shared_password'] === $rotated, 'rotation applied');
    require_once dirname(__DIR__) . '/migrations/run.php';
    foreach (statements(file_get_contents(dirname(__DIR__) . '/migrations/0020_hardening.sql')) as $sql) { $db->run($sql); }
    check(expect(adminRequest($admin, 'POST', $path . '/reveal'), 200, 'reveal after migration replay')['shared_password'] === $rotated, 'migration replay preserves settings');
    try {
        $db->run('INSERT INTO device_hardening_status (tenant_id,device_id) VALUES (?,?)', [$b['tenant_id'], $device]);
        check(false, 'cross-tenant status FK must reject');
    } catch (PDOException $e) { check(($e->errorInfo[1] ?? 0) === 1452, 'status FK enforces tenant'); }
    $audit = Util::json($db->run('SELECT action,changed_fields FROM audit_log WHERE tenant_id IN (?,?)', [$tenant, $b['tenant_id']])->fetchAll());
    $logs = '';
    foreach (['http.out', 'http.err'] as $file) {
        $file = dirname(Config::get('RATE_DIR')) . '/' . $file;
        check(is_file($file), 'HTTP log available for secret scan');
        $logs .= file_get_contents($file);
    }
    foreach ([$password, $otherPassword, $rotated] as $secret) {
        check(!str_contains($audit, $secret) && !str_contains($logs, $secret), 'password absent from audit and HTTP/error logs');
    }
    check((int) $db->one("SELECT COUNT(*) n FROM permissions WHERE code='hardening.gestionar'")['n'] === 1, 'permission seed replay is unique');
    echo 'PASS hardening: ' . ($GLOBALS['checks'] - $before) . " assertions (encryption, mask, reveal, RBAC, tenant, report, queue, replay, logs)\n";
}
