<?php
declare(strict_types=1);
require dirname(__DIR__).'/tests/smoke.php';
use Keeper\{Database,Util,Config,ProductivityWorker};

function phaseBTests(Database $db): void
{
    $base=secondTenant($db); $tenant=$base['tenant_id']; $tid=Util::id($tenant);
    $permissions=array_column($db->run('SELECT code FROM permissions WHERE platform_only=FALSE')->fetchAll(),'code');
    $admin=adminFixture($db,$base,$permissions);
    $reader=adminFixture($db,adminMember($db,$base),['usuarios.ver']);
    $foreign=secondTenant($db); $other=adminFixture($db,$foreign,$permissions);
    $day=(new DateTimeImmutable('yesterday'))->format('Y-m-d');
    while ((new DateTimeImmutable($day))->format('N')>5) { $day=(new DateTimeImmutable($day))->modify('-1 day')->format('Y-m-d'); }
    $sourceName='keeper_phase_b_k3_test';
    $db->run('CREATE DATABASE '.$sourceName.' CHARACTER SET utf8mb4');
    $sourceDsn=preg_replace('/dbname=[^;]+/','dbname='.$sourceName,Config::get('DB_DSN'));
    $source=new PDO($sourceDsn,Config::get('DB_USER'),'', [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $source->exec("SET time_zone='+00:00'");
    $table=null; $columns=[];
    $flush=static function() use ($source,&$table,&$columns): void { if ($table) { $source->exec("CREATE TABLE $table (".implode(',',$columns).', PRIMARY KEY(id)) ENGINE=InnoDB'); } };
    foreach (file(__DIR__.'/k3-prod-schema.txt',FILE_IGNORE_NEW_LINES) as $line) {
        if (preg_match('/^### (keeper_[a-z_]+)$/',$line,$m)) { $flush(); $table=$m[1]; $columns=[]; }
        elseif (preg_match('/^  (\w+) (.+?)(?: NOTNULL)?$/',$line,$m)) { $columns[]='`'.$m[1].'` '.$m[2].($m[1]==='id'?' NOT NULL':' NULL'); }
    }
    $flush();
    foreach (['firmas','sedes','areas','cargos'] as $kind) {
        $active=$kind==='cargos'?'activo':'activa'; $source->exec("INSERT INTO keeper_$kind (id,nombre,$active) VALUES (1,'Fixture',1)");
    }
    $source->exec('CREATE TABLE keeper_sociedades (id BIGINT PRIMARY KEY,nombre VARCHAR(255),activa BOOLEAN)');
    $source->exec("INSERT INTO keeper_sociedades VALUES (1,'Sociedad A',1),(2,'Sociedad B',1)");
    $source->exec('CREATE TABLE keeper_holidays (id BIGINT PRIMARY KEY,holiday_date DATE,name VARCHAR(160))');
    $source->exec('CREATE TABLE keeper_holiday_sociedades (holiday_id BIGINT,sociedad_id BIGINT,PRIMARY KEY(holiday_id,sociedad_id))');
    $holidayDay=(new DateTimeImmutable($day))->modify('-1 day')->format('Y-m-d');
    $source->prepare('INSERT INTO keeper_holidays VALUES (1,?,?)')->execute([$holidayDay,'Festivo importado']);
    $source->exec('INSERT INTO keeper_holiday_sociedades VALUES (1,1)');
    $source->exec('CREATE TABLE keeper_suspicious_apps (id INT PRIMARY KEY,app_pattern VARCHAR(190),category VARCHAR(40),description VARCHAR(255),is_active BOOLEAN)');
    $source->exec("INSERT INTO keeper_suspicious_apps VALUES (1,'anydesk','remote_desktop','Acceso remoto',1)");
    $source->exec('CREATE TABLE keeper_dual_job_alerts (id BIGINT PRIMARY KEY,user_id BIGINT,day_date DATE,alert_type VARCHAR(40),severity VARCHAR(10),evidence_json JSON,is_reviewed BOOLEAN,notes TEXT)');
    $source->prepare("INSERT INTO keeper_dual_job_alerts VALUES (1,1,?,'remote_desktop','medium','{}',0,NULL)")->execute([$day]);
    $source->exec('CREATE TABLE keeper_install_coverage_notes (id BIGINT PRIMARY KEY,legacy_employee_id INT,note_text TEXT,is_exempt BOOLEAN)');
    $source->exec("INSERT INTO keeper_install_coverage_notes VALUES (1,1,'Enrolar equipo de reemplazo',0)");
    $insert=static function(string $table,array $values) use ($source): void {
        $source->prepare("INSERT INTO $table (".implode(',',array_keys($values)).') VALUES ('.implode(',',array_fill(0,count($values),'?')).')')->execute(array_values($values));
    };
    for ($i=1;$i<=160;$i++) {
        $insert('keeper_users',['id'=>$i,'legacy_employee_id'=>$i,'display_name'=>'Persona '.$i,'email'=>'p'.$i.'@example.test','status'=>'active','employment_status'=>'active','created_at'=>'2026-01-01 00:00:00']);
        $insert('keeper_user_assignments',['id'=>$i,'keeper_user_id'=>$i,'firm_id'=>1,'sede_id'=>1,'area_id'=>1,'cargo_id'=>1,'sociedad_id'=>$i<=80?1:2,'assigned_at'=>'2026-01-01 00:00:00']);
        $insert('keeper_devices',['id'=>$i,'user_id'=>$i,'device_guid'=>Util::uuid(),'device_name'=>'Equipo '.$i,'client_version'=>'3.0.0','status'=>'active','device_status'=>'active','created_at'=>'2026-01-01 00:00:00','last_seen_at'=>$day.' 22:00:00']);
        foreach ([$day,$holidayDay] as $n=>$d) {
            $insert('keeper_focus_daily',['id'=>$i+$n*160,'user_id'=>$i,'device_id'=>$i,'day_date'=>$d,'context_switches'=>4,'deep_work_seconds'=>7200,'deep_work_sessions'=>3,'distraction_seconds'=>60,'longest_focus_streak_seconds'=>3600,'focus_score'=>80,'productivity_pct'=>90,'constancy_pct'=>75,'first_activity_time'=>$i%2?'08:17:00':'07:50:00','scheduled_start'=>'08:00:00','punctuality_minutes'=>$i%2?-17:10,'created_at'=>$d.' 23:00:00']);
            $insert('keeper_activity_day',['id'=>$i+$n*160,'user_id'=>$i,'device_id'=>$i,'day_date'=>$d,'active_seconds'=>28800,'idle_seconds'=>600,'first_event_at'=>$d.($i%2?' 13:17:00':' 12:50:00'),'last_event_at'=>$d.' 22:00:00','created_at'=>$d.' 23:00:00']);
        }
        $insert('keeper_window_episode',['id'=>$i,'user_id'=>$i,'device_id'=>$i,'start_at'=>$day.' 13:17:00','end_at'=>$day.' 14:17:00','duration_seconds'=>3600,'process_name'=>'anydesk.exe','window_title'=>'Fixture','app_name'=>'AnyDesk']);
    }
    $envNames=['K3_DSN','K3_USER','K3_PASSWORD','K3_PASSWORD_FILE','IMPORT_TENANT_ID','K3_EPISODE_DAYS']; $saved=[];
    foreach ($envNames as $name) { $saved[$name]=getenv($name); }
    putenv('K3_DSN='.$sourceDsn); putenv('K3_USER='.Config::get('DB_USER')); putenv('K3_PASSWORD='); putenv('K3_PASSWORD_FILE='); putenv('IMPORT_TENANT_ID='.$tid); putenv('K3_EPISODE_DAYS=10');
    try {
        $run=static function(array $args=[]): void {
            $proc=proc_open([PHP_BINARY,__DIR__.'/import-k3.php',...$args],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
            $out=stream_get_contents($pipes[1]); $err=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
            check(proc_close($proc)===0,'ETL succeeded: '.$err.' '.$out);
        };
        $run();
        $counts=static function() use ($db,$tenant): array { $result=[]; foreach (['users','devices','focus_daily','episode_daily','day_summary','holidays','holiday_society_links','sociedades','dual_job_alerts','suspicious_apps','install_coverage_notes','episodes'] as $table) { $result[$table]=(int)$db->one("SELECT COUNT(*) n FROM $table WHERE tenant_id=?",[$tenant])['n']; } return $result; };
        $firstCounts=$counts(); check($firstCounts['focus_daily']===320 && $firstCounts['episodes']===160,'160 people with two imported days and raw episodes');
        $db->run('UPDATE focus_daily SET first_activity_time=NULL,scheduled_start=NULL,punctuality_minutes=NULL,productivity_pct=NULL,constancy_pct=NULL,deep_work_sessions=0,longest_focus_streak_seconds=0 WHERE tenant_id=?',[$tenant]);
        $run(); check($firstCounts===$counts(),'ETL idempotent row counts');
        check((int)$db->one('SELECT COUNT(*) n FROM focus_daily WHERE tenant_id=? AND first_activity_time IS NOT NULL AND deep_work_sessions=3 AND longest_focus_streak_seconds=3600',[$tenant])['n']===320,'ETL repairs all missing focus columns');
        $run(['--dry-run']); check($firstCounts===$counts(),'ETL dry run leaves row counts unchanged');
    } finally { foreach ($saved as $name=>$value) { putenv($value===false?$name:$name.'='.$value); } }
    $presence=expect(adminRequest($admin,'GET','/reports/presence?day='.$day),200,'presence with no doors');
    $people=array_values(array_filter($presence['data'],static fn($r)=>str_starts_with($r['display_name'],'Persona ')));
    check(count($people)===160,'all 160 people with imported activity present');
    check(count(array_filter($people,static fn($r)=>$r['status']==='tarde' && $r['late_seconds']===1020 && $r['punctuality_minutes']===-17))===80,'80 real late arrivals');
    check(count(array_filter($people,static fn($r)=>$r['status']==='a_tiempo' && $r['punctuality_minutes']===10))===80,'80 real early arrivals');
    $user=$db->one("SELECT u.* FROM users u JOIN user_external_refs x ON x.tenant_id=u.tenant_id AND x.user_id=u.id WHERE u.tenant_id=? AND x.source='k3' AND x.external_ref='1'",[$tenant]); $uid=Util::id($user['id']);
    $db->run('UPDATE focus_daily SET first_activity_time=NULL WHERE tenant_id=? AND user_id=? AND day=?',[$tenant,$user['id'],$day]);
    $r=expect(adminRequest($admin,'GET','/reports/presence?day='.$day.'&user_id='.$uid),200,'day summary fallback'); check($r['data'][0]['late_seconds']===1020,'summary first activity used');
    $db->run('UPDATE day_summary SET first_activity=NULL,last_activity=NULL WHERE tenant_id=? AND user_id=? AND day=?',[$tenant,$user['id'],$day]);
    $r=expect(adminRequest($admin,'GET','/reports/presence?day='.$day.'&user_id='.$uid),200,'episode daily fallback'); check($r['data'][0]['late_seconds']===1020 && $r['data'][0]['last_activity']!==null,'episode first and last activity used');
    $holiday=expect(adminRequest($admin,'POST','/schedules/holidays',['day'=>$day,'name'=>'Festivo tenant','society_ids'=>[]]),201,'create holiday');
    $r=expect(adminRequest($admin,'GET','/reports/presence?day='.$day),200,'holiday presence'); check(count(array_filter($r['data'],static fn($x)=>$x['status']!=='no_laborable' || $x['late_seconds']!==null || $x['punctuality_minutes']!==null))===0,'holiday excludes punctuality for everyone');
    check((int)$db->one('SELECT SUM(expected_seconds) n FROM day_summary WHERE tenant_id=? AND day=?',[$tenant,$day])['n']===0,'holiday refresh excludes expected seconds');
    expect(adminRequest($other,'GET','/schedules/holidays/'.$holiday['id']),404,'foreign holiday');
    expect(adminRequest($admin,'DELETE','/schedules/holidays/'.$holiday['id']),204,'delete holiday');
    $societies=expect(adminRequest($admin,'GET','/schedules/holiday-societies'),200,'societies use existing organization');
    $holiday=expect(adminRequest($admin,'POST','/schedules/holidays',['day'=>$day,'name'=>'Solo una sociedad','society_ids'=>[$societies['data'][0]['id']]]),201,'society holiday');
    $r=expect(adminRequest($admin,'GET','/reports/presence?day='.$day),200,'society calendar'); check(count(array_filter($r['data'],static fn($x)=>str_starts_with($x['display_name'],'Persona ') && $x['status']==='no_laborable'))===80,'society holiday excludes exactly 80 people');
    foreach (['/schedules/holidays','/policies/suspicious-apps','/reports/dual-job-alerts','/reports/suspicious-apps','/reports/compliance-signals','/devices/install-coverage','/audit/client-logs','/tenants/server-health','/tenants/panel-settings'] as $path) {
        expect(adminRequest($reader,'GET',$path),403,'missing permission '.$path);
        expect(adminRequest($admin,'GET',$path),200,'authorized '.$path);
    }
    expect(adminRequest($admin,'PUT','/users/'.Util::id($foreign['id']).'/install-coverage',['note_text'=>'x','is_exempt'=>false]),404,'foreign coverage write');
    expect(adminRequest($admin,'GET','/reports/dual-job-alerts?user_id='.Util::id($foreign['id'])),404,'foreign compliance filter');
    $note=expect(adminRequest($admin,'PUT','/users/'.$uid.'/install-coverage',['note_text'=>'Programado','is_exempt'=>true]),200,'coverage note'); check($note['status']==='exempt','coverage exemption');
    expect(adminRequest($admin,'PUT','/tenants/panel-settings',['install_coverage_heartbeat_days'=>10]),200,'save panel setting');
    $app=expect(adminRequest($admin,'POST','/policies/suspicious-apps',['app_pattern'=>'test.exe','category'=>'vm','description'=>'Test','active'=>true]),201,'app catalog create');
    expect(adminRequest($other,'PUT','/policies/suspicious-apps/'.$app['id'],['app_pattern'=>'test.exe','category'=>'vm','description'=>'Test','active'=>true]),404,'foreign app catalog write');
    expect(adminRequest($admin,'DELETE','/policies/suspicious-apps/'.$app['id']),204,'app catalog delete');
    $db->run('UPDATE user_assignments SET starts_at=UTC_TIMESTAMP()-INTERVAL 15 DAY WHERE tenant_id=? AND user_id=?',[$tenant,$base['id']]);
    foreach (range(1,7) as $weekday) { $db->run('INSERT INTO schedule_days VALUES (?,?,?)',[$tenant,$base['schedule_id'],$weekday]); }
    $device=Util::bin(Util::uuid()); $da=Util::bin(Util::uuid());
    $ua=$db->one('SELECT id FROM user_assignments WHERE tenant_id=? AND user_id=?',[$tenant,$base['id']])['id'];
    $db->run("INSERT INTO devices (tenant_id,id,user_id,hostname,agent_version,os_edition,cpu,ram_bytes,capabilities) VALUES (?,?,?,'Fresh v4','4.0.0','test','test',1,'[]')",[$tenant,$device,$base['id']]);
    $db->run("INSERT INTO device_assignments (tenant_id,id,device_id,user_id,starts_at,reason) VALUES (?,?,?,?,UTC_TIMESTAMP()-INTERVAL 15 DAY,'phase B fixture')",[$tenant,$da,$device,$base['id']]);
    $db->run("INSERT INTO app_classification (tenant_id,id,process_name,category) VALUES (?,?,'editor.exe','productive')",[$tenant,Util::bin(Util::uuid())]);
    $episode=static function(string $d,string $start,string $end,int $active) use ($db,$tenant,$base,$device,$da,$ua): void {
        $db->run("INSERT INTO episodes (tenant_id,device_id,event_id,user_id,device_assignment_id,user_assignment_id,event_date,started_at,ended_at,process_name,active_seconds,idle_seconds,body_hash) VALUES (?,?,?,?,?,?,?,?,?,'editor.exe',?,0,?)",[$tenant,$device,Util::bin(Util::uuid()),$base['id'],$da,$ua,$d,$start,$end,$active,random_bytes(32)]);
    };
    $episode($day,$day.' 13:05:00',$day.' 13:35:00',1800);
    foreach (range(1,5) as $n) {
        $d=(new DateTimeImmutable($day))->modify('-'.$n.' days')->format('Y-m-d');
        $episode($d,$d.' 23:00:00',(new DateTimeImmutable($d))->modify('+1 day')->format('Y-m-d').' 00:00:00',3600);
    }
    $db->run('INSERT INTO rollup_days (tenant_id,day) VALUES (?,?) ON DUPLICATE KEY UPDATE dirty=TRUE,input_revision=input_revision+1',[$tenant,$day]);
    $result=(new ProductivityWorker($db))->run($tid,1,120); check($result['days']===1,'phase B productivity cron');
    $fresh=$db->one('SELECT * FROM focus_daily WHERE tenant_id=? AND day=? AND user_id=?',[$tenant,$day,$base['id']]);
    check($fresh['first_activity_time']==='08:05:00' && (int)$fresh['punctuality_minutes']===-5 && (int)$fresh['deep_work_sessions']===1 && (int)$fresh['longest_focus_streak_seconds']===1800 && (float)$fresh['constancy_pct']===11.11,'fresh v4 calculates punctuality, deep sessions, streak and occupied blocks');
    check((int)$db->one("SELECT SUM(active_seconds) n FROM day_summary WHERE tenant_id=? AND day=? AND calculation_version LIKE 'k3-import-%'",[$tenant,$day])['n']===160*28800,'partial raw replay preserves imported daily totals');
    check((int)$db->one("SELECT COUNT(*) n FROM dual_job_alerts WHERE tenant_id=? AND user_id=? AND alert_type='after_hours_pattern'",[$tenant,$base['id']])['n']===1,'five days of after-hours activity detected');
    check((int)$db->one('SELECT COUNT(*) n FROM suspicious_app_detections WHERE tenant_id=? AND day=?',[$tenant,$day])['n']===160,'detect suspicious processes for 160 people');
    $rows=expect(adminRequest($admin,'GET','/reports/suspicious-apps?limit=100'),200,'detections first page'); check(count($rows['data'])===100 && $rows['next_offset']===100,'detection pagination');
    $self=adminFixture($db,$user,['cumplimiento.ver','cobertura.ver','operacion.logs'],false,'self');
    $scoped=expect(adminRequest($self,'GET','/reports/suspicious-apps'),200,'self compliance scope'); check(count($scoped['data'])===1 && $scoped['data'][0]['user_id']===$uid,'self cannot see colleagues');
    expect(adminRequest($self,'GET','/reports/suspicious-apps?user_id='.Util::id($base['id'])),404,'same-tenant out-of-scope user');
    $scoped=expect(adminRequest($self,'GET','/devices/install-coverage'),200,'self coverage scope'); check(count($scoped['data'])===1,'coverage respects self scope');
    $db->run("INSERT INTO client_logs (tenant_id,device_id,event_id,at,level,code,component,body_hash) VALUES (?,?,?,UTC_TIMESTAMP(),'error','fixture_error','activity',?)",[$tenant,$device,Util::bin(Util::uuid()),random_bytes(32)]);
    $logs=expect(adminRequest($admin,'GET','/audit/client-logs?level=error'),200,'client logs actual rows'); check(count($logs['data'])===1,'client error log visible');
    $logs=expect(adminRequest($self,'GET','/audit/client-logs'),200,'scoped logs'); check(count($logs['data'])===0,'self cannot read colleague logs');
    expect(adminRequest($other,'GET','/audit/client-logs?device_id='.Util::id($device)),404,'foreign log device');
    check($db->one('SELECT status FROM productivity_cron_status WHERE tenant_id=?',[$tenant])['status']==='ok','cron health persisted');
    $db->run('UPDATE rollup_days SET dirty=TRUE,input_revision=input_revision+1 WHERE tenant_id=? AND day=?',[$tenant,$day]); (new ProductivityWorker($db))->run($tid,1,120);
    check((int)$db->one('SELECT COUNT(*) n FROM suspicious_app_detections WHERE tenant_id=? AND day=?',[$tenant,$day])['n']===160,'detections idempotent');
    $db->run('DROP DATABASE '.$sourceName);
    echo "PHASE B PASS: 160 imported people, 320 daily rows, presence fallback, holiday scopes, RBAC, tenant boundaries, ETL replay, detections and operations\n";
}
try { $before=$checks; phaseBTests(new Database()); echo 'PHASE B ASSERTIONS: '.($checks-$before).PHP_EOL; }
catch (Throwable $e) { fwrite(STDERR,$e->getMessage().PHP_EOL.$e->getTraceAsString().PHP_EOL); exit(1); }
