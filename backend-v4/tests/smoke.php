<?php
declare(strict_types=1);
require dirname(__DIR__) . '/config/bootstrap.php';
require __DIR__ . '/regressions.php';
require __DIR__ . '/policy-compiler.php';
require __DIR__ . '/admin.php';
require __DIR__ . '/admin-review.php';
require __DIR__ . '/external.php';
require __DIR__ . '/external-auth.php';
require __DIR__ . '/activity-migration.php';
require __DIR__ . '/hardening.php';

use Keeper\{Config, Database, Util, Validator};

if (getenv('KEEPER_TEST_ALLOW_FIXTURES') !== '1' || !preg_match('/dbname=[a-z0-9_]+_test(?:;|$)/', Config::get('DB_DSN'))) { fwrite(STDERR, "Use a migrated *_test database and KEEPER_TEST_ALLOW_FIXTURES=1\n"); exit(1); }

$checks = 0;
function check(bool $value, string $message): void
{
    global $checks;
    if (!$value) { throw new RuntimeException('FAIL: ' . $message); }
    $checks++;
}
function keypair(): array
{
    $private = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1', 'config' => __DIR__ . '/openssl.cnf']);
    if (!$private) { throw new RuntimeException('Cannot generate P-256 key'); }
    $d = openssl_pkey_get_details($private)['ec'];
    return [$private, (object) ['kty' => 'EC', 'crv' => 'P-256', 'x' => Util::b64(str_pad($d['x'], 32, "\0", STR_PAD_LEFT)), 'y' => Util::b64(str_pad($d['y'], 32, "\0", STR_PAD_LEFT))]];
}
function thumb(object $key): string { return hash('sha256', json_encode(['crv' => 'P-256', 'kty' => 'EC', 'x' => $key->x, 'y' => $key->y], JSON_UNESCAPED_SLASHES), true); }
function rawSignature(string $der): string
{
    $pos = 2; $raw = '';
    for ($n = 0; $n < 2; $n++) {
        if (ord($der[$pos++]) !== 2) { throw new RuntimeException('Bad DER test signature'); }
        $length = ord($der[$pos++]);
        $raw .= str_pad(ltrim(substr($der, $pos, $length), "\0"), 32, "\0", STR_PAD_LEFT); $pos += $length;
    }
    return $raw;
}
function request(string $method, string $path, mixed $body = null, ?array $identity = null, ?string $token = null, ?string $idem = null, ?string $nonce = null, array $extra = [], bool $sign = true): array
{
    $uri = Config::get('ORIGIN') . '/v1' . $path;
    $raw = $body === null ? '' : Util::json($body);
    $headers = [];
    if ($body !== null) { $headers['content-type'] = 'application/json'; $headers['content-digest'] = 'sha-256=:' . base64_encode(hash('sha256', $raw, true)) . ':'; }
    if ($token !== null) { $headers['authorization'] = 'Bearer ' . $token; }
    if ($idem !== null) { $headers['idempotency-key'] = $idem; }
    if ($identity && $sign) {
        $fields = ['@method' => $method, '@target-uri' => $uri] + $headers;
        $components = implode(' ', array_map(fn ($v) => '"' . $v . '"', array_keys($fields)));
        $now = time();
        $input = '(' . $components . ');created=' . $now . ';expires=' . ($now + 60) . ';nonce="' . ($nonce ?? Util::b64(random_bytes(24))) . '";keyid="' . Util::b64(thumb($identity[1])) . '";alg="ecdsa-p256-sha256"';
        $lines = [];
        foreach ($fields as $k => $v) { $lines[] = '"' . $k . '": ' . $v; }
        $lines[] = '"@signature-params": ' . $input;
        openssl_sign(implode("\n", $lines), $der, $identity[0], OPENSSL_ALGO_SHA256);
        $headers['signature-input'] = 'sig1=' . $input;
        $headers['signature'] = 'sig1=:' . base64_encode(rawSignature($der)) . ':';
    }
    $headers = array_merge($headers, $extra);
    $received = [];
    $curl = curl_init($uri);
    curl_setopt_array($curl, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_HTTPHEADER => array_map(fn ($k, $v) => $k . ': ' . $v, array_keys($headers), $headers), CURLOPT_HEADERFUNCTION => static function ($c, $line) use (&$received): int { if (str_contains($line, ':')) { [$k, $v] = explode(':', $line, 2); $received[strtolower($k)] = trim($v); } return strlen($line); }]);
    if ($body !== null) { curl_setopt($curl, CURLOPT_POSTFIELDS, $raw); }
    $response = curl_exec($curl);
    if ($response === false) { throw new RuntimeException('HTTP connection failed'); }
    $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE); curl_close($curl);
    $json = $response === '' ? null : json_decode($response, true, 64, JSON_THROW_ON_ERROR);
    if ($status >= 400) { (new Validator())->named('Problem', json_decode($response)); }
    return [$status, $json, $received, $response];
}
function expect(array $r, int $status, string $label): array
{
    check($r[0] === $status, $label . ' HTTP=' . $r[0] . ' code=' . ($r[1]['code'] ?? 'none'));
    return $r[1] ?? [];
}
function enrollment(Database $db, array $user, array $key, ?string $device = null): string
{
    $ticket = Util::b64(random_bytes(32));
    $db->run('INSERT INTO enrollments (tenant_id,id,user_id,device_id,public_key_thumbprint,ticket_hash,reason,created_at,expires_at) VALUES (?,?,?,?,?,?,\'smoke fixture\',UTC_TIMESTAMP(6),UTC_TIMESTAMP(6)+INTERVAL 10 MINUTE)', [$user['tenant_id'], Util::bin(Util::uuid()), $user['id'], $device, thumb($key[1]), hash('sha256', $ticket, true)]);
    return $ticket;
}
function enroll(Database $db, array $user, array $key): array
{
    $ticket = enrollment($db, $user, $key);
    $a = expect(request('POST', '/client/auth/challenges', ['enrollment_ticket' => $ticket]), 200, 'challenge A');
    $b = expect(request('POST', '/client/auth/challenges', ['enrollment_ticket' => $ticket]), 200, 'challenge B');
    check($a['nonce'] !== $b['nonce'], 'independent random challenges');
    $body = ['enrollment_ticket' => $ticket, 'public_key' => $key[1], 'hostname' => 'smoke-device', 'agent_version' => '4.0.0'];
    expect(request('POST', '/client/login', $body), 401, 'unsigned enrollment rejected');
    return expect(request('POST', '/client/login', $body, $key, null, null, $a['nonce']), 200, 'signed enrollment uses earlier challenge');
}
function policy(Database $db, array $token): void
{
    $doc = ['tenant_id' => $token['tenant_id'], 'device_id' => $token['device_id'], 'version' => '1', 'etag' => '"1"', 'rules' => [], 'schedules' => [], 'composition' => [], 'management_hosts' => ['keeper.example.test']];
    $db->run('INSERT INTO effective_policies (tenant_id,device_id,policy_version,compiler_version,composition_hash,document,content_hash) VALUES (?,?,1,\'smoke\',?,?,?)', [Util::bin($token['tenant_id']), Util::bin($token['device_id']), hash('sha256', 'smoke', true), Util::json($doc), hash('sha256', Util::json($doc), true)]);
}
function secondTenant(Database $db): array
{
    $tenant = Util::bin(Util::uuid());
    $db->run('INSERT INTO tenants (tenant_id,name) VALUES (?,\'smoke isolated tenant\')', [$tenant]);
    $ids = [];
    foreach (['firm', 'site', 'area', 'position'] as $kind) {
        $ids[$kind] = Util::bin(Util::uuid());
        $db->run('INSERT INTO org_units (tenant_id,id,kind,name) VALUES (?,?,?,?)', [$tenant, $ids[$kind], $kind, 'smoke ' . $kind]);
    }
    $schedule = Util::bin(Util::uuid());
    $db->run('INSERT INTO schedules (tenant_id,id,name,timezone,start_local,end_local) VALUES (?,?,\'smoke\',\'America/Bogota\',\'08:00:00\',\'17:00:00\')', [$tenant, $schedule]);
    $user = Util::bin(Util::uuid());
    $db->run('INSERT INTO users (tenant_id,id,display_name,firm_id,site_id,area_id,position_id,schedule_id) VALUES (?,?,\'smoke member\',?,?,?,?,?)', [$tenant, $user, $ids['firm'], $ids['site'], $ids['area'], $ids['position'], $schedule]);
    $db->run('INSERT INTO user_assignments (tenant_id,id,user_id,firm_id,site_id,area_id,position_id,schedule_id,starts_at) VALUES (?,?,?,?,?,?,?,?,UTC_TIMESTAMP(6)-INTERVAL 2 DAY)', [$tenant, Util::bin(Util::uuid()), $user, $ids['firm'], $ids['site'], $ids['area'], $ids['position'], $schedule]);
    $db->run('INSERT INTO retention_settings (tenant_id) VALUES (?)', [$tenant]);
    $db->run("INSERT INTO principals (tenant_id,id,kind) VALUES (?,?,'system')", [$tenant, Util::bin(Util::uuid())]);
    return $db->one('SELECT * FROM users WHERE tenant_id=? AND id=?', [$tenant, $user]);
}

try {
    $contract = (new Validator())->contract;
    check(hash_file('sha256', dirname(__DIR__, 2) . '/docs/architecture/openapi-v4.yaml') === $contract['source_sha256'], 'OpenAPI extract current');
    $db = new Database();
    if (getenv('KEEPER_TEST_ACTIVITY_ONLY')==='1') { activityMigrationTests($db); echo "A1 SMOKE PASS: $checks assertions\n"; exit(0); }
    $user = $db->one('SELECT * FROM users WHERE tenant_id<>0x00000000000040008000000000000001 AND status=\'active\' LIMIT 1');
    check($user !== null, 'demo member seed');
    $key = keypair(); $token = enroll($db, $user, $key); policy($db, $token);
    $device = Util::bin($token['device_id']); $tenant = Util::bin($token['tenant_id']); $bearer = $token['access_token'];
    check($token['expires_in'] === 3600 && $token['token_type'] === 'Bearer', 'session TTL');
    $db->run('UPDATE device_assignments SET starts_at=UTC_TIMESTAMP(6)-INTERVAL 2 DAY WHERE tenant_id=? AND device_id=?', [$tenant, $device]);
    echo "PASS challenge and signed enrollment\n";

    $sync = ['protocol_version' => 1, 'sequence' => 1, 'policy_version' => null, 'release_id' => null];
    $idem = Util::uuid();
    $first = request('POST', '/client/sync', $sync, $key, $bearer, $idem);
    $s = expect($first, 200, 'initial sync');
    check(isset($first[2]['ratelimit-limit'], $first[2]['ratelimit-remaining'], $first[2]['ratelimit-reset'], $first[2]['x-ratelimit-scope']), 'HTTP rate-limit headers');
    check($s['policy_version'] === 1 && $s['policy']['version'] === '1', 'first policy downloaded');
    $repeat = request('POST', '/client/sync', $sync, $key, $bearer, $idem);
    expect($repeat, 200, 'idempotency replay'); check($repeat[3] === $first[3], 'exact response replay');
    $sync['sequence'] = 2; $sync['policy_version'] = 1;
    expect(request('POST', '/client/sync', $sync, $key, $bearer, $idem), 409, 'idempotency body conflict');
    $s = expect(request('POST', '/client/sync', $sync, $key, $bearer, Util::uuid()), 200, 'unchanged policy sync'); check($s['policy'] === null, 'policy omitted');
    $sync['policy_version'] = 2;
    $s = expect(request('POST', '/client/sync', $sync, $key, $bearer, Util::uuid()), 200, 'client ahead'); check($s['policy'] === null, 'no policy downgrade');
    $sync['policy_version'] = 1;
    expect(request('POST', '/client/sync', $sync, $key, $bearer), 422, 'idempotency mandatory');
    expect(request('POST', '/client/sync', $sync, $key, $bearer, Util::uuid(), null, [], false), 401, 'bearer alone rejected');
    expect(request('POST', '/client/sync', $sync, $key, null, Util::uuid()), 401, 'signature alone rejected');
    $nonce = Util::b64(random_bytes(24));
    expect(request('POST', '/client/sync', $sync, $key, $bearer, Util::uuid(), $nonce), 200, 'first proof');
    expect(request('POST', '/client/sync', $sync, $key, $bearer, Util::uuid(), $nonce), 401, 'proof replay rejected');
    expect(request('POST', '/client/sync', $sync, keypair(), $bearer, Util::uuid()), 401, 'wrong signing key');
    expect(request('POST', '/client/sync', $sync, $key, $bearer, Util::uuid(), null, ['content-digest' => 'sha-256=:AAAA:']), 401, 'digest tampering');
    expect(request('POST', '/client/sync', $sync + ['tenant_id' => Util::uuid()], $key, $bearer, Util::uuid()), 422, 'tenant body rejected');
    expect(request('POST', '/client/sync', $sync, $key, $bearer, Util::uuid(), null, ['x-tenant-id' => Util::uuid()]), 400, 'tenant header rejected');
    $get = request('GET', '/client/policy', null, $key, $bearer);
    expect($get, 200, 'policy GET'); check($get[2]['etag'] === '"1"', 'ETag numeric policy version');
    expect(request('GET', '/client/policy', null, $key, $bearer, null, null, ['if-none-match' => '"1"']), 304, 'conditional policy');
    echo "PASS sync, ETag, idempotency, proof replay and tenant selectors\n";

    $episode = ['event_id' => Util::uuid(), 'started_at' => gmdate('Y-m-d\TH:i:s\Z', time() - 120), 'ended_at' => gmdate('Y-m-d\TH:i:s\Z', time() - 60), 'process_name' => 'editor.exe', 'active_seconds' => 50, 'idle_seconds' => 10];
    $batch = ['episodes' => [$episode]]; $batchKey = Util::uuid();
    $one = request('POST', '/client/episodes:batch', $batch, $key, $bearer, $batchKey);
    check(expect($one, 200, 'episode accepted')['acks'][0]['status'] === 'accepted', 'episode ACK accepted');
    $two = request('POST', '/client/episodes:batch', $batch, $key, $bearer, $batchKey); expect($two, 200, 'batch replay'); check($one[3] === $two[3], 'batch exact replay');
    $ack = expect(request('POST', '/client/episodes:batch', $batch, $key, $bearer, Util::uuid()), 200, 'event dedupe')['acks'][0]; check($ack['status'] === 'duplicate', 'ledger dedupe');
    $sync['episodes'] = [$episode];
    $s = expect(request('POST', '/client/sync', $sync, $key, $bearer, Util::uuid()), 200, 'cross-route dedupe'); check($s['episode_acks'][0]['status'] === 'duplicate', 'sync does not duplicate'); unset($sync['episodes']);
    $conflict = $episode; $conflict['process_name'] = 'other.exe';
    $ack = expect(request('POST', '/client/episodes:batch', ['episodes' => [$conflict]], $key, $bearer, Util::uuid()), 200, 'event hash conflict')['acks'][0]; check($ack['code'] === 'event_conflict', 'same UUID different body rejected');
    expect(request('POST', '/client/episodes:batch', ['episodes' => [$episode, $episode]], $key, $bearer, Util::uuid()), 422, 'duplicate UUID envelope rejected');
    $bad = $episode; $bad['event_id'] = Util::uuid(); $bad['active_seconds'] = 61;
    $good = $episode; $good['event_id'] = Util::uuid();
    $acks = expect(request('POST', '/client/episodes:batch', ['episodes' => [$bad, $good]], $key, $bearer, Util::uuid()), 200, 'partial batch')['acks']; check($acks[0]['status'] === 'rejected' && $acks[1]['status'] === 'accepted', 'individual errors preserve valid events');
    $count = $db->one('SELECT COUNT(*) n FROM episodes WHERE tenant_id=? AND device_id=?', [$tenant, $device]); check((int) $count['n'] === 2, 'exactly two stored episodes');
    check((int) $db->one('SELECT COUNT(*) n FROM episode_ingest_keys WHERE tenant_id=? AND device_id=?', [$tenant, $device])['n'] === 2, 'ledger agrees');
    echo "PASS bounded batch, ledger dedupe, partial ACK and cross-route retries\n";

    $command = Util::uuid();
    $db->run('INSERT INTO device_command (tenant_id,id,device_id,sequence,type,reason,created_at,expires_at) VALUES (?,?,?,1,\'refresh_policy\',\'smoke\',UTC_TIMESTAMP(6)-INTERVAL 1 SECOND,UTC_TIMESTAMP(6)+INTERVAL 5 MINUTE)', [$tenant, Util::bin($command), $device]);
    check(count(expect(request('GET', '/client/commands', null, $key, $bearer), 200, 'commands GET')['data']) === 1, 'own pending command');
    $s = expect(request('POST', '/client/sync', $sync, $key, $bearer, Util::uuid()), 200, 'sync command delivery'); check($s['commands'][0]['id'] === $command, 'sync delivers pending');
    $result = ['event_id' => Util::uuid(), 'status' => 'succeeded', 'at' => Util::now(), 'code' => 'OK'];
    $path = '/client/commands/' . $command . '/result';
    check(expect(request('POST', $path, $result, $key, $bearer, Util::uuid()), 200, 'command result')['status'] === 'accepted', 'command accepted');
    check(expect(request('POST', $path, $result, $key, $bearer, Util::uuid()), 200, 'command result repeat')['status'] === 'duplicate', 'command duplicate');
    $contradiction = $result; $contradiction['event_id'] = Util::uuid(); $contradiction['status'] = 'failed';
    expect(request('POST', $path, $contradiction, $key, $bearer, Util::uuid()), 409, 'terminal conflict');
    expect(request('POST', '/client/commands/' . Util::uuid() . '/result', $result, $key, $bearer, Util::uuid()), 404, 'unknown command');
    $otherKey = keypair(); $other = enroll($db, $user, $otherKey);
    expect(request('POST', $path, $result, $otherKey, $other['access_token'], Util::uuid()), 404, 'same tenant other device command rejected');
    check($db->one('SELECT status FROM device_command WHERE tenant_id=? AND id=?', [$tenant, Util::bin($command)])['status'] === 'succeeded', 'command persisted');
    echo "PASS commands, ownership and terminal transitions\n";

    $log = ['event_id' => Util::uuid(), 'at' => Util::now(), 'level' => 'info', 'code' => 'SYNC_OK', 'component' => 'agent', 'fields' => (object) ['attempt' => 1]];
    $logs = expect(request('POST', '/client/logs', ['entries' => [$log]], $key, $bearer, Util::uuid()), 200, 'structured logs'); check($logs['acks'][0]['status'] === 'accepted', 'log accepted');
    $badLog = $log; $badLog['message'] = 'PIN=1234';
    expect(request('POST', '/client/logs', ['entries' => [$badLog]], $key, $bearer, Util::uuid()), 422, 'free-form secret log rejected');
    $report = ['event_id' => Util::uuid(), 'observed_at' => Util::now(), 'report_hash' => hash('sha256', '[]'), 'controls' => []];
    expect(request('POST', '/client/security/report', $report, $key, $bearer, Util::uuid()), 200, 'security report');
    check(expect(request('POST', '/client/security/report', $report, $key, $bearer, Util::uuid()), 200, 'security dedupe')['status'] === 'duplicate', 'security duplicate');
    $checkin = ['event_id' => Util::uuid(), 'occurred_at' => Util::now(), 'direction' => 'in', 'site_id' => Util::id($user['site_id'])];
    $ci = expect(request('POST', '/client/check-ins', $checkin, $key, $bearer, Util::uuid()), 201, 'check-in'); check($ci['user_id'] === Util::id($user['id']), 'member from token');
    $ci2 = expect(request('POST', '/client/check-ins', $checkin, $key, $bearer, Util::uuid()), 201, 'check-in dedupe'); check($ci2['id'] === $ci['id'], 'check-in stable ID');
    $sync['activity'] = ['snapshot_id' => Util::uuid(), 'sequence' => 1, 'day' => (new DateTimeImmutable('now', new DateTimeZone('America/Bogota')))->format('Y-m-d'), 'active_seconds' => 50, 'idle_seconds' => 10];
    $s = expect(request('POST', '/client/sync', $sync, $key, $bearer, Util::uuid()), 200, 'activity snapshot'); check($s['activity_ack']['status'] === 'accepted', 'snapshot ACK');
    $s = expect(request('POST', '/client/sync', $sync, $key, $bearer, Util::uuid()), 200, 'activity repeat'); check($s['activity_ack']['status'] === 'duplicate', 'snapshot not summed');
    $conflictSync = $sync; $conflictSync['activity']['active_seconds']++;
    $s = expect(request('POST', '/client/sync', $conflictSync, $key, $bearer, Util::uuid()), 200, 'activity conflict preserves sync envelope');
    check($s['activity_ack']['status'] === 'rejected' && $s['activity_ack']['code'] === 'event_conflict', 'snapshot conflict ACK');
    $sync['activity']['snapshot_id'] = Util::uuid(); $sync['activity']['sequence']++; $sync['activity']['active_seconds']++;
    $s = expect(request('POST', '/client/sync', $sync, $key, $bearer, Util::uuid()), 200, 'activity advances monotonically');
    check($s['activity_ack']['status'] === 'accepted', 'existing day accepts newer snapshot');
    unset($sync['activity']);
    snapshotRace($db, $tenant, $device, $user['id']);
    expect(request('GET', '/client/releases/' . Util::uuid(), null, $key, $bearer), 404, 'unassigned release');
    echo "PASS logs, security, check-ins and monotonic activity snapshots\n";

    $foreignUser = secondTenant($db); $foreignKey = keypair(); $foreign = enroll($db, $foreignUser, $foreignKey); policy($db, $foreign);
    $foreignBearer = $foreign['access_token'];
    expect(request('POST', $path, $result, $foreignKey, $foreignBearer, Util::uuid()), 404, 'other tenant command hidden');
    $foreignPolicy = expect(request('GET', '/client/policy', null, $foreignKey, $foreignBearer), 200, 'other tenant policy');
    check($foreignPolicy['tenant_id'] === $foreign['tenant_id'] && $foreignPolicy['device_id'] === $foreign['device_id'], 'policy scoped to other tenant');
    $foreignCheckin = $checkin; $foreignCheckin['event_id'] = Util::uuid();
    expect(request('POST', '/client/check-ins', $foreignCheckin, $foreignKey, $foreignBearer, Util::uuid()), 404, 'foreign site reference rejected');
    expect(request('POST', '/client/sync', $sync, $key, $foreignBearer, Util::uuid()), 401, 'cross-tenant token key mix rejected');
    $foreignSync = expect(request('POST', '/client/sync', ['protocol_version' => 1, 'sequence' => 1, 'policy_version' => null, 'release_id' => null], $foreignKey, $foreignBearer, $idem), 200, 'idempotency key isolated by tenant');
    check($foreignSync['policy']['tenant_id'] === $foreign['tenant_id'], 'replay cannot disclose another tenant');
    echo "PASS hard boundary across two actual tenants\n";

    $cursorCommands = [];
    foreach ([2, 3] as $sequence) {
        $cid = Util::uuid(); $cursorCommands[] = $cid;
        $db->run('INSERT INTO device_command (tenant_id,id,device_id,sequence,type,reason,created_at,expires_at) VALUES (?,?,?,?,\'refresh_policy\',\'smoke\',UTC_TIMESTAMP(6)-INTERVAL 1 SECOND,UTC_TIMESTAMP(6)+INTERVAL 5 MINUTE)', [$tenant, Util::bin($cid), $device, $sequence]);
    }
    $page = expect(request('GET', '/client/commands?limit=1', null, $key, $bearer), 200, 'command page');
    check(count($page['data']) === 1 && $page['next_cursor'] !== null, 'bounded command cursor');
    $pagePath = '/client/commands?limit=1&cursor=' . rawurlencode($page['next_cursor']);
    $next = expect(request('GET', $pagePath, null, $key, $bearer), 200, 'command next page');
    check(count($next['data']) === 1 && $next['data'][0]['id'] !== $page['data'][0]['id'] && $next['next_cursor'] === null, 'stable keyset page');
    expect(request('GET', $pagePath, null, $foreignKey, $foreignBearer), 404, 'foreign cursor rejected');
    expect(request('POST', '/client/commands/' . $cursorCommands[0] . '/result', ['event_id' => Util::uuid(), 'status' => 'succeeded', 'at' => Util::now()], $key, $bearer, Util::uuid()), 200, 'optional result code supported');

    $releaseKey = keypair(); $keyFile = Config::get('RELEASE_KEYS_FILE');
    check($keyFile !== '' && str_contains(str_replace('\\', '/', $keyFile), '/backend-v4/.validation/') && !is_file($keyFile), 'isolated release trust fixture path');
    file_put_contents($keyFile, Util::json(['smoke-release' => $releaseKey[1]]));
    $manifest = ['id' => Util::uuid(), 'version' => '4.0.1', 'channel' => 'stable', 'sequence' => 1, 'min_agent_version' => '4.0.0', 'architecture' => 'x64', 'artifact_url' => 'https://example.test/keeper.msi', 'size_bytes' => 12345, 'sha256' => hash('sha256', 'fixture-artifact'), 'key_id' => 'smoke-release', 'published_at' => Util::now()];
    $jwsInput = Util::b64(Util::json(['alg' => 'ES256', 'kid' => 'smoke-release'])) . '.' . Util::b64(Util::json($manifest));
    openssl_sign($jwsInput, $der, $releaseKey[0], OPENSSL_ALGO_SHA256);
    $jws = $jwsInput . '.' . Util::b64(rawSignature($der));
    $platform = Util::bin('00000000-0000-4000-8000-000000000001');
    $db->run('INSERT INTO client_releases (tenant_id,id,version,channel,sequence,min_agent_version,architecture,artifact_url,size_bytes,sha256,key_id,manifest_jws,published_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)', [$platform, Util::bin($manifest['id']), $manifest['version'], $manifest['channel'], $manifest['sequence'], $manifest['min_agent_version'], $manifest['architecture'], $manifest['artifact_url'], $manifest['size_bytes'], hex2bin($manifest['sha256']), $manifest['key_id'], $jws, Util::sqlTime($manifest['published_at'])]);
    $db->run('INSERT INTO release_deployments (tenant_id,release_id,ring,percentage,enabled) VALUES (?, ?,\'stable\',100,TRUE)', [$tenant, Util::bin($manifest['id'])]);
    $db->run('UPDATE devices SET specs=? WHERE tenant_id=? AND id=?', ['{"architecture":"x64"}', $tenant, $device]);
    $release = expect(request('GET', '/client/releases/' . $manifest['id'], null, $key, $bearer), 200, 'signed release');
    check($release['manifest_jws'] === $jws, 'manifest preserved');
    $s = expect(request('POST', '/client/sync', $sync, $key, $bearer, Util::uuid()), 200, 'release via sync'); check($s['release']['id'] === $manifest['id'], 'eligible release delivered');
    $known = $sync; $known['release_id'] = $manifest['id'];
    $s = expect(request('POST', '/client/sync', $known, $key, $bearer, Util::uuid()), 200, 'known release'); check($s['release'] === null, 'unchanged release omitted');
    expect(request('GET', '/client/releases/' . $manifest['id'], null, $foreignKey, $foreignBearer), 404, 'unassigned tenant release');
    file_put_contents($keyFile, '{}');
    expect(request('GET', '/client/releases/' . $manifest['id'], null, $key, $bearer), 503, 'untrusted manifest withheld');
    $db->run('UPDATE release_deployments SET enabled=FALSE WHERE tenant_id=? AND release_id=?', [$tenant, Util::bin($manifest['id'])]);
    echo "PASS bounded cursors and trusted release manifests\n";

    expect(request('POST', '/client/episodes:batch', ['episodes' => array_fill(0, 201, $episode)], $key, $bearer, Util::uuid()), 422, 'batch item cap');
    expect(request('POST', '/client/logs', ['entries' => [], 'padding' => str_repeat('x', 33000)], $key, $bearer, Util::uuid()), 413, 'body byte cap');
    $stringVersion = $sync; $stringVersion['policy_version'] = '1';
    expect(request('POST', '/client/sync', $stringVersion, $key, $bearer, Util::uuid()), 422, 'policy version strictly integer');
    $pendingKey = keypair(); $pendingTicket = enrollment($db, $foreignUser, $pendingKey);
    $pending = [];
    for ($n = 0; $n < 3; $n++) { $pending[] = expect(request('POST', '/client/auth/challenges', ['enrollment_ticket' => $pendingTicket]), 200, 'outstanding challenge'); }
    expect(request('POST', '/client/auth/challenges', ['enrollment_ticket' => $pendingTicket]), 429, 'outstanding challenge cap');
    $db->run('UPDATE device_challenges SET created_at=UTC_TIMESTAMP(6)-INTERVAL 61 SECOND,expires_at=UTC_TIMESTAMP(6)-INTERVAL 1 SECOND WHERE tenant_id=? AND nonce_hash=?', [$foreignUser['tenant_id'], hash('sha256', $pending[0]['nonce'], true)]);
    $pendingBody = ['enrollment_ticket' => $pendingTicket, 'public_key' => $pendingKey[1], 'hostname' => 'pending', 'agent_version' => '4.0.0'];
    expect(request('POST', '/client/login', $pendingBody, $pendingKey, null, null, $pending[0]['nonce']), 401, 'expired server nonce');
    echo "PASS body limits, strict schemas, outstanding cap and nonce TTL\n";

    $db->run('UPDATE device_sessions SET created_at=UTC_TIMESTAMP(6)-INTERVAL 55 MINUTE,expires_at=UTC_TIMESTAMP(6)+INTERVAL 5 MINUTE WHERE tenant_id=? AND token_hash=?', [$tenant, hash('sha256', $bearer, true)]);
    $renewKey = Util::uuid(); $renew = request('POST', '/client/sync', $sync, $key, $bearer, $renewKey);
    $renewed = expect($renew, 200, 'token renewal'); check(isset($renewed['token']), 'less than ten minutes renews');
    $renew2 = request('POST', '/client/sync', $sync, $key, $bearer, $renewKey); expect($renew2, 200, 'renewal replay'); check($renew[3] === $renew2[3], 'same renewed token replay');
    $stored = $db->one('SELECT response_ciphertext FROM idempotency_keys WHERE tenant_id=? AND idempotency_key=?', [$tenant, Util::bin($renewKey)]); check(!str_contains($stored['response_ciphertext'], $renewed['token']['access_token']), 'persisted response encrypted');
    $db->run('UPDATE device_sessions SET created_at=UTC_TIMESTAMP(6)-INTERVAL 61 MINUTE,expires_at=UTC_TIMESTAMP(6)-INTERVAL 1 MINUTE WHERE tenant_id=? AND token_hash=?', [$tenant, hash('sha256', $bearer, true)]);
    expect(request('POST', '/client/sync', $sync, $key, $bearer, Util::uuid()), 401, 'expired token');
    $bearer = $renewed['token']['access_token'];
    $challenge = expect(request('POST', '/client/auth/challenges', ['device_id' => $token['device_id']]), 200, 'registered challenge');
    $login = ['device_id' => $token['device_id'], 'public_key' => $key[1], 'agent_version' => '4.0.0', 'hostname' => 'smoke-device'];
    $evilKey = keypair(); $evilLogin = $login; $evilLogin['public_key'] = $evilKey[1];
    expect(request('POST', '/client/login', $evilLogin, $evilKey, null, null, $challenge['nonce']), 401, 'GUID plus replacement key denied');
    expect(request('POST', '/client/login', $login, $key, null, null, $challenge['nonce']), 200, 'registered-key login');
    expect(request('POST', '/client/login', $login, $key, null, null, $challenge['nonce']), 401, 'login challenge replay');
    $db->run('UPDATE tenants SET status=\'suspended\' WHERE tenant_id=?', [$tenant]);
    expect(request('GET', '/client/policy', null, $key, $bearer), 401, 'tenant deactivation immediate');
    $db->run('UPDATE tenants SET status=\'active\' WHERE tenant_id=?', [$tenant]);
    $db->run('UPDATE users SET auth_version=auth_version+1 WHERE tenant_id=? AND id=?', [$tenant, $user['id']]);
    expect(request('GET', '/client/policy', null, $key, $bearer), 401, 'membership revocation version');
    check((int) $db->one('SELECT COUNT(*) n FROM audit_log WHERE tenant_id=? AND actor_type=\'device\'', [$tenant])['n'] >= 6, 'tenant actor audit');
    echo "PASS renewal encryption, expiry, GUID attack and immediate revocation\n";

    $recoveryKey = keypair();
    $recoveryTicket = enrollment($db, $foreignUser, $recoveryKey, Util::bin($foreign['device_id']));
    $challenge = expect(request('POST', '/client/auth/challenges', ['enrollment_ticket' => $recoveryTicket]), 200, 'recovery challenge');
    $recovery = expect(request('POST', '/client/login', ['enrollment_ticket' => $recoveryTicket, 'public_key' => $recoveryKey[1], 'hostname' => 'recovered', 'agent_version' => '4.0.0'], $recoveryKey, null, null, $challenge['nonce']), 200, 'signed recovery');
    check($recovery['device_id'] === $foreign['device_id'] && $recovery['tenant_id'] === $foreign['tenant_id'], 'recovery preserves device ownership');
    expect(request('GET', '/client/policy', null, $foreignKey, $foreignBearer), 401, 'recovery revokes old bearer');
    expect(request('GET', '/client/policy', null, $recoveryKey, $recovery['access_token']), 200, 'new recovery key valid');
    $challenge = expect(request('POST', '/client/auth/challenges', ['device_id' => $foreign['device_id']]), 200, 'challenge after recovery');
    expect(request('POST', '/client/login', ['device_id' => $foreign['device_id'], 'public_key' => $foreignKey[1], 'hostname' => 'old', 'agent_version' => '4.0.0'], $foreignKey, null, null, $challenge['nonce']), 401, 'old key cannot renew');
    echo "PASS recovery and revocation of prior key\n";

    $limiter = new Keeper\RateLimiter();
    $bucket = ['device', 'smoke-' . Util::uuid(), 1, 1]; $limiter->take([$bucket]);
    try { $limiter->take([$bucket]); throw new RuntimeException('Missing 429'); }
    catch (Keeper\ApiError $e) { check($e->status === 429 && (int) $e->headers['Retry-After'] > 0, 'atomic rate gate'); }
    echo "PASS rate-limit counters\n";
    rateRecovery();
    uriReferences();
    policyCompilerTests($db);
    adminTests($db);
    externalTests($db);
    externalAuthTests($db);
    activityMigrationTests($db);
    hardeningTests($db);
    echo "SMOKE PASS: $checks assertions\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e instanceof PDOException ? 'Database fixture failure SQLSTATE=' . $e->getCode() . ' driver=' . ($e->errorInfo[1]??0) . "\n" : $e->getMessage() . "\n");
    foreach ($e->getTrace() as $frame) { fwrite(STDERR,basename($frame['file']??'').':'.($frame['line']??0)."\n"); }
    exit(1);
}
