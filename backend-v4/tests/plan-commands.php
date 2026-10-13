<?php
declare(strict_types=1);
use Keeper\{Database,Util};

// Encargo 4 (2026-10-13): las ordenes remotas exigen ademas que el plan del DUENO del equipo incluya la funcion
// (comandos: bloquear, desbloquear, reiniciar, apagar; borrado: borrar). Sin dueno activo decide la funcion del cliente.
function planCommandTests(Database $db): void
{
    $before = $GLOBALS['checks'];
    $platform = Util::bin('00000000-0000-4000-8000-000000000001');
    $u = secondTenant($db); $t = $u['tenant_id'];
    $owner = adminMember($db, $u);
    $device = Util::bin(Util::uuid()); $did = Util::id($device);
    $db->run("INSERT INTO devices (tenant_id,id,user_id,hostname,agent_version,os_edition,cpu,ram_bytes,capabilities) VALUES (?,?,?,'plan-device','4.0.30','test','test',1024,'[]')", [$t, $device, $owner['id']]);
    accessResetPreloginQuota();
    $admin = adminFixture($db, $u, ['equipos.ver', 'equipos.bloquear', 'equipos.desbloquear', 'equipos.reiniciar', 'equipos.apagar', 'equipos.borrar', 'reglas.aplicar', 'equipos.editar', 'usuarios.ver']);
    $plan = function (array $features) use ($db, $platform, $t, $owner): void {
        $id = Util::bin(Util::uuid());
        $db->run('INSERT INTO person_plans (tenant_id,id,name,badge_label) VALUES (?,?,?,?)', [$platform, $id, 'Plan ordenes ' . substr(Util::uuid(), 0, 8), 'P']);
        foreach ($features as $f) { $db->run('INSERT INTO person_plan_items (tenant_id,plan_id,feature_code) VALUES (?,?,?)', [$platform, $id, $f]); }
        $db->run('UPDATE users SET plan_tenant_id=?,plan_id=? WHERE tenant_id=? AND id=?', [$platform, $id, $t, $owner['id']]);
    };
    $base = ['reason' => 'Prueba de plan', 'expires_at' => gmdate('Y-m-d\TH:i:s\Z', time() + 600)];
    $send = fn (string $type, array $extra = []) => adminRequest($admin, 'POST', '/devices/' . $did . '/commands', $base + ['type' => $type] + $extra, ['idempotency-key' => Util::uuid()]);
    $allowed = fn () => expect(adminRequest($admin, 'GET', '/devices/' . $did), 200, 'device with allowed commands')['allowed_commands'];
    $listed = function () use ($admin, $t, $owner, $did): ?array {
        $rows = expect(adminRequest($admin, 'GET', '/tenants/' . Util::id($t) . '/devices?user_id=' . Util::id($owner['id'])), 200, 'person devices')['data'];
        return array_values(array_filter($rows, fn ($d) => $d['id'] === $did))[0]['allowed_commands'] ?? null;
    };
    check(adminRequest($admin, 'POST', '/auth/reauth', ['password' => $admin['password']])[0] === 200, 'reauth for wipe');
    $wipe = ['confirmation' => 'plan-device'];
    $denied = fn () => (int) $db->one("SELECT COUNT(*) n FROM audit_log WHERE tenant_id=? AND action='createDeviceCommand' AND outcome='denied' AND resource_id=?", [$t, $device])['n'];
    $all = ['lock', 'unlock', 'restart', 'shutdown', 'wipe', 'refresh_policy', 'rename_computer'];
    // Plan con comandos y borrado: todo permitido.
    $plan(['comandos', 'borrado', 'productividad']);
    check($allowed() === $all, 'full plan allows every command');
    check($listed() === $all, 'person device list exposes the same allowed commands');
    check($send('lock')[0] === 202, 'lock allowed by plan with comandos');
    check($send('wipe', $wipe)[0] === 202, 'wipe allowed by plan with borrado');
    // Plan sin comandos ni borrado: 403 feature_not_in_plan con la funcion en detail, auditado; refresh y renombre siguen.
    $plan(['productividad']);
    check($allowed() === ['refresh_policy', 'rename_computer'], 'plan without comandos/borrado allows only refresh and rename');
    foreach (['lock', 'unlock', 'restart', 'shutdown'] as $type) {
        $r = $send($type);
        check($r[0] === 403 && $r[1]['code'] === 'feature_not_in_plan' && $r[1]['detail'] === 'comandos', "$type needs comandos in the owner plan");
    }
    $r = $send('wipe', $wipe);
    check($r[0] === 403 && $r[1]['code'] === 'feature_not_in_plan' && $r[1]['detail'] === 'borrado', 'wipe needs borrado in the owner plan');
    check($denied() === 5, 'every plan denial is audited');
    $row = $db->one("SELECT reason,actor_type FROM audit_log WHERE tenant_id=? AND action='createDeviceCommand' AND outcome='denied' AND resource_id=? ORDER BY at DESC LIMIT 1", [$t, $device]);
    check($row['reason'] === 'feature_not_in_plan:borrado' && $row['actor_type'] === 'admin', 'denial audit names the function');
    check($send('refresh_policy')[0] === 202, 'refresh_policy never depends on the plan');
    check($send('rename_computer', ['parameters' => ['computer_name' => 'ACT-0001', 'restart_now' => false]])[0] === 202, 'rename_computer never depends on the plan');
    // Solo comandos: reiniciar si, borrar no.
    $plan(['comandos']);
    check($send('restart')[0] === 202, 'restart allowed with comandos only');
    $r = $send('wipe', $wipe); check($r[0] === 403 && $r[1]['detail'] === 'borrado', 'wipe still blocked without borrado');
    // Sin plan alguno: bloquear no, refresh_policy si.
    $db->run('UPDATE users SET plan_tenant_id=NULL,plan_id=NULL WHERE tenant_id=? AND id=?', [$t, $owner['id']]);
    $db->run('UPDATE tenants SET default_plan_tenant_id=NULL,default_plan_id=NULL WHERE tenant_id=?', [$t]);
    check($send('lock')[0] === 403 && $send('refresh_policy')[0] === 202, 'no plan: lock blocked, refresh_policy allowed');
    // Equipo sin dueno activo (persona retirada y el equipo sin reasignar): decide la funcion del cliente.
    $db->run("UPDATE users SET status='inactive' WHERE tenant_id=? AND id=?", [$t, $owner['id']]);
    check($allowed() === $all, 'device without active owner follows the client functions');
    check($send('lock')[0] === 202 && $send('wipe', $wipe)[0] === 202, 'device without active owner: commands allowed by the client');
    $db->run("DELETE FROM tenant_features WHERE tenant_id=? AND feature_code='borrado'", [$t]);
    check(!in_array('wipe', $allowed(), true), 'device without active owner: client without borrado cannot wipe');
    $r = $send('wipe', $wipe); check($r[0] === 403 && $r[1]['detail'] === 'feature_not_contracted:borrado', 'client function checked before the plan');
    // Panel: el boton se muestra deshabilitado con el motivo y el 403 se traduce igual.
    $js = file_get_contents(dirname(__DIR__) . '/public/assets/device-actions.js');
    check(str_contains($js, "'No incluido en el plan de esta persona'") && substr_count($js, 'PLAN_REASON') >= 3 && str_contains($js, 'allowed_commands') && str_contains($js, 'feature_not_in_plan'), 'panel explains plan-blocked commands');
    echo 'PASS plan commands (' . ($GLOBALS['checks'] - $before) . " assertions)\n";
}
