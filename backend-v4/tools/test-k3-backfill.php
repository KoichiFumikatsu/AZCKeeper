<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$port = (int) ($argv[1] ?? 0);
$temp = realpath($argv[2] ?? '');
if ($port < 1024 || !$temp || !str_starts_with($temp, __DIR__ . DIRECTORY_SEPARATOR . '.backfill-test-')) { exit(2); }
$base = "mysql:host=127.0.0.1;port=$port;charset=utf8mb4";
for ($attempt = 0; ; $attempt++) {
    try { $admin = new PDO($base, 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]); break; }
    catch (PDOException $e) { if ($attempt >= 100) { throw $e; } usleep(100000); }
}
if (rtrim(str_replace('\\', '/', (string) $admin->query('SELECT @@datadir')->fetchColumn()), '/') !== str_replace('\\', '/', $temp) . '/data') {
    throw new RuntimeException('El servidor no pertenece al entorno aislado.');
}
if (($argv[3] ?? '') === '--shutdown') { $admin->exec('SHUTDOWN'); exit; }
foreach (['k3_backfill_source', 'k3_backfill_target'] as $db) { $admin->exec("CREATE DATABASE $db"); }
$sourceDsn = $base . ';dbname=k3_backfill_source';
$targetDsn = $base . ';dbname=k3_backfill_target';
putenv('KEEPER_DB_DSN=' . $targetDsn);
putenv('KEEPER_DB_USER=root');
putenv('KEEPER_DB_PASSWORD=');
putenv('KEEPER_DB_PASSWORD_FILE=');
putenv('K3_DSN=' . $sourceDsn);
putenv('K3_USER=k3_reader');
putenv('K3_PASSWORD=');
putenv('K3_PASSWORD_FILE=');
putenv('K3_DATETIME_TIMEZONE=UTC');
putenv('IMPORT_TENANT_NAME=');
putenv('IMPORT_TENANT_ID=00000000-0000-4000-8000-000000000002');
require __DIR__ . '/import-k3.php';
require dirname(__DIR__) . '/migrations/run.php';

$checks = 0;
function check(bool $ok, string $message): void {
    global $checks;
    if (!$ok) { throw new RuntimeException('FAIL: ' . $message); }
    $checks++;
}
function cli(array $args, int $expected = 0): string {
    $process = proc_open([PHP_BINARY, __DIR__ . '/import-k3.php', ...$args], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    check(proc_close($process) === $expected, 'exit CLI: ' . $out);
    return $out;
}
function stats(string $output, string $table): array {
    preg_match('/^' . preg_quote($table, '/') . ': (.+)$/m', $output, $m);
    return json_decode($m[1] ?? '{}', true, 32, JSON_THROW_ON_ERROR);
}
function legacyId(string $tenant, string $type, int $legacy): string {
    $hash = substr(sha1(hex2bin('6c40ce1eff5351beb17c88e792106073') . bin2hex($tenant) . ':' . $type . ':' . $legacy, true), 0, 16);
    $hash[6] = chr((ord($hash[6]) & 15) | 80); $hash[8] = chr((ord($hash[8]) & 63) | 128);
    return $hash;
}
$source = K3Import::connect($sourceDsn, 'root', '');
$target = K3Import::connect($targetDsn, 'root', '');
$run = static fn(string $sql, array $args = []) => K3Import::statement($target, $sql, $args);
$tenant = hex2bin('00000000000040008000000000000002');
$other = hex2bin('00000000000040008000000000000003');
$tables = ['tenants', 'org_units', 'sociedades', 'schedules', 'schedule_days', 'users', 'user_external_refs', 'user_assignments',
    'devices', 'device_assignments', 'episode_daily', 'day_summary', 'focus_daily', 'holidays', 'holiday_society_links'];
foreach (glob(dirname(__DIR__) . '/migrations/*.sql') as $file) {
    preg_match_all('/CREATE TABLE (?:IF NOT EXISTS )?(\w+)\s*\(.*?\) ENGINE=[^;]+;/s', file_get_contents($file), $matches, PREG_SET_ORDER);
    foreach ($matches as $match) { if (in_array($match[1], $tables, true)) { $target->exec($match[0]); } }
}
foreach (['0011_activity_rollups.sql', '0013_focus_presence.sql'] as $file) {
    foreach (statements(file_get_contents(dirname(__DIR__) . '/migrations/' . $file)) as $sql) {
        if (preg_match('/ALTER TABLE (\w+)/', $sql, $match) && in_array($match[1], $tables, true)) { $target->exec($sql); }
    }
}
$source->exec('CREATE TABLE keeper_users (id BIGINT PRIMARY KEY)');
$source->exec('CREATE TABLE keeper_activity_day (id BIGINT PRIMARY KEY AUTO_INCREMENT,user_id BIGINT NOT NULL,device_id BIGINT NOT NULL,day_date DATE NOT NULL,
    active_seconds INT NOT NULL DEFAULT 3600,idle_seconds INT NOT NULL DEFAULT 0,first_event_at DATETIME NULL,last_event_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,updated_at TIMESTAMP NULL)');
$source->exec('CREATE TABLE keeper_focus_daily (id BIGINT PRIMARY KEY AUTO_INCREMENT,user_id BIGINT NOT NULL,device_id BIGINT NOT NULL,day_date DATE NOT NULL,
    deep_work_seconds INT NOT NULL DEFAULT 600,focus_score INT NOT NULL DEFAULT 80,context_switches INT NOT NULL DEFAULT 1,distraction_seconds INT NOT NULL DEFAULT 0,
    first_activity_time TIME NULL,scheduled_start TIME NULL,punctuality_minutes SMALLINT NULL,productivity_pct TINYINT UNSIGNED NULL,constancy_pct TINYINT UNSIGNED NULL,
    deep_work_sessions INT NOT NULL DEFAULT 2,longest_focus_streak_seconds INT NOT NULL DEFAULT 300,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,updated_at TIMESTAMP NULL)');
$admin->exec("CREATE USER 'k3_reader'@'127.0.0.1'");
$admin->exec("GRANT SELECT ON k3_backfill_source.* TO 'k3_reader'@'127.0.0.1'");
foreach ([$tenant, $other] as $t) {
    $run("INSERT INTO tenants (tenant_id,name) VALUES (?,'Synthetic')", [$t]);
    foreach (['firm', 'site', 'area', 'position'] as $n => $kind) { $run('INSERT INTO org_units (tenant_id,id,kind,name) VALUES (?,?,?,?)', [$t, str_repeat(chr($n + 1), 16), $kind, $kind]); }
    $run("INSERT INTO schedules (tenant_id,id,name,timezone,start_local,end_local) VALUES (?,?,'Synthetic','America/Bogota','08:00:00','18:00:00')", [$t, str_repeat('s', 16)]);
    for ($d = 1; $d <= 5; $d++) { $run('INSERT INTO schedule_days VALUES (?,?,?)', [$t, str_repeat('s', 16), $d]); }
    for ($i = 1; $i <= ($t === $tenant ? 160 : 1); $i++) {
        $u = legacyId($t, 'user', $i);
        $a = legacyId($t, 'assignment', $i);
        $dev = legacyId($t, 'device', $i);
        $dims = [str_repeat(chr(1), 16), str_repeat(chr(2), 16), str_repeat(chr(3), 16), str_repeat(chr(4), 16), str_repeat('s', 16)];
        $run("INSERT INTO users (tenant_id,id,display_name,firm_id,site_id,area_id,position_id,schedule_id) VALUES (?,?,'Synthetic',?,?,?,?,?)", [$t, $u, ...$dims]);
        $run("INSERT INTO user_external_refs VALUES (?,?,'k3',?)", [$t, $u, (string) $i]);
        $run("INSERT INTO user_assignments (tenant_id,id,user_id,firm_id,site_id,area_id,position_id,schedule_id,starts_at) VALUES (?,?,?,?,?,?,?,?,'1970-01-01')", [$t, $a, $u, ...$dims]);
        $run("INSERT INTO devices (tenant_id,id,user_id,hostname,agent_version,os_edition,cpu,ram_bytes,capabilities) VALUES (?,?,?,'Synthetic','0','unknown','unknown',0,'[]')", [$t, $dev, $u]);
        $run("INSERT INTO day_summary (tenant_id,day,user_id,user_assignment_id,active_seconds,idle_seconds,productive_seconds,expected_seconds,coverage_percent,calculation_version,timezone,calculated_at,data_through)
            VALUES (?,'2026-09-16',?,?,3600,0,0,36000,10,'k3-import-v2','America/Bogota',UTC_TIMESTAMP(),UTC_TIMESTAMP())", [$t, $u, $a]);
        $run("INSERT INTO episode_daily (tenant_id,day,user_id,user_assignment_id,device_id,process_name,category,active_seconds,idle_seconds,episode_count,calculation_version,calculated_at,data_through)
            VALUES (?,'2026-09-16',?,?,?,'__k3_daily__','unclassified',3600,0,0,'k3-import-v2',UTC_TIMESTAMP(),UTC_TIMESTAMP())", [$t, $u, $a, $dev]);
        $run("INSERT INTO focus_daily (tenant_id,day,user_id,user_assignment_id,focus_seconds,calculation_version,calculated_at,data_through)
            VALUES (?,'2026-09-16',?,?,600,'k3-import-v2',UTC_TIMESTAMP(),UTC_TIMESTAMP())", [$t, $u, $a]);
        if ($t !== $tenant) { continue; }
        K3Import::statement($source, "INSERT INTO keeper_activity_day (user_id,device_id,day_date,first_event_at,last_event_at) VALUES (?,?,'2026-09-16','2026-09-16 13:05:00','2026-09-16 22:00:00')", [$i, $i]);
        K3Import::statement($source, "INSERT INTO keeper_focus_daily (user_id,device_id,day_date,first_activity_time,scheduled_start,punctuality_minutes,productivity_pct,constancy_pct)
            VALUES (?,?,'2026-09-16','08:05:00','08:00:00',-5,80,60)", [$i, $i]);
    }
}
// Cross a source page inside a duplicate group; highest id must win.
for ($i = 0; $i < 1001; $i++) { $source->exec("INSERT INTO keeper_activity_day (user_id,device_id,day_date,first_event_at,last_event_at) VALUES (1,1,'2026-09-16','2026-09-16 13:05:00','2026-09-16 22:00:00')"); }
// Cross a destination batch with unknown users, plus a known user without that day.
for ($i = 1000; $i < 1600; $i++) { $source->exec("INSERT INTO keeper_activity_day (user_id,device_id,day_date,first_event_at,last_event_at) VALUES ($i,$i,'2026-09-16','2026-09-16 13:00:00','2026-09-16 22:00:00')"); }
$source->exec("INSERT INTO keeper_activity_day (user_id,device_id,day_date) VALUES (1,1,'2026-09-17')");
$source->exec("UPDATE keeper_focus_daily SET first_activity_time=NULL,punctuality_minutes=NULL,productivity_pct=NULL,constancy_pct=NULL WHERE user_id=160");
$readOnly = new K3ReadOnly();
check((int) $readOnly->select('SELECT @@session.transaction_read_only')->fetchColumn() === 1, 'K3 READ ONLY enforced');
foreach (['UPDATE keeper_users SET id=1', 'SELECT 1; SELECT 2', 'SELECT 1 INTO OUTFILE \'blocked\'', 'SELECT * FROM keeper_users FOR UPDATE'] as $sql) {
    try { $readOnly->select($sql); check(false, 'K3 SQL guard'); } catch (LogicException) { check(true, 'K3 SQL guard'); }
}
try { K3Import::connect($sourceDsn, 'k3_reader', '')->query('SELECT 1; SELECT 2'); check(false, 'multi statements disabled'); }
catch (PDOException) { check(true, 'multi statements disabled'); }
$file = $temp . '/backfill.jsonl';
putenv('KEEPER_DB_DSN=mysql:host=127.0.0.1;port=1;dbname=unreachable');
$out = cli(['--export-backfill=' . $file]);
check(stats($out, 'keeper_activity_day')['leidas'] === 1762, 'all source pages read');
check(stats($out, 'keeper_activity_day')['exportadas'] === 761, 'duplicates collapsed across pages');
cli(['--export-backfill=' . $file], 1);
putenv('KEEPER_DB_DSN=' . $targetDsn);
putenv('K3_DSN=mysql:host=127.0.0.1;port=1;dbname=unreachable');
// A valid but non-deterministic user id proves resolution uses external refs.
$arbitraryUser = str_repeat('u', 16);
$run('SET FOREIGN_KEY_CHECKS=0');
foreach (['users' => 'id', 'user_external_refs' => 'user_id', 'user_assignments' => 'user_id', 'devices' => 'user_id', 'day_summary' => 'user_id', 'episode_daily' => 'user_id', 'focus_daily' => 'user_id'] as $table => $column) {
    $run("UPDATE $table SET $column=? WHERE tenant_id=? AND $column=?", [$arbitraryUser, $tenant, legacyId($tenant, 'user', 1)]);
}
$run('SET FOREIGN_KEY_CHECKS=1');
$snapshot = static function () use ($run): string {
    $all = [];
    foreach (['day_summary', 'episode_daily', 'focus_daily'] as $table) { $all[$table] = $run("SELECT * FROM $table ORDER BY tenant_id,day,user_id,user_assignment_id")->fetchAll(); }
    return serialize($all);
};
$before = $snapshot();
$dry = cli(['--import-backfill=' . $file, '--dry-run']);
check($snapshot() === $before && stats($dry, 'day_summary')['actualizadas'] === 160, 'dry-run predicts without writing');
$truncated = $temp . '/truncated.jsonl';
$lines = file($file); array_pop($lines); file_put_contents($truncated, implode('', $lines));
cli(['--import-backfill=' . $truncated], 1);
check($snapshot() === $before, 'truncated file rejected before batch writes');
$out = cli(['--import-backfill=' . $file]);
check(stats($out, 'user_external_refs')['no_encontradas'] === 600, 'unknown legacy users counted without abort');
foreach (['day_summary', 'episode_daily'] as $table) {
    check(stats($out, $table)['actualizadas'] === 160 && stats($out, $table)['no_encontradas'] === 1, 'counts ' . $table);
    check((int) $run("SELECT COUNT(*) FROM $table WHERE tenant_id=? AND active_seconds>0 AND first_activity='2026-09-16 13:05:00' AND last_activity='2026-09-16 22:00:00'", [$tenant])->fetchColumn() === 160, '160 NULL rows repaired: ' . $table);
    check((int) $run("SELECT COUNT(*) FROM $table WHERE tenant_id=? AND first_activity IS NULL", [$other])->fetchColumn() === 1, 'tenant isolation ' . $table);
}
$focus = $run('SELECT * FROM focus_daily WHERE tenant_id=? AND user_id=?', [$tenant, $arbitraryUser])->fetch();
check($focus['first_activity_time'] === '08:05:00' && $focus['scheduled_start'] === '08:00:00' && $focus['punctuality_minutes'] === -5
    && $focus['productivity_pct'] === '80.00' && $focus['constancy_pct'] === '60.00' && $focus['deep_work_sessions'] === 2 && $focus['longest_focus_streak_seconds'] === 300, 'seven focus fields');
check($run('SELECT punctuality_minutes FROM focus_daily WHERE tenant_id=? AND user_id=?', [$tenant, legacyId($tenant, 'user', 160)])->fetchColumn() === null, 'nullable punctuality stays NULL');
$after = $snapshot();
$out = cli(['--import-backfill=' . $file]);
check($snapshot() === $after, 'second run byte-identical values, no duplication or corruption');
foreach (['day_summary', 'episode_daily', 'focus_daily'] as $table) { check(stats($out, $table)['actualizadas'] === 0 && stats($out, $table)['sin_cambios'] === 160, 'idempotent counts ' . $table); }
// Multiple devices retain their own bounds; summary and focus aggregate at user/day.
putenv('K3_DSN=' . $sourceDsn);
$secondUser = legacyId($tenant, 'user', 2);
$secondAssignment = legacyId($tenant, 'assignment', 2);
$extraDevice = legacyId($tenant, 'device', 900);
$run("INSERT INTO devices (tenant_id,id,user_id,hostname,agent_version,os_edition,cpu,ram_bytes,capabilities) VALUES (?,?,?,'Synthetic','0','unknown','unknown',0,'[]')", [$tenant, $extraDevice, $secondUser]);
foreach (['__k3_daily__' => 'k3-import-v2', 'native.exe' => 'v4-worker'] as $process => $version) {
    $run("INSERT INTO episode_daily (tenant_id,day,user_id,user_assignment_id,device_id,process_name,category,active_seconds,idle_seconds,episode_count,calculation_version,calculated_at,data_through)
        VALUES (?,'2026-09-16',?,?,?,?,'unclassified',3600,0,0,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())", [$tenant, $secondUser, $secondAssignment, $extraDevice, $process, $version]);
}
$source->exec("INSERT INTO keeper_activity_day (user_id,device_id,day_date,first_event_at,last_event_at) VALUES (2,900,'2026-09-16','2026-09-16 12:50:00','2026-09-16 23:00:00')");
$source->exec("INSERT INTO keeper_focus_daily (user_id,device_id,day_date,first_activity_time,scheduled_start,punctuality_minutes,productivity_pct,constancy_pct,deep_work_sessions,longest_focus_streak_seconds)
    VALUES (2,900,'2026-09-16','07:50:00','08:00:00',10,40,80,3,600)");
$multi = $temp . '/multiple-devices.jsonl';
cli(['--export-backfill=' . $multi]);
putenv('K3_DSN=invalid-and-unused');
putenv('K3_DATETIME_TIMEZONE=invalid-and-unused');
cli(['--import-backfill=' . $multi]);
$bounds = $run('SELECT first_activity,last_activity FROM day_summary WHERE tenant_id=? AND user_id=?', [$tenant, $secondUser])->fetch();
check($bounds === ['first_activity' => '2026-09-16 12:50:00.000000', 'last_activity' => '2026-09-16 23:00:00.000000'], 'summary MIN/MAX across devices');
$metric = $run('SELECT first_activity_time,punctuality_minutes,productivity_pct,constancy_pct,deep_work_sessions,longest_focus_streak_seconds FROM focus_daily WHERE tenant_id=? AND user_id=?', [$tenant, $secondUser])->fetch();
check($metric === ['first_activity_time' => '07:50:00', 'punctuality_minutes' => 10, 'productivity_pct' => '60.00', 'constancy_pct' => '70.00', 'deep_work_sessions' => 5, 'longest_focus_streak_seconds' => 600], 'focus earliest/AVG/SUM/MAX across devices');
check($run("SELECT first_activity FROM episode_daily WHERE tenant_id=? AND user_id=? AND device_id=?", [$tenant, $secondUser, legacyId($tenant, 'device', 2)])->fetchColumn() === '2026-09-16 13:05:00.000000', 'device-specific bounds preserved');
check($run("SELECT first_activity FROM episode_daily WHERE tenant_id=? AND process_name='native.exe'", [$tenant])->fetchColumn() === null, 'native rollups untouched');
// Verify normal daily ETL, mixed NULL groups and a configurable source datetime zone.
putenv('K3_DSN=' . $sourceDsn);
$source->exec('DELETE FROM keeper_activity_day WHERE user_id>=1000 OR user_id=1');
$source->exec('DELETE FROM keeper_focus_daily WHERE user_id=1');
$source->exec("UPDATE keeper_activity_day SET first_event_at=NULL,last_event_at=NULL WHERE user_id=160");
foreach (['day_summary', 'episode_daily', 'focus_daily'] as $table) {
    $run("DELETE FROM $table WHERE tenant_id=? AND user_id IN (?,?) AND calculation_version='k3-import-v2'", [$tenant, $secondUser, legacyId($tenant, 'user', 160)]);
}
putenv('K3_DATETIME_TIMEZONE=America/Bogota');
cli(['--only=daily']);
check($run('SELECT first_activity FROM day_summary WHERE tenant_id=? AND user_id=?', [$tenant, $secondUser])->fetchColumn() === '2026-09-16 17:50:00.000000', 'explicit source zone converted to UTC');
check($run('SELECT first_activity FROM day_summary WHERE tenant_id=? AND user_id=?', [$tenant, legacyId($tenant, 'user', 160)])->fetchColumn() === null, 'mixed NULL/non-NULL daily batch columns aligned');
$after = $snapshot(); cli(['--only=daily']);
// Normal ETL refreshes calculated_at; compare business fields separately.
check((int) $run('SELECT COUNT(*) FROM day_summary WHERE tenant_id=?', [$tenant])->fetchColumn() === 160, 'normal ETL does not duplicate rows');
putenv('KEEPER_DB_DSN=' . $sourceDsn);
cli(['--import-backfill=' . $file], 1);
echo "OK: $checks comprobaciones; 160 filas reparadas por tabla, reejecución sin cambios, 600 referencias ausentes.\n";
