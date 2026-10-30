<?php
declare(strict_types=1);
use Keeper\{Database,Validator,ApiError,Util};

function supervisorContractTests(?Database $db = null): void
{
    $v = new Validator();
    foreach (['/reports/supervisor'=>'getSupervisorOverview','/users/{id}/supervisor-summary'=>'getSupervisorPersonSummary'] as $path=>$op) {
        check(($v->contract['paths'][$path]['get']['operationId']??null)===$op, "supervisor contract $op");
        check($v->contract['paths'][$path]['get']['security']===[['adminSession'=>[]]], 'panel session only');
        check(!Keeper\Portal::allowed('GET',$path),'new supervisor route absent from Portal');
    }
    $v->named('SupervisorAvailability',(object)['status'=>'no_measurement','reason'=>'no_capture']);
    $v->named('SupervisorProtection',(object)['state'=>'unconfirmed','reasons'=>[(object)['code'=>'no_report','device_id'=>null,'control'=>null,'report_at'=>null,'last_seen_at'=>null]]]);
    foreach ([['SupervisorAvailability',(object)['status'=>'invented','reason'=>null]],['SupervisorProtection',(object)['state'=>'partial','reasons'=>[]]],['SupervisorLimit',0],['SupervisorLimit',101],['SupervisorDay','2026-02-30']] as [$name,$value]) {
        try { $v->named($name,$value); check(false,"reject $name"); } catch (ApiError $e) { check($e->status===422,"reject $name"); }
    }
    echo "PASS supervisor contracts\n";
}

function supervisorTests(Database $db): void
{
    supervisorContractTests($db);
    supervisorContextTests($db);
    supervisorActivityTests($db);
    supervisorApiTests($db);
    supervisorScopeAndSignalsTests($db);
    supervisorMultiClientTests($db);
    supervisorCalendarTests($db);
    supervisorTrendTests($db);
}

function supervisorTrendTests(Database $db): void
{
    $u=secondTenant($db); $t=$u['tenant_id']; $p=sharedPlatform($db);
    $c=supervisorOrigin($db,$p,null,true);
    $monday=(new DateTimeImmutable('monday last week',new DateTimeZone('America/Bogota')))->format('Y-m-d');
    $previous=(new DateTimeImmutable($monday))->modify('-7 days')->format('Y-m-d');
    $db->run('UPDATE user_assignments SET starts_at=? WHERE tenant_id=? AND user_id=?',[(new DateTimeImmutable($previous))->modify('-2 days')->format('Y-m-d'),$t,$u['id']]);
    for ($w=1;$w<=7;$w++) { $db->run('INSERT INTO schedule_days (tenant_id,schedule_id,weekday) VALUES (?,?,?)',[$t,$u['schedule_id'],$w]); }
    $assignment=$db->one('SELECT id FROM user_assignments WHERE tenant_id=? AND user_id=?',[$t,$u['id']])['id'];
    foreach ([[$monday,10800],[$previous,5400]] as [$day,$seconds]) {
        $db->run("INSERT INTO day_summary (tenant_id,day,user_id,user_assignment_id,active_seconds,idle_seconds,productive_seconds,expected_seconds,coverage_percent,calculation_version,timezone,calculated_at,data_through) VALUES (?,?,?,?,?,0,?,32400,0,'test','America/Bogota',UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))",[$t,$day,$u['id'],$assignment,$seconds,$seconds]);
    }
    $run=function (string $localNow) use ($db,$c,$t): array {
        $model=new Keeper\SupervisorReadModel($db,$c,['tenant_id'=>Util::id($t)]);
        (new ReflectionProperty($model,'now'))->setValue($model,(new DateTimeImmutable($localNow,new DateTimeZone('America/Bogota')))->getTimestamp());
        return $model->overview()['kpis']['trend'];
    };
    $tuesday=(new DateTimeImmutable($monday))->modify('+1 day')->format('Y-m-d');
    $trend=$run("$tuesday 10:00:00");
    check($trend['availability']['status']==='available' && $trend['people']===1,'trend common measured cohort');
    check($trend['current']['workday_productive_seconds']===10800 && $trend['previous']['workday_productive_seconds']===5400,'trend equivalent closed windows');
    check(abs($trend['delta_pp']-100*5400/32400)<0.0001,'trend compares weighted P/J');
    $db->run('DELETE FROM day_summary WHERE tenant_id=? AND day=?',[$t,$previous]);
    check($run("$tuesday 10:00:00")['people']===0,'trend excludes missing previous measurement');
    check($run("$monday 10:00:00")['availability']['reason']==='no_closed_days','Monday excludes today');
    echo "PASS supervisor trend: equivalent closed days and common coverage\n";
}

function supervisorAt(Database $db,array $c,string $user,string $localNow): array
{
    $now=new DateTimeImmutable($localNow,new DateTimeZone($c['timezone']));
    $model=new Keeper\SupervisorReadModel($db,$c,['day'=>$now->format('Y-m-d')]);
    (new ReflectionProperty($model,'now'))->setValue($model,$now->getTimestamp());
    return $model->person($user);
}

function supervisorCalendarTests(Database $db): void
{
    $u=secondTenant($db); $t=$u['tenant_id']; $p=sharedPlatform($db);
    $c=array_replace(supervisorOrigin($db,$p,null,true),['tenant_id'=>$t,'timezone'=>'America/Bogota']);
    $day=(new DateTimeImmutable('today',new DateTimeZone('America/Bogota')))->modify('-3 days')->format('Y-m-d');
    $next=(new DateTimeImmutable($day))->modify('+1 day')->format('Y-m-d');
    $db->run('UPDATE user_assignments SET starts_at=? WHERE tenant_id=? AND user_id=?',[(new DateTimeImmutable($day))->modify('-15 days')->format('Y-m-d'),$t,$u['id']]);
    for ($w=1;$w<=7;$w++) { $db->run('INSERT INTO schedule_days (tenant_id,schedule_id,weekday) VALUES (?,?,?)',[$t,$u['schedule_id'],$w]); }
    $db->run("UPDATE schedules SET lunch_start_local='12:00',lunch_end_local='13:00' WHERE tenant_id=? AND id=?",[$t,$u['schedule_id']]);
    $kinds=fn($dto)=>array_column($dto['alerts']['data'],'kind');
    $dto=supervisorAt($db,$c,$u['id'],"$day 10:00:00");
    check(in_array('offline_in_schedule',$kinds($dto),true),'no devices offline during work');
    check($dto['activity']['active_seconds']===null && $dto['activity']['availability']['status']==='no_measurement','absence of capture never zero');
    check(!in_array('offline_in_schedule',$kinds(supervisorAt($db,$c,$u['id'],"$day 08:10:00")),true),'offline waits for arrival margin');
    check(!in_array('offline_in_schedule',$kinds(supervisorAt($db,$c,$u['id'],"$day 12:30:00")),true),'offline excludes lunch');
    $absence=Util::bin(Util::uuid());
    $db->run("INSERT INTO absences (tenant_id,id,user_id,kind,starts_at,ends_at,all_day,status) VALUES (?,?,?,'permiso',?,?,0,'approved')",[$t,$absence,$u['id'],"$day 14:00:00","$day 16:00:00"]);
    $dto=supervisorAt($db,$c,$u['id'],"$day 10:00:00");
    check(!in_array('offline_in_schedule',$kinds($dto),true) && $dto['activity']['workday_expected_seconds']===21600,'partial absence removes offline and expected hours');
    $db->run('DELETE FROM absences WHERE tenant_id=? AND id=?',[$t,$absence]);
    $holiday=Util::bin(Util::uuid()); $db->run("INSERT INTO holidays (tenant_id,id,day,name) VALUES (?,?,?,'test')",[$t,$holiday,$day]);
    $dto=supervisorAt($db,$c,$u['id'],"$day 10:00:00");
    check($dto['activity']['availability']['status']==='no_workday' && !in_array('offline_in_schedule',$kinds($dto),true),'holiday excludes work');
    $db->run('DELETE FROM holidays WHERE tenant_id=? AND id=?',[$t,$holiday]);
    $db->run("UPDATE schedules SET start_local='22:00',end_local='06:00',lunch_start_local='02:00',lunch_end_local='03:00' WHERE tenant_id=? AND id=?",[$t,$u['schedule_id']]);
    $dto=supervisorAt($db,$c,$u['id'],"$next 01:00:00");
    check($dto['schedule']['day']===$day && in_array('offline_in_schedule',$kinds($dto),true),'overnight work belongs to previous day');
    check(!in_array('offline_in_schedule',$kinds(supervisorAt($db,$c,$u['id'],"$next 02:30:00")),true),'overnight lunch excluded');
    $db->run("UPDATE tenants SET timezone='Asia/Tokyo' WHERE tenant_id=?",[$t]);
    $db->run("UPDATE schedules SET timezone='Asia/Tokyo' WHERE tenant_id=?",[$t]);
    $c['timezone']='Asia/Tokyo';
    $dto=supervisorAt($db,$c,$u['id'],"$next 01:00:00");
    check($dto['day']===$next && $dto['timezone']==='Asia/Tokyo' && $dto['schedule']['day']===$day,'tenant timezone controls local day');
    $old=(new DateTimeImmutable($day))->modify('-2 years')->format('Y-m-d');
    $dto=(new Keeper\SupervisorReadModel($db,$c,['day'=>$old]))->person($u['id']);
    check($dto['activity']['availability']['status']==='not_retained' && $dto['activity']['active_seconds']===null && !$dto['raw_detail_available'],'expired retention explicit');
    echo "PASS supervisor calendar, overnight, absences, timezone and retention\n";
}

function supervisorMultiClientTests(Database $db): void
{
    $clients=[secondTenant($db),secondTenant($db),secondTenant($db)]; $outside=secondTenant($db);
    $a=adminFixture($db,$clients[0],['usuarios.ver','cumplimiento.ver']);
    $group=Util::bin(Util::uuid()); $db->run("INSERT INTO company_groups (id,name) VALUES (?,'Supervisor 120')",[$group]);
    $db->run('UPDATE admin_accounts SET group_tenant_id=?,group_id=? WHERE tenant_id=? AND id=?',[Util::bin(Keeper\AdminAuth::PLATFORM),$group,$a['tenant'],$a['id']]);
    $commonId=Util::bin(Util::uuid()); $day=(new DateTimeImmutable('today',new DateTimeZone('America/Bogota')))->format('Y-m-d');
    foreach ($clients as $n=>$u) {
        $t=$u['tenant_id']; $db->run('INSERT INTO company_group_members (group_id,tenant_id) VALUES (?,?)',[$group,$t]);
        if ($n>0) {
            $role=Util::bin(Util::uuid()); $db->run("INSERT INTO roles (tenant_id,id,name) VALUES (?,?,'Multi reader')",[$t,$role]);
            foreach ($n===2?['usuarios.ver','cumplimiento.ver']:['usuarios.ver'] as $permission) { $db->run('INSERT INTO role_permissions VALUES (?,?,?)',[$t,$role,$permission]); }
            Keeper\AccessGrants::setForGroupAccount($db,$t,$a['tenant'],$a['id'],$role,Keeper\AccessScope::all());
        }
        $db->run("UPDATE users SET display_name='Supervisor multi 000' WHERE tenant_id=? AND id=?",[$t,$u['id']]);
        for ($i=1;$i<40;$i++) {
            $id=$i===1?$commonId:Util::bin(Util::uuid());
            $db->run('INSERT INTO users (tenant_id,id,display_name,firm_id,site_id,area_id,position_id,schedule_id) VALUES (?,?,?,?,?,?,?,?)',[$t,$id,'Supervisor multi '.str_pad((string)$i,3,'0',STR_PAD_LEFT),$u['firm_id'],$u['site_id'],$u['area_id'],$u['position_id'],$u['schedule_id']]);
        }
        $db->run("INSERT INTO dual_job_alerts (tenant_id,id,user_id,day,alert_type,severity,evidence,source) VALUES (?,?,?,?,'remote_desktop',?,'{}','worker')",[$t,Util::bin(Util::uuid()),$u['id'],$day,$n===0?'low':'high']);
    }
    $first=expect(adminRequest($a,'GET','/reports/supervisor?limit=50'),200,'three clients attention');
    check($first['cohort']['people']===120 && $first['data'][0]['tenant_id']===Util::id($clients[2]['tenant_id']),'high alert in third client sorts first');
    check($first['kpis']['alerts']['high_people']===1,'unauthorized compliance cannot affect KPI or order');
    $page=$first; $keys=[];
    do {
        foreach ($page['data'] as $p) { $keys[]=$p['tenant_id'].':'.$p['user_id']; }
        check($page['cohort']===$first['cohort'] && $page['kpis']['alerts']===$first['kpis']['alerts'],'multi client KPIs stable across pages');
        $cursor=$page['next_cursor'];
        if ($cursor!==null) { $page=expect(adminRequest($a,'GET','/reports/supervisor?limit=50&cursor='.urlencode($cursor)),200,'three client next page'); }
    } while ($cursor!==null);
    check(count($keys)===120 && count(array_unique($keys))===120,'120 people across three clients; repeated UUID safe');
    check(count($first['facets']['tenants'])===3 && !in_array(Util::id($outside['tenant_id']),array_column($first['facets']['tenants'],'id'),true),'unauthorized client absent in facets');
    $b=$a; $b['tenant']=$clients[1]['tenant_id'];
    $dto=expect(adminRequest($b,'GET','/users/'.Util::id($clients[1]['id']).'/supervisor-summary?day='.$day),200,'foreign group summary');
    check($dto['sections']['compliance']['status']==='not_authorized' && $dto['alerts']['pending_count']===null,'foreign section uses foreign role');
    $db->run('DELETE FROM access_grants WHERE tenant_id=? AND admin_id=?',[$clients[1]['tenant_id'],$a['id']]);
    expect(adminRequest($a,'GET','/reports/supervisor?limit=50&cursor='.urlencode($first['next_cursor'])),422,'revoked candidate invalidates cursor');
    expect(adminRequest($a,'GET','/reports/supervisor?tenant_id='.Util::id($clients[1]['tenant_id'])),404,'revoked candidate not selectable');
    $noSession=adminRequest($a,'GET','/reports/supervisor',null,['cookie'=>'__Host-keeper_admin=invalid']); expect($noSession,401,'invalid cookie');
    $db->run('UPDATE admin_accounts SET must_change_password=TRUE WHERE tenant_id=? AND id=?',[$a['tenant'],$a['id']]);
    expect(adminRequest($a,'GET','/reports/supervisor'),403,'temporary password guard');
    echo "PASS supervisor 120 people / three clients / independent permissions\n";
}

function supervisorScopeAndSignalsTests(Database $db): void
{
    $o=accessOrg($db); $t=$o['tenant']; $tid=Util::id($t);
    $permissions=['usuarios.ver','equipos.ver','reportes.ver','presencia.ver','cumplimiento.ver','reglas.ver'];
    $a=accessAccount($db,$o,'coord2',$permissions,new Keeper\AccessScope('filters',[$o['azc']],['Medellin'],[$o['med']],[$o['agentes']]));
    $today=(new DateTimeImmutable('today',new DateTimeZone('America/Bogota')))->format('Y-m-d');
    $yesterday=(new DateTimeImmutable($today))->modify('-1 day')->format('Y-m-d');
    $base='/reports/supervisor?tenant_id='.$tid;
    $list=expect(adminRequest($a,'GET',$base),200,'AND scope overview');
    $ids=array_column($list['data'],'user_id'); sort($ids); $wanted=[Util::id($o['coord2']['id']),Util::id($o['ag808']['id'])]; sort($wanted);
    check($ids===$wanted && $list['cohort']['people']===2,'AND across all scope dimensions');
    check(count($list['facets']['sites'])===1 && $list['facets']['sites'][0]['id']===Util::id($o['med']),'facets within scope');
    expect(adminRequest($a,'GET','/users/'.Util::id($o['bl1']['id']).'/supervisor-summary?day='.$today),404,'same tenant outside scope');
    $u=$o['ag808']; $uid=Util::id($u['id']); $assignment=$db->one('SELECT id FROM user_assignments WHERE tenant_id=? AND user_id=? AND ends_at IS NULL',[$t,$u['id']])['id'];
    $db->run('UPDATE user_assignments SET area_id=? WHERE tenant_id=? AND id=?',[$o['juridica'],$t,$assignment]);
    $db->run("INSERT INTO day_summary (tenant_id,day,user_id,user_assignment_id,active_seconds,idle_seconds,productive_seconds,expected_seconds,coverage_percent,calculation_version,timezone,calculated_at,data_through) VALUES (?,?,?,?,999,0,999,3600,0,'test','America/Bogota',UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))",[$t,$today,$u['id'],$assignment]);
    $summary='/users/'.$uid.'/supervisor-summary?day='.$today;
    $dto=expect(adminRequest($a,'GET',$summary),200,'history outside current scope');
    check(!$dto['raw_detail_available'] && $dto['activity']['active_seconds']===null,'historical foreign assignment omitted');
    $db->run('UPDATE user_assignments SET area_id=? WHERE tenant_id=? AND id=?',[$u['area_id'],$t,$assignment]);
    $db->run("INSERT INTO punctuality_margins (tenant_id,from_day,minutes) VALUES (?,'2026-01-01',20)",[$t]);
    $db->run("UPDATE day_summary SET first_activity=CONCAT(?,' 13:19:00') WHERE tenant_id=? AND day=? AND user_id=?",[$today,$t,$today,$u['id']]);
    $dto=expect(adminRequest($a,'GET',$summary),200,'configured margin');
    check($dto['arrival']['margin_minutes']===20 && $dto['arrival']['late']!==true,'configured margin instead of fixed 15');
    $db->run("INSERT INTO dual_job_alerts (tenant_id,id,user_id,day,alert_type,severity,evidence,source) VALUES (?,?,?,?,'foreign_app','low',?,'worker')",[$t,Util::bin(Util::uuid()),$u['id'],$today,Util::json(['apps'=>[['process_name'=>'foreign.exe','active_seconds'=>10]],'secret'=>'must not appear'])]);
    $device=$db->one('SELECT id FROM devices WHERE tenant_id=? AND user_id=?',[$t,$u['id']])['id'];
    $app=Util::bin(Util::uuid()); $db->run("INSERT INTO suspicious_apps (tenant_id,id,app_pattern,category) VALUES (?,?,'foreign.exe','foreign_workspace')",[$t,$app]);
    $db->run("INSERT INTO suspicious_app_detections (tenant_id,id,user_id,user_assignment_id,device_id,app_id,day,process_name,active_seconds,first_activity,last_activity) VALUES (?,?,?,?,?,?,?,'foreign.exe',10,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))",[$t,Util::bin(Util::uuid()),$u['id'],$assignment,$device,$app,$today]);
    $dto=expect(adminRequest($a,'GET',$summary),200,'deduplicate compliance');
    $apps=array_values(array_filter($dto['alerts']['data'],fn($x)=>$x['kind']==='suspicious_app'));
    check(count($apps)===1 && !str_contains(Util::json($dto),'must not appear'),'deduplicated sanitized evidence');
    check(array_key_exists('calculated_at',$dto['alerts']) && $dto['alerts']['calculated_at']===null,'unknown alert calculation time explicit');
    $db->run("INSERT INTO rollup_days (tenant_id,day,dirty,calculated_at,data_through) VALUES (?,?,FALSE,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))",[$t,$today]);
    $fresh=expect(adminRequest($a,'GET',$summary),200,'alert calculation freshness');
    check($fresh['alerts']['calculated_at']!==null && $fresh['alerts']['data_through']!==null,'alert calculation from selected day');
    $db->run('UPDATE dual_job_alerts SET reviewed=TRUE WHERE tenant_id=? AND user_id=?',[$t,$u['id']]);
    $db->run('UPDATE suspicious_app_detections SET reviewed=TRUE WHERE tenant_id=? AND user_id=?',[$t,$u['id']]);
    $dto=expect(adminRequest($a,'GET',$summary),200,'reviewed alerts');
    check($dto['alerts']['pending_count']===0 && $dto['alerts']['data'][0]['reviewed'],'reviewed retained in detail but not pending');
    $dto=expect(adminRequest($a,'GET','/users/'.$uid.'/supervisor-summary?day='.$yesterday),200,'historical offline unavailable');
    check(!$dto['alerts']['complete'] && in_array('offline_in_schedule',$dto['alerts']['unavailable_types'],true),'no invented historical offline');
    $db->run("UPDATE roles SET seed_key='it' WHERE tenant_id=? AND id=?",[$t,$a['role']]);
    $dto=expect(adminRequest($a,'GET',$summary),200,'IT protection permitted');
    check(isset($dto['current']['protection']) && isset($dto['current']['devices']['data'][0]['protection']),'IT role has protection');
    $db->run("DELETE FROM role_permissions WHERE tenant_id=? AND role_id=? AND permission_code='equipos.ver'",[$t,$a['role']]);
    $dto=expect(adminRequest($a,'GET',$summary),200,'missing devices permission');
    check($dto['current']['devices']['total']===null && !isset($dto['current']['protection']) && $dto['current']['connection']['state']===null,'missing devices not unprotected or offline');
    $db->run("DELETE FROM tenant_features WHERE tenant_id=? AND feature_code='productividad'",[$t]);
    $dto=expect(adminRequest($a,'GET',$summary),200,'missing activity feature');
    check($dto['activity']['availability']['status']==='not_contracted' && $dto['activity']['active_seconds']===null,'section feature required');
    Keeper\AccessGrants::setForUser($db,$t,$o['coord2']['id'],$a['role'],Keeper\AccessScope::own(),'manual');
    check(expect(adminRequest($a,'GET',$base),200,'self overview')['total']===1,'self sees only self');
    Keeper\AccessGrants::setForUser($db,$t,$o['coord2']['id'],$a['role'],new Keeper\AccessScope('team'),'manual');
    $db->run('UPDATE users SET reports_to_user_id=? WHERE tenant_id=? AND id=?',[$o['jur808']['id'],$t,$o['coord2']['id']]);
    check(expect(adminRequest($a,'GET',$base),200,'team with cycle')['total']===3,'transitive team cycle bounded');
    Keeper\AccessGrants::setForUser($db,$t,$o['coord2']['id'],$a['role'],new Keeper\AccessScope('filters'),'manual');
    check(expect(adminRequest($a,'GET',$base),200,'empty filters')['total']===0,'empty filters never all');
    echo "PASS supervisor scope, historical data, IT and section permissions\n";
}

function supervisorDevice(Database $db,array $u,string $name,string $state='applied'): string
{
    $t=$u['tenant_id']; $d=Util::bin(Util::uuid());
    $db->run("INSERT INTO devices (tenant_id,id,user_id,hostname,agent_version,os_edition,cpu,ram_bytes,capabilities,last_seen_at,policy_version) VALUES (?,?,?,?,'4.0.30','test','test',1024,'[]',UTC_TIMESTAMP(6),1)",[$t,$d,$u['id'],$name]);
    $db->run("INSERT INTO device_assignments (tenant_id,id,device_id,user_id,starts_at,reason) VALUES (?,?,?,?,UTC_TIMESTAMP(6)-INTERVAL 3 DAY,'test')",[$t,Util::bin(Util::uuid()),$d,$u['id']]);
    $rules=Util::json(['rules'=>array_map(fn($kind)=>['kind'=>$kind],['web','usb','download','installation'])]);
    $db->run("INSERT INTO effective_policies (tenant_id,device_id,policy_version,compiler_version,composition_hash,document,content_hash,created_at) VALUES (?,?,1,'test',?,?,?,UTC_TIMESTAMP(6)-INTERVAL 2 DAY)",[$t,$d,random_bytes(32),$rules,random_bytes(32)]);
    $controls=[]; foreach (Keeper\ProtectionSummary::CONTROLS as $def) { $controls[]=['control_id'=>$def['agent'],'state'=>$state]; }
    $db->run('INSERT INTO security_reports (tenant_id,device_id,event_id,observed_at,report_hash,controls) VALUES (?,?,?,UTC_TIMESTAMP(6)-INTERVAL 1 HOUR,?,?)',[$t,$d,Util::bin(Util::uuid()),random_bytes(32),Util::json($controls)]);
    return $d;
}

function supervisorApiTests(Database $db): void
{
    $u=secondTenant($db); $t=$u['tenant_id']; $tid=Util::id($t); $uid=Util::id($u['id']);
    $a=adminFixture($db,$u,['usuarios.ver','equipos.ver','presencia.ver','reportes.ver','cumplimiento.ver','reglas.ver']);
    $today=(new DateTimeImmutable('today',new DateTimeZone('America/Bogota')))->format('Y-m-d');
    $base='/reports/supervisor?tenant_id='.$tid;
    $list=expect(adminRequest($a,'GET',$base),200,'supervisor overview');
    check($list['cohort']['people']===1 && $list['total']===1,'roster count');
    check(!str_contains(Util::json($list),'protection'),'supervisor overview omits protection completely');
    $d1=supervisorDevice($db,$u,'A'); $d2=supervisorDevice($db,$u,'B','failed');
    $summary='/users/'.$uid.'/supervisor-summary?day='.$today.'&devices_limit=1';
    $dto=expect(adminRequest($a,'GET',$summary),200,'supervisor summary');
    check(!str_contains(Util::json($dto),'protection') && $dto['current']['devices']['total']===2,'supervisor keeps devices without protection');
    $platform=sharedPlatform($db); $pa=$platform; $pa['tenant']=$t;
    $dto=expect(adminRequest($pa,'GET',$summary),200,'platform summary');
    check($dto['current']['protection']['state']==='unprotected' && count($dto['current']['devices']['data'])===1,'all devices evaluated before page');
    check($dto['current']['devices']['data'][0]['protection']['state']==='protected','fresh applied controls');
    $db->run("UPDATE devices SET status='revoked' WHERE tenant_id=? AND id=?",[$t,$d2]);
    $dto=expect(adminRequest($pa,'GET',$summary),200,'revoked device excluded');
    check($dto['current']['protection']['state']==='protected','revoked failure excluded');
    $db->run('UPDATE devices SET policy_version=NULL WHERE tenant_id=? AND id=?',[$t,$d1]);
    check(expect(adminRequest($pa,'GET',$summary),200,'pending rules')['current']['protection']['state']==='unconfirmed','old policy cannot confirm');
    $db->run('INSERT INTO admin_policy_recompiles (tenant_id) VALUES (?)',[$t]);
    expect(adminRequest($pa,'GET',$summary),200,'read pending compilation');
    check((bool)$db->one('SELECT tenant_id FROM admin_policy_recompiles WHERE tenant_id=?',[$t]),'GET never compiles pending rules');
    $db->run('DELETE FROM admin_policy_recompiles WHERE tenant_id=?',[$t]);
    for ($i=0;$i<119;$i++) { $p=adminMember($db,$u); $db->run('UPDATE users SET display_name=? WHERE tenant_id=? AND id=?',['Persona '.str_pad((string)$i,3,'0',STR_PAD_LEFT),$t,$p['id']]); }
    $query=$base.'&order=name&limit=50'; $ids=[]; $first=null;
    do {
        $page=expect(adminRequest($a,'GET',$query),200,'global page'); $first??=$page;
        check($page['cohort']['people']===120 && $page['total']===120,'KPIs before pagination');
        array_push($ids,...array_column($page['data'],'user_id'));
        $query=$base.'&order=name&limit=50&cursor='.urlencode($page['next_cursor']??'');
    } while ($page['next_cursor']!==null);
    check(count($ids)===120 && count(array_unique($ids))===120,'all pages no duplicates or omissions');
    $cursorOrigin=supervisorOrigin($db,$a,$u['id']);
    $cursorOrigin['session_id']=$db->one('SELECT id FROM admin_sessions WHERE token_hash=?',[hash('sha256',$a['cookie'],true)])['id'];
    $cursorModel=new Keeper\SupervisorReadModel($db,$cursorOrigin,['tenant_id'=>$tid,'order'=>'name','limit'=>'50']);
    $cursorModel->authorizedContexts($cursorOrigin,$tid);
    $binding=(new ReflectionMethod($cursorModel,'cursorContext'))->invoke($cursorModel,'overview');
    $resources=new Keeper\Resources($db,['cursor_context'=>$binding],new Validator());
    $expired=$resources->decodeCursor($first['next_cursor']); $expired['exp']=time()-1;
    expect(adminRequest($a,'GET',$base.'&order=name&limit=50&cursor='.urlencode($resources->encodeCursor($expired))),422,'expired cursor');
    $anotherSession=adminLogin($a);
    expect(adminRequest($anotherSession,'GET',$base.'&order=name&limit=50&cursor='.urlencode($first['next_cursor'])),422,'same account different session cursor');
    $filtered=expect(adminRequest($a,'GET',$base.'&state=connected'),200,'state filter');
    check($filtered['cohort']['people']===120 && $filtered['total']===1,'state does not change cohort');
    $search=expect(adminRequest($a,'GET',$base.'&q=Persona%20118'),200,'global search');
    check($search['cohort']['people']===1,'search changes cohort beyond first page');
    expect(adminRequest($a,'GET',$base.'&order=name&limit=50&cursor='.urlencode($first['next_cursor']).'x'),422,'tampered cursor');
    expect(adminRequest($a,'GET',$base.'&order=attention&limit=50&cursor='.urlencode($first['next_cursor'])),422,'cursor different filters');
    $db->run("UPDATE users SET display_name='Changed order' WHERE tenant_id=? AND id=?",[$t,Util::bin($ids[1])]);
    expect(adminRequest($a,'GET',$base.'&order=name&limit=50&cursor='.urlencode($first['next_cursor'])),422,'sequence changed');
    foreach (['limit=0','limit=101','tenant_id=bad','area_id='.Util::uuid(),'protection=protected'] as $q) { expect(adminRequest($a,'GET','/reports/supervisor?'.$q),422,'invalid filter'); }
    expect(adminRequest($a,'GET','/reports/supervisor?tenant_id='.Util::uuid()),404,'foreign tenant');
    expect(adminRequest($a,'GET','/users/'.Util::uuid().'/supervisor-summary?day='.$today),404,'foreign person');
    expect(adminRequest($a,'GET','/users/'.$uid.'/supervisor-summary?day=2026-02-30'),422,'invalid date');
    expect(adminRequest($a,'GET','/users/'.$uid.'/supervisor-summary?day=2099-01-01'),422,'future date');
    expect(request('GET','/reports/supervisor'),401,'session required');
    expect(adminRequest($a,'GET','/reports/supervisor',null,['x-tenant-id'=>Util::uuid()]),200,'residual tenant ignored');
    echo "PASS supervisor API, device batches and pagination\n";
}

function supervisorActivityTests(Database $db): void
{
    $u=secondTenant($db); $t=$u['tenant_id'];
    $day=(new DateTimeImmutable('yesterday',new DateTimeZone('America/Bogota')))->format('Y-m-d');
    $to=(new DateTimeImmutable($day))->modify('+1 day')->format('Y-m-d');
    for ($w=1;$w<=7;$w++) { $db->run('INSERT INTO schedule_days (tenant_id,schedule_id,weekday) VALUES (?,?,?)',[$t,$u['schedule_id'],$w]); }
    $a=$db->one('SELECT id FROM user_assignments WHERE tenant_id=? AND user_id=?',[$t,$u['id']])['id'];
    $p=sharedPlatform($db); $c=array_replace(supervisorOrigin($db,$p,null,true),['tenant_id'=>$t,'timezone'=>'America/Bogota']);
    $access=new Keeper\AdminAccess($db,$c,'reportes.ver','getPeopleReport');
    $reader=new Keeper\ActivityReports($db,$c,$access);
    $rows=$reader->readRows($day,$to,['user_ids'=>[$u['id']],'current_scope'=>true]);
    check(count($rows)===1 && !$rows[0]['measured'],'no telemetry is not measured zero');
    $db->run("INSERT INTO day_summary (tenant_id,day,user_id,user_assignment_id,active_seconds,idle_seconds,productive_seconds,expected_seconds,coverage_percent,calculation_version,timezone,calculated_at,data_through) VALUES (?,?,?,?,0,60,0,32400,0,'supervisor-test','America/Bogota',UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))",[$t,$day,$u['id'],$a]);
    $rows=$reader->readRows($day,$to,['user_ids'=>[$u['id']],'current_scope'=>true]);
    check($rows[0]['measured'] && (int)$rows[0]['active_seconds']===0,'captured zero remains measured');
    check((int)$rows[0]['workday_expected_seconds']===32400,'calendar workday');
    $saved=$_GET; $_GET=['from'=>$day,'to'=>$to];
    try { $old=json_decode($reader->dispatch('getPeopleReport',null)['json'],true)['data'][0]['totals']; }
    finally { $_GET=$saved; }
    check($old['workday_expected_seconds']===(int)$rows[0]['workday_expected_seconds'] && $old['workday_productive_seconds']===(int)$rows[0]['workday_productive_seconds'],'old P/J unchanged');
    $db->run("UPDATE schedules SET lunch_start_local='12:00',lunch_end_local='13:00' WHERE tenant_id=? AND id=?",[$t,$u['schedule_id']]);
    $rows=$reader->readRows($day,$to,['user_ids'=>[$u['id']],'current_scope'=>true]);
    check((int)$rows[0]['workday_expected_seconds']===28800,'lunch subtracted');
    check(count($rows[0]['work_periods'])===1 && $rows[0]['lunch']!==null,'schedule projected');
    echo "PASS supervisor activity batches\n";
}

function supervisorOrigin(Database $db,array $account,?string $user,bool $platform=false): array
{
    $t=$db->one('SELECT timezone,auth_version FROM tenants WHERE tenant_id=?',[$account['tenant']]);
    return ['tenant_id'=>$account['tenant'],'admin_tenant_id'=>$account['tenant'],'admin_id'=>$account['id'],'user_id'=>$user,'platform'=>$platform,'group_id'=>null,
        'timezone'=>$t['timezone'],'auth_revision'=>(int)$t['auth_version'],'session_id'=>str_repeat('s',16)];
}

function supervisorContextTests(Database $db): void
{
    $a=secondTenant($db); $b=secondTenant($db); $c=secondTenant($db);
    $account=adminFixture($db,$a,['usuarios.ver']);
    $origin=supervisorOrigin($db,$account,$a['id']);
    $model=new Keeper\SupervisorReadModel($db,$origin);
    check(array_column($model->authorizedContexts($origin,null),'tenant_id')===[$a['tenant_id']],'normal context only home');
    try { $model->authorizedContexts($origin,Util::id($b['tenant_id'])); check(false,'foreign tenant'); } catch (ApiError $e) { check($e->status===404,'foreign tenant 404'); }
    $group=Util::bin(Util::uuid());
    $db->run('INSERT INTO company_groups (id,name) VALUES (?,?)',[$group,'Supervisor test group']);
    foreach ([$a,$b] as $u) { $db->run('INSERT INTO company_group_members (group_id,tenant_id) VALUES (?,?)',[$group,$u['tenant_id']]); }
    $db->run('UPDATE admin_accounts SET group_tenant_id=?,group_id=? WHERE tenant_id=? AND id=?',[Util::bin(Keeper\AdminAuth::PLATFORM),$group,$a['tenant_id'],$account['id']]);
    $role=Util::bin(Util::uuid()); $db->run("INSERT INTO roles (tenant_id,id,name) VALUES (?,?,'Supervisor remote')",[$b['tenant_id'],$role]);
    $db->run("INSERT INTO role_permissions VALUES (?,?,'usuarios.ver')",[$b['tenant_id'],$role]);
    Keeper\AccessGrants::setForGroupAccount($db,$b['tenant_id'],$a['tenant_id'],$account['id'],$role,Keeper\AccessScope::all());
    $origin['group_id']=$group;
    $contexts=$model->authorizedContexts($origin,null);
    check(count($contexts)===2,'group has two authorized contexts');
    $foreign=array_values(array_filter($contexts,fn($x)=>$x['tenant_id']===$b['tenant_id']))[0];
    check($foreign['user_id']===null && !$foreign['platform'],'foreign context no local identity or platform');
    try { $db->run("UPDATE access_grants SET scope_kind='self' WHERE tenant_id=? AND admin_id=?",[$b['tenant_id'],$account['id']]); check(false,'foreign self rejected'); }
    catch (PDOException $e) { check(in_array((int)$e->errorInfo[1],[3819,4025],true),'schema forbids foreign self'); }
    $db->run('DELETE FROM access_grants WHERE tenant_id=? AND admin_id=?',[$b['tenant_id'],$account['id']]);
    check(count($model->authorizedContexts($origin,null))===1,'revoked group grant removed');
    $db->run('DELETE FROM role_permissions WHERE tenant_id=? AND role_id=?',[$a['tenant_id'],$account['role']]);
    try { $model->authorizedContexts($origin,null); check(false,'no base permission'); } catch (ApiError $e) { check($e->status===403,'no base permission 403'); }
    $platform=sharedPlatform($db); $p=supervisorOrigin($db,$platform,null,true);
    $db->run('UPDATE tenants SET archived_at=UTC_TIMESTAMP(6) WHERE tenant_id=?',[$c['tenant_id']]);
    $ids=array_column($model->authorizedContexts($p,null),'tenant_id');
    check(!in_array($c['tenant_id'],$ids,true) && !in_array(Util::bin(Keeper\AdminAuth::PLATFORM),$ids,true),'platform excludes archived and technical');
    echo "PASS supervisor contexts\n";
}

if (realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__) {
    require dirname(__DIR__).'/config/bootstrap.php';
    $checks=0;
    function check(bool $condition,string $message): void { global $checks; $checks++; if (!$condition) { throw new RuntimeException($message); } }
    supervisorContractTests();
    echo "SUPERVISOR CONTRACT PASS: $checks assertions\n";
}
