<?php
declare(strict_types=1);
use Keeper\{Database,Util};

// Nombre de Windows por placa (Koichi, 2026-10-17): el agente aplica rename_computer y responde pending_restart; Windows
// solo toma el nombre al reiniciar. El DTO del equipo (computer_rename) dice en que va y el alta puede pedir el reinicio.
function computerRenameTests(Database $db): void
{
    $before = $GLOBALS['checks'];
    $platform = Util::bin('00000000-0000-4000-8000-000000000001');
    $u = secondTenant($db); $t = $u['tenant_id'];
    $owner = adminMember($db, $u);
    $plan = function (array $features) use ($db, $platform, $t, $owner): void {
        $id = Util::bin(Util::uuid());
        $db->run('INSERT INTO person_plans (tenant_id,id,name,badge_label) VALUES (?,?,?,?)', [$platform, $id, 'Plan nombre ' . substr(Util::uuid(), 0, 8), 'N']);
        foreach ($features as $f) { $db->run('INSERT INTO person_plan_items (tenant_id,plan_id,feature_code) VALUES (?,?,?)', [$platform, $id, $f]); }
        $db->run('UPDATE users SET plan_tenant_id=?,plan_id=? WHERE tenant_id=? AND id=?', [$platform, $id, $t, $owner['id']]);
    };
    $plan(['comandos', 'productividad']);
    accessResetPreloginQuota();
    $permissions = array_column($db->run('SELECT code FROM permissions WHERE platform_only=FALSE')->fetchAll(), 'code');
    $admin = adminFixture($db, $u, $permissions);

    // 1) Estado del nombre en el DTO, desde la orden rename_computer mas reciente y el hostname del agente.
    $device = Util::bin(Util::uuid()); $did = Util::id($device);
    $db->run("INSERT INTO devices (tenant_id,id,user_id,hostname,agent_version,os_edition,cpu,ram_bytes,capabilities) VALUES (?,?,?,'GRAZC_2107','4.0.26','test','test',1024,'[]')", [$t, $device, $owner['id']]);
    $get = fn () => expect(adminRequest($admin, 'GET', '/devices/' . $did), 200, 'device with rename state')['computer_rename'];
    $listed = function () use ($admin, $t, $owner, $did) {
        $rows = expect(adminRequest($admin, 'GET', '/tenants/' . Util::id($t) . '/devices?user_id=' . Util::id($owner['id'])), 200, 'device list with rename state')['data'];
        return array_values(array_filter($rows, fn ($d) => $d['id'] === $did))[0]['computer_rename'];
    };
    $send = fn (string $type, array $extra = []) => adminRequest($admin, 'POST', '/devices/' . $did . '/commands', ['type' => $type, 'reason' => 'Prueba de nombre', 'expires_at' => gmdate('Y-m-d\TH:i:s\Z', time() + 3600)] + $extra, ['idempotency-key' => Util::uuid()]);
    $last = fn () => $db->one("SELECT id FROM device_command WHERE tenant_id=? AND device_id=? AND type='rename_computer' ORDER BY sequence DESC LIMIT 1", [$t, $device])['id'];
    $finish = fn (string $status, ?string $code) => $db->run('UPDATE device_command SET status=?,result_code=?,completed_at=IF(? IS NULL,NULL,UTC_TIMESTAMP(6)) WHERE tenant_id=? AND id=?', [$status, $code, $code, $t, $last()]);
    check($get() === null, 'no rename order: no rename state');
    check($send('rename_computer', ['parameters' => ['computer_name' => 'ACT-0025', 'restart_now' => false]])[0] === 202, 'rename order queued');
    $r = $get();
    check($r['state'] === 'renaming' && $r['computer_name'] === 'ACT-0025' && $r['result_code'] === null && $r['completed_at'] === null && $r['restart_pending'] === false, 'pending order: renaming');
    check($listed() === $r, 'device list exposes the same rename state');
    $db->run("UPDATE device_command SET status='delivered' WHERE tenant_id=? AND id=?", [$t, $last()]);
    check($get()['state'] === 'renaming', 'delivered order: still renaming');
    $finish('succeeded', 'pending_restart');
    $r = $get();
    check($r['state'] === 'pending_restart' && $r['result_code'] === 'pending_restart' && is_string($r['completed_at']) && $r['restart_pending'] === false, 'succeeded + pending_restart: waiting for a restart');
    check($send('restart', ['parameters' => ['notice_seconds' => 300]])[0] === 202, 'restart with notice from the device page');
    check($get()['restart_pending'] === true, 'restart sent after the rename is reported');
    $db->run("UPDATE devices SET hostname='act-0025' WHERE tenant_id=? AND id=?", [$t, $device]);
    check($get() === null && $listed() === null, 'hostname already matches (any case): nothing to show');
    // Fallo del agente, vencida sin respuesta y cancelada: failed con el motivo.
    check($send('rename_computer', ['parameters' => ['computer_name' => 'ACT-0026', 'restart_now' => false]])[0] === 202, 'second rename order');
    $finish('failed', 'Win32Exception');
    $r = $get(); check($r['state'] === 'failed' && $r['result_code'] === 'Win32Exception' && $r['computer_name'] === 'ACT-0026' && $r['restart_pending'] === false, 'failed order: failed with the agent code');
    check($send('rename_computer', ['parameters' => ['computer_name' => 'ACT-0027', 'restart_now' => false]])[0] === 202, 'third rename order');
    $db->run('UPDATE device_command SET created_at=UTC_TIMESTAMP(6)-INTERVAL 2 DAY,expires_at=UTC_TIMESTAMP(6)-INTERVAL 1 DAY WHERE tenant_id=? AND id=?', [$t, $last()]);
    $r = $get(); check($r['state'] === 'failed' && $r['result_code'] === 'expired' && $r['computer_name'] === 'ACT-0027', 'pending order past its expiry: failed, expired');
    $db->run("UPDATE device_command SET status='cancelled' WHERE tenant_id=? AND id=?", [$t, $last()]);
    check($get()['result_code'] === 'cancelled', 'cancelled order: failed, cancelled');

    // 2) Aprobar con placa y «Reiniciar con aviso para aplicarlo ya».
    $doc = (string) random_int(1000000000, 1999999999);
    $db->run("INSERT INTO user_external_refs (tenant_id,user_id,source,external_ref) VALUES (?,?,'document',?)", [$t, $owner['id'], $doc]);
    expect(adminRequest($admin, 'POST', '/auth/reauth', ['password' => $admin['password']]), 200, 'reauth for rename enrollment key');
    $key = expect(adminRequest($admin, 'POST', '/enrollments/keys'), 201, 'rename enrollment key')['key'];
    $ask = fn (array $pair, string $host, string $version) => expect(intakeAsk($key, $pair, null, $host, null, $version), 200, "request $host");
    $approve = fn (array $req, array $body, ?array $as = null) => adminRequest($as ?? $admin, 'POST', '/enrollments/requests/' . $req['request_id'] . '/approve', $body);
    $pair = keypair(); $req = $ask($pair, 'DESKTOP-NOMBRE', '4.0.26');
    check($approve($req, ['person' => $doc, 'asset_code' => null, 'restart_with_notice' => true])[0] === 422, 'restart without asset code rejected');
    check($approve($req, ['person' => $doc, 'asset_code' => '1234', 'restart_with_notice' => true])[0] === 422, 'restart with an asset that is not a valid name rejected');
    $noRestart = adminFixture($db, adminMember($db, $u), ['equipos.enrolar', 'equipos.ver']);
    $r = $approve($req, ['person' => $doc, 'asset_code' => 'ACT_0025', 'restart_with_notice' => true], $noRestart);
    check($r[0] === 403 && $r[1]['code'] === 'permission_denied', 'restart needs equipos.reiniciar of whoever approves');
    $plan(['productividad']);
    $r = $approve($req, ['person' => $doc, 'asset_code' => 'ACT_0025', 'restart_with_notice' => true]);
    check($r[0] === 403 && $r[1]['code'] === 'feature_not_in_plan' && $r[1]['detail'] === 'comandos', 'restart needs comandos in the person plan');
    $plan(['comandos', 'productividad']);
    $oldPair = keypair(); $old = $ask($oldPair, 'DESKTOP-VIEJO', '4.0.16');
    $r = $approve($old, ['person' => $doc, 'asset_code' => 'ACT_0031', 'restart_with_notice' => true]);
    check($r[0] === 409 && $r[1]['code'] === 'agent_too_old', 'restart needs an agent that shows the notice');
    check(!$db->one("SELECT id FROM enrollment_requests WHERE tenant_id=? AND status<>'pending' AND id IN (?,?)", [$t, Util::bin($req['request_id']), Util::bin($old['request_id'])]), 'rejected approvals change nothing');
    $ok = expect($approve($req, ['person' => $doc, 'asset_code' => 'ACT_0025', 'restart_with_notice' => true]), 200, 'approve with restart');
    check($ok['status'] === 'approved' && $ok['asset_code'] === 'ACT_0025' && $ok['restart_with_notice'] === true, 'approval records the restart choice');
    $audit = $db->one("SELECT changed_fields FROM audit_log WHERE tenant_id=? AND action='approveEnrollmentRequest' AND resource_id=? ORDER BY at DESC LIMIT 1", [$t, Util::bin($req['request_id'])]);
    check($audit && str_contains((string) $audit['changed_fields'], 'restart_with_notice'), 'restart choice audited');
    $token = intakeLogin($ask($pair, 'DESKTOP-NOMBRE', '4.0.26')['enrollment_ticket'], $pair, 'DESKTOP-NOMBRE', '4.0.26');
    $commands = $db->run('SELECT type,parameters,reason FROM device_command WHERE tenant_id=? AND device_id=? ORDER BY sequence', [$t, Util::bin($token['device_id'])])->fetchAll();
    check(array_column($commands, 'type') === ['rename_computer', 'restart'], 'enrollment queues the rename and then the restart');
    check(json_decode($commands[0]['parameters'], true) == ['computer_name' => 'ACT-0025', 'restart_now' => false] && json_decode($commands[1]['parameters'], true) == ['notice_seconds' => 300], 'rename applies on restart; restart with 5 minute notice');
    $state = expect(adminRequest($admin, 'GET', '/devices/' . $token['device_id']), 200, 'enrolled device')['computer_rename'];
    check($state['state'] === 'renaming' && $state['computer_name'] === 'ACT-0025' && $state['restart_pending'] === true, 'new device shows renaming with the restart on its way');
    // Por defecto (sin el campo, como hoy): solo el cambio de nombre, que se aplica en el proximo reinicio.
    $pair2 = keypair(); $req2 = $ask($pair2, 'DESKTOP-NOMBRE2', '4.0.26');
    $ok = expect($approve($req2, ['person' => $doc, 'asset_code' => 'ACT_0032']), 200, 'approve without the field');
    check($ok['restart_with_notice'] === false, 'default approval: next restart');
    $token2 = intakeLogin($ask($pair2, 'DESKTOP-NOMBRE2', '4.0.26')['enrollment_ticket'], $pair2, 'DESKTOP-NOMBRE2', '4.0.26');
    check(array_column($db->run('SELECT type FROM device_command WHERE tenant_id=? AND device_id=? ORDER BY sequence', [$t, Util::bin($token2['device_id'])])->fetchAll(), 'type') === ['rename_computer'], 'default approval queues only the rename');
    // El plan se revalida al enrolar: si ya no incluye comandos, solo se encola el cambio de nombre.
    $pair3 = keypair(); $req3 = $ask($pair3, 'DESKTOP-NOMBRE3', '4.0.26');
    expect($approve($req3, ['person' => $doc, 'asset_code' => 'ACT_0033', 'restart_with_notice' => true]), 200, 'approve with restart before a plan change');
    $plan(['productividad']);
    $token3 = intakeLogin($ask($pair3, 'DESKTOP-NOMBRE3', '4.0.26')['enrollment_ticket'], $pair3, 'DESKTOP-NOMBRE3', '4.0.26');
    check(array_column($db->run('SELECT type FROM device_command WHERE tenant_id=? AND device_id=? ORDER BY sequence', [$t, Util::bin($token3['device_id'])])->fetchAll(), 'type') === ['rename_computer'], 'plan without comandos at enrollment: no restart');

    // Panel: textos llanos compartidos y estado/boton en lista y ficha.
    $assets = dirname(__DIR__) . '/public/assets/';
    $codes = file_get_contents($assets . 'result-codes.js');
    check(str_contains($codes, 'pending_restart') && str_contains($codes, 'waiting_panel') && str_contains($codes, 'driver_blocked') && str_contains($codes, 'hardened_logoff'), 'shared map of result codes');
    check(!str_contains(file_get_contents($assets . 'device-actions.js'), "pending_restart: '"), 'device-actions reuses the shared map');
    check(str_contains(file_get_contents($assets . 'app.js'), 'Reiniciar para aplicar el nombre') && str_contains(file_get_contents($assets . 'fleet.js'), 'renameNote'), 'rename state on device page and list');
    check(str_contains(file_get_contents($assets . 'intake.js'), 'restart_with_notice'), 'approval offers the restart');
    echo 'PASS computer rename (' . ($GLOBALS['checks'] - $before) . " assertions)\n";
}
