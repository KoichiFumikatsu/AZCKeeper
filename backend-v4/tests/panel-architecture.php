<?php
declare(strict_types=1);
use Keeper\{Database,Util,ProductivityWorker,Placeholders};

function panelArchitectureTests(Database $db): void
{
    $before=$GLOBALS['checks']; $u=secondTenant($db); $t=$u['tenant_id']; $uid=Util::id($u['id']);
    $a=sharedPlatform($db); $a['tenant']=$t;
    $day=(new DateTimeImmutable('yesterday',new DateTimeZone('America/Bogota')))->format('Y-m-d'); $to=(new DateTimeImmutable($day))->modify('+1 day')->format('Y-m-d');
    $db->run("UPDATE schedules SET start_local='08:00:00',end_local='17:30:00' WHERE tenant_id=? AND id=?",[$t,$u['schedule_id']]);
    for ($n=1;$n<=7;$n++) { $db->run("INSERT INTO schedule_days (tenant_id,schedule_id,weekday,start_local,end_local) VALUES (?,?,?,'08:00:00','17:30:00')",[$t,$u['schedule_id'],$n]); }
    $settings=['punctuality_margin_minutes'=>15,'default_schedule_id'=>Util::id($u['schedule_id'])];
    expect(adminRequest($a,'GET','/schedules/settings'),200,'schedule settings default');
    expect(adminRequest($a,'PUT','/schedules/settings',$settings),200,'schedule settings save');
    check((int)$db->one('SELECT COUNT(*) n FROM rollup_days WHERE tenant_id=?',[$t])['n']===30,'schedule settings queue exactly 30 days');
    $foreign=secondTenant($db); expect(adminRequest($a,'PUT','/schedules/settings',array_replace($settings,['default_schedule_id'=>Util::id($foreign['schedule_id'])])),404,'default schedule tenant boundary');
    expect(adminRequest($a,'PUT','/schedules/settings',array_replace($settings,['punctuality_margin_minutes'=>181])),422,'margin bounded');
    $placeholder=Placeholders::schedule($db,$t,'America/Bogota');
    $db->run('UPDATE users SET schedule_id=? WHERE tenant_id=? AND id=?',[$placeholder,$t,$u['id']]);
    $db->run('UPDATE user_assignments SET schedule_id=?,starts_at=?-INTERVAL 60 DAY WHERE tenant_id=? AND user_id=?',[$placeholder,$day,$t,$u['id']]);
    $ua=$db->one('SELECT id FROM user_assignments WHERE tenant_id=? AND user_id=? AND ends_at IS NULL',[$t,$u['id']])['id'];
    $token=enroll($db,$u,keypair()); $device=Util::bin($token['device_id']);
    $da=$db->one('SELECT id FROM device_assignments WHERE tenant_id=? AND device_id=? AND ends_at IS NULL',[$t,$device])['id'];
    $db->run('UPDATE device_assignments SET starts_at=?-INTERVAL 60 DAY WHERE tenant_id=? AND id=?',[$day,$t,$da]);
    $event=Util::bin(Util::uuid());
    $db->run("INSERT INTO episodes (tenant_id,device_id,event_id,user_id,device_assignment_id,user_assignment_id,event_date,started_at,ended_at,process_name,site,window_title,active_seconds,idle_seconds,call_seconds,body_hash) VALUES (?,?,?,?,?,?,?,?,?,'WINWORD','example.test','PRIVATE TITLE MUST NOT LEAK',1800,1800,0,?)",[$t,$device,$event,$u['id'],$da,$ua,$day,"$day 12:00:00","$day 13:00:00",random_bytes(32)]);
    $windows=expect(adminRequest($a,'GET',"/users/$uid/windows?from=$day&to=$to"),200,'windows aggregate');
    check(count($windows['data'])===1 && $windows['data'][0]['active_seconds']===1800 && $windows['data'][0]['idle_seconds']===1800 && $windows['data'][0]['sessions']===1,'windows aggregate active idle visits');
    $timeline=expect(adminRequest($a,'GET',"/users/$uid/timeline?day=$day&limit=1"),200,'timeline one page');
    check(!str_contains(Util::json([$windows,$timeline]),'PRIVATE TITLE') && !array_key_exists('title',$timeline['data'][0]),'no titles even for platform');
    expect(adminRequest($a,'GET',"/users/$uid/windows?from=$to&to=$day"),422,'windows invalid range');
    foreach (['07:00:00'=>'temprano','08:00:00'=>'a_tiempo','08:15:00'=>'a_tiempo','08:15:01'=>'tarde','07:44:59'=>'temprano','10:30:00'=>'tarde'] as $clock=>$expected) {
        $start=(new DateTimeImmutable("$day $clock",new DateTimeZone('America/Bogota')))->setTimezone(new DateTimeZone('UTC'));
        $db->run('DELETE FROM episodes WHERE tenant_id=?',[$t]);
        $db->run("INSERT INTO episodes (tenant_id,device_id,event_id,user_id,device_assignment_id,user_assignment_id,event_date,started_at,ended_at,process_name,active_seconds,idle_seconds,call_seconds,body_hash) VALUES (?,?,?,?,?,?,?,?,?,'WINWORD',1800,1800,0,?)",[$t,$device,Util::bin(Util::uuid()),$u['id'],$da,$ua,$day,$start->format('Y-m-d H:i:s'),$start->modify('+1 hour')->format('Y-m-d H:i:s'),random_bytes(32)]);
        $db->run('UPDATE rollup_days SET dirty=TRUE WHERE tenant_id=? AND day=?',[$t,$day]);
        (new ProductivityWorker($db))->run(Util::id($t),40,60);
        $r=expect(adminRequest($a,'GET',"/users/$uid/activity?from=$day&to=$to"),200,'arrival '.$clock);
        check($r['data'][0]['status']===$expected,'arrival classified '.$clock.' '.$r['data'][0]['status']);
        check($r['data'][0]['expected_seconds']===34200,'placeholder uses company default');
    }
    check($db->one('SELECT schedule_id FROM user_assignments WHERE tenant_id=? AND id=?',[$t,$ua])['schedule_id']===$placeholder,'default does not rewrite assignments');
    $old=(new DateTimeImmutable($day))->modify('-40 days')->format('Y-m-d');
    $db->run("INSERT INTO day_summary (tenant_id,day,user_id,user_assignment_id,active_seconds,idle_seconds,productive_seconds,expected_seconds,coverage_percent,productivity_percent,calculation_version,timezone,calculated_at,data_through,first_activity) VALUES (?,?,?,?,3600,0,3600,34200,10,100,'k3-import-test','America/Bogota',UTC_TIMESTAMP(),UTC_TIMESTAMP(),?)",[$t,$old,$u['id'],$ua,"$old 13:10:00"]);
    expect(adminRequest($a,'PUT','/schedules/settings',array_replace($settings,['punctuality_margin_minutes'=>0])),200,'change margin');
    $oldTo=(new DateTimeImmutable($old))->modify('+1 day')->format('Y-m-d');
    $r=expect(adminRequest($a,'GET',"/users/$uid/activity?from=$old&to=$oldTo"),200,'old calculation'); check($r['data'][0]['status']==='a_tiempo','older than 30 days retains prior margin');
    expect(adminRequest($a,'PUT',"/users/$uid/portal-access",['portal_login_enabled'=>true]),200,'portal flag save');
    $user=expect(adminRequest($a,'GET',"/users/$uid"),200,'latest user'); check($user['portal_login_enabled'] && !$user['panel_login_enabled'],'portal flag does not enable panel');
    $user=expect(adminRequest($a,'PATCH',"/users/$uid",['employment_status'=>'vacaciones'],['if-match'=>'"'.$user['version'].'"']),200,'employment leave'); check($user['status']==='active','vacation does not revoke');
    $user=expect(adminRequest($a,'PATCH',"/users/$uid",['employment_status'=>'retirado'],['if-match'=>'"'.$user['version'].'"']),200,'employment retirement');
    check($user['status']==='inactive' && $db->one('SELECT status FROM devices WHERE tenant_id=? AND id=?',[$t,$device])['status']==='revoked','retirement revokes device');
    $presence=expect(adminRequest($a,'GET','/reports/presence'),200,'today excludes retired'); check(!in_array($uid,array_column($presence['data'],'user_id'),true),'retired people not counted in current arrivals');
    $health=expect(adminRequest($a,'GET','/tenants/server-health'),200,'calculation status'); check(array_key_exists('cron_last_error',$health),'health exposes last error');
    $db->run("UPDATE schedules SET timezone='Invalid/Fixture' WHERE tenant_id=? AND id=?",[$t,$u['schedule_id']]);
    try { (new ProductivityWorker($db))->run(Util::id($t),1,5); check(false,'invalid timezone must fail calculation'); } catch (Exception) {}
    $health=expect(adminRequest($a,'GET','/tenants/server-health'),200,'failed calculation status');
    check($health['cron_status']==='failed' && str_contains($health['cron_last_error'],'Invalid/Fixture') && mb_strlen($health['cron_last_error'])<=1000,'calculation persists bounded error');
    $db->run("UPDATE schedules SET timezone='America/Bogota' WHERE tenant_id=? AND id=?",[$t,$u['schedule_id']]);
    $problems=expect(adminRequest($a,'GET','/dashboard/problems'),200,'platform sees calculation failure'); check(in_array('calculation_failed',array_column($problems['data'],'kind'),true),'calculation problem actionable');
    echo 'PASS panel architecture ('.($GLOBALS['checks']-$before)." assertions)\n";
}
