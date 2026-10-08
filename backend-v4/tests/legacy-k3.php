<?php
declare(strict_types=1);
use Keeper\{Database, Util};

// K3 residual en equipos v4: el inventario trae legacy_k3 {running,autostart,installed}; se guarda en devices.specs y la ficha lo expone.
function legacyK3Tests(Database $db): void
{
    $before = $GLOBALS['checks'];
    accessResetPreloginQuota();
    $a = secondTenant($db);
    $tenant = $a['tenant_id']; $tid = Util::id($tenant);
    $admin = adminFixture($db, $a, ['equipos.ver']);
    $key = keypair(); $token = enroll($db, $a, $key); policy($db, $token);
    $sync = static function (int $seq, array $inventory, int $status = 200, string $label = 'legacy k3 sync') use ($key, $token) {
        return expect(request('POST', '/client/sync', ['protocol_version'=>1,'sequence'=>$seq,'policy_version'=>1,'release_id'=>null,'inventory'=>$inventory],
            $key, $token['access_token'], Util::uuid()), $status, $label);
    };
    $inventory = ['os_edition'=>'Windows 11 Pro','cpu'=>'cpu (4 hilos)','ram_bytes'=>8589934592,'architecture'=>'x64'];
    $device = '/devices/' . $token['device_id'];
    $stored = static fn() => $db->one('SELECT JSON_EXTRACT(specs,\'$.legacy_k3\') k, JSON_UNQUOTE(JSON_EXTRACT(specs,\'$.architecture\')) a FROM devices WHERE tenant_id=? AND id=?',
        [$tenant, Util::bin($token['device_id'])]);

    check(expect(adminRequest($admin, 'GET', $device), 200, 'device before K3 report')['legacy_k3'] === null, 'device DTO legacy_k3 null before any report');
    $sync(1, $inventory + ['legacy_k3'=>['running'=>true,'autostart'=>true,'installed'=>true]]);
    $row = $stored();
    check(json_decode((string) $row['k'], true) === ['running'=>true,'autostart'=>true,'installed'=>true] && $row['a'] === 'x64', 'sync with legacy_k3 stores the object in specs');
    check(expect(adminRequest($admin, 'GET', $device), 200, 'device with K3')['legacy_k3'] === ['running'=>true,'autostart'=>true,'installed'=>true], 'device DTO exposes legacy_k3');
    $list = expect(adminRequest($admin, 'GET', '/tenants/' . $tid . '/devices'), 200, 'device list with K3');
    check(array_column($list['data'], 'legacy_k3', 'id')[$token['device_id']] === ['running'=>true,'autostart'=>true,'installed'=>true], 'device list exposes legacy_k3');

    $sync(2, $inventory + ['legacy_k3'=>['running'=>false,'autostart'=>null,'installed'=>true]]);
    check(expect(adminRequest($admin, 'GET', $device), 200, 'device K3 partly unknown')['legacy_k3'] === ['running'=>false,'autostart'=>null,'installed'=>true], 'unknown K3 values stay null');

    $sync(3, $inventory + ['legacy_k3'=>['running'=>'yes','autostart'=>false,'installed'=>false]], 422, 'non boolean K3 value rejected by contract');
    $sync(4, $inventory + ['legacy_k3'=>['running'=>false,'autostart'=>false]], 422, 'incomplete K3 object rejected by contract');
    $sync(5, $inventory + ['legacy_k3'=>['running'=>false,'autostart'=>false,'installed'=>false,'pid'=>1]], 422, 'closed K3 object rejected by contract');
    check(expect(adminRequest($admin, 'GET', $device), 200, 'device after rejected K3')['legacy_k3']['installed'] === true, 'rejected inventories change nothing');

    $sync(6, $inventory);
    check(expect(adminRequest($admin, 'GET', $device), 200, 'device without K3 field')['legacy_k3'] === null, 'inventory without legacy_k3 does not break and clears it');
    check($stored()['a'] === 'x64', 'inventory without legacy_k3 keeps the rest of specs');

    foreach (['legacy_k3_running'=>'warn','legacy_k3_autostart'=>'info','legacy_k3_gone'=>'info'] as $code => $level) {
        $entry = ['event_id'=>Util::uuid(),'at'=>gmdate('Y-m-d\TH:i:s\Z'),'level'=>$level,'component'=>'LegacyK3','code'=>$code,'fields'=>(object) []];
        $ack = expect(request('POST', '/client/logs', ['entries'=>[$entry]], $key, $token['access_token'], Util::uuid()), 200, 'K3 log upload');
        check($ack['acks'][0]['status'] === 'accepted', 'K3 agent log code accepted: ' . $code);
    }
    echo 'PASS legacy k3 (' . ($GLOBALS['checks'] - $before) . " assertions)\n";
}
