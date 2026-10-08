<?php
declare(strict_types=1);
use Keeper\{Database, Util};

// Interruptor por cliente para apagar Smart App Control (4.0.19): API admin con reauth, sync por version e inventario.
function windowsSecurityTests(Database $db): void
{
    $before = $GLOBALS['checks'];
    accessResetPreloginQuota();   // 4 logins de fixture seguidos
    $a = secondTenant($db); $b = secondTenant($db);
    $tenant = $a['tenant_id']; $tid = Util::id($tenant); $bid = Util::id($b['tenant_id']);
    $admin = adminFixture($db, $a, ['equipos.desbloquear','equipos.ver']);
    $other = adminFixture($db, $b, ['equipos.desbloquear']);
    $reader = adminFixture($db, adminMember($db, $a), ['equipos.ver']);
    $scoped = adminFixture($db, adminMember($db, $a), ['equipos.desbloquear'], false, 'self');
    $path = '/admin/tenants/' . $tid . '/windows-security';
    $key = keypair(); $token = enroll($db, $a, $key); policy($db, $token);
    $otherKey = keypair(); $otherToken = enroll($db, $b, $otherKey); policy($db, $otherToken);
    $sync = static function (array $identity, array $session, int $seq, array $extra = []) {
        return expect(request('POST', '/client/sync', ['protocol_version'=>1,'sequence'=>$seq,'policy_version'=>1,'release_id'=>null] + $extra,
            $identity, $session['access_token'], Util::uuid()), 200, 'windows security sync');
    };
    $initial = expect(adminRequest($admin, 'GET', $path), 200, 'SAC settings readable');
    check($initial === ['disable_smart_app_control'=>false,'updated_at'=>null,'updated_by'=>null], 'SAC off by default without row');
    expect(adminRequest($admin, 'PUT', $path, ['disable_smart_app_control'=>true]), 403, 'SAC change requires reauth');
    expect(adminRequest($other, 'GET', $path), 404, 'SAC other tenant hidden');
    expect(adminRequest($other, 'PUT', $path, ['disable_smart_app_control'=>true]), 404, 'SAC other tenant cannot change');
    foreach ([$reader,$scoped] as $denied) {
        expect(adminRequest($denied, 'GET', $path), 403, 'SAC read needs tenant-wide permission');
        expect(adminRequest($denied, 'PUT', $path, ['disable_smart_app_control'=>true]), 403, 'SAC change needs tenant-wide permission');
    }
    check($db->one('SELECT COUNT(*) n FROM tenant_windows_security WHERE tenant_id IN (?,?)', [$tenant, $b['tenant_id']])['n'] == 0, 'denied SAC changes stored nothing');
    expect(adminRequest($admin, 'POST', '/auth/reauth', ['password'=>$admin['password']]), 200, 'reauth SAC');
    expect(adminRequest($admin, 'PUT', $path, ['disable_smart_app_control'=>'yes']), 422, 'SAC flag must be boolean');
    expect(adminRequest($admin, 'PUT', $path, ['disable_smart_app_control'=>true,'extra'=>1]), 422, 'SAC body closed');
    $saved = expect(adminRequest($admin, 'PUT', $path, ['disable_smart_app_control'=>true]), 200, 'enable SAC switch');
    $principal = $db->one("SELECT id FROM principals WHERE tenant_id=? AND kind='admin' AND admin_id=?", [$tenant, $admin['id']])['id'];
    check($saved['disable_smart_app_control'] === true && $saved['updated_at'] !== null && $saved['updated_by'] === Util::id($principal), 'SAC switch saved with author');
    check(expect(adminRequest($admin, 'GET', $path), 200, 'SAC reread')['disable_smart_app_control'] === true, 'SAC switch persisted');
    check((int) $db->one("SELECT COUNT(*) n FROM audit_log WHERE tenant_id=? AND action='windows_security.changed'", [$tenant])['n'] === 1, 'SAC change audited');

    check(!array_key_exists('windows_security', $sync($key, $token, 1)), 'agent 4.0.0 receives no windows_security');
    $db->run("UPDATE devices SET agent_version='4.0.18' WHERE tenant_id IN (?,?)", [$tenant, $b['tenant_id']]);
    check(!array_key_exists('windows_security', $sync($key, $token, 2)), 'agent 4.0.18 receives no windows_security');
    $db->run("UPDATE devices SET agent_version='4.0.19' WHERE tenant_id IN (?,?)", [$tenant, $b['tenant_id']]);
    check($sync($key, $token, 3)['windows_security'] === ['disable_smart_app_control'=>true], 'agent 4.0.19 receives SAC switch');
    check($sync($otherKey, $otherToken, 1)['windows_security'] === ['disable_smart_app_control'=>false], 'other tenant receives SAC off');
    expect(adminRequest($admin, 'PUT', $path, ['disable_smart_app_control'=>false]), 200, 'disable SAC switch');
    check($sync($key, $token, 4)['windows_security'] === ['disable_smart_app_control'=>false], 'switch off reaches next sync');
    check((int) $db->one("SELECT COUNT(*) n FROM audit_log WHERE tenant_id=? AND action='windows_security.changed'", [$tenant])['n'] === 2, 'every SAC change audited');
    $db->run('UPDATE admin_sessions SET reauthenticated_at=UTC_TIMESTAMP(6)-INTERVAL 301 SECOND WHERE tenant_id=? AND admin_id=?', [$tenant, $admin['id']]);
    expect(adminRequest($admin, 'PUT', $path, ['disable_smart_app_control'=>true]), 403, 'expired reauth cannot change SAC');

    $inventory = ['os_edition'=>'Windows 11 Pro','cpu'=>'cpu (4 hilos)','ram_bytes'=>8589934592,'architecture'=>'x64'];
    $device = '/devices/' . $token['device_id'];
    check(expect(adminRequest($admin, 'GET', $device), 200, 'device before SAC report')['smart_app_control'] === null, 'device DTO null before SAC report');
    $sync($key, $token, 5, ['inventory'=>$inventory + ['smart_app_control'=>'on']]);
    $specs = $db->one('SELECT JSON_UNQUOTE(JSON_EXTRACT(specs,\'$.smart_app_control\')) s, JSON_UNQUOTE(JSON_EXTRACT(specs,\'$.architecture\')) a FROM devices WHERE tenant_id=? AND id=?', [$tenant, Util::bin($token['device_id'])]);
    check($specs['s'] === 'on' && $specs['a'] === 'x64', 'inventory stores SAC state in specs');
    check(expect(adminRequest($admin, 'GET', $device), 200, 'device with SAC')['smart_app_control'] === 'on', 'device DTO exposes SAC state');
    $list = expect(adminRequest($admin, 'GET', '/tenants/' . $tid . '/devices'), 200, 'device list with SAC');
    check(array_column($list['data'], 'smart_app_control', 'id')[$token['device_id']] === 'on', 'device list exposes SAC state');
    $sync($key, $token, 6, ['inventory'=>$inventory + ['smart_app_control'=>'off']]);
    check(expect(adminRequest($admin, 'GET', $device), 200, 'device SAC off')['smart_app_control'] === 'off', 'SAC state refreshed by inventory');
    expect(request('POST', '/client/sync', ['protocol_version'=>1,'sequence'=>7,'policy_version'=>1,'release_id'=>null,'inventory'=>$inventory + ['smart_app_control'=>'enabled']],
        $key, $token['access_token'], Util::uuid()), 422, 'invalid SAC state rejected by contract');
    $sync($key, $token, 8, ['inventory'=>$inventory]);
    check(expect(adminRequest($admin, 'GET', $device), 200, 'device without SAC report')['smart_app_control'] === null, 'inventory without SAC clears the state');
    foreach (['smart_app_control_disabled','smart_app_control_disable_failed','update_launch_failed','update_apply_stalled'] as $i => $code) {
        $entry = ['event_id'=>Util::uuid(),'at'=>gmdate('Y-m-d\TH:i:s\Z'),'level'=>$i === 0 ? 'info' : 'warn','component'=>$i < 2 ? 'SmartAppControl' : 'UpdateManager','code'=>$code,
            'fields'=>$i === 0 ? (object) [] : ['error_code'=>$i === 2 ? 'win32_4551' : 'UnauthorizedAccessException']];
        $ack = expect(request('POST', '/client/logs', ['entries'=>[$entry]], $key, $token['access_token'], Util::uuid()), 200, 'SAC log upload');
        check($ack['acks'][0]['status'] === 'accepted', 'new agent log code accepted: ' . $code);
    }
    check((int) $db->one("SELECT COUNT(*) n FROM client_logs WHERE tenant_id=? AND code='update_launch_failed' AND error_code='win32_4551'", [$tenant])['n'] === 1, 'launch failure stored with win32 code');
    echo 'PASS windows security (' . ($GLOBALS['checks'] - $before) . " assertions)\n";
}
