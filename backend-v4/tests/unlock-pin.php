<?php
declare(strict_types=1);
use Keeper\{Database, Util};

function unlockPinTests(Database $db): void
{
    $before = $GLOBALS['checks'];
    check(base64_encode(hash_pbkdf2('sha256','0625184',str_repeat('s',32),210000,32,true))==='AtPsP9xC4jfTG3OfjdXOAY3/h0LL00/BvRebLDPHAnE=','PHP and C# PIN vector');
    $a = secondTenant($db); $b = secondTenant($db);
    $tenant = $a['tenant_id']; $tid = Util::id($tenant); $bid = Util::id($b['tenant_id']);
    $admin = adminFixture($db, $a, ['equipos.desbloquear','operacion.logs']);
    $other = adminFixture($db, $b, ['equipos.desbloquear']);
    $reader = adminFixture($db, adminMember($db, $a), ['equipos.ver']);
    $scoped = adminFixture($db, adminMember($db, $a), ['equipos.desbloquear'], false, 'self');
    $path = '/admin/tenants/' . $tid . '/unlock-pin';
    $pin = '0625184'; $next = '0735192';
    $key = keypair(); $token = enroll($db, $a, $key); policy($db, $token);
    $otherKey = keypair(); $otherToken = enroll($db, $b, $otherKey); policy($db, $otherToken);
    $sync = static function (array $identity, array $session, int $seq) {
        return expect(request('POST', '/client/sync', ['protocol_version'=>1,'sequence'=>$seq,'policy_version'=>1,'release_id'=>null],
            $identity, $session['access_token'], Util::uuid()), 200, 'PIN sync');
    };
    check(!expect(adminRequest($admin, 'GET', $path), 200, 'PIN initially absent')['configured'], 'PIN unconfigured');
    expect(adminRequest($admin, 'PUT', $path, ['pin'=>$pin]), 403, 'PIN change requires reauth');
    expect(adminRequest($admin, 'POST', $path.'/reveal'), 403, 'PIN reveal requires reauth');
    foreach ([$reader,$scoped,$other] as $denied) {
        check(in_array(adminRequest($denied, 'GET', $path)[0], [403,404], true), 'PIN metadata scope enforced');
        check(in_array(adminRequest($denied, 'PUT', $path, ['pin'=>$pin])[0], [403,404], true), 'PIN change scope enforced');
        check(in_array(adminRequest($denied, 'POST', $path.'/reveal')[0], [403,404], true), 'PIN reveal scope enforced');
    }
    expect(adminRequest($admin, 'POST', '/auth/reauth', ['password'=>$admin['password']]), 200, 'reauth PIN');
    foreach (['12345', str_repeat('1',33), '12345a', "123456\n"] as $invalid) {
        expect(adminRequest($admin, 'PUT', $path, ['pin'=>$invalid]), 422, 'invalid PIN rejected');
    }
    $settings = expect(adminRequest($admin, 'PUT', $path, ['pin'=>$pin]), 200, 'set PIN');
    check($settings['configured'] && $settings['revision'] === 1 && !isset($settings['pin']), 'setting response contains no PIN');
    $row = $db->one('SELECT * FROM tenant_unlock_pin WHERE tenant_id=?', [$tenant]);
    check(!str_contains($row['pin_enc'],$pin) && strlen($row['salt'])===32, 'PIN encrypted and salted');
    check(hash_equals($row['verifier'],hash_pbkdf2('sha256',$pin,$row['salt'],210000,32,true)), 'PBKDF2 verifier matches PIN');
    check(expect(adminRequest($admin,'POST',$path.'/reveal'),200,'reveal PIN')['pin']===$pin,'reveal retains leading zero');
    check(!array_key_exists('unlock_pin',$sync($key,$token,1)),'old agent receives no unknown sync field');
    $db->run("UPDATE devices SET agent_version='4.0.17' WHERE tenant_id IN (?,?)",[$tenant,$b['tenant_id']]);
    $client=$sync($key,$token,2)['unlock_pin'];
    check($client['revision']===1 && base64_decode($client['hash'])===$row['verifier'] && base64_decode($client['salt'])===$row['salt'],'new agent receives verifier only');
    check(!str_contains(Util::json($client),$pin),'sync has no raw PIN');
    check($sync($otherKey,$otherToken,1)['unlock_pin']===['revision'=>0],'other tenant receives no verifier');
    expect(adminRequest($admin,'PUT',$path,['pin'=>$next]),200,'rotate PIN');
    $rotated=$sync($key,$token,3)['unlock_pin'];
    check($rotated['revision']===2 && $rotated['salt']!==$client['salt'] && $rotated['hash']!==$client['hash'],'rotation reaches next sync');
    check(hash_equals(base64_decode($rotated['hash']),hash_pbkdf2('sha256',$next,base64_decode($rotated['salt']),210000,32,true)),'rotated verifier matches');
    $logs=$db->run("SELECT changed_fields FROM audit_log WHERE tenant_id=? AND action IN ('unlock_pin.changed','unlock_pin.revealed')",[$tenant])->fetchAll();
    check(count($logs)===3 && !str_contains(Util::json($logs),$pin) && !str_contains(Util::json($logs),$next),'PIN actions audited without secret values');
    $db->run('UPDATE admin_sessions SET reauthenticated_at=UTC_TIMESTAMP(6)-INTERVAL 301 SECOND WHERE tenant_id=? AND admin_id=?',[$tenant,$admin['id']]);
    expect(adminRequest($admin,'POST',$path.'/reveal'),403,'expired reauth cannot reveal PIN');
    expect(adminRequest($admin,'PUT',$path,['pin'=>$pin]),403,'expired reauth cannot change PIN');
    $event=['event_id'=>Util::uuid(),'at'=>gmdate('Y-m-d\TH:i:s\Z',time()-86400),'level'=>'info','component'=>'DeviceLock','code'=>'local_unlock'];
    $upload=expect(request('POST','/client/logs',['entries'=>[$event]],$key,$token['access_token'],Util::uuid()),200,'offline unlock report');
    check($upload['acks'][0]['status']==='accepted','delayed unlock accepted');
    $duplicate=expect(request('POST','/client/logs',['entries'=>[$event]],$key,$token['access_token'],Util::uuid()),200,'unlock replay');
    check($duplicate['acks'][0]['status']==='duplicate','unlock report deduplicated');
    check((int)$db->one('SELECT COUNT(*) n FROM client_logs WHERE tenant_id=? AND event_id=?',[$tenant,Util::bin($event['event_id'])])['n']===1,'one durable panel log');
    $panel=expect(adminRequest($admin,'GET','/audit/client-logs?device_id='.$token['device_id'].'&limit=50'),200,'panel reads unlock');
    check(in_array('local_unlock',array_column($panel['data'],'code'),true),'unlock visible in panel');
    check((int)$db->one("SELECT COUNT(*) n FROM audit_log WHERE tenant_id=? AND action='device.local_unlock_reported'",[$tenant])['n']===1,'one audit entry after replay');
    $old=$event; $old['event_id']=Util::uuid(); $old['at']=gmdate('Y-m-d\TH:i:s\Z',time()-90*86400);
    $late=expect(request('POST','/client/logs',['entries'=>[$old]],$key,$token['access_token'],Util::uuid()),200,'long offline report');
    check($late['acks'][0]['status']==='accepted','long offline report accepted');
    check((int)$db->one("SELECT COUNT(*) n FROM audit_log WHERE tenant_id=? AND action='device.local_unlock_reported'",[$tenant])['n']===2,'late report reaches audit despite log retention');
    expect(adminRequest($other,'POST','/auth/reauth',['password'=>$other['password']]),200,'other reauth');
    expect(adminRequest($other,'PUT','/admin/tenants/'.$bid.'/unlock-pin',['pin'=>$pin]),200,'other PIN');
    $db->run('UPDATE tenant_unlock_pin SET pin_enc=? WHERE tenant_id=?',[$row['pin_enc'],$b['tenant_id']]);
    expect(adminRequest($other,'POST','/admin/tenants/'.$bid.'/unlock-pin/reveal'),500,'ciphertext bound to tenant');
    echo 'PASS unlock PIN (' . ($GLOBALS['checks'] - $before) . " assertions)\n";
}
