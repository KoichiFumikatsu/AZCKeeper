<?php
declare(strict_types=1);
use Keeper\{Database,ProductivityWorker,Util,Config};

function activityMigrationTests(Database $db): void
{
    $before=$GLOBALS['checks']; $u=secondTenant($db); $tenant=$u['tenant_id']; $tid=Util::id($tenant);
    $admin=adminFixture($db,$u,['reportes.ver','reglas.ver','migracion.ver','migracion.gestionar','migracion.recuperar']);
    $reader=adminFixture($db,secondTenant($db),['usuarios.ver']);
    $foreign=secondTenant($db);
    $day=(new DateTimeImmutable('yesterday'))->format('Y-m-d'); $from=(new DateTimeImmutable($day))->modify('-19 days')->format('Y-m-d'); $to=(new DateTimeImmutable($day))->modify('+1 day')->format('Y-m-d');
    $db->pdo->beginTransaction();
    $db->run('UPDATE user_assignments SET starts_at=UTC_TIMESTAMP()-INTERVAL 150 DAY WHERE tenant_id=?',[$tenant]);
    for ($weekday=1;$weekday<=7;$weekday++) { $db->run('INSERT INTO schedule_days VALUES (?,?,?)',[$tenant,$u['schedule_id'],$weekday]); }
    $users=[$u];
    for ($n=1;$n<238;$n++) {
        $member=adminMember($db,$u); $users[]=$member;
        $db->run('INSERT INTO user_assignments (tenant_id,id,user_id,firm_id,site_id,area_id,position_id,schedule_id,starts_at) VALUES (?,?,?,?,?,?,?,?,UTC_TIMESTAMP()-INTERVAL 150 DAY)',[$tenant,Util::bin(Util::uuid()),$member['id'],$u['firm_id'],$u['site_id'],$u['area_id'],$u['position_id'],$u['schedule_id']]);
    }
    $db->run('CREATE TEMPORARY TABLE fixture_devices (n INT PRIMARY KEY,device_id BINARY(16),user_id BINARY(16),ua BINARY(16),da BINARY(16))');
    for ($n=0;$n<298;$n++) {
        $member=$users[$n%238]; $device=Util::bin(Util::uuid()); $assignment=Util::bin(Util::uuid());
        $db->run("INSERT INTO devices (tenant_id,id,user_id,hostname,agent_version,os_edition,cpu,ram_bytes,capabilities) VALUES (?,?,?,'synthetic','4.0.0','test','test',1,'[]')",[$tenant,$device,$member['id']]);
        $db->run("INSERT INTO device_assignments (tenant_id,id,device_id,user_id,starts_at,reason) VALUES (?,?,?,?,UTC_TIMESTAMP()-INTERVAL 150 DAY,'synthetic')",[$tenant,$assignment,$device,$member['id']]);
        $db->run("INSERT INTO principals (tenant_id,id,kind,device_id) VALUES (?,?,'device',?)",[$tenant,Util::bin(Util::uuid()),$device]);
        $ua=$db->one('SELECT id FROM user_assignments WHERE tenant_id=? AND user_id=?',[$tenant,$member['id']])['id'];
        $db->run('INSERT INTO fixture_devices VALUES (?,?,?,?,?)',[$n,$device,$member['id'],$ua,$assignment]);
    }
    foreach (['productive','neutral','unproductive'] as $n=>$category) { $db->run('INSERT INTO app_classification (tenant_id,id,process_name,category) VALUES (?,?,?,?)',[$tenant,Util::bin(Util::uuid()),'app'.$n,$category]); }
    $db->run('CREATE TEMPORARY TABLE fixture_numbers (n INT PRIMARY KEY)');
    for ($step=0;$step<44000;$step+=500) { $db->run('INSERT INTO fixture_numbers VALUES '.implode(',',array_fill(0,500,'(?)')),range($step,$step+499)); }
    $start=microtime(true);
    $db->run("INSERT INTO episodes (tenant_id,device_id,event_id,user_id,device_assignment_id,user_assignment_id,event_date,started_at,ended_at,process_name,active_seconds,idle_seconds,call_seconds,body_hash)
        SELECT ?,d.device_id,UNHEX(LPAD(HEX(n.n+1),32,'0')),d.user_id,d.da,d.ua,DATE(DATE_ADD(?,INTERVAL FLOOR(n.n/298)%20 DAY)),
        DATE_ADD(DATE_ADD(CONCAT(?,' 13:00:00'),INTERVAL FLOOR(n.n/298)%20 DAY),INTERVAL FLOOR(n.n/5960)*120 SECOND),
        DATE_ADD(DATE_ADD(CONCAT(?,' 13:00:00'),INTERVAL FLOOR(n.n/298)%20 DAY),INTERVAL FLOOR(n.n/5960)*120+60 SECOND),
        CONCAT('app',n.n%3),50,10,5,UNHEX(SHA2(CONCAT('synthetic',n.n),256))
        FROM fixture_numbers n JOIN fixture_devices d ON d.n=n.n%298 WHERE n.n<43969",[$tenant,$from,$from,$from]);
    // Retained historical aggregates reproduce the imported workload independently of raw retention.
    $db->run("INSERT INTO day_summary (tenant_id,day,user_id,user_assignment_id,active_seconds,idle_seconds,productive_seconds,expected_seconds,coverage_percent,productivity_percent,calculation_version,timezone,calculated_at,data_through)
        SELECT ?,DATE_SUB(?,INTERVAL n.n+1 DAY),a.user_id,a.id,50,10,50,32400,1,100,'synthetic-import','America/Bogota',UTC_TIMESTAMP(),UTC_TIMESTAMP() FROM user_assignments a JOIN fixture_numbers n ON n.n<87 WHERE a.tenant_id=? LIMIT 20704",[$tenant,$from,$tenant]);
    $db->run("INSERT INTO focus_daily (tenant_id,day,user_id,user_assignment_id,focus_seconds,focus_score,calculation_version,calculated_at,data_through) SELECT tenant_id,day,user_id,user_assignment_id,50,100,'synthetic-import',calculated_at,data_through FROM day_summary WHERE tenant_id=? LIMIT 17310",[$tenant]);
    $db->run("INSERT INTO episode_daily (tenant_id,day,user_id,user_assignment_id,device_id,process_name,category,active_seconds,idle_seconds,episode_count,calculation_version,calculated_at,data_through)
        SELECT ?,DATE_SUB(?,INTERVAL n.n+1 DAY),d.user_id,d.ua,d.device_id,'app0','productive',50,10,1,'synthetic-import',UTC_TIMESTAMP(),UTC_TIMESTAMP() FROM fixture_devices d JOIN fixture_numbers n ON n.n<70 LIMIT 20756",[$tenant,$from]);
    $db->pdo->commit();
    check((int)$db->one('SELECT COUNT(*) n FROM episodes WHERE tenant_id=?',[$tenant])['n']===43969,'43969 raw synthetic episodes');
    foreach (['day_summary'=>20704,'focus_daily'=>17310,'episode_daily'=>20756] as $table=>$count) { check((int)$db->one("SELECT COUNT(*) n FROM $table WHERE tenant_id=?",[$tenant])['n']===$count,'import-size '.$table); }
    for ($n=0;$n<20;$n++) { $db->run('INSERT INTO rollup_days (tenant_id,day) VALUES (?,DATE_ADD(?,INTERVAL ? DAY))',[$tenant,$from,$n]); }
    $worker=new ProductivityWorker($db); $r=$worker->run($tid,1,30); check($r['days']===1,'worker day budget checkpoint');
    $r=$worker->run($tid,30,300); check($r['days']===19,'worker resumes remaining days: '.Util::json($r));
    $sum=$db->one('SELECT SUM(active_seconds) a,SUM(idle_seconds) i,SUM(call_seconds) c,SUM(productive_seconds) p FROM day_summary WHERE tenant_id=? AND day>=? AND day<?',[$tenant,$from,$to]);
    check((int)$sum['a']===43969*50 && (int)$sum['i']===43969*10 && (int)$sum['c']===43969*5 && (int)$sum['p']===14657*50,'SQL aggregation matches independent expected sums');
    $sumFocus=$db->one('SELECT SUM(focus_seconds) f,SUM(distraction_seconds) d,SUM(deep_work_seconds) w FROM focus_daily WHERE tenant_id=? AND day>=? AND day<?',[$tenant,$from,$to]);
    check((int)$sumFocus['f']===14657*50 && (int)$sumFocus['d']===14656*50 && (int)$sumFocus['w']===0,'focus classification and no false deep work');
    $snapshot=$db->one('SELECT COUNT(*) n,SUM(active_seconds) s FROM episode_daily WHERE tenant_id=?',[$tenant]);
    check($worker->run($tid,30,300)['days']===0,'clean checkpoint noop');
    $process=proc_open([PHP_BINARY,dirname(__DIR__).'/config/productivity-cron.php','--tenant='.$tid,'--max-days=1','--budget-seconds=20'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    fclose($pipes[0]); $output=stream_get_contents($pipes[1]); $error=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
    check(proc_close($process)===0 && $error==='' && json_decode($output,true,16,JSON_THROW_ON_ERROR)['days']===0,'CLI cron invocation uses checkpoints');
    $db->run('UPDATE rollup_days SET dirty=TRUE,input_revision=input_revision+1 WHERE tenant_id=? AND day=?',[$tenant,$from]);
    check($worker->run($tid,1,60)['days']===1,'explicit recalculation');
    check($snapshot===$db->one('SELECT COUNT(*) n,SUM(active_seconds) s FROM episode_daily WHERE tenant_id=?',[$tenant]),'recalculation replaces rather than duplicates');
    $range='?from='.$from.'&to='.$to;
    $apps=expect(adminRequest($admin,'GET','/reports/apps'.$range.'&limit=1'),200,'app top limit');
    check($apps['total_seconds']===43969*60 && $apps['data'][0]['sessions']===14657 && $apps['data'][0]['percent_total']===33.33,'app denominator before limit');
    $presence=expect(adminRequest($admin,'GET','/reports/presence?day='.$day),200,'roster presence');
    check(count($presence['data'])===238,'roster includes 238 assigned people');
    $absent=expect(adminRequest($admin,'GET','/reports/presence?day='.$to),200,'roster without activity');
    check(count($absent['data'])===238 && count(array_filter($absent['data'],static fn($r)=>$r['status']==='sin_actividad'))===238,'no activity is not omitted');
    $series=expect(adminRequest($admin,'GET','/users/'.Util::id($u['id']).'/activity'.$range),200,'user series');
    check(count($series['data'])===20 && $series['totals']['call_seconds']>0,'daily metrics and calls');
    expect(adminRequest($admin,'GET','/users/'.Util::id($u['id']).'/policies'),200,'effective policies compiled');
    foreach (['/reports/apps'.$range,'/reports/presence?day='.$day,'/users/'.Util::id($u['id']).'/activity'.$range,'/users/'.Util::id($u['id']).'/policies'] as $path) {
        expect(adminRequest($reader,'GET',$path),403,'report missing permission');
        expect(adminRequest($admin,'GET',$path,null,['x-tenant-id'=>Util::id($foreign['tenant_id'])]),404,'report foreign tenant');
    }
    expect(adminRequest($admin,'GET','/reports/apps'.$range.'&tenant='.Util::id($foreign['tenant_id'])),404,'tenant query cannot override session');
    expect(adminRequest($admin,'GET','/users/'.Util::id($foreign['id']).'/activity'.$range),404,'foreign person detail');
    expect(adminRequest($admin,'GET','/reports/presence?day='.$day.'&from='.$from.'&to='.$to),422,'ambiguous date range');
    echo 'ACTIVITY VOLUME PASS: 238 users, 298 devices, 43969 episodes, 17310/20756/20704 imported aggregates; '.round(microtime(true)-$start,2)."s\n";

    $device=$db->one('SELECT device_id FROM fixture_devices WHERE n=0')['device_id']; $did=Util::id($device); $key=keypair();
    $migration='/devices/'.$did.'/migration'; $escrow='/devices/'.$did.'/escrow';
    expect(adminRequest($admin,'PUT',$migration,['phase'=>'instalado','revision'=>1]),200,'installed');
    expect(adminRequest($admin,'PUT',$migration,['phase'=>'enrolado','revision'=>2]),409,'enrollment evidence required');
    expect(adminRequest($reader,'POST','/migration/authorizations',['device_id'=>$did,'public_key_thumbprint'=>bin2hex(thumb($key[1])),'expires_in'=>600]),403,'migration permission');
    expect(adminRequest($admin,'POST','/migration/authorizations',['device_id'=>$did,'public_key_thumbprint'=>bin2hex(thumb($key[1])),'expires_in'=>600]),403,'migration requires recent reauth');
    expect(adminRequest($admin,'POST','/auth/reauth',['password'=>$admin['password']]),200,'migration reauth');
    $token=expect(adminRequest($admin,'POST','/migration/authorizations',['device_id'=>$did,'public_key_thumbprint'=>bin2hex(thumb($key[1])),'expires_in'=>600]),200,'migration token');
    expect(adminRequest($admin,'POST','/migration/authorizations/validate',['device_id'=>$did,'token'=>$token['token']]),200,'validate token binding');
    $otherDevice=Util::id($db->one('SELECT device_id FROM fixture_devices WHERE n=1')['device_id']);
    expect(adminRequest($admin,'POST','/migration/authorizations/validate',['device_id'=>$otherDevice,'token'=>$token['token']]),409,'token bound to device');
    $challenge=expect(request('POST','/client/auth/challenges',['enrollment_ticket'=>$token['token']]),200,'migration bootstrap challenge');
    $login=['enrollment_ticket'=>$token['token'],'public_key'=>$key[1],'hostname'=>'migrated','agent_version'=>'4.0.0'];
    $own=expect(request('POST','/client/login',$login,$key,null,null,$challenge['nonce']),200,'migration proof consumes token');
    check($own['device_id']===$did && $own['tenant_id']===$tid && $own['access_token']!==$token['token'],'own device credential');
    expect(request('POST','/client/login',$login,$key,null,null,$challenge['nonce']),401,'second token use rejected');
    expect(adminRequest($admin,'POST','/migration/authorizations/validate',['device_id'=>$did,'token'=>$token['token']]),409,'consumed token invalid');
    expect(adminRequest($admin,'PUT',$migration,['phase'=>'enrolado','revision'=>2]),200,'enrolled');
    expect(adminRequest($admin,'PUT',$migration,['phase'=>'escrow_ok','revision'=>3]),409,'no escrow ACK blocks advancement');
    $vault=expect(adminRequest($admin,'GET','/migration/escrow-key'),200,'vault public key');
    $password=Util::b64(random_bytes(24)); $sid='S-1-5-21-111-222-333-1001';
    $clear=['tenant_id'=>$tid,'device_id'=>$did,'account_sid'=>$sid,'revision'=>1,'password'=>$password];
    $cipher=sodium_crypto_box_seal(Util::json($clear),base64_decode($vault['public_key'],true));
    $body=['tenant_id'=>$tid,'device_id'=>$did,'account_sid'=>$sid,'revision'=>1,'key_id'=>$vault['key_id'],'envelope'=>base64_encode($cipher)];
    $ack=expect(adminRequest($admin,'POST',$escrow,$body),200,'durable escrow ACK');
    check($ack['persisted'] && $ack['sha256']===hash('sha256',$cipher),'ACK matches persisted ciphertext');
    check($ack===expect(adminRequest($admin,'POST',$escrow,$body),200,'same escrow retry'),'escrow retry stable ACK');
    expect(adminRequest($admin,'PUT',$migration,['phase'=>'escrow_ok','revision'=>3]),409,'ACK without recovery proof blocks advance');
    expect(adminRequest($admin,'POST',$escrow.'/verify',['revision'=>1,'sha256'=>str_repeat('0',64)]),409,'wrong ACK rejected');
    $verified=expect(adminRequest($admin,'POST',$escrow.'/verify',['revision'=>1,'sha256'=>$ack['sha256']]),200,'vault recovery proof');
    check(!str_contains(Util::json($verified),$password),'verification reveals no password');
    expect(adminRequest($reader,'POST',$escrow.'/recover',['revision'=>1,'reason'=>'Authorized test']),403,'explicit recovery permission');
    $recovered=expect(adminRequest($admin,'POST',$escrow.'/recover',['revision'=>1,'reason'=>'Authorized test']),200,'IT audited recovery');
    check($recovered['password']===$password && $db->one("SELECT id FROM audit_log WHERE tenant_id=? AND id=? AND action='migration.escrow_recovered'",[$tenant,Util::bin($recovered['audit_id'])])!==null,'recovery and mandatory persisted audit');
    $db->run("CREATE TRIGGER a1_audit_failure BEFORE INSERT ON audit_log FOR EACH ROW BEGIN IF NEW.action='migration.escrow_recovered' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='synthetic audit failure'; END IF; END");
    try {
        $failed=adminRequest($admin,'POST',$escrow.'/recover',['revision'=>1,'reason'=>'Audit failure test']);
        expect($failed,409,'audit failure denies secret'); check(!str_contains($failed[3],$password),'no secret without audit commit');
    } finally { $db->run('DROP TRIGGER a1_audit_failure'); }
    $audit=$db->run('SELECT changed_fields,reason FROM audit_log WHERE tenant_id=?',[$tenant])->fetchAll();
    check(!str_contains(Util::json($audit),$password) && !str_contains(Util::json($audit),$body['envelope']),'no escrow secrets in audit');
    foreach (['escrow_ok'=>3,'degradado'=>4,'pendiente_reinicio'=>5] as $phase=>$revision) { expect(adminRequest($admin,'PUT',$migration,['phase'=>$phase,'revision'=>$revision]),200,$phase); }
    expect(adminRequest($admin,'PUT',$migration,['phase'=>'completo','revision'=>6]),409,'standard token verification required');
    expect(adminRequest($admin,'PUT',$migration,['phase'=>'completo','revision'=>6,'standard_user_verified'=>true]),200,'migration complete');
    expect(adminRequest($admin,'PUT',$migration,['phase'=>'completo','revision'=>6,'standard_user_verified'=>true]),200,'phase retry idempotent');
    expect(adminRequest($admin,'POST',$escrow,array_replace($body,['tenant_id'=>Util::id($foreign['tenant_id'])])),404,'foreign envelope tenant');
    $clear['revision']=2; $rotated=$body; $rotated['revision']=2;
    $rotated['envelope']=base64_encode(sodium_crypto_box_seal(Util::json($clear),base64_decode($vault['public_key'],true)));
    expect(adminRequest($admin,'POST',$escrow,$rotated),200,'new escrow revision');
    $state=expect(adminRequest($admin,'GET',$migration),200,'rotated escrow state');
    check($state['phase']==='excepcion' && $state['escrow_revision']===null,'new escrow invalidates previous recovery proof');
    expect(adminRequest($admin,'PUT',$migration,['phase'=>'escrow_ok','revision'=>$state['revision']+1]),409,'unverified revision blocks advance');
    foreach ([['GET',$migration,null],['PUT',$migration,['phase'=>'excepcion','revision'=>99]],['POST',$escrow,$body],['POST',$escrow.'/verify',['revision'=>1,'sha256'=>$ack['sha256']]],['POST',$escrow.'/recover',['revision'=>1,'reason'=>'Foreign test']]] as [$method,$path,$payload]) {
        expect(adminRequest($admin,$method,$path,$payload,['x-tenant-id'=>Util::id($foreign['tenant_id'])]),404,'migration tenant boundary');
        expect(adminRequest($reader,$method,$path,$payload),403,'migration endpoint permission');
    }
    $expired=expect(adminRequest($admin,'POST','/migration/authorizations',['device_id'=>$otherDevice,'public_key_thumbprint'=>bin2hex(thumb(keypair()[1])),'expires_in'=>60]),200,'expiring migration authorization');
    $db->run('UPDATE enrollments SET created_at=UTC_TIMESTAMP()-INTERVAL 120 SECOND,expires_at=UTC_TIMESTAMP()-INTERVAL 60 SECOND WHERE tenant_id=? AND ticket_hash=?',[$tenant,hash('sha256',$expired['token'],true)]);
    expect(adminRequest($admin,'POST','/migration/authorizations/validate',['device_id'=>$otherDevice,'token'=>$expired['token']]),409,'expired migration token');
    expect(request('POST','/client/auth/challenges',['enrollment_ticket'=>$expired['token']]),401,'bootstrap rejects expired token');
    $db->run('DROP TEMPORARY TABLE fixture_devices'); $db->run('DROP TEMPORARY TABLE fixture_numbers');
    activityEdgeTests($db);
    echo 'ACTIVITY/MIGRATION PASS: '.($GLOBALS['checks']-$before)." assertions\n";
}

function activityEdgeTests(Database $db): void
{
    $u=secondTenant($db); $t=$u['tenant_id']; $key=keypair(); $identity=enroll($db,$u,$key); $device=Util::bin($identity['device_id']);
    $db->run('UPDATE user_assignments SET starts_at=UTC_TIMESTAMP()-INTERVAL 10 DAY WHERE tenant_id=?',[$t]);
    $db->run('UPDATE device_assignments SET starts_at=UTC_TIMESTAMP()-INTERVAL 10 DAY WHERE tenant_id=?',[$t]);
    $a=$db->one('SELECT id FROM user_assignments WHERE tenant_id=? AND user_id=?',[$t,$u['id']])['id'];
    $da=$db->one('SELECT id FROM device_assignments WHERE tenant_id=? AND device_id=?',[$t,$device])['id'];
    for ($n=1;$n<=7;$n++) { $db->run('INSERT INTO schedule_days VALUES (?,?,?)',[$t,$u['schedule_id'],$n]); }
    foreach (['editor'=>'productive','video'=>'unproductive'] as $app=>$category) { $db->run('INSERT INTO app_classification VALUES (?,?,?,?,1)',[$t,Util::bin(Util::uuid()),$app,$category]); }
    $day=gmdate('Y-m-d',time()-3*86400); $next=(new DateTimeImmutable($day))->modify('+1 day')->format('Y-m-d');
    foreach ([['13:05:00',900,900,0,0,'editor'],['13:20:00',900,900,0,0,'editor'],['13:36:00',60,60,0,0,'video'],['23:59:00',120,100,20,33,'video']] as [$at,$duration,$active,$idle,$calls,$app]) {
        $start=new DateTimeImmutable($day.' '.$at,new DateTimeZone($at==='23:59:00'?'America/Bogota':'UTC')); $end=$start->modify('+'.$duration.' seconds');
        $s=Util::sqlTime($start->format('c')); $e=Util::sqlTime($end->format('c'));
        $db->run('INSERT INTO episodes (tenant_id,device_id,event_id,user_id,device_assignment_id,user_assignment_id,event_date,started_at,ended_at,process_name,active_seconds,idle_seconds,call_seconds,body_hash) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)',[$t,$device,Util::bin(Util::uuid()),$u['id'],$da,$a,substr($s,0,10),$s,$e,$app,$active,$idle,$calls,hash('sha256',$s,true)]);
    }
    foreach ([$day,$next] as $d) { $db->run('INSERT INTO rollup_days (tenant_id,day) VALUES (?,?)',[$t,$d]); }
    $worker=new ProductivityWorker($db); $worker->run(Util::id($t),2,60);
    $rows=$db->run('SELECT * FROM day_summary WHERE tenant_id=? ORDER BY day',[$t])->fetchAll();
    check(count($rows)===2 && (int)$rows[0]['active_seconds']===1910 && (int)$rows[1]['active_seconds']===50 && (int)$rows[0]['call_seconds']===16 && (int)$rows[1]['call_seconds']===17,'midnight conservation and call subset');
    check((int)$rows[0]['late_seconds']===300 && $rows[0]['first_activity']===$day.' 13:05:00.000000','five minute lateness in schedule zone');
    $focus=$db->one('SELECT * FROM focus_daily WHERE tenant_id=? AND day=?',[$t,$day]);
    check((int)$focus['deep_work_seconds']===1800 && (int)$focus['focus_seconds']===1800 && (int)$focus['context_switches']===1 && (int)$focus['distraction_seconds']===110,'deep work islands and context changes');
    $db->run('UPDATE rollup_days SET dirty=TRUE,input_revision=input_revision+1 WHERE tenant_id=? AND day=?',[$t,$day]);
    $db->run("CREATE TRIGGER a1_rollup_failure BEFORE INSERT ON focus_daily FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='synthetic interruption'");
    try { $worker->run(Util::id($t),1,60); throw new RuntimeException('Expected rollup failure'); }
    catch (PDOException) { check($rows===$db->run('SELECT * FROM day_summary WHERE tenant_id=? ORDER BY day',[$t])->fetchAll(),'failed rollup restores all daily summaries'); }
    finally { $db->run('DROP TRIGGER a1_rollup_failure'); }
    check((bool)$db->one('SELECT dirty FROM rollup_days WHERE tenant_id=? AND day=?',[$t,$day])['dirty'],'failed checkpoint remains resumable');
    check($worker->run(Util::id($t),1,60)['days']===1,'retry after interrupted transaction');
    $db->run("UPDATE schedules SET timezone='America/New_York' WHERE tenant_id=? AND id=?",[$t,$u['schedule_id']]);
    Keeper\ActivityCalendar::create($db,$t,'2026-03-08','2026-03-09');
    check((int)$db->one('SELECT TIMESTAMPDIFF(SECOND,starts_at,ends_at) seconds FROM activity_calendar')['seconds']===82800,'23-hour DST day');
    $db->run('DROP TEMPORARY TABLE activity_calendar');
}
