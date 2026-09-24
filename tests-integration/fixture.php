<?php
declare(strict_types=1);

require dirname(__DIR__) . '/backend-v4/config/bootstrap.php';
require dirname(__DIR__) . '/backend-v4/migrations/run.php';

use Keeper\{Database, PolicyComposer, Signature, Util};

function check(bool $condition, string $name): void
{
    if (!$condition) { throw new RuntimeException('FAIL ' . $name); }
    echo 'PASS ' . $name . "\n";
}

try {
    $root = getenv('KEEPER_INTEGRATION_ROOT');
    $expected = realpath($root . '/data');
    if (PHP_SAPI !== 'cli' || !$expected || !str_starts_with(str_replace('\\', '/', $expected), str_replace('\\', '/', __DIR__) . '/.runs/')) {
        throw new RuntimeException('Requires isolated tests-integration datadir');
    }
    $dsn = getenv('KEEPER_DB_DSN');
    if (!str_contains($dsn, 'dbname=keeper_v4_integration_test;')) { throw new RuntimeException('Unexpected database'); }
    $serverDsn = str_replace('dbname=keeper_v4_integration_test;', '', $dsn);
    $pdo = null;
    for ($attempt = 0; $attempt < 120; $attempt++) {
        try { $pdo = new PDO($serverDsn, 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]); break; }
        catch (PDOException $e) { if ($argv[1] !== 'prepare') { throw $e; } usleep(250000); }
    }
    if (!$pdo) { throw new RuntimeException('MySQL startup timeout'); }
    $normalize = static fn(string $path): string => strtolower(rtrim(str_replace('\\', '/', $path), '/'));
    if ($normalize($pdo->query('SELECT @@datadir')->fetchColumn()) !== $normalize($expected)) { throw new RuntimeException('Wrong MySQL instance'); }
    if ($argv[1] === 'shutdown') { $pdo->exec('SHUTDOWN'); exit(0); }
    if ($argv[1] === 'prepare') {
        $pdo->exec('CREATE DATABASE keeper_v4_integration_test CHARACTER SET utf8mb4 COLLATE ' . migrationCollation($pdo));
        echo 'PASS isolated MySQL ' . $pdo->query('SELECT VERSION()')->fetchColumn() . "\n";
        exit(0);
    }
    $db = new Database();
    if ($argv[1] === 'provision') {
        $migrations = glob(dirname(__DIR__) . '/backend-v4/migrations/[0-9][0-9][0-9][0-9]_*.sql');
        foreach ($migrations as $migration) {
            $row = $db->one('SELECT sha256 FROM schema_migrations WHERE filename=?', [basename($migration)]);
            check($row !== null && hash_equals(hash_file('sha256', $migration, true), $row['sha256']), 'migration ' . basename($migration));
        }
        check((int)$db->one('SELECT COUNT(*) n FROM role_templates')['n'] === 8 &&
            (int)$db->one('SELECT COUNT(*) n FROM tier')['n'] === 3, 'seed roles and tiers');
        $public = json_decode(file_get_contents($root . '/public.json'), false, 32, JSON_THROW_ON_ERROR);
        $thumb = Signature::thumbprint($public->public_key);
        check(hash_equals(Util::b64($thumb), $public->keyid), 'C# JWK keyid equals PHP SHA-256 thumbprint');
        $tenant = Util::bin(Util::uuid()); $user = Util::bin(Util::uuid()); $device = Util::bin(Util::uuid());
        $db->transaction(function () use ($db, $tenant, $user, $device, $thumb, $public): void {
            $db->run("INSERT INTO tenants (tenant_id,name) VALUES (?,'C# PHP integration')", [$tenant]);
            $units = [];
            foreach (['firm', 'site', 'area', 'position'] as $kind) {
                $units[$kind] = Util::bin(Util::uuid());
                $db->run('INSERT INTO org_units (tenant_id,id,kind,name) VALUES (?,?,?,?)', [$tenant, $units[$kind], $kind, 'integration ' . $kind]);
            }
            $schedule = Util::bin(Util::uuid());
            $db->run("INSERT INTO schedules (tenant_id,id,name,timezone,start_local,end_local) VALUES (?,?,'integration','America/Bogota','08:00:00','17:00:00')", [$tenant, $schedule]);
            $org = [$units['firm'], $units['site'], $units['area'], $units['position'], $schedule];
            $db->run("INSERT INTO users (tenant_id,id,display_name,firm_id,site_id,area_id,position_id,schedule_id) VALUES (?,?,'integration member',?,?,?,?,?)", [$tenant, $user, ...$org]);
            $db->run('INSERT INTO user_assignments (tenant_id,id,user_id,firm_id,site_id,area_id,position_id,schedule_id,starts_at) VALUES (?,?,?,?,?,?,?,?,UTC_TIMESTAMP(6)-INTERVAL 2 DAY)', [$tenant, Util::bin(Util::uuid()), $user, ...$org]);
            $db->run('INSERT INTO retention_settings (tenant_id) VALUES (?)', [$tenant]);
            $db->run("INSERT INTO principals (tenant_id,id,kind) VALUES (?,?,'system')", [$tenant, Util::bin(Util::uuid())]);
            $db->run("INSERT INTO devices (tenant_id,id,user_id,hostname,agent_version,os_edition,cpu,ram_bytes,capabilities) VALUES (?,?,?,'integration','4.0.0','Windows','test',0,'[]')", [$tenant, $device, $user]);
            $db->run("INSERT INTO device_assignments (tenant_id,id,device_id,user_id,starts_at,reason) VALUES (?,?,?,?,UTC_TIMESTAMP(6)-INTERVAL 2 DAY,'integration')", [$tenant, Util::bin(Util::uuid()), $device, $user]);
            $db->run("INSERT INTO principals (tenant_id,id,kind,device_id) VALUES (?,?,'device',?)", [$tenant, Util::bin(Util::uuid()), $device]);
            $db->run('INSERT INTO device_keys (tenant_id,id,device_id,thumbprint,jwk_x,jwk_y) VALUES (?,?,?,?,?,?)', [$tenant, Util::bin(Util::uuid()), $device, $thumb, Util::unb64($public->public_key->x), Util::unb64($public->public_key->y)]);
            $enrollment = Util::bin(Util::uuid());
            $db->run("INSERT INTO enrollments (tenant_id,id,user_id,device_id,public_key_thumbprint,ticket_hash,status,reason,created_at,expires_at,consumed_at) VALUES (?,?,?,?,?,?,'consumed','integration',UTC_TIMESTAMP(6),UTC_TIMESTAMP(6)+INTERVAL 10 MINUTE,UTC_TIMESTAMP(6))", [$tenant, $enrollment, $user, $device, $thumb, random_bytes(32)]);
            $db->run('INSERT INTO device_sync_state (tenant_id,device_id,enrollment_id,updated_at) VALUES (?,?,?,UTC_TIMESTAMP(6))', [$tenant, $device, $enrollment]);
        });
        file_put_contents($root . '/fixture.json', Util::json(['tenant_id' => Util::id($tenant), 'device_id' => Util::id($device), 'keyid' => Util::b64($thumb)]));
        check(true, 'tenant + user + registered P-256 device provisioned');
    } elseif ($argv[1] === 'export-policy') {
        $fixture = json_decode(file_get_contents($root . '/fixture.json'), true, 32, JSON_THROW_ON_ERROR);
        $row = $db->one('SELECT * FROM effective_policies WHERE tenant_id=? AND device_id=? ORDER BY policy_version DESC LIMIT 1', [Util::bin($fixture['tenant_id']), Util::bin($fixture['device_id'])]);
        check($row !== null && $row['compiler_version'] === PolicyComposer::VERSION && hash_equals($row['content_hash'], hash('sha256', PolicyComposer::canonical(json_decode($row['document'], false, 32, JSON_THROW_ON_ERROR)), true)), 'PolicyCompiler persisted effective policy');
        $fixture['policy_version'] = (int)$row['policy_version'];
        $fixture['policy'] = json_decode($row['document'], false, 32, JSON_THROW_ON_ERROR);
        file_put_contents($root . '/fixture.json', Util::json($fixture));
    } elseif ($argv[1] === 'verify') {
        $result = json_decode(file_get_contents($root . '/result.json'), true, 32, JSON_THROW_ON_ERROR);
        $ids = [Util::bin($result['tenant_id']), Util::bin($result['device_id']), Util::bin($result['event_id'])];
        foreach (['episodes', 'episode_ingest_keys'] as $table) {
            check((int)$db->one("SELECT COUNT(*) n FROM $table WHERE tenant_id=? AND device_id=? AND event_id=?", $ids)['n'] === 1,
                "idempotency: exactly 1 row in $table after three batch requests");
        }
        check((int)$db->one('SELECT COUNT(*) n FROM episodes WHERE tenant_id=? AND device_id=?', array_slice($ids, 0, 2))['n'] === 1, 'no extra episodes persisted');
        check((int)$db->one('SELECT last_sequence FROM device_sync_state WHERE tenant_id=? AND device_id=?', array_slice($ids, 0, 2))['last_sequence'] === 2, 'two SyncClient sequences committed');
    } else { throw new RuntimeException('Unknown fixture command'); }
} catch (Throwable $error) {
    fwrite(STDERR, 'FAIL fixture: ' . $error->getMessage() . "\n");
    exit(1);
}
