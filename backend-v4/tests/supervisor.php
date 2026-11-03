<?php
declare(strict_types=1);
use Keeper\{Database,Validator,ApiError,Util};
require_once __DIR__.'/supervisor-capacity.php';

function supervisorContractTests(?Database $db = null): void
{
    $v = new Validator();
    foreach (['/reports/supervisor'=>'getSupervisorOverview','/users/{id}/supervisor-summary'=>'getSupervisorPersonSummary'] as $path=>$op) {
        check(($v->contract['paths'][$path]['get']['operationId']??null)===$op, "supervisor contract $op");
        check($v->contract['paths'][$path]['get']['security']===[['portalBearer'=>[]],['adminSession'=>[]]], 'panel session or portal token');
        check(Keeper\Portal::allowed('GET',$path),'supervisor route available in Portal');
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
    supervisorPortalTests($db);
    supervisorEntryTests($db);
    supervisorContextTests($db);
    supervisorActivityTests($db);
    supervisorApiTests($db);
    supervisorScopeAndSignalsTests($db);
    supervisorMultiClientTests($db);
    supervisorCalendarTests($db);
    supervisorTrendTests($db);
    supervisorCapacityTests($db);
}

function supervisorPortalTests(Database $db): void
{
    $o=accessOrg($db); $t=$o['tenant']; $tid=Util::id($t); $uid=Util::id($o['coord2']['id']);
    $perms=['usuarios.ver','equipos.ver','presencia.ver','reportes.ver','cumplimiento.ver','reglas.ver','horarios.ver','organizacion.ver','festivos.ver'];
    $a=accessAccount($db,$o,'coord2',$perms,new Keeper\AccessScope('team'));
    $db->run('UPDATE users SET portal_login_enabled=TRUE WHERE tenant_id=? AND id=?',[$t,$o['coord2']['id']]);
    $session=expect(portalLogin($a['email'],$a['password']),200,'supervisor portal login'); $token=$session['access_token'];
    $day=(new DateTimeImmutable('today',new DateTimeZone('America/Bogota')))->format('Y-m-d');
    $base='/reports/supervisor?tenant_id='.$tid;
    $summary='/users/'.$uid.'/supervisor-summary?day='.$day;
    $sorted=static function (array $ids): array { sort($ids); return $ids; };
    $schedule=function (string $name) use ($db,$t): string {
        $id=Util::bin(Util::uuid());
        $db->run("INSERT INTO schedules (tenant_id,id,name,timezone,start_local,end_local) VALUES (?,?,?,'America/Bogota','08:00','17:00')",[$t,$id,$name]);
        return $id;
    };
    $keys=['base','zafra','bl1','bl2','juan','ag808','agTorre','jur808','coord2']; $schedules=[];
    foreach ($keys as $key) {
        $schedules[$key]=$schedule($key);
        $db->run('UPDATE users SET schedule_id=? WHERE tenant_id=? AND id=?',[$schedules[$key],$t,$o[$key]['id']]);
        $db->run('UPDATE user_assignments SET schedule_id=? WHERE tenant_id=? AND user_id=?',[$schedules[$key],$t,$o[$key]['id']]);
    }
    $unused=$schedule('Unassigned'); $foreign=secondTenant($db);
    $cases=[
        ['self',Keeper\AccessScope::own(),['coord2']],
        ['team',new Keeper\AccessScope('team'),['coord2','ag808','jur808']],
        ['area',new Keeper\AccessScope('filters',areas:[$o['agentes']]),['bl1','juan','ag808','agTorre','coord2']],
        ['site',new Keeper\AccessScope('filters',sites:[$o['med']]),['ag808','jur808','coord2']],
        ['filters',new Keeper\AccessScope('filters',companies:[$o['azc']],sites:[$o['med']],areas:[$o['agentes']]),['ag808','coord2']],
        ['empty',new Keeper\AccessScope('filters'),[]],
    ];
    foreach ($cases as [$label,$scope,$visible]) {
        Keeper\AccessGrants::setForUser($db,$t,$o['coord2']['id'],$a['role'],$scope,'manual');
        $panel=expect(adminRequest($a,'GET',$base),200,'panel scope '.$label);
        $portal=expect(portalRequest('GET',$base,null,$token),200,'portal scope '.$label);
        $expected=$sorted(array_map(fn($key)=>Util::id($o[$key]['id']),$visible));
        check($sorted(array_column($portal['data'],'user_id'))===$expected && $sorted(array_column($panel['data'],'user_id'))===$expected,'same exact supervisor scope '.$label);
        check(!str_contains(Util::json($portal),'protection'),'portal overview omits protection '.$label);
        foreach (['coord2','ag808','zafra'] as $key) {
            $path='/users/'.Util::id($o[$key]['id']).'/supervisor-summary?day='.$day;
            $status=in_array($key,$visible,true)?200:404;
            $dto=expect(portalRequest('GET',$path,null,$token),$status,'portal person '.$label.' '.$key);
            expect(adminRequest($a,'GET',$path),$status,'panel person '.$label.' '.$key);
            if ($status===200) { check(!str_contains(Util::json($dto),'protection'),'portal person omits protection'); }
        }
        $rows=expect(portalRequest('GET','/schedules?limit=100',null,$token),200,'scoped schedules '.$label);
        check($sorted(array_column($rows['data'],'id'))===$sorted(array_map(fn($key)=>Util::id($schedules[$key]),$visible)),'only effective schedules in scope '.$label);
        expect(adminRequest($a,'GET','/schedules'),403,'panel schedules still require all');
        expect(portalRequest('GET','/organization',null,$token),200,'organization any scope');
        foreach (['/schedules/holidays'=>403,'/schedules/holiday-calendars'=>200] as $path=>$status) {
            expect(portalRequest('GET',$path,null,$token),$status,'portal catalog retains scope gate');
            expect(adminRequest($a,'GET',$path),$status,'panel catalog scope gate');
        }
    }
    $out=$base.'&area_id='.Util::id($o['juridica']);
    Keeper\AccessGrants::setForUser($db,$t,$o['coord2']['id'],$a['role'],new Keeper\AccessScope('filters',areas:[$o['agentes']]),'manual');
    expect(portalRequest('GET',$out,null,$token),403,'portal outside area filter');
    expect(adminRequest($a,'GET',$out),403,'panel outside area filter');
    expect(portalRequest('GET','/reports/supervisor?tenant_id='.Util::id($foreign['tenant_id']),null,$token),404,'portal foreign tenant query');
    expect(adminRequest($a,'GET','/reports/supervisor?tenant_id='.Util::id($foreign['tenant_id'])),404,'panel foreign tenant query');
    expect(portalRequest('GET',$summary,null,$token,['x-tenant-id'=>Util::id($foreign['tenant_id'])]),404,'portal foreign tenant header');

    // El predeterminado solo aparece si una persona visible lo usa; sin predeterminado queda el provisional.
    Keeper\AccessGrants::setForUser($db,$t,$o['coord2']['id'],$a['role'],new Keeper\AccessScope('team'),'manual');
    $placeholder=Keeper\Placeholders::schedule($db,$t,'America/Bogota');
    $db->run('UPDATE users SET schedule_id=? WHERE tenant_id=? AND id IN (?,?)',[$placeholder,$t,$o['ag808']['id'],$o['jur808']['id']]);
    $db->run('UPDATE tenants SET default_schedule_id=? WHERE tenant_id=?',[$unused,$t]);
    $rows=expect(portalRequest('GET','/schedules',null,$token),200,'team effective default');
    check($sorted(array_column($rows['data'],'id'))===$sorted([Util::id($schedules['coord2']),Util::id($unused)]),'default deduplicated and outside schedules excluded');
    $db->run('UPDATE tenants SET default_schedule_id=NULL WHERE tenant_id=?',[$t]);
    $rows=expect(portalRequest('GET','/schedules',null,$token),200,'team without default');
    check($sorted(array_column($rows['data'],'id'))===$sorted([Util::id($schedules['coord2']),Util::id($placeholder)]),'placeholder retained without default');
    $seen=[]; $path='/schedules?limit=1';
    do {
        $page=expect(portalRequest('GET',$path,null,$token),200,'scoped schedules pagination');
        array_push($seen,...array_column($page['data'],'id'));
        $path='/schedules?limit=1&cursor='.urlencode($page['next_cursor']??'');
    } while ($page['next_cursor']!==null);
    check($sorted($seen)===$sorted(array_column($rows['data'],'id')),'schedule pages neither expand nor duplicate scope');

    $first=expect(portalRequest('GET',$base.'&limit=1',null,$token),200,'portal supervisor cursor');
    $next=$base.'&limit=1&cursor='.urlencode($first['next_cursor']);
    expect(portalRequest('GET',$next,null,$token),200,'portal family cursor works without cookie');
    expect(adminRequest($a,'GET',$next),422,'portal cursor rejected by panel session');
    $other=expect(portalLogin($a['email'],$a['password']),200,'another portal family');
    expect(portalRequest('GET',$next,null,$other['access_token']),422,'portal cursor rejected by another family');
    $refreshed=expect(portalRequest('POST','/refresh',['refresh_token'=>$session['refresh_token']]),200,'portal cursor refresh');
    $token=$refreshed['access_token'];
    expect(portalRequest('GET',$next,null,$token),200,'portal cursor survives refresh in same family');

    Keeper\AccessGrants::setForUser($db,$t,$o['coord2']['id'],$a['role'],Keeper\AccessScope::all(),'manual');
    $newRoutes=[$base,$summary,'/organization','/schedules/holidays','/schedules/holiday-calendars'];
    foreach ($newRoutes as $path) {
        expect(portalRequest('GET',$path,null,$token),200,'new Portal route '.$path);
        expect(adminRequest($a,'GET',$path),200,'new route panel parity');
        expect(portalRequest('GET',$path),401,'new route needs token');
    }
    $rows=expect(portalRequest('GET','/schedules?limit=100',null,$token),200,'all schedules unchanged');
    check(count($rows['data'])===(int)$db->one('SELECT COUNT(*) n FROM schedules WHERE tenant_id=?',[$t])['n'],'all scope sees entire tenant catalog');
    foreach (['usuarios.ver'=>['/reports/supervisor',$summary],'organizacion.ver'=>['/organization'],'festivos.ver'=>['/schedules/holidays','/schedules/holiday-calendars'],'horarios.ver'=>['/schedules']] as $permission=>$paths) {
        $db->run('DELETE FROM role_permissions WHERE tenant_id=? AND role_id=? AND permission_code=?',[$t,$a['role'],$permission]);
        foreach ($paths as $path) {
            expect(portalRequest('GET',$path,null,$token),403,'portal permission '.$permission);
            expect(adminRequest($a,'GET',$path),403,'panel permission '.$permission);
        }
        $db->run('INSERT INTO role_permissions VALUES (?,?,?)',[$t,$a['role'],$permission]);
    }
    foreach (['reportes.ver'=>'activity','presencia.ver'=>'presence','equipos.ver'=>'devices','cumplimiento.ver'=>'compliance','reglas.ver'=>'rules'] as $permission=>$section) {
        $db->run('DELETE FROM role_permissions WHERE tenant_id=? AND role_id=? AND permission_code=?',[$t,$a['role'],$permission]);
        $dto=expect(portalRequest('GET',$summary,null,$token),200,'portal missing section '.$section);
        $panel=expect(adminRequest($a,'GET',$summary),200,'panel missing section '.$section);
        check($dto['sections']===$panel['sections'] && $dto['sections'][$section]['status']==='not_authorized','same omitted section '.$section);
        $db->run('INSERT INTO role_permissions VALUES (?,?,?)',[$t,$a['role'],$permission]);
    }

    $db->run("UPDATE devices SET status='revoked' WHERE tenant_id=? AND user_id=?",[$t,$o['coord2']['id']]);
    $device=supervisorDevice($db,$o['coord2'],'Portal protected');
    $db->run("UPDATE roles SET seed_key='it' WHERE tenant_id=? AND id=?",[$t,$a['role']]);
    $platform=sharedPlatform($db); $pa=$platform; $pa['tenant']=$t;
    $ps=expect(portalLogin($platform['email'],$platform['password']),200,'platform portal supervisor');
    $header=['x-tenant-id'=>$tid];
    foreach ($newRoutes as $path) { expect(portalRequest('GET',$path,null,$ps['access_token'],$header),200,'platform new route with tenant header'); }
    foreach (['protected','unprotected','unconfirmed'] as $state) {
        $db->run('UPDATE security_reports SET controls=? WHERE tenant_id=? AND device_id=?',[Util::json(array_map(fn($def)=>['control_id'=>$def['agent'],'state'=>$state==='unprotected'?'failed':'applied'],array_values(Keeper\ProtectionSummary::CONTROLS))),$t,$device]);
        $db->run('UPDATE devices SET policy_version=? WHERE tenant_id=? AND id=?',[$state==='unconfirmed'?null:1,$t,$device]);
        foreach ([[$a,$token,[]],[$pa,$ps['access_token'],$header]] as [$account,$bearer,$headers]) {
            $dto=expect(portalRequest('GET',$summary,null,$bearer,$headers),200,'portal protection '.$state);
            $panel=expect(adminRequest($account,'GET',$summary),200,'panel protection '.$state);
            check($dto['current']['protection']['state']===$state && $dto['current']['protection']===$panel['current']['protection'],'IT/platform same protection '.$state);
            check(isset($dto['current']['devices']['data'][0]['protection']),'IT/platform device protection present');
        }
    }
    $db->run("UPDATE roles SET seed_key=NULL WHERE tenant_id=? AND id=?",[$t,$a['role']]);
    $dto=expect(portalRequest('GET',$summary,null,$token),200,'supervisor after IT removed');
    check(!str_contains(Util::json($dto),'protection'),'removing IT immediately omits all protection');
    $db->run('INSERT INTO admin_policy_recompiles (tenant_id) VALUES (?)',[$t]);
    $before=$db->run('SELECT * FROM effective_policies WHERE tenant_id=? ORDER BY device_id,policy_version',[$t])->fetchAll();
    foreach ([$base,$summary] as $path) {
        expect(portalRequest('GET',$path,null,$ps['access_token'],$header),200,'portal read with pending policy');
        check((bool)$db->one('SELECT tenant_id FROM admin_policy_recompiles WHERE tenant_id=?',[$t]),'portal supervisor GET never compiles');
    }
    check($before===$db->run('SELECT * FROM effective_policies WHERE tenant_id=? ORDER BY device_id,policy_version',[$t])->fetchAll(),'portal GET preserves compiled policies');
    $db->run('DELETE FROM admin_policy_recompiles WHERE tenant_id=?',[$t]);

    $origin='https://supervisor-'.substr(Util::uuid(),0,8).'.example.test';
    $db->run('INSERT INTO portal_origins (tenant_id,id,origin) VALUES (?,?,?)',[Util::bin(Keeper\AdminAuth::PLATFORM),Util::bin(Util::uuid()),$origin]);
    foreach ($newRoutes as $path) {
        $preflight=portalRequest('OPTIONS',$path,null,null,['origin'=>$origin,'access-control-request-method'=>'GET']);
        check($preflight[0]===204 && $preflight[2]['access-control-allow-origin']===$origin && $preflight[2]['access-control-allow-methods']==='GET, POST' && $preflight[2]['access-control-allow-headers']==='Authorization, Content-Type, X-Tenant-ID' && !isset($preflight[2]['access-control-allow-credentials']),'new route CORS unchanged');
        $read=portalRequest('GET',$path,null,$token,['origin'=>$origin]);
        check($read[0]===200 && $read[2]['access-control-allow-origin']===$origin,'new route allowed origin');
        $denied=portalRequest('GET',$path,null,null,['origin'=>$origin]);
        check($denied[0]===401 && $denied[2]['access-control-allow-origin']===$origin,'new route errors retain CORS');
        check(!isset(portalRequest('GET',$path,null,$token,['origin'=>'https://untrusted.example.test'])[2]['access-control-allow-origin']),'untrusted origin gets no CORS');
        check(!isset(adminRequest($a,'GET',$path,null,['origin'=>$origin])[2]['access-control-allow-origin']),'panel has no CORS');
    }
    foreach (['/reports/dual-job-alerts','/data-sources','/sync-runs','/app-classification','/person-plans','/person-plans/available','/policies','/users/'.$uid.'/rules'] as $path) {
        expect(portalRequest('GET',$path,null,$ps['access_token'],$header),404,'unlisted route remains hidden');
    }
    echo "PASS supervisor Portal routes, scopes, schedules, protection, cursors and CORS\n";
}

function supervisorEntryTests(Database $db): void
{
    $u=secondTenant($db); $t=$u['tenant_id'];
    $a=adminFixture($db,$u,['usuarios.ver','equipos.ver','reportes.ver','reglas.ver']);
    $report=adminMember($db,$u);
    foreach ([null,'coordinacion','direccion','gerencia','corporativo','it','rrhh','colaborador','admin_empresa'] as $seed) {
        $db->run('UPDATE roles SET seed_key=?,name=? WHERE tenant_id=? AND id=?',[$seed,'Nombre cambiado',$t,$a['role']]);
        foreach ([false,true] as $manages) {
            $db->run('UPDATE users SET reports_to_user_id=? WHERE tenant_id=? AND id=?',[$manages?$u['id']:null,$t,$report['id']]);
            $access=expect(adminRequest($a,'GET','/me/access'),200,'login hierarchy '.($seed??'custom'));
            check(($access['supervisor_home']??null)===$manages,'login follows active reports, independent of role '.($seed??'custom'));
        }
    }
    $db->run("UPDATE users SET status='inactive' WHERE tenant_id=? AND id=?",[$t,$report['id']]);
    check(expect(adminRequest($a,'GET','/me/access'),200,'inactive report')['supervisor_home']===false,'inactive report does not make a supervisor');
    $db->run("UPDATE users SET status='active' WHERE tenant_id=? AND id=?",[$t,$report['id']]);
    $db->run("UPDATE roles SET seed_key='coordinacion',active=FALSE WHERE tenant_id=? AND id=?",[$t,$a['role']]);
    $access=expect(adminRequest($a,'GET','/me/access'),200,'manager without permissions');
    check($access['supervisor_home']===true && $access['permissions']===[],'hierarchy navigation never grants permissions');
    expect(adminRequest($a,'GET','/reports/supervisor'),403,'manager without permission cannot read people');
    $db->run('UPDATE roles SET active=TRUE WHERE tenant_id=? AND id=?',[$t,$a['role']]);
    $db->run('UPDATE users SET reports_to_user_id=NULL WHERE tenant_id=? AND id=?',[$t,$report['id']]);
    check(expect(adminRequest($a,'GET','/me/access'),200,'removed last report')['supervisor_home']===false,'removing last report changes entry immediately');
    $p=sharedPlatform($db);
    check(expect(adminRequest($p,'GET','/me/access'),200,'platform home')['supervisor_home']===true,'platform supervisor home');
    expect(request('GET','/reports/supervisor'),401,'supervisor requires session');
    $portal=curl_init(Keeper\Config::get('ORIGIN').'/portal/v1/reports/supervisor');
    curl_setopt($portal,CURLOPT_RETURNTRANSFER,true); curl_exec($portal); $portalStatus=curl_getinfo($portal,CURLINFO_RESPONSE_CODE); curl_close($portal);
    check($portalStatus===401,'supervisor Portal route requires token');
    foreach (['/supervisor.php','/supervisor-persona.php','/','/index.php','/admin/','/usuarios.php','/miembro.php','/proteccion.php','/login.php'] as $path) {
        $curl=curl_init(Keeper\Config::get('ORIGIN').$path); curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HEADER=>true]);
        $body=curl_exec($curl); $status=curl_getinfo($curl,CURLINFO_RESPONSE_CODE); curl_close($curl);
        check($status===200,'page shell '.$path);
        check(str_contains($body,'Content-Security-Policy:') && str_contains($body,'Cache-Control: no-store'),'page headers '.$path);
        if (str_starts_with($path,'/supervisor')) { check(!str_contains($body,'smoke member') && !str_contains($body,'type="module" src="/assets/app.js'),'supervisor shell no identity/admin bootstrap'); }
    }
    $db->run('INSERT INTO admin_policy_recompiles (tenant_id) VALUES (?)',[$t]);
    $fingerprint=static function () use ($db,$t): array {
        $out=[];
        foreach (['policy_documents','policy_versions','policy_rules','policy_assignments','effective_policies','access_grants','access_grant_units','access_grant_cities','device_command','dual_job_alerts','suspicious_app_detections','user_assignments','device_assignments','users','tenants'] as $table) {
            $rows=array_map(fn($row)=>hash('sha256',serialize($row)),$db->run("SELECT * FROM $table WHERE tenant_id=?",[$t])->fetchAll()); sort($rows); $out[$table]=$rows;
        }
        return $out;
    };
    $before=$fingerprint();
    $day=(new DateTimeImmutable('today',new DateTimeZone('America/Bogota')))->format('Y-m-d');
    $uid=Util::id($u['id']); $next=(new DateTimeImmutable($day))->modify('+1 day')->format('Y-m-d');
    foreach (['/me/access','/me',"/users/$uid/windows?from=$day&to=$next","/users/$uid/timeline?day=$day","/users/$uid/rules"] as $route) {
        expect(adminRequest($a,'GET',$route),200,'read-only supervisor dependency');
        check((bool)$db->one('SELECT tenant_id FROM admin_policy_recompiles WHERE tenant_id=?',[$t]),'dependency does not compile '.$route);
    }
    expect(adminRequest($a,'GET','/reports/supervisor'),200,'read-only overview');
    expect(adminRequest($a,'GET',"/users/$uid/supervisor-summary?day=$day"),200,'read-only summary');
    check($fingerprint()===$before,'GETs preserve policies plans grants commands reviewed alerts and assignments');
    $db->run('DELETE FROM admin_policy_recompiles WHERE tenant_id=?',[$t]);
    echo "PASS supervisor entry, page shells and read-only dependencies\n";
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
    $outside=Util::id($o['bl1']['id']); $next=(new DateTimeImmutable($today))->modify('+1 day')->format('Y-m-d');
    foreach (["/users/$outside/windows?from=$today&to=$next","/users/$outside/timeline?day=$today","/users/$outside/rules"] as $route) { expect(adminRequest($a,'GET',$route),404,'reused detail outside scope'); }
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
    check(!str_contains(Util::json($dto),'protection'),'filters supervisor omits protection and reasons');
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
    foreach (['presencia.ver'=>'presence','reglas.ver'=>'rules','cumplimiento.ver'=>'compliance'] as $permission=>$section) {
        $db->run('DELETE FROM role_permissions WHERE tenant_id=? AND role_id=? AND permission_code=?',[$t,$a['role'],$permission]);
        $restricted=expect(adminRequest($a,'GET',$summary),200,'missing section '.$section);
        check($restricted['sections'][$section]['status']==='not_authorized','section unavailable '.$section);
        if ($section==='presence') check($restricted['arrival']['first_at']===null && $restricted['schedule']['start_at']===null,'no presence evidence');
        if ($section==='rules') expect(adminRequest($a,'GET','/users/'.$uid.'/rules'),403,'rules denied by server');
        if ($section==='compliance') check(!array_filter($restricted['alerts']['data'],fn($x)=>in_array($x['kind'],['possible_dual_job','suspicious_app'],true)),'no compliance evidence');
        $db->run('INSERT INTO role_permissions VALUES (?,?,?)',[$t,$a['role'],$permission]);
    }
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
    foreach (["archived_at=NULL,status='suspended'","status='active',deleted_at=UTC_TIMESTAMP(6)"] as $change) {
        $db->run("UPDATE tenants SET $change WHERE tenant_id=?",[$c['tenant_id']]);
        check(!in_array($c['tenant_id'],array_column($model->authorizedContexts($p,null),'tenant_id'),true),'platform excludes inactive or deleted');
    }
    echo "PASS supervisor contexts\n";
}

if (realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__) {
    require dirname(__DIR__).'/config/bootstrap.php';
    $checks=0;
    function check(bool $condition,string $message): void { global $checks; $checks++; if (!$condition) { throw new RuntimeException($message); } }
    supervisorContractTests();
    echo "SUPERVISOR CONTRACT PASS: $checks assertions\n";
}
