<?php
declare(strict_types=1);
use Keeper\{Database,ProductivityWorker,ProductivityWorkerLegacy,Util};

require_once __DIR__ . '/legacy/ProductivityWorkerLegacy.php';

// 2026-10-19 (flota K3 -> v4): el calculo de productividad y los envios de los equipos chocaban (deadlock 1213). El calculo
// tomaba rollup_days al empezar y tenants al insertar (llave foranea); el envio toma tenants al autenticar y rollup_days al
// marcar dias. Ahora el calculo prepara sin bloqueos y escribe en una transaccion corta: tenants primero, rollup_days al final
// con compare-and-set sobre input_revision.

/** Cliente aislado con 2 equipos, 3 dias de episodios (productivo, distraccion, sitio, app sospechosa, medianoche) y snapshots. */
function productivityBatchFixture(Database $db): array
{
    $u = secondTenant($db); $tenant = $u['tenant_id'];
    for ($w = 1; $w <= 7; $w++) { $db->run('INSERT INTO schedule_days (tenant_id,schedule_id,weekday) VALUES (?,?,?)', [$tenant, $u['schedule_id'], $w]); }
    $zone = new DateTimeZone('America/Bogota');
    $days = [];
    for ($i = 3; $i >= 1; $i--) { $days[] = (new DateTimeImmutable('today', $zone))->modify("-$i day")->format('Y-m-d'); }
    $devices = []; $keys = []; $tokens = [];
    foreach ([0, 1] as $i) {
        $keys[$i] = keypair(); $tokens[$i] = enroll($db, $u, $keys[$i]); policy($db, $tokens[$i]); $device = Util::bin($tokens[$i]['device_id']);
        $db->run('UPDATE device_assignments SET starts_at=? - INTERVAL 10 DAY WHERE tenant_id=? AND device_id=?', [$days[0], $tenant, $device]);
        $devices[] = [$device, $db->one('SELECT id FROM device_assignments WHERE tenant_id=? AND device_id=? ORDER BY starts_at DESC LIMIT 1', [$tenant, $device])['id']];
    }
    $db->run('UPDATE user_assignments SET starts_at=? - INTERVAL 10 DAY WHERE tenant_id=? AND user_id=?', [$days[0], $tenant, $u['id']]);
    $ua = $db->one('SELECT id FROM user_assignments WHERE tenant_id=? AND user_id=? AND ends_at IS NULL', [$tenant, $u['id']])['id'];
    $db->run("INSERT INTO app_classification (tenant_id,id,process_name,category) VALUES (?,?,'WINWORD','productive'),(?,?,'solitaire','unproductive')", [$tenant, Util::bin(Util::uuid()), $tenant, Util::bin(Util::uuid())]);
    $db->run("INSERT INTO suspicious_apps (tenant_id,id,app_pattern,category,description,active) VALUES (?,?,'anydesk','remote_desktop','',TRUE)", [$tenant, Util::bin(Util::uuid())]);
    $episode = function (int $device, string $localStart, int $minutes, string $process, int $idle = 0) use ($db, $tenant, $devices, $u, $ua, $zone): void {
        $a = (new DateTimeImmutable($localStart, $zone))->setTimezone(new DateTimeZone('UTC')); $b = $a->modify("+$minutes minutes");
        $db->run('INSERT INTO episodes (tenant_id,device_id,event_id,user_id,device_assignment_id,user_assignment_id,event_date,started_at,ended_at,process_name,active_seconds,idle_seconds,call_seconds,body_hash) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,0,?)',
            [$tenant, $devices[$device][0], Util::bin(Util::uuid()), $u['id'], $devices[$device][1], $ua, $a->format('Y-m-d'), $a->format('Y-m-d H:i:s'), $b->format('Y-m-d H:i:s'), $process, $minutes * 60 - $idle, $idle, random_bytes(32)]);
    };
    foreach ($days as $n => $d) {
        $episode(0, "$d 07:10", 40, 'WINWORD');            // antes de la jornada (cumplimiento: fuera de horario)
        $episode(0, "$d 08:00", 35 + $n, 'WINWORD');       // racha de foco
        $episode(0, "$d 08:40", 10, 'solitaire', 120);
        $episode(1, "$d 09:00", 25, 'anydesk.exe');         // app sospechosa
        $episode(1, "$d 10:00", 20, 'notepad.exe', 300);    // sin clasificar
        $episode(0, "$d 23:50", 20, 'WINWORD');            // cruza medianoche: se reparte entre dos dias
        $db->run('INSERT INTO activity_snapshots (tenant_id,device_id,day,snapshot_id,sequence,user_id,device_assignment_id,active_seconds,idle_seconds,body_hash,received_at) VALUES (?,?,?,?,1,?,?,?,1200,?,UTC_TIMESTAMP(6))',
            [$tenant, $devices[0][0], $d, Util::bin(Util::uuid()), $u['id'], $devices[0][1], 7200 + 60 * $n, random_bytes(32)]);
    }
    productivityMarkDirty($db, $tenant, $days);
    return ['user' => $u, 'tenant' => $tenant, 'tid' => Util::id($tenant), 'days' => $days, 'devices' => $devices, 'ua' => $ua, 'episode' => $episode, 'keys' => $keys, 'tokens' => $tokens];
}

function productivityMarkDirty(Database $db, string $tenant, array $days): void
{
    foreach ($days as $d) { $db->run('INSERT INTO rollup_days (tenant_id,day) VALUES (?,?) ON DUPLICATE KEY UPDATE dirty=TRUE,input_revision=input_revision+1,finalized_at=NULL', [$tenant, $d]); }
}

/** Filas de los resultados de un dia (o de todo) sin las columnas de hora de calculo. */
function productivitySnapshot(Database $db, ?string $tenant = null, ?string $day = null): array
{
    $out = [];
    foreach (['episode_daily', 'day_summary', 'focus_daily', 'suspicious_app_detections', 'dual_job_alerts'] as $table) {
        $where = []; $args = [];
        if ($tenant !== null) { $where[] = 'tenant_id=?'; $args[] = $tenant; }
        if ($day !== null) { $where[] = 'day=?'; $args[] = $day; }
        $rows = $db->run("SELECT * FROM $table" . ($where ? ' WHERE ' . implode(' AND ', $where) : ''), $args)->fetchAll();
        $rows = array_map(static function (array $r): string { unset($r['calculated_at'], $r['data_through']); ksort($r); return bin2hex(serialize($r)); }, $rows);
        sort($rows); $out[$table] = $rows;
    }
    return $out;
}

function productivityRunAll(Database $db, object $worker): float
{
    $start = microtime(true);
    for ($i = 0; $i < 20 && ($r = $worker->run(null, 366, 300))['days'] > 0; $i++) {}
    return microtime(true) - $start;
}

function productivityBatchTests(Database $db): void
{
    $before = $GLOBALS['checks'];

    // (a) Un envio que vuelve a marcar el dia mientras se calcula: el dia NO queda limpio con datos viejos.
    $fx = productivityBatchFixture($db); [$d1, $d2, $d3] = $fx['days']; $t = $fx['tenant'];
    $other = new Database(); $bumped = false; $revAtStart = null;
    $probe = function (string $point, string $tenant, string $day) use ($other, $fx, $d3, &$bumped, &$revAtStart): void {
        if ($point !== 'prepared' || $day !== $d3 || $bumped) { return; }
        $bumped = true;
        $revAtStart = (int) $other->one('SELECT input_revision FROM rollup_days WHERE tenant_id=? AND day=?', [$tenant, $day])['input_revision'];
        // Otra conexion (como un envio): episodio nuevo y dia marcado, confirmados mientras el calculo ya leyo los episodios.
        $a = (new DateTimeImmutable("$d3 11:00", new DateTimeZone('America/Bogota')))->setTimezone(new DateTimeZone('UTC'));
        $other->run('INSERT INTO episodes (tenant_id,device_id,event_id,user_id,device_assignment_id,user_assignment_id,event_date,started_at,ended_at,process_name,active_seconds,idle_seconds,call_seconds,body_hash) VALUES (?,?,?,?,?,?,?,?,?,\'late.exe\',600,0,0,?)',
            [$tenant, $fx['devices'][0][0], Util::bin(Util::uuid()), $fx['user']['id'], $fx['devices'][0][1], $fx['ua'], $a->format('Y-m-d'), $a->format('Y-m-d H:i:s'), $a->modify('+10 minutes')->format('Y-m-d H:i:s'), random_bytes(32)]);
        $other->run('INSERT INTO rollup_days (tenant_id,day,dirty,input_revision) VALUES (?,?,TRUE,1) ON DUPLICATE KEY UPDATE dirty=TRUE,input_revision=input_revision+VALUES(input_revision),finalized_at=NULL', [$tenant, $day]);
    };
    $r = (new ProductivityWorker($db, $probe))->run($fx['tid'], 3, 60);
    // Por referencia: el cliente ($t) cambia en cada parte de la prueba.
    $state = function (string $d) use ($db, &$t): ?array { return $db->one('SELECT dirty,input_revision,calculated_revision,calculation_version FROM rollup_days WHERE tenant_id=? AND day=?', [$t, $d]); };
    $s3 = $state($d3);
    check($bumped && $r['days'] === 3 && $r['requeued'] === 1, 're-marked day counted as requeued ' . json_encode($r));
    check((int) $s3['dirty'] === 1 && (int) $s3['calculated_revision'] === $revAtStart && (int) $s3['input_revision'] === $revAtStart + 1, 're-marked day stays dirty, calculated_revision = revision read at start ' . json_encode($s3));
    check((int) $db->one("SELECT COUNT(*) n FROM episode_daily WHERE tenant_id=? AND day=? AND process_name='late.exe'", [$t, $d3])['n'] === 0
        && (int) $db->one('SELECT COUNT(*) n FROM day_summary WHERE tenant_id=? AND day=?', [$t, $d3])['n'] === 1, 'written data is the complete calculation of the revision read (late episode not in it)');
    foreach ([$d1, $d2] as $d) { $s = $state($d); check((int) $s['dirty'] === 0 && $s['calculated_revision'] === $s['input_revision'], "untouched day $d clean"); }
    $r = (new ProductivityWorker($db))->run($fx['tid'], 5, 60);
    $s3 = $state($d3);
    check($r['days'] === 1 && $r['requeued'] === 0 && (int) $s3['dirty'] === 0 && $s3['calculated_revision'] === $s3['input_revision'], 'next run closes the re-marked day');
    check((int) $db->one("SELECT COUNT(*) n FROM episode_daily WHERE tenant_id=? AND day=? AND process_name='late.exe'", [$t, $d3])['n'] === 1, 'next run includes the late episode');

    // (b) Un fallo a mitad de un dia: ese dia queda como estaba (ni a medias ni limpio) y los dias ya cerrados quedan bien.
    $fx = productivityBatchFixture($db); [$d1, $d2, $d3] = $fx['days']; $t = $fx['tenant'];
    (new ProductivityWorker($db))->run($fx['tid'], 5, 60);
    foreach ($fx['days'] as $d) { ($fx['episode'])(1, "$d 14:00", 15, 'excel.exe'); }
    productivityMarkDirty($db, $t, $fx['days']);
    $old2 = productivitySnapshot($db, $t, $d2); $old3 = productivitySnapshot($db, $t, $d3);
    $probe = function (string $point, string $tenant, string $day) use ($d2): void { if ($point === 'written' && $day === $d2) { throw new RuntimeException('falla simulada'); } };
    try { (new ProductivityWorker($db, $probe))->run($fx['tid'], 5, 60); check(false, 'simulated failure must propagate'); }
    catch (RuntimeException $e) { check($e->getMessage() === 'falla simulada', 'failure propagates to the cron'); }
    check($db->one('SELECT status FROM productivity_cron_status WHERE tenant_id=?', [$t])['status'] === 'failed', 'cron status records the failure');
    $s1 = $state($d1);
    check((int) $s1['dirty'] === 0 && $s1['calculated_revision'] === $s1['input_revision']
        && (int) $db->one("SELECT COUNT(*) n FROM episode_daily WHERE tenant_id=? AND day=? AND process_name='excel.exe'", [$t, $d1])['n'] === 1, 'day closed before the failure stays committed and complete');
    $now2 = productivitySnapshot($db, $t, $d2);
    $differs = array_keys(array_filter($old2, fn ($rows, $table) => $rows !== $now2[$table], ARRAY_FILTER_USE_BOTH));
    check($differs === [] && (int) $state($d2)['dirty'] === 1, 'failed day rolled back entirely (no half day) and stays dirty ' . json_encode([$differs, $state($d2)]));
    check(productivitySnapshot($db, $t, $d3) === $old3 && (int) $state($d3)['dirty'] === 1, 'day after the failure untouched');
    check(!$db->one("SELECT 1 FROM information_schema.INNODB_TRX WHERE trx_mysql_thread_id=CONNECTION_ID()"), 'no transaction left open after the failure');
    $r = (new ProductivityWorker($db))->run($fx['tid'], 5, 60);
    check($r['days'] === 2 && (int) $state($d2)['dirty'] === 0 && (int) $state($d3)['dirty'] === 0
        && (int) $db->one("SELECT COUNT(*) n FROM episode_daily WHERE tenant_id=? AND day IN (?,?) AND process_name='excel.exe'", [$t, $d2, $d3])['n'] === 2, 'next run recovers the pending days');

    // Ingest: los dias se marcan juntos al final del request y input_revision sube uno por episodio aceptado, como antes.
    $zone = new DateTimeZone('America/Bogota'); $now = time();
    $eps = []; $expected = [];
    foreach ([7200, 5400, 3600] as $ago) {
        $eps[] = ['event_id' => Util::uuid(), 'started_at' => gmdate('Y-m-d\TH:i:s\Z', $now - $ago), 'ended_at' => gmdate('Y-m-d\TH:i:s\Z', $now - $ago + 600), 'process_name' => 'ingest.exe', 'active_seconds' => 500, 'idle_seconds' => 100];
        $local = (new DateTimeImmutable('@' . ($now - $ago)))->setTimezone($zone)->format('Y-m-d'); $expected[$local] = ($expected[$local] ?? 0) + 1;
    }
    $bad = $eps[0]; $bad['event_id'] = Util::uuid(); $bad['active_seconds'] = 601;
    $revisions = fn () => array_column($db->run('SELECT day,input_revision FROM rollup_days WHERE tenant_id=?', [$t])->fetchAll(), 'input_revision', 'day');
    $was = $revisions();
    $acks = expect(request('POST', '/client/episodes:batch', ['episodes' => [$eps[0], $bad, $eps[1]]], $fx['keys'][0], $fx['tokens'][0]['access_token'], Util::uuid()), 200, 'episode batch marks days')['acks'];
    check(array_column($acks, 'status') === ['accepted', 'rejected', 'accepted'], 'batch acks');
    $sync = ['protocol_version' => 1, 'sequence' => 1, 'policy_version' => 1, 'release_id' => null, 'episodes' => [$eps[2]]];
    check(expect(request('POST', '/client/sync', $sync, $fx['keys'][0], $fx['tokens'][0]['access_token'], Util::uuid()), 200, 'sync marks days')['episode_acks'][0]['status'] === 'accepted', 'sync episode accepted');
    $now2 = $revisions(); $delta = [];
    foreach ($now2 as $d => $rev) { if ((int) $rev !== (int) ($was[$d] ?? 0)) { $delta[$d] = (int) $rev - (int) ($was[$d] ?? 0); } }
    ksort($delta); ksort($expected);
    check($delta === $expected, 'input_revision +1 per accepted episode per day, rejected one excluded ' . json_encode([$delta, $expected]));
    check(!$db->one('SELECT 1 FROM rollup_days WHERE tenant_id=? AND day IN (' . implode(',', array_fill(0, count($expected), '?')) . ') AND (dirty=FALSE OR finalized_at IS NOT NULL)', [$t, ...array_keys($expected)]), 'marked days dirty and not finalized');

    // (c) Mismos resultados que el metodo anterior: corrida completa con el calculo viejo (copia congelada) y con el nuevo
    // sobre todos los clientes de la base de pruebas.
    $markAll = fn () => $db->run('UPDATE rollup_days r JOIN retention_settings s ON s.tenant_id=r.tenant_id SET r.dirty=TRUE,r.input_revision=r.input_revision+1,r.finalized_at=NULL WHERE r.day>=UTC_DATE()-INTERVAL s.raw_days DAY AND r.day>=DATE_SUB(UTC_DATE(),INTERVAL s.aggregate_months MONTH)');
    $markAll(); $pending = (int) $db->one('SELECT COUNT(*) n FROM rollup_days WHERE dirty=TRUE')['n'];
    $legacyTime = productivityRunAll($db, new ProductivityWorkerLegacy($db));
    $legacy = productivitySnapshot($db);
    $markAll();
    $writes = []; $mark = 0.0;
    $timing = function (string $point) use (&$writes, &$mark): void { if ($point === 'prepared') { $mark = microtime(true); } else { $writes[] = microtime(true) - $mark; } };
    $newTime = productivityRunAll($db, new ProductivityWorker($db, $timing));
    $new = productivitySnapshot($db);
    $rows = array_sum(array_map('count', $new));
    foreach ($legacy as $table => $rowsLegacy) { check($rowsLegacy === $new[$table], "same $table as the previous method (" . count($rowsLegacy) . ' vs ' . count($new[$table]) . ' rows)'); }
    check($rows > 50 && count($writes) >= 6, "comparison covers real data ($rows rows, " . count($writes) . ' days)');
    check(!$db->one('SELECT 1 FROM rollup_days r JOIN retention_settings s ON s.tenant_id=r.tenant_id WHERE (r.dirty=TRUE OR r.calculated_revision<>r.input_revision) AND r.day>=UTC_DATE()-INTERVAL s.raw_days DAY AND r.day>=DATE_SUB(UTC_DATE(),INTERVAL s.aggregate_months MONTH)'), 'every day clean after the new run');
    sort($writes);
    printf("  comparison: %d days, %d rows; legacy %.2f s, new %.2f s; write phase (prepared->written) median %.1f ms, max %.1f ms\n",
        $pending, $rows, $legacyTime, $newTime, 1000 * $writes[intdiv(count($writes), 2)], 1000 * max($writes));

    // (d) Concurrencia real: un envio (tenants FOR UPDATE como la autenticacion anterior a Auth::lockDevice, luego marca el dia) y el calculo en otro
    // proceso. Con el calculo viejo hay deadlock; con el nuevo el envio pasa y el dia queda pendiente.
    $legacyRace = productivityRace($db, $fx, 'legacy');
    check($legacyRace['midway'] && $legacyRace['waited'] && ($legacyRace['sync'] === 1213 || ($legacyRace['calc']['driver'] ?? 0) === 1213), 'previous method deadlocks against a sync ' . json_encode($legacyRace));
    $newRace = productivityRace($db, $fx, 'new');
    check($newRace['midway'] && $newRace['waited'] && $newRace['sync'] === null && isset($newRace['calc']['ok']) && $newRace['calc']['ok']['requeued'] === 1, 'new method: sync commits, calculation waits and keeps the day pending ' . json_encode($newRace));
    // El mismo run vuelve a tomar el dia (sigue pendiente) y lo cierra ya con la revision del envio.
    $s = $state($fx['days'][2]);
    check($newRace['calc']['ok']['days'] === 2 && (int) $s['dirty'] === 0 && $s['calculated_revision'] === $s['input_revision'], 'requeued day recalculated with the sync revision in the same run ' . json_encode($s));
    printf("  race: legacy sync=%s calc=%s; new sync=%s calc=%s\n", var_export($legacyRace['sync'], true), json_encode($legacyRace['calc']), var_export($newRace['sync'], true), json_encode($newRace['calc']));
    echo 'PASS productivity short transactions (' . ($GLOBALS['checks'] - $before) . " assertions)\n";
}

/**
 * Envio simulado + calculo (viejo o nuevo) en un proceso aparte, sobre el ultimo dia del fixture. Intercalado: el calculo va a
 * mitad de camino, el envio toma la fila de tenants (como la autenticacion anterior, ... FOR UPDATE), el calculo sigue hasta pedir
 * tenants y el envio marca el dia (INSERT ... rollup_days de Ingest::flushDays).
 *  - viejo: "a mitad de camino" = ya tiene rollup_days FOR UPDATE; se lo frena antes de su DELETE de episode_daily con otra
 *    conexion que tiene esas filas FOR UPDATE.
 *  - nuevo: "a mitad de camino" = termino de preparar (sin bloqueos); el proceso hijo espera un GET_LOCK en ese punto.
 */
function productivityRace(Database $db, array $fx, string $kind): array
{
    $day = $fx['days'][2]; $t = $fx['tenant'];
    $db->run('UPDATE rollup_days SET dirty=FALSE WHERE tenant_id=?', [$t]);
    productivityMarkDirty($db, $t, [$day]);
    // Otra transaccion (el proceso hijo) esperando un bloqueo; devuelve su sentencia (puede venir vacia en servidores que
    // no la exponen para sentencias preparadas).
    $waiting = function () use ($db): ?string {
        for ($i = 0; $i < 40; $i++) {
            $row = $db->one("SELECT COALESCE(trx_query,'') q FROM information_schema.INNODB_TRX WHERE trx_state='LOCK WAIT' LIMIT 1");
            if ($row) { return $row['q']; }
            usleep(150000); // INNODB_TRX solo se refresca si nadie lo leyo en los ultimos 100 ms
        }
        return null;
    };
    $gate = new Database(); $sync = new Database();
    $sync->run('SET SESSION innodb_lock_wait_timeout=10');
    if ($kind === 'legacy') {
        $gate->pdo->beginTransaction();
        check((bool) $gate->run('SELECT 1 FROM episode_daily WHERE tenant_id=? AND day=? FOR UPDATE', [$t, $day])->fetchAll(), 'race fixture has episode_daily rows to hold');
    } else {
        $gate->one("SELECT GET_LOCK('productivity-race',0) g");
    }
    $child = proc_open([PHP_BINARY, __DIR__ . '/productivity-child.php', $kind, $fx['tid']], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $midway = null;
    if ($kind === 'legacy') { $midway = $waiting(); }
    else { for ($i = 0; $i < 200 && $midway === null; $i++) { usleep(25000); $midway = $db->one("SELECT 1 FROM information_schema.PROCESSLIST WHERE INFO LIKE '%productivity-race%' AND ID<>CONNECTION_ID() AND ID<>?", [(int) $gate->one('SELECT CONNECTION_ID() id')['id']]) ? 'GET_LOCK' : null; } }
    $sync->pdo->beginTransaction();
    $sync->run('SELECT d.id FROM devices d JOIN tenants t ON t.tenant_id=d.tenant_id JOIN users u ON u.tenant_id=d.tenant_id AND u.id=d.user_id WHERE d.tenant_id=? AND d.id=? FOR UPDATE', [$t, $fx['devices'][0][0]]);
    if ($kind === 'legacy') { $gate->pdo->commit(); } else { $gate->one("SELECT RELEASE_LOCK('productivity-race') r"); }
    usleep(300000); // el hijo sale de la espera anterior y llega a pedir tenants (milisegundos con estos datos)
    $waited = $waiting();
    $syncError = null;
    try {
        $sync->run('INSERT INTO rollup_days (tenant_id,day,dirty,input_revision) VALUES (?,?,TRUE,1) ON DUPLICATE KEY UPDATE dirty=TRUE,input_revision=input_revision+VALUES(input_revision),finalized_at=NULL', [$t, $day]);
        $sync->pdo->commit();
    } catch (PDOException $e) {
        $syncError = (int) ($e->errorInfo[1] ?? 0);
        if ($sync->pdo->inTransaction()) { $sync->pdo->rollBack(); }
    }
    $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]); proc_close($child);
    return ['midway' => $midway !== null, 'waited' => $waited !== null, 'waits' => [substr((string) $midway, 0, 40), substr((string) $waited, 0, 40)], 'sync' => $syncError, 'calc' => json_decode($out, true) ?? ['raw' => $out . $err], 'err' => $err];
}
