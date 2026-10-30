<?php
declare(strict_types=1);
use Keeper\{Database,Util,AccessGrants,AccessScope,SupervisorReadModel};

// Instrumentación exclusivamente del arnés: conserva SQL y parámetros en memoria, nunca imprime sus valores.
final class SupervisorMeasuredStatement extends PDOStatement
{
    public static bool $enabled=false;
    public static array $queries=[];
    private array $values=[];
    protected function __construct() {}
    public function bindValue(string|int $param,mixed $value,int $type=PDO::PARAM_STR): bool
    {
        $this->values[$param]=$value; return parent::bindValue($param,$value,$type);
    }
    public function execute(?array $params=null): bool
    {
        if (self::$enabled) { self::$queries[]=[$this->queryString,$params??array_values($this->values)]; }
        return parent::execute($params);
    }
}

function supervisorProfile(Database $db,callable $read): array
{
    $db->pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS,[SupervisorMeasuredStatement::class]);
    SupervisorMeasuredStatement::$queries=[]; SupervisorMeasuredStatement::$enabled=true;
    memory_reset_peak_usage(); $start=hrtime(true);
    try { $dto=$read(); }
    finally { SupervisorMeasuredStatement::$enabled=false; }
    $ms=(hrtime(true)-$start)/1e6;
    $result=['queries'=>count(SupervisorMeasuredStatement::$queries),'peak_bytes'=>memory_get_peak_usage(true),'model_ms'=>round($ms,2),'bytes'=>strlen(Util::json($dto))];
    return [$result,SupervisorMeasuredStatement::$queries,$dto];
}

function supervisorHttpBenchmark(array $account,string $route): array
{
    $send=fn()=>request('GET',$route,null,null,null,null,null,['cookie'=>'__Host-keeper_admin='.$account['cookie'],'x-tenant-id'=>Util::id($account['tenant'])]);
    expect($send(),200,'capacity warmup'); $times=[]; $bytes=0;
    for ($i=0;$i<20;$i++) {
        $start=hrtime(true); $response=$send(); $times[]=(hrtime(true)-$start)/1e6;
        expect($response,200,'capacity measured request'); $bytes=max($bytes,strlen($response[3]));
    }
    sort($times); return ['requests'=>20,'p50_ms'=>round(($times[9]+$times[10])/2,2),'p95_ms'=>round($times[18],2),'max_bytes'=>$bytes];
}

function supervisorCapacityTests(Database $db): void
{
    $clients=[secondTenant($db),secondTenant($db),secondTenant($db)];
    $permissions=['usuarios.ver','equipos.ver','presencia.ver','reportes.ver','cumplimiento.ver','reglas.ver'];
    $account=adminFixture($db,$clients[0],$permissions); $group=Util::bin(Util::uuid());
    $db->run("INSERT INTO company_groups (id,name) VALUES (?,'Capacity isolated group')",[$group]);
    $db->run('UPDATE admin_accounts SET group_tenant_id=?,group_id=? WHERE tenant_id=? AND id=?',[Util::bin(Keeper\AdminAuth::PLATFORM),$group,$account['tenant'],$account['id']]);
    $day=(new DateTimeImmutable('today',new DateTimeZone('America/Bogota')))->format('Y-m-d');
    $first=(new DateTimeImmutable($day))->modify('-13 days')->format('Y-m-d');
    $roles=[]; $people=[[],[],[]]; $devices=[];
    foreach ($clients as $n=>$u) {
        $t=$u['tenant_id']; $db->run('INSERT INTO company_group_members (group_id,tenant_id) VALUES (?,?)',[$group,$t]);
        $role=$n===0?$account['role']:Util::bin(Util::uuid()); $roles[]=$role;
        if ($n>0) {
            $db->run("INSERT INTO roles (tenant_id,id,name,seed_key) VALUES (?,?,'Capacity IT','it')",[$t,$role]);
            foreach ($permissions as $permission) { $db->run('INSERT INTO role_permissions VALUES (?,?,?)',[$t,$role,$permission]); }
            AccessGrants::setForGroupAccount($db,$t,$account['tenant'],$account['id'],$role,AccessScope::all());
        } else { $db->run("UPDATE roles SET seed_key='it' WHERE tenant_id=? AND id=?",[$t,$role]); }
        for ($w=1;$w<=7;$w++) { $db->run('INSERT INTO schedule_days (tenant_id,schedule_id,weekday) VALUES (?,?,?)',[$t,$u['schedule_id'],$w]); }
        $db->run('UPDATE user_assignments SET starts_at=? WHERE tenant_id=?',[$first,$t]);
        for ($d=0;$d<14;$d++) {
            $date=(new DateTimeImmutable($first))->modify("+$d day")->format('Y-m-d');
            $db->run("INSERT INTO rollup_days (tenant_id,day,dirty,calculated_at,data_through) VALUES (?,?,FALSE,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))",[$t,$date]);
        }
    }
    $origin=array_replace(supervisorOrigin($db,$account,$clients[0]['id']),['group_id'=>$group]);
    $counts=[]; $lastQueries=[]; $accepted=true;
    echo 'SUPERVISOR CAPACITY ENV '.Util::json(['php'=>PHP_VERSION,'db'=>$db->one('SELECT VERSION() version')['version'],'os'=>PHP_OS_FAMILY,'cpu'=>getenv('PROCESSOR_IDENTIFIER')?:'not reported','logical_processors'=>getenv('NUMBER_OF_PROCESSORS')?:'not reported','buffer_pool_bytes'=>(int)$db->one('SELECT @@innodb_buffer_pool_size bytes')['bytes']])."\n";
    foreach ([[30,14],[300,142],[300,600]] as [$size,$deviceCount]) {
        $db->transaction(function () use ($db,$clients,&$people,&$devices,$size,$deviceCount,$first): void {
            foreach ($clients as $n=>$base) {
                while (count($people[$n])<$size/3) {
                    $i=count($people[$n]); $u=$i===0?$base:adminMember($db,$base); $t=$u['tenant_id'];
                    $db->run('UPDATE users SET display_name=? WHERE tenant_id=? AND id=?',['Capacity '.str_pad((string)$i,3,'0',STR_PAD_LEFT),$t,$u['id']]);
                    if ($i===0) { $assignment=$db->one('SELECT id FROM user_assignments WHERE tenant_id=? AND user_id=?',[$t,$u['id']])['id']; }
                    else {
                        $assignment=Util::bin(Util::uuid());
                        $db->run('INSERT INTO user_assignments (tenant_id,id,user_id,firm_id,site_id,area_id,position_id,schedule_id,starts_at) VALUES (?,?,?,?,?,?,?,?,?)',[$t,$assignment,$u['id'],$u['firm_id'],$u['site_id'],$u['area_id'],$u['position_id'],$u['schedule_id'],$first]);
                    }
                    $u['assignment']=$assignment; $people[$n][]=$u;
                    for ($d=0;$d<14;$d++) {
                        $date=(new DateTimeImmutable($first))->modify("+$d day")->format('Y-m-d');
                        $db->run("INSERT INTO day_summary (tenant_id,day,user_id,user_assignment_id,active_seconds,idle_seconds,productive_seconds,expected_seconds,coverage_percent,calculation_version,timezone,first_activity,last_activity,calculated_at,data_through) VALUES (?,?,?,?,14400,3600,10800,32400,100,'capacity','America/Bogota',CONCAT(?,' 13:00:00'),CONCAT(?,' 21:00:00'),UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))",[$t,$date,$u['id'],$assignment,$date,$date]);
                        $db->run("INSERT INTO dual_job_alerts (tenant_id,id,user_id,day,alert_type,severity,evidence,source) VALUES (?,?,?,?,'remote_desktop','low','{}','worker')",[$t,Util::bin(Util::uuid()),$u['id'],$date]);
                    }
                }
            }
            $all=array_merge(...$people);
            while (count($devices)<$deviceCount) {
                $i=count($devices); $u=$all[$i%count($all)]; $t=$u['tenant_id'];
                $device=supervisorDevice($db,$u,'Capacity PC '.$i); $devices[]=$device;
                $db->run('UPDATE device_assignments SET starts_at=? WHERE tenant_id=? AND device_id=?',[$first,$t,$device]);
                $da=$db->one('SELECT id FROM device_assignments WHERE tenant_id=? AND device_id=?',[$t,$device])['id'];
                for ($d=0;$d<14;$d++) {
                    $date=(new DateTimeImmutable($first))->modify("+$d day")->format('Y-m-d');
                    $db->run("INSERT INTO episodes (tenant_id,device_id,event_id,user_id,device_assignment_id,user_assignment_id,event_date,started_at,ended_at,process_name,active_seconds,idle_seconds,body_hash) VALUES (?,?,?,?,?,?,?,CONCAT(?,' 13:00:00'),CONCAT(?,' 14:00:00'),'capacity.exe',3000,600,?)",[$t,$device,Util::bin(Util::uuid()),$u['id'],$da,$u['assignment'],$date,$date,$date,random_bytes(32)]);
                    $db->run("INSERT INTO security_reports (tenant_id,device_id,event_id,observed_at,report_hash,controls) VALUES (?,?,?,CONCAT(?,' 00:00:00'),?,'[]')",[$t,$device,Util::bin(Util::uuid()),$date,random_bytes(32)]);
                }
            }
        });
        [$profile,$queries,$dto]=supervisorProfile($db,fn()=>(new SupervisorReadModel($db,$origin,['limit'=>50]))->overview());
        check($dto['total']===$size && count($dto['data'])===min(50,$size),'capacity real roster');
        check(count($dto['facets']['tenants'])===3,'capacity three authorized clients');
        check(!array_filter($queries,fn($q)=>preg_match('/\bFROM episodes\b/i',$q[0])),'overview never reads raw episodes');
        $counts[]=$profile['queries']; $lastQueries=$queries;
        $http=supervisorHttpBenchmark($account,'/reports/supervisor?limit=50');
        $accepted=$accepted && $http['p95_ms']<=2000 && $http['max_bytes']<=250*1024;
        echo 'SUPERVISOR CAPACITY '.Util::json(['people'=>$size,'devices'=>$deviceCount,'days'=>14,'endpoint'=>'overview']+$http+$profile)."\n";
        $c=$origin; $uid=Util::id($clients[0]['id']);
        [$profile,$queries,$dto]=supervisorProfile($db,fn()=>(new SupervisorReadModel($db,$c,['day'=>$day]))->person($clients[0]['id']));
        check(isset($dto['current']['protection']),'capacity IT includes protection'); $lastQueries=array_merge($lastQueries,$queries);
        $http=supervisorHttpBenchmark($account,"/users/$uid/supervisor-summary?day=$day");
        $accepted=$accepted && $http['p95_ms']<=1000;
        echo 'SUPERVISOR CAPACITY '.Util::json(['people'=>$size,'devices'=>$deviceCount,'days'=>14,'endpoint'=>'summary']+$http+$profile)."\n";
    }
    check($counts[0]===$counts[1] && $counts[1]===$counts[2],'query count constant at 30/14, 300/142, 300/600');
    echo 'PASS supervisor bounded queries '.implode('/',$counts)."\n";
    $uid=Util::id($clients[0]['id']); $next=(new DateTimeImmutable($day))->modify('+1 day')->format('Y-m-d');
    foreach (['windows'=>"/users/$uid/windows?from=$day&to=$next",'timeline'=>"/users/$uid/timeline?day=$day&limit=200",'rules'=>"/users/$uid/rules",'filtered'=>"/reports/supervisor?tenant_id=".Util::id($account['tenant']).'&q=Capacity%200&state=connected&order=name'] as $name=>$route) {
        echo 'SUPERVISOR CAPACITY '.Util::json(['people'=>300,'devices'=>600,'endpoint'=>$name]+supervisorHttpBenchmark($account,$route))."\n";
    }
    $db->run("DELETE FROM role_permissions WHERE tenant_id=? AND role_id=? AND permission_code IN ('cumplimiento.ver','presencia.ver')",[$account['tenant'],$roles[0]]);
    echo 'SUPERVISOR CAPACITY '.Util::json(['people'=>300,'devices'=>600,'endpoint'=>'partial-permissions']+supervisorHttpBenchmark($account,'/reports/supervisor?limit=50'))."\n";
    $explained=[];
    foreach ($lastQueries as [$sql,$args]) {
        $kind=str_contains($sql,'FROM devices d JOIN users')?'devices/latest-report-policy':(str_contains($sql,'FROM users u LEFT JOIN org_units')?'roster':(str_contains($sql,'FROM dual_job_alerts x')?'alerts':null));
        if ($kind===null || isset($explained[$kind]) || ($kind==='devices/latest-report-policy' && !str_contains($sql,'security_reports'))) continue;
        $explained[$kind]=true; $plan=$db->run('EXPLAIN '.$sql,$args)->fetchAll();
        echo 'SUPERVISOR EXPLAIN SQL '.$kind.' '.preg_replace('/\s+/',' ',trim($sql))."\n";
        echo 'SUPERVISOR EXPLAIN '.Util::json(['kind'=>$kind,'plan'=>array_map(fn($row)=>array_intersect_key($row,array_flip(['table','type','possible_keys','key','rows','Extra'])),$plan)])."\n";
        check((bool)array_filter($plan,fn($row)=>($row['key']??null)!==null),'indexed access '.$kind);
    }
    check(count($explained)===3,'explain real roster devices and alerts queries');
    echo $accepted?"PASS supervisor capacity budgets\n":"BLOCKED supervisor capacity budgets: see measured p95/bytes\n";
}
