<?php
declare(strict_types=1);
use Keeper\{Database,Resources,Util,Validator};

// Cancelar una orden remota (Koichi, 2026-10-19): lanzo un reinicio con aviso de 5 min a varios equipos y necesitaba
// detenerlo en uno. pending -> cancelled y nunca se entrega; cuenta regresiva ya entregada -> cancel_requested_at, que el
// sync reenvia a agentes >= 4.0.27 y el agente confirma; ya ejecutada -> 409. Cancelar y entregar no se cruzan.
function commandCancelTests(Database $db): void
{
    $before = $GLOBALS['checks'];
    $platform = Util::bin('00000000-0000-4000-8000-000000000001');
    $u = secondTenant($db); $t = $u['tenant_id'];
    $owner = adminMember($db, $u);
    $plan = function (array $features) use ($db, $platform, $t, $owner): void {
        $id = Util::bin(Util::uuid());
        $db->run('INSERT INTO person_plans (tenant_id,id,name,badge_label) VALUES (?,?,?,?)', [$platform, $id, 'Plan cancelar ' . substr(Util::uuid(), 0, 8), 'C']);
        foreach ($features as $f) { $db->run('INSERT INTO person_plan_items (tenant_id,plan_id,feature_code) VALUES (?,?,?)', [$platform, $id, $f]); }
        $db->run('UPDATE users SET plan_tenant_id=?,plan_id=? WHERE tenant_id=? AND id=?', [$platform, $id, $t, $owner['id']]);
    };
    $plan(['comandos', 'borrado', 'productividad']);
    $key = keypair(); $token = enroll($db, $owner, $key); policy($db, $token);
    $did = $token['device_id']; $device = Util::bin($did);
    $version = fn (string $v) => $db->run('UPDATE devices SET agent_version=? WHERE tenant_id=? AND id=?', [$v, $t, $device]);
    $version('4.0.27');
    accessResetPreloginQuota();
    $admin = adminFixture($db, $u, ['equipos.ver', 'equipos.bloquear', 'equipos.desbloquear', 'equipos.reiniciar', 'equipos.apagar', 'equipos.borrar', 'usuarios.ver']);
    $send = function (string $type, ?int $notice = null, array $extra = []) use ($admin, $did): string {
        $body = ['type' => $type, 'reason' => 'Prueba de cancelar', 'expires_at' => gmdate('Y-m-d\TH:i:s\Z', time() + 3600)] + ($notice ? ['parameters' => ['notice_seconds' => $notice]] : []) + $extra;
        return expect(adminRequest($admin, 'POST', "/devices/$did/commands", $body, ['idempotency-key' => Util::uuid()]), 202, "queue $type")['id'];
    };
    $cancel = fn (string $cid, ?array $as = null, mixed $body = ['reason' => 'Ya no hace falta']) => adminRequest($as ?? $admin, 'POST', "/devices/$did/commands/$cid/cancel", $body, ['idempotency-key' => Util::uuid()]);
    $seq = 10;
    $sync = function (array $results = []) use (&$seq, $key, $token): array {
        return expect(request('POST', '/client/sync', ['protocol_version' => 1, 'sequence' => $seq++, 'policy_version' => null, 'release_id' => null] + ($results ? ['command_results' => $results] : []), $key, $token['access_token'], Util::uuid()), 200, 'device sync');
    };
    $byId = function (array $response): array { $out = []; foreach ($response['commands'] as $c) { $out[$c['id']] = $c; } return $out; };
    $row = fn (string $cid) => $db->one('SELECT * FROM device_command WHERE tenant_id=? AND id=?', [$t, Util::bin($cid)]);
    $result = fn (string $cid, string $status, string $code) => ['command_id' => $cid, 'result' => ['event_id' => Util::uuid(), 'status' => $status, 'at' => gmdate('Y-m-d\TH:i:s\Z', time() + 2), 'code' => $code]];
    $listed = function (string $cid) use ($admin, $did): array {
        $rows = expect(adminRequest($admin, 'GET', "/devices/$did/commands?limit=100"), 200, 'command history')['data'];
        return array_values(array_filter($rows, fn ($r) => $r['id'] === $cid))[0];
    };
    $audits = fn (string $cid) => $db->run("SELECT actor_type,actor_id,reason,changed_fields,outcome FROM audit_log WHERE tenant_id=? AND action='cancelDeviceCommand' AND resource_id=? ORDER BY at", [$t, Util::bin($cid)])->fetchAll();

    // 1) Pendiente (el equipo aun no la recibio): queda cancelada y no se entrega nunca. Auditada con quien y motivo.
    $pending = $send('restart', 300);
    $r = expect($cancel($pending), 200, 'cancel a pending restart');
    check($r['status'] === 'cancelled' && isset($r['cancelled_at']) && !isset($r['cancel_requested_at']) && $r['result'] === null && ($r['parameters']['notice_seconds'] ?? null) === 300, 'pending order cancelled at once');
    check(!isset($byId($sync())[$pending]), 'cancelled pending order is not delivered by sync');
    check(!in_array($pending, array_column(expect(request('GET', '/client/commands', null, $key, $token['access_token']), 200, 'client command list')['data'], 'id'), true), 'nor by the client command list');
    check($row($pending)['status'] === 'cancelled' && $row($pending)['cancelled_at'] !== null, 'stored as cancelled with its time');
    $a = $audits($pending);
    check(count($a) === 1 && $a[0]['actor_type'] === 'admin' && $a[0]['actor_id'] !== null && $a[0]['reason'] === 'Ya no hace falta' && $a[0]['outcome'] === 'allowed' && str_contains($a[0]['changed_fields'], 'cancelled_at'), 'cancel audited: who, when, reason');
    $r = $cancel($pending);
    check($r[0] === 409 && $r[1]['code'] === 'command_already_cancelled', 'cancelling twice: 409 already cancelled');
    $noReason = $send('lock');
    check(expect($cancel($noReason, null, new stdClass()), 200, 'cancel without reason')['status'] === 'cancelled' && $audits($noReason)[0]['reason'] === null, 'reason is optional');

    // 2) Entregar marca delivered; sin aviso, ya entregada, no hay nada que detener.
    $lock = $send('lock');
    $delivered = $byId($sync());
    check(($delivered[$lock]['status'] ?? null) === 'delivered' && $row($lock)['status'] === 'delivered', 'sync marks the command delivered');
    $r = $cancel($lock);
    check($r[0] === 409 && $r[1]['code'] === 'command_executes_on_receipt', 'delivered order without notice: 409 executes on receipt');
    $immediate = $send('restart'); $sync();
    check($cancel($immediate)[1]['code'] === 'command_executes_on_receipt', 'immediate restart already delivered: 409');

    // 3) Cuenta regresiva en curso (agente 4.0.27): cancel_requested_at, el sync la reenvia con el campo y el agente confirma.
    $restart = $send('restart', 300);
    check(!array_key_exists('cancel_requested_at', $byId($sync())[$restart]), 'no cancel field before IT asks');
    $r = expect($cancel($restart), 200, 'cancel a countdown in progress');
    check($r['status'] === 'delivered' && isset($r['cancel_requested_at']) && !isset($r['cancelled_at']), 'countdown: cancellation requested to the device');
    $again = expect($cancel($restart), 200, 'repeat the cancellation');
    check($again['cancel_requested_at'] === $r['cancel_requested_at'] && count($audits($restart)) === 1, 'repeating changes nothing and is audited once');
    $got = $byId($sync())[$restart] ?? null;
    check($got !== null && isset($got['cancel_requested_at']) && $got['status'] === 'delivered', 'sync re-delivers it with cancel_requested_at to 4.0.27');
    $acks = $sync([$result($restart, 'failed', 'cancelled_by_admin')])['command_acks'];
    check(($acks[0]['status'] ?? null) === 'accepted', 'agent confirmation accepted');
    $stored = $row($restart);
    check($stored['status'] === 'cancelled' && $stored['cancelled_at'] !== null && $stored['result_code'] === null && $stored['completed_at'] === null, 'confirmed by the agent: cancelled');
    $l = $listed($restart);
    check($l['status'] === 'cancelled' && isset($l['cancelled_at'], $l['cancel_requested_at']), 'history shows the cancelled order');
    check(!isset($byId($sync())[$restart]), 'cancelled order no longer delivered');

    // 4) Llega tarde: el agente ya la ejecuto -> succeeded con cancel_requested_at; cancelar despues: 409 con la hora.
    $late = $send('shutdown', 60); $sync();
    expect($cancel($late), 200, 'cancel requested on a countdown about to end');
    $sync([$result($late, 'succeeded', 'completed')]);
    $l = $listed($late);
    check($l['status'] === 'succeeded' && isset($l['cancel_requested_at']) && $l['result']['code'] === 'completed', 'late cancellation: executed, request kept for the panel');
    $r = $cancel($late);
    check($r[0] === 409 && $r[1]['code'] === 'command_already_executed' && str_contains($r[1]['detail'] ?? '', 'a las'), 'already executed: 409 with the time');

    // 5) Una cuenta regresiva con cancelacion pedida se reenvia aunque la orden haya vencido (la cuenta no caduca).
    $expiring = $send('restart', 600); $sync(); expect($cancel($expiring), 200, 'cancel before expiry');
    $db->run('UPDATE device_command SET created_at=UTC_TIMESTAMP(6)-INTERVAL 2 HOUR,expires_at=UTC_TIMESTAMP(6)-INTERVAL 1 HOUR WHERE tenant_id=? AND id=?', [$t, Util::bin($expiring)]);
    check(isset($byId($sync())[$expiring]['cancel_requested_at']), 'expired order with cancellation still delivered');
    $sync([$result($expiring, 'failed', 'cancelled_by_admin')]);
    check($row($expiring)['status'] === 'cancelled', 'confirmation accepted after expiry');
    $stale = $send('lock');
    $db->run('UPDATE device_command SET created_at=UTC_TIMESTAMP(6)-INTERVAL 2 HOUR,expires_at=UTC_TIMESTAMP(6)-INTERVAL 1 HOUR WHERE tenant_id=? AND id=?', [$t, Util::bin($stale)]);
    check($cancel($stale)[1]['code'] === 'command_expired', 'pending past its expiry: 409 expired');

    // 6) Agente anterior a 4.0.27: la cuenta ya iniciada no se puede cancelar (409) y nunca recibe el campo.
    $version('4.0.26');
    $old = $send('restart', 300); $sync();
    $r = $cancel($old);
    check($r[0] === 409 && $r[1]['code'] === 'agent_too_old' && $row($old)['cancel_requested_at'] === null, 'old agent: countdown cannot be cancelled');
    $oldPending = $send('restart', 300);
    check(expect($cancel($oldPending), 200, 'old agent: pending order still cancellable')['status'] === 'cancelled', 'pending cancel works for any agent');
    $db->run('UPDATE device_command SET cancel_requested_at=UTC_TIMESTAMP(6) WHERE tenant_id=? AND id=?', [$t, Util::bin($old)]);
    $oldSync = $sync();
    check(!isset($byId($oldSync)[$old]) && !array_filter($oldSync['commands'], fn ($c) => array_key_exists('cancel_requested_at', $c)), 'agents < 4.0.27 never receive cancel_requested_at');
    $version('4.0.27');
    check(isset($byId($sync())[$old]['cancel_requested_at']), 'same order reaches the device once it runs 4.0.27');
    $sync([$result($old, 'failed', 'cancelled_by_admin')]);

    // 7) Permiso, alcance y plan del dueño: los mismos que para crear la orden.
    accessResetPreloginQuota();
    $lockOnly = adminFixture($db, adminMember($db, $u), ['equipos.ver', 'equipos.bloquear']);
    $guarded = $send('restart', 300);
    $r = $cancel($guarded, $lockOnly);
    check($r[0] === 403 && $r[1]['code'] === 'permission_denied' && $row($guarded)['status'] === 'pending', 'cancelling a restart needs equipos.reiniciar');
    check(expect($cancel($send('lock'), $lockOnly), 200, 'lock permission cancels a lock')['status'] === 'cancelled', 'permission follows the command type');
    $site = Util::bin(Util::uuid());
    $db->run("INSERT INTO org_units (tenant_id,id,kind,name) VALUES (?,?,'site','Otra sede')", [$t, $site]);
    accessResetPreloginQuota();
    $elsewhere = adminFixture($db, adminMember($db, $u), ['equipos.ver', 'equipos.reiniciar'], false, 'site', [$site]);
    $r = $cancel($guarded, $elsewhere);
    check($r[0] === 404 && $row($guarded)['status'] === 'pending', 'device outside the admin scope: not found, unchanged');
    check($cancel(Util::uuid())[0] === 404, 'unknown command: 404');
    $plan(['productividad']);
    $r = $cancel($guarded);
    check($r[0] === 403 && $r[1]['code'] === 'feature_not_in_plan' && $r[1]['detail'] === 'comandos' && $row($guarded)['status'] === 'pending', 'owner plan without comandos: 403');
    check((int) $db->one("SELECT COUNT(*) n FROM audit_log WHERE tenant_id=? AND action='cancelDeviceCommand' AND outcome='denied' AND reason='feature_not_in_plan:comandos'", [$t])['n'] === 1, 'plan denial audited');
    $plan(['comandos', 'borrado', 'productividad']);
    // Borrado: reautenticacion de menos de 5 minutos, como al crearlo.
    expect(adminRequest($admin, 'POST', '/auth/reauth', ['password' => $admin['password']]), 200, 'reauth for wipe');
    $wipe = $send('wipe', null, ['confirmation' => 'smoke-device']);
    $db->run('UPDATE admin_sessions SET reauthenticated_at=UTC_TIMESTAMP(6)-INTERVAL 1 HOUR WHERE tenant_id=? AND admin_id=?', [$t, $admin['id']]);
    check($cancel($wipe)[1]['code'] === 'reauth_required', 'cancelling a wipe needs a recent reauth');
    expect(adminRequest($admin, 'POST', '/auth/reauth', ['password' => $admin['password']]), 200, 'reauth again');
    check(expect($cancel($wipe), 200, 'cancel wipe after reauth')['status'] === 'cancelled', 'wipe cancelled');

    // 8) Carreras con la fila tomada por la otra parte (segunda conexion): nunca se entrega una orden cancelada.
    $race = function (array $handle, callable $meanwhile): array {
        [$curl, $received] = $handle;
        $multi = curl_multi_init(); curl_multi_add_handle($multi, $curl);
        $running = 1; $until = microtime(true) + 0.8;
        do { curl_multi_exec($multi, $running); if ($running) { curl_multi_select($multi, 0.05); } } while ($running && microtime(true) < $until);
        $blocked = $running > 0;
        $meanwhile();
        do { curl_multi_exec($multi, $running); if ($running) { curl_multi_select($multi, 0.1); } } while ($running);
        $body = curl_multi_getcontent($curl); curl_multi_remove_handle($multi, $curl); curl_multi_close($multi);
        return [$blocked, requestResult($curl, $received, $body)];
    };
    $cancelHandle = fn (string $cid) => requestHandle('POST', "/devices/$did/commands/$cid/cancel", ['reason' => 'carrera'], null, null, null, null, ['cookie' => '__Host-keeper_admin=' . $admin['cookie'], 'x-csrf-token' => $admin['csrf'], 'origin' => Keeper\Config::get('ORIGIN'), 'x-tenant-id' => Util::id($t), 'idempotency-key' => Util::uuid()]);
    $syncHandle = function () use (&$seq, $key, $token) { return requestHandle('POST', '/client/sync', ['protocol_version' => 1, 'sequence' => $seq++, 'policy_version' => null, 'release_id' => null], $key, $token['access_token'], Util::uuid()); };
    $deliver = function () use ($t, $device): array {
        $other = new Database(); $other->pdo->beginTransaction();
        $data = (new Resources($other, ['tenant_id' => $t, 'device_id' => $device, 'agent_version' => '4.0.27', 'cursor_context' => 'carrera'], new Validator()))->commands()['data'];
        return [$other, array_column($data, 'id')];
    };
    // a) El sync entrega primero (fila tomada): la cancelacion espera y ve delivered.
    $first = $send('lock');
    [$other, $handed] = $deliver();
    check(in_array($first, $handed, true), 'race a: the sync side hands out the pending order');
    [$blocked, $r] = $race($cancelHandle($first), fn () => $other->pdo->commit());
    check($blocked && $r[0] === 409 && $r[1]['code'] === 'command_executes_on_receipt' && $row($first)['status'] === 'delivered', 'race a: cancel waits for the delivery and does not cancel what was delivered');
    $countdown = $send('restart', 300);
    [$other, $handed] = $deliver();
    [$blocked, $r] = $race($cancelHandle($countdown), fn () => $other->pdo->commit());
    check($blocked && $r[0] === 200 && isset($r[1]['cancel_requested_at']) && $r[1]['status'] === 'delivered', 'race a: countdown delivered meanwhile -> cancellation requested to the agent');
    $sync([$result($countdown, 'failed', 'cancelled_by_admin')]);
    // b) La cancelacion toma la fila primero (misma compare-and-set del endpoint): el sync espera y no la entrega.
    $second = $send('restart', 300);
    $other = new Database(); $other->pdo->beginTransaction();
    $changed = $other->run("UPDATE device_command SET status='cancelled',cancelled_at=UTC_TIMESTAMP(6) WHERE tenant_id=? AND id=? AND status='pending'", [$t, Util::bin($second)])->rowCount();
    [$blocked, $r] = $race($syncHandle(), fn () => $other->pdo->commit());
    check($changed === 1 && $blocked && $r[0] === 200 && !in_array($second, array_column($r[1]['commands'], 'id'), true) && $row($second)['status'] === 'cancelled', 'race b: sync waits for the cancellation and never delivers it');
    $other = null;

    echo 'PASS command cancel (' . ($GLOBALS['checks'] - $before) . " assertions)\n";
}
