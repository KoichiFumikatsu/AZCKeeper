<?php
declare(strict_types=1);
require __DIR__ . '/run.php';

// Integration checks use disposable databases and roll back all test fixtures.
$pdo = connectDatabase();
$database = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
if (!preg_match('/_(test|validation[0-9]*)$/', $database)) {
    throw new RuntimeException('Verification requires a disposable database ending in _test or _validation[N]');
}
$checks = 0;
function verify(bool $condition, string $name): void
{
    global $checks;
    if (!$condition) {
        throw new RuntimeException("FAIL: $name");
    }
    ++$checks;
    echo "PASS $name\n";
}
function rejected(PDO $pdo, string $sql, int $code, string $name): void
{
    if ($code===3819 && stripos((string)$pdo->getAttribute(PDO::ATTR_SERVER_VERSION),'MariaDB')!==false) { $code=4025; }
    try {
        $pdo->exec($sql);
    } catch (PDOException $error) {
        verify((int) ($error->errorInfo[1] ?? 0) === $code, "$name (expected $code; got " . ($error->errorInfo[1] ?? 0) . ')');
        return;
    }
    throw new RuntimeException("FAIL: accepted $name");
}
function countRows(PDO $pdo, string $sql): int
{
    return (int) $pdo->query($sql)->fetchColumn();
}
function fixtureId(int $id): string
{
    return '0x20000000000040008000' . str_pad(dechex($id), 12, '0', STR_PAD_LEFT);
}
function seedId(int $id): string
{
    return '0x10000000000040008000' . str_pad(dechex($id), 12, '0', STR_PAD_LEFT);
}

try {
    verify(countRows($pdo, "SELECT COUNT(*) FROM information_schema.tables t WHERE t.table_schema = DATABASE()
        AND t.table_type = 'BASE TABLE' AND NOT EXISTS (SELECT 1 FROM information_schema.table_constraints c
        WHERE c.table_schema = t.table_schema AND c.table_name = t.table_name AND c.constraint_type = 'PRIMARY KEY')") === 0, 'every table has a PK');
    verify(countRows($pdo, "SELECT COUNT(*) FROM information_schema.tables t WHERE t.table_schema = DATABASE()
        AND t.table_name NOT IN ('permissions','role_templates','role_template_permissions','scopes','modules','schema_migrations')
        AND NOT EXISTS (SELECT 1 FROM information_schema.columns c WHERE c.table_schema = t.table_schema
        AND c.table_name = t.table_name AND c.column_name = 'tenant_id' AND c.is_nullable = 'NO')") === 0, 'all data tables have mandatory tenant_id');
    verify(countRows($pdo, "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND engine <> 'InnoDB'") === 0, 'all tables use InnoDB');
    verify(countRows($pdo, "SELECT COUNT(*) FROM information_schema.key_column_usage k WHERE k.table_schema = DATABASE()
        AND k.referenced_table_name IS NOT NULL
        AND k.referenced_table_name NOT IN ('permissions','role_templates','scopes','modules')
        AND NOT EXISTS (SELECT 1 FROM information_schema.key_column_usage x WHERE x.table_schema = k.table_schema
        AND x.table_name = k.table_name AND x.constraint_name = k.constraint_name AND x.referenced_column_name = 'tenant_id')") === 0, 'every business FK includes tenant');
    verify(countRows($pdo, "SELECT COUNT(*) FROM information_schema.partitions WHERE table_schema = DATABASE()
        AND table_name = 'episodes' AND partition_name = 'p_future'") === 1
        && countRows($pdo, "SELECT COUNT(*) FROM information_schema.partitions WHERE table_schema = DATABASE()
        AND table_name = 'episodes' AND partition_description <> 'MAXVALUE'
        AND CAST(TRIM(BOTH '\\'' FROM partition_description) AS DATE) >= GREATEST('2028-01-01', UTC_DATE() + INTERVAL 3 MONTH)") > 0,
        'episodes has future monthly coverage and fallback');
    verify(countRows($pdo, "SELECT COUNT(*) FROM information_schema.events WHERE event_schema = DATABASE()
        AND event_name = 'maintain_episode_partitions' AND status = 'ENABLED'
        AND interval_value = '1' AND interval_field = 'DAY'") === 1, 'daily partition maintenance event enabled');
    verify(countRows($pdo, 'SELECT COUNT(*) FROM role_templates') === 8, 'eight seed roles');
    verify(countRows($pdo, 'SELECT COUNT(*) FROM roles') === 8, 'platform role plus seven demo roles');
    verify(countRows($pdo, 'SELECT COUNT(*) FROM tenants WHERE rbac_self_management <> 0') === 0, 'RBAC gate defaults OFF');
    verify(countRows($pdo, 'SELECT COUNT(*) FROM tier') === 3, 'three seed tiers');
    verify(countRows($pdo, "SELECT COUNT(*) FROM role_permissions WHERE tenant_id <> 0x00000000000040008000000000000001 AND permission_code = 'roles.gestionar'") === 0, 'meta-permission not delegated by seed');

    $pdo->beginTransaction();
    $tenant = '0x00000000000040008000000000000002';
    $platform = '0x00000000000040008000000000000001';
    $other = fixtureId(1);
    $user = seedId(7);
    $assignment = seedId(8);
    $device = fixtureId(2);
    $deviceAssignment = fixtureId(3);
    $event = fixtureId(4);
    $admin = fixtureId(5);
    $pdo->exec("INSERT INTO tenants (tenant_id,name) VALUES ($other,'Validation tenant B')");
    $pdo->exec("INSERT INTO retention_settings (tenant_id) VALUES ($other)");
    $deviceInsert = "INSERT INTO devices (tenant_id,id,user_id,hostname,agent_version,os_edition,cpu,ram_bytes,capabilities)
        VALUES ($tenant,$device,$user,'test','4.0.0','Windows 11 Pro','test',8192,JSON_ARRAY())";
    rejected($pdo, str_replace($tenant, $other, $deviceInsert), 1452, 'cross-tenant device owner');
    $pdo->exec($deviceInsert);
    rejected($pdo, "UPDATE users SET firm_id = NULL WHERE tenant_id = $tenant AND id = $user", 1048, 'mandatory firm');
    rejected($pdo, "UPDATE users SET firm_id = " . seedId(2) . " WHERE tenant_id = $tenant AND id = $user", 1452, 'site cannot masquerade as firm');
    rejected($pdo, "INSERT INTO user_roles VALUES ($other,$user," . seedId(107) . ')', 1452, 'cross-tenant role binding');
    rejected($pdo, "INSERT INTO role_permissions VALUES ($tenant," . seedId(104) . ",'empresas.rbac_habilitar')", 1644, 'tenant cannot receive platform permission');
    $pdo->exec("INSERT INTO device_assignments (tenant_id,id,device_id,user_id,starts_at,reason)
        VALUES ($tenant,$deviceAssignment,$device,$user,'2026-01-01','test')");
    rejected($pdo, "INSERT INTO device_assignments (tenant_id,id,device_id,user_id,starts_at,ends_at,reason)
        VALUES ($tenant," . fixtureId(6) . ",$device,$user,'2026-02-01','2026-03-01','overlap')", 1644, 'device interval overlap');
    rejected($pdo, "INSERT INTO subscriptions (tenant_id,id,user_id,tier_id,starts_at,ends_at)
        VALUES ($tenant," . fixtureId(7) . ",$user," . seedId(202) . ",'2026-02-01','2026-03-01')", 1644, 'member subscription overlap');
    $pdo->exec("UPDATE subscriptions SET ends_at = '2026-02-01' WHERE tenant_id = $tenant AND id = " . seedId(204));
    $pdo->exec("INSERT INTO subscriptions (tenant_id,id,user_id,tier_id,starts_at,ends_at)
        VALUES ($tenant," . fixtureId(7) . ",$user," . seedId(202) . ",'2026-02-01','2026-03-01')");
    verify(true, 'adjacent subscription intervals accepted');
    rejected($pdo, "UPDATE subscriptions SET starts_at = '2026-01-31' WHERE tenant_id = $tenant AND id = " . fixtureId(7), 1644, 'overlap rejected on update');

    $episode = "INSERT INTO episodes (tenant_id,device_id,event_id,user_id,device_assignment_id,user_assignment_id,event_date,
        started_at,ended_at,process_name,active_seconds,idle_seconds,body_hash)
        VALUES ($tenant,$device,$event,$user,$deviceAssignment,$assignment,UTC_DATE() - INTERVAL 1 DAY,
        UTC_DATE() - INTERVAL 1 DAY,UTC_DATE() - INTERVAL 1 DAY + INTERVAL 60 SECOND,'test.exe',50,10,UNHEX(SHA2('event',256)))";
    $pdo->exec($episode);
    verify(countRows($pdo, "SELECT COUNT(*) FROM episode_ingest_keys WHERE tenant_id=$tenant AND device_id=$device") === 1, 'episode and FK ledger persisted together');
    rejected($pdo, $episode, 1062, 'same event deduplicated');
    rejected($pdo, str_replace('INTERVAL 1 DAY', 'INTERVAL 2 DAY', $episode), 1062, 'same event cannot evade dedupe using another partition date');
    rejected($pdo, str_replace($tenant, $other, str_replace($event, fixtureId(8), $episode)), 1644, 'partitioned episode rejects cross-tenant ownership');
    rejected($pdo, str_replace('INTERVAL 1 DAY', 'INTERVAL 31 DAY', str_replace($event, fixtureId(8), $episode)), 1644, 'expired ingest window rejected');
    rejected($pdo, "UPDATE episodes SET process_name='changed' WHERE tenant_id=$tenant AND event_id=$event", 1644, 'raw episodes immutable');
    rejected($pdo, "DELETE FROM episode_ingest_keys WHERE tenant_id=$tenant AND device_id=$device AND event_id=$event", 1644, 'cannot remove live dedupe key');
    $badEpisode = str_replace($event, fixtureId(9), str_replace("'test.exe',50,10", "'test.exe',70,10", $episode));
    rejected($pdo, $badEpisode, 3819, 'duration constraint');
    verify(countRows($pdo, "SELECT COUNT(*) FROM episode_ingest_keys WHERE tenant_id=$tenant AND event_id=" . fixtureId(9)) === 0, 'failed raw insert rolls back ledger');

    $hash = $pdo->quote(password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT));
    $pdo->exec("INSERT INTO admin_accounts (tenant_id,id,user_id,email,password_hash)
        VALUES ($tenant,$admin,$user,'validation@example.invalid',$hash)");
    $session = "INSERT INTO admin_sessions (tenant_id,id,admin_id,token_hash,csrf_hash,auth_version,created_at,last_seen_at,expires_at,idle_expires_at)
        VALUES ($tenant," . fixtureId(10) . ",$admin,UNHEX(SHA2('session',256)),UNHEX(SHA2('csrf',256)),1,
        UTC_TIMESTAMP(6),UTC_TIMESTAMP(6),UTC_TIMESTAMP(6)+INTERVAL 8 HOUR,UTC_TIMESTAMP(6)+INTERVAL 30 MINUTE)";
    rejected($pdo, str_replace('INTERVAL 8 HOUR', 'INTERVAL 9 HOUR', $session), 3819, 'admin absolute TTL');
    rejected($pdo, str_replace('INTERVAL 30 MINUTE', 'INTERVAL 31 MINUTE', $session), 3819, 'admin idle TTL');
    $pdo->exec($session);
    verify(true, 'valid admin session');
    $key = fixtureId(11);
    $pdo->exec("INSERT INTO device_keys (tenant_id,id,device_id,thumbprint,jwk_x,jwk_y)
        VALUES ($tenant,$key,$device,UNHEX(SHA2('key',256)),UNHEX(SHA2('x',256)),UNHEX(SHA2('y',256)))");
    $nonce = "INSERT INTO device_signature_nonces (tenant_id,key_id,nonce_hash,created_at,expires_at)
        VALUES ($tenant,$key,UNHEX(SHA2('nonce',256)),UTC_TIMESTAMP(6),UTC_TIMESTAMP(6)+INTERVAL 300 SECOND)";
    rejected($pdo, str_replace('300 SECOND', '301 SECOND', $nonce), 3819, 'nonce maximum TTL 300 seconds');
    rejected($pdo, str_replace('300 SECOND', '119 SECOND', $nonce), 3819, 'nonce minimum TTL 120 seconds');
    $pdo->exec($nonce);
    verify(true, 'nonce maximum boundary accepted');
    $command = "INSERT INTO device_command (tenant_id,id,device_id,sequence,type,status,reason,created_at,expires_at,result_code,completed_at)
        VALUES ($tenant," . fixtureId(20) . ",$device,1,'lock','pending','test',UTC_TIMESTAMP(6),UTC_TIMESTAMP(6)+INTERVAL 1 HOUR,NULL,NULL)";
    $pdo->exec($command);
    foreach (['succeeded', 'failed'] as $status) {
        rejected($pdo, "UPDATE device_command SET status='$status' WHERE tenant_id=$tenant AND id=" . fixtureId(20), 3819, "$status requires result and completion");
        rejected($pdo, "UPDATE device_command SET status='$status',result_code='ok' WHERE tenant_id=$tenant AND id=" . fixtureId(20), 3819, "$status requires completion");
        rejected($pdo, "UPDATE device_command SET status='$status',completed_at=UTC_TIMESTAMP(6) WHERE tenant_id=$tenant AND id=" . fixtureId(20), 3819, "$status requires result");
        $pdo->exec("UPDATE device_command SET status='$status',result_code='ok',completed_at=UTC_TIMESTAMP(6) WHERE tenant_id=$tenant AND id=" . fixtureId(20));
        verify(true, "$status accepts complete result");
        $pdo->exec("UPDATE device_command SET status='pending',result_code=NULL,completed_at=NULL WHERE tenant_id=$tenant AND id=" . fixtureId(20));
    }
    foreach (['pending', 'delivered', 'running', 'expired', 'cancelled'] as $status) {
        rejected($pdo, "UPDATE device_command SET status='$status',result_code='ok',completed_at=UTC_TIMESTAMP(6) WHERE tenant_id=$tenant AND id=" . fixtureId(20), 3819, "$status forbids terminal result");
    }
    $token = "INSERT INTO device_sessions (tenant_id,id,device_id,user_id,assignment_id,key_id,token_hash,
        device_auth_version,user_auth_version,tenant_auth_version,created_at,expires_at)
        VALUES ($tenant," . fixtureId(12) . ",$device,$user,$deviceAssignment,$key,UNHEX(SHA2('token',256)),1,1,1,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6)+INTERVAL 1 HOUR)";
    rejected($pdo, str_replace('INTERVAL 1 HOUR', 'INTERVAL 2 HOUR', $token), 3819, 'device token TTL');
    $pdo->exec($token);
    verify(true, 'valid device token bound to key and assignment');
    $principal = seedId(401);
    $idem = "INSERT INTO idempotency_keys (tenant_id,principal_id,method,route_hash,route,idempotency_key,request_hash,created_at,expires_at)
        VALUES ($tenant,$principal,'POST',UNHEX(SHA2('/v1/client/sync',256)),'/v1/client/sync'," . fixtureId(13) . ",UNHEX(SHA2('body',256)),UTC_TIMESTAMP(6),UTC_TIMESTAMP(6)+INTERVAL 24 HOUR)";
    rejected($pdo, str_replace('INTERVAL 24 HOUR', 'INTERVAL 25 HOUR + INTERVAL 1 MICROSECOND', $idem), 3819, 'idempotency maximum TTL 25h');
    rejected($pdo, str_replace('INTERVAL 24 HOUR', 'INTERVAL 0 HOUR', $idem), 3819, 'idempotency positive TTL');
    $pdo->exec(str_replace('INTERVAL 24 HOUR', 'INTERVAL 24 HOUR + INTERVAL 1 MICROSECOND', $idem));
    verify(true, 'idempotency tolerates timestamp microseconds');
    $pdo->exec("UPDATE idempotency_keys SET expires_at=created_at+INTERVAL 25 HOUR WHERE tenant_id=$tenant AND idempotency_key=" . fixtureId(13));
    verify(true, 'idempotency 25h boundary accepted');
    rejected($pdo, $idem, 1062, 'idempotency tenant/principal/route/key uniqueness');
    $pdo->exec("INSERT INTO audit_log (tenant_id,id,actor_id,actor_type,action,resource_type,resource_id,request_id,outcome,changed_fields)
        VALUES ($tenant," . fixtureId(14) . ",$principal,'system','test','device',$device," . fixtureId(15) . ",'allowed',JSON_ARRAY())");
    rejected($pdo, "UPDATE audit_log SET action='changed' WHERE tenant_id=$tenant AND id=" . fixtureId(14), 1644, 'audit immutable');
    rejected($pdo, "DELETE FROM audit_log WHERE tenant_id=$tenant AND id=" . fixtureId(14), 1644, 'audit append-only');
    $release = "INSERT INTO client_releases (tenant_id,id,version,channel,sequence,min_agent_version,architecture,artifact_url,size_bytes,sha256,key_id,manifest_jws,published_at)
        VALUES ($platform," . fixtureId(16) . ",'4.0.0-test','test',1,'4.0.0','x64','https://example.invalid/test.zip',100,UNHEX(SHA2('artifact',256)),'test',NULL,UTC_TIMESTAMP(6))";
    rejected($pdo, $release, 1048, 'unsigned release rejected');
    $pdo->rollBack();
    echo "Verified $checks checks; fixtures rolled back. MySQL " . $pdo->query('SELECT VERSION()')->fetchColumn() . "\n";
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
}
