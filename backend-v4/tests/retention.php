<?php
declare(strict_types=1);
use Keeper\{Database,RetentionWorker,Util};

// Retención aplicada (2026-10-07): la revisión nocturna cuenta lo que quedó fuera de los plazos y solo lo borra donde
// el cliente la tiene encendida; nunca lo que aún puede llegar tarde, nunca auditoría ni nada fuera de las 8 tablas.

/** Filas viejas (80 días), dentro de la ventana de llegada tardía (35/41 días) y recientes (1 día) de un cliente. */
function retentionSeed(Database $db): array
{
    $u = secondTenant($db); $t = $u['tenant_id'];
    $db->run('UPDATE user_assignments SET starts_at=UTC_TIMESTAMP(6)-INTERVAL 150 DAY WHERE tenant_id=?', [$t]);
    $ua = $db->one('SELECT id FROM user_assignments WHERE tenant_id=? AND user_id=?', [$t, $u['id']])['id'];
    $device = Util::bin(Util::uuid()); $da = Util::bin(Util::uuid());
    $db->run("INSERT INTO devices (tenant_id,id,user_id,hostname,agent_version,os_edition,cpu,ram_bytes,capabilities) VALUES (?,?,?,'retention','4.0','test','test',1,'[]')", [$t, $device, $u['id']]);
    $db->run("INSERT INTO device_assignments (tenant_id,id,device_id,user_id,starts_at,reason) VALUES (?,?,?,?,UTC_TIMESTAMP(6)-INTERVAL 150 DAY,'retention')", [$t, $da, $device, $u['id']]);
    // Para sembrar episodios de hace 80 días la ventana de llegada tardía tiene que admitirlos (máximo 90).
    $db->run('UPDATE retention_settings SET late_arrival_days=90,raw_days=90 WHERE tenant_id=?', [$t]);
    foreach ([80, 41, 1] as $ago) {
        $day = gmdate('Y-m-d', time() - $ago * 86400);
        $db->run("INSERT INTO episodes (tenant_id,device_id,event_id,user_id,device_assignment_id,user_assignment_id,event_date,started_at,ended_at,process_name,active_seconds,idle_seconds,body_hash)
            VALUES (?,?,?,?,?,?,?,?,?,'app',50,10,?)", [$t, $device, Util::bin(Util::uuid()), $u['id'], $da, $ua, $day, "$day 13:00:00", "$day 13:01:00", random_bytes(32)]);
        $db->run("INSERT INTO activity_snapshots (tenant_id,device_id,day,snapshot_id,sequence,user_id,device_assignment_id,active_seconds,idle_seconds,body_hash,received_at) VALUES (?,?,?,?,1,?,?,60,0,?,UTC_TIMESTAMP(6))",
            [$t, $device, $day, Util::bin(Util::uuid()), $u['id'], $da, random_bytes(32)]);
    }
    foreach ([80, 35, 1] as $ago) {
        $day = gmdate('Y-m-d', time() - $ago * 86400);
        $db->run("INSERT INTO day_summary (tenant_id,day,user_id,user_assignment_id,active_seconds,idle_seconds,productive_seconds,expected_seconds,coverage_percent,productivity_percent,calculation_version,timezone,calculated_at,data_through)
            VALUES (?,?,?,?,50,10,50,32400,1,100,'retention','America/Bogota',UTC_TIMESTAMP(),UTC_TIMESTAMP())", [$t, $day, $u['id'], $ua]);
        $db->run("INSERT INTO focus_daily (tenant_id,day,user_id,user_assignment_id,focus_seconds,focus_score,calculation_version,calculated_at,data_through) VALUES (?,?,?,?,50,100,'retention',UTC_TIMESTAMP(),UTC_TIMESTAMP())", [$t, $day, $u['id'], $ua]);
        $db->run("INSERT INTO episode_daily (tenant_id,day,user_id,user_assignment_id,device_id,process_name,category,active_seconds,idle_seconds,episode_count,calculation_version,calculated_at,data_through)
            VALUES (?,?,?,?,?,'app','productive',50,10,1,'retention',UTC_TIMESTAMP(),UTC_TIMESTAMP())", [$t, $day, $u['id'], $ua, $device]);
        $db->run('INSERT INTO rollup_days (tenant_id,day,dirty,input_revision,calculated_revision) VALUES (?,?,FALSE,1,1)', [$t, $day]);
        $db->run("INSERT INTO client_logs (tenant_id,device_id,event_id,at,level,code,component,body_hash) VALUES (?,?,?,?,'info','retention.probe','Test',?)",
            [$t, $device, Util::bin(Util::uuid()), "$day 10:00:00", random_bytes(32)]);
    }
    // Plazos de la prueba: detalle 40 días, resúmenes 1 mes, registros 10 días, llegada tardía 40 (corte = hoy - 42).
    $db->run('UPDATE retention_settings SET late_arrival_days=40,raw_days=40,aggregate_months=1,log_days=10 WHERE tenant_id=?', [$t]);
    return $u + ['device' => $device];
}

/** Filas por tabla de un cliente, separadas en viejas (más de 60 días) y el resto. */
function retentionCounts(Database $db, string $t): array
{
    $old = gmdate('Y-m-d', time() - 60 * 86400); $out = [];
    foreach (['episodes' => 'event_date', 'episode_ingest_keys' => 'event_date', 'activity_snapshots' => 'day', 'episode_daily' => 'day', 'focus_daily' => 'day', 'day_summary' => 'day', 'rollup_days' => 'day', 'client_logs' => 'at'] as $table => $col) {
        $r = $db->one("SELECT SUM($col<?) o,SUM($col>=?) k FROM $table WHERE tenant_id=?", [$old, $old, $t]);
        $out[$table] = [(int) $r['o'], (int) $r['k']];
    }
    foreach (['audit_log', 'users', 'devices', 'device_assignments', 'user_assignments', 'org_units', 'principals', 'retention_settings'] as $table) {
        $out[$table] = (int) $db->one("SELECT COUNT(*) n FROM $table WHERE tenant_id=?", [$t])['n'];
    }
    return $out;
}

function retentionCli(array $args): array
{
    $process = proc_open([PHP_BINARY, dirname(__DIR__) . '/config/retention-cron.php', ...$args], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]); $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
    return [proc_close($process), $out, $err];
}

function retentionTests(Database $db): void
{
    $before = $GLOBALS['checks'];
    $a = retentionSeed($db); $b = retentionSeed($db);
    $ta = $a['tenant_id']; $tb = $b['tenant_id']; $ida = Util::id($ta);
    $seeded = ['episodes' => [1, 2], 'episode_ingest_keys' => [1, 2], 'activity_snapshots' => [1, 2], 'episode_daily' => [1, 2], 'focus_daily' => [1, 2], 'day_summary' => [1, 2], 'rollup_days' => [1, 2], 'client_logs' => [1, 2]];
    $initialA = retentionCounts($db, $ta); $initialB = retentionCounts($db, $tb);
    check(array_intersect_key($initialA, $seeded) === $seeded && array_intersect_key($initialB, $seeded) === $seeded, 'retention seed: one old row and two kept rows per table');
    check((int) $db->one('SELECT enforce FROM retention_settings WHERE tenant_id=?', [$ta])['enforce'] === 0, 'enforce defaults OFF');

    // 1) Revisión con la retención apagada: no borra nada y deja los conteos.
    $worker = new RetentionWorker($db);
    $dry = $worker->run($ta)[0];
    check($dry['mode'] === 'dry_run' && $dry['error'] === null && $dry['complete'] === true, 'dry run recorded: ' . Util::json($dry));
    check($dry['raw_rows'] === 2 && $dry['raw_days'] === 1 && $dry['aggregate_rows'] === 3 && $dry['aggregate_days'] === 1 && $dry['log_rows'] === 1
        && $dry['tables']['episode_ingest_keys'] === 1 && $dry['tables']['rollup_days'] === 1, 'dry run counts only what is past its window (late-arrival rows excluded)');
    check(retentionCounts($db, $ta) === $initialA, 'dry run deletes nothing');
    $row = $db->one('SELECT * FROM retention_runs WHERE tenant_id=? ORDER BY started_at DESC LIMIT 1', [$ta]);
    check($row['mode'] === 'dry_run' && $row['finished_at'] !== null && (int) $row['raw_rows'] === 2 && (int) $row['log_rows'] === 1 && $row['error'] === null
        && json_decode($row['table_rows'], true)['episodes'] === 1, 'dry run row: mode, counts per kind and per table');
    check($row['raw_before'] === gmdate('Y-m-d', time() - 42 * 86400) && $row['aggregate_before'] === gmdate('Y-m-d', time() - 42 * 86400), 'cutoffs never inside late_arrival_days + 2');

    // 2) API: lectura con la última revisión; permisos y reautenticación para encender.
    $editor = adminFixture($db, $a, ['operacion.ajustes_ver', 'operacion.ajustes_editar']);
    $viewer = adminFixture($db, adminMember($db, $a), ['operacion.ajustes_ver']);
    $scoped = adminFixture($db, adminMember($db, $a), ['operacion.ajustes_ver', 'operacion.ajustes_editar'], false, 'site', [$a['site_id']]);
    $other = adminFixture($db, $b, ['operacion.ajustes_ver', 'operacion.ajustes_editar']);
    $cfg = expect(adminRequest($viewer, 'GET', '/tenants/panel-settings'), 200, 'viewer reads settings');
    check($cfg['retention_enforce'] === false && $cfg['retention_last_run']['mode'] === 'dry_run' && $cfg['retention_last_run']['raw_days'] === 1
        && $cfg['retention_last_run']['log_rows'] === 1 && $cfg['retention_last_run']['failed'] === false, 'settings show the switch and the last run');
    check(expect(adminRequest($other, 'GET', '/tenants/panel-settings'), 200, 'other tenant reads settings')['retention_last_run'] === null, 'last run is per tenant');
    $on = ['install_coverage_heartbeat_days' => 7, 'retention_enforce' => true];
    check(expect(adminRequest($viewer, 'PUT', '/tenants/panel-settings', $on), 403, 'viewer cannot switch')['code'] === 'permission_denied', 'switch needs ajustes_editar');
    expect(adminRequest($scoped, 'PUT', '/tenants/panel-settings', $on), 403, 'scoped editor cannot switch');
    check(expect(adminRequest($editor, 'PUT', '/tenants/panel-settings', $on), 403, 'enabling needs reauth')['code'] === 'reauth_required', 'enable: reauth_required');
    check((int) $db->one('SELECT enforce FROM retention_settings WHERE tenant_id=?', [$ta])['enforce'] === 0, 'refused enable leaves it OFF');
    expect(adminRequest($editor, 'POST', '/auth/reauth', ['password' => $editor['password']]), 200, 'reauth for retention');
    check(expect(adminRequest($editor, 'PUT', '/tenants/panel-settings', $on), 200, 'enable after reauth')['retention_enforce'] === true, 'enable saved');
    $db->run('UPDATE admin_sessions SET reauthenticated_at=UTC_TIMESTAMP(6)-INTERVAL 301 SECOND WHERE tenant_id=? AND admin_id=?', [$ta, $editor['id']]);
    check(expect(adminRequest($editor, 'PUT', '/tenants/panel-settings', ['install_coverage_heartbeat_days' => 8]), 200, 'other settings with stale reauth')['retention_enforce'] === true, 'saving other settings keeps it ON without reauth');
    check(expect(adminRequest($editor, 'PUT', '/tenants/panel-settings', $on), 200, 'already ON needs no reauth')['retention_enforce'] === true, 'keeping it ON needs no reauth');
    check(expect(adminRequest($editor, 'PUT', '/tenants/panel-settings', ['install_coverage_heartbeat_days' => 7, 'retention_enforce' => false]), 200, 'disable without reauth')['retention_enforce'] === false, 'disabling needs no reauth');
    expect(adminRequest($editor, 'PUT', '/tenants/panel-settings', $on), 403, 'enabling again needs fresh reauth');
    expect(adminRequest($editor, 'POST', '/auth/reauth', ['password' => $editor['password']]), 200, 'reauth again');
    expect(adminRequest($editor, 'PUT', '/tenants/panel-settings', $on), 200, 'enable again');
    check((int) $db->one('SELECT enforce FROM retention_settings WHERE tenant_id=?', [$tb])['enforce'] === 0, 'switch is per tenant');
    $audit = $db->one("SELECT changed_fields FROM audit_log WHERE tenant_id=? AND action='putPanelSettings' ORDER BY at DESC,id LIMIT 1", [$ta]);
    check($audit !== null && str_contains((string) $audit['changed_fields'], 'retention_enforce'), 'switch change is audited');

    // 3) Lock: con otra revisión en curso no corre nada (clase y CLI).
    $holder = new Database(); $lock = $worker->lockName();
    check((int) $holder->one('SELECT GET_LOCK(?,0) l', [$lock])['l'] === 1, 'test holds the retention lock');
    $runs = (int) $db->one('SELECT COUNT(*) n FROM retention_runs WHERE tenant_id=?', [$ta])['n'];
    check($worker->run($ta) === null, 'overlapping run refused (class)');
    [$code, , $err] = retentionCli(['--tenant=' . $ida]);
    check($code === 3 && str_contains($err, 'retention_already_running'), 'overlapping run refused (CLI exit 3)');
    check((int) $db->one('SELECT COUNT(*) n FROM retention_runs WHERE tenant_id=?', [$ta])['n'] === $runs && retentionCounts($db, $ta)['episodes'] === [1, 2], 'refused run records and deletes nothing');
    $holder->run('DO RELEASE_LOCK(?)', [$lock]);

    // 4) Tiempo agotado: aplicada pero sin tiempo, no borra y queda pendiente.
    $late = $worker->run($ta, 5000, 0)[0];
    check($late['mode'] === 'applied' && $late['complete'] === false && $late['raw_rows'] === 0 && retentionCounts($db, $ta)['episodes'] === [1, 2], 'time-boxed run stops before deleting');
    check(expect(adminRequest($editor, 'GET', '/tenants/panel-settings'), 200, 'read pending run')['retention_last_run']['complete'] === false, 'pending run visible');

    // 5) Aplicada (CLI, lotes de 1 fila para recorrer el bucle): solo las viejas de ese cliente.
    $auditBefore = (int) $db->one('SELECT COUNT(*) n FROM audit_log')['n']; $beforeApply = retentionCounts($db, $ta);
    [$code, $out, $err] = retentionCli(['--tenant=' . $ida, '--batch=1']);
    $res = json_decode($out, true)[0] ?? [];
    check($code === 0 && $err === '' && $res['mode'] === 'applied' && $res['complete'] === true, 'applied CLI run: ' . $out . $err);
    check($res['raw_rows'] === 2 && $res['aggregate_rows'] === 3 && $res['log_rows'] === 1 && $res['tables']['episode_ingest_keys'] === 1 && $res['tables']['rollup_days'] === 1, 'applied run reports what it deleted');
    $after = retentionCounts($db, $ta);
    foreach ($seeded as $table => $_) { check($after[$table] === [0, 2], "enforce deleted only old $table (late-arrival and recent kept)"); }
    foreach (['audit_log', 'users', 'devices', 'device_assignments', 'user_assignments', 'org_units', 'principals', 'retention_settings'] as $table) { check($after[$table] === $beforeApply[$table], "$table untouched"); }
    check((int) $db->one('SELECT COUNT(*) n FROM audit_log')['n'] === $auditBefore, 'audit_log untouched by the run');
    check(array_intersect_key(retentionCounts($db, $tb), $seeded) === $seeded, 'other tenant untouched');
    $last = expect(adminRequest($editor, 'GET', '/tenants/panel-settings'), 200, 'read applied run')['retention_last_run'];
    check($last['mode'] === 'applied' && $last['raw_rows'] === 2 && $last['complete'] === true, 'settings show what was deleted');
    check(retentionCli(['--tenant=' . $ida])[0] === 0 && $worker->run($ta)[0]['raw_rows'] === 0, 'second applied run finds nothing');

    // 6) Todos los clientes: el apagado solo cuenta.
    $all = $worker->run();
    $byTenant = array_column($all, null, 'tenant_id');
    check(isset($byTenant[Util::id($tb)]) && $byTenant[Util::id($tb)]['mode'] === 'dry_run' && $byTenant[Util::id($tb)]['raw_rows'] === 2, 'full run: OFF tenant counted');
    check(array_intersect_key(retentionCounts($db, $tb), $seeded) === $seeded && !array_filter($all, fn ($r) => $r['error'] !== null), 'full run: OFF tenants keep everything, no errors');

    // 7) Un cliente que falla: STDERR con el cliente, salida 1, error anotado y visible.
    $db->run("INSERT INTO client_logs (tenant_id,device_id,event_id,at,level,code,component,body_hash) VALUES (?,?,?,UTC_TIMESTAMP(6)-INTERVAL 80 DAY,'info','retention.probe','Test',?)", [$ta, $a['device'], Util::bin(Util::uuid()), random_bytes(32)]);
    $db->run("CREATE TRIGGER retention_failure_probe BEFORE DELETE ON client_logs FOR EACH ROW BEGIN IF OLD.code='retention.probe' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='synthetic retention failure'; END IF; END");
    try {
        [$code, , $err] = retentionCli(['--tenant=' . $ida]);
        check($code === 1 && str_contains($err, 'retention_failed tenant=' . $ida), 'failing tenant: exit 1 and STDERR names it');
        check($db->one('SELECT error FROM retention_runs WHERE tenant_id=? ORDER BY started_at DESC LIMIT 1', [$ta])['error'] !== null, 'failing tenant: error recorded');
        check(expect(adminRequest($editor, 'GET', '/tenants/panel-settings'), 200, 'read failed run')['retention_last_run']['failed'] === true, 'failed run visible');
    } finally { $db->run('DROP TRIGGER retention_failure_probe'); }
    echo 'PASS retention (' . ($GLOBALS['checks'] - $before) . " assertions)\n";
}
