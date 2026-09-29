<?php
declare(strict_types=1);
namespace Keeper;

final class ExternalApi
{
    private string $tenant;
    private array $filters=[];
    private ?ExternalMetrics $metrics=null;
    private ?array $member=null;
    private string $start;
    private string $end;
    public function __construct(private Database $db, private array $c, private Request $r, private Validator $validator) { $this->tenant=$c['tenant_id']; }

    private function row(string $table, string $id): array
    {
        return $this->db->one("SELECT * FROM $table WHERE tenant_id=? AND id=?",[$this->tenant,$id]) ?? throw new ApiError(404,'resource_not_found');
    }
    public function dispatch(): mixed
    {
        if ($this->r->operation['operationId']==='externalImportExpectedDevices') {
            $result=(new DeviceIntake($this->db))->importRows($this->tenant,null,$this->r->body->rows,'api');
            (new Audit($this->db))->record($this->c,$this->r,'externalImportExpectedDevices','expected_device',$this->tenant,['rows','created:'.$result['created']]);
            return $result;
        }
        if ($this->r->method!=='GET') { throw new ApiError(404,'resource_not_found'); }
        foreach (['area_id','site_id','user_id'] as $field) {
            if (!isset($_GET[$field])) { continue; }
            $id=Util::bin($_GET[$field]); $row=$this->row($field==='user_id'?'users':'org_units',$id);
            if ($field!=='user_id' && $row['kind']!==substr($field,0,-3)) { throw new ApiError(404,'resource_not_found'); }
            $this->filters[$field]=$id;
        }
        if ($this->r->resourceId!==null) { $this->member=$this->row('users',Util::bin($this->r->resourceId)); }
        if (isset($_GET['from'])) {
            $from=new \DateTimeImmutable($_GET['from'],new \DateTimeZone($this->c['timezone']));
            $to=new \DateTimeImmutable($_GET['to'],new \DateTimeZone($this->c['timezone']));
            $max=$this->r->operation['operationId']==='externalProductivity'?366:31;
            if ($from >= $to || $from->diff($to)->days>$max) { throw new ApiError(422,'invalid_range'); }
            $this->start=$from->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            $this->end=$to->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            $this->metrics=new ExternalMetrics($this->db,$this->c,$_GET['from'],$_GET['to'],$this->filters);
        }
        return match ($this->r->operation['operationId']) {
            'externalDashboard'=>$this->dashboard(), 'externalTeam'=>$this->team(), 'externalMember'=>$this->overview(),
            'externalDevices'=>$this->devices(), 'externalActivity'=>$this->activity(), 'externalPresence'=>$this->presence(),
            'externalSubscription'=>$this->subscription(), 'externalTiers'=>$this->tiers(),
            'externalProductivity'=>$this->metrics->report(), 'externalOrganization'=>$this->organization(), 'externalNotifications'=>$this->notifications(),
            default=>throw new ApiError(404,'resource_not_found'),
        };
    }
    private function page(string $sql, array $args, callable $map, string $at='created_at', array $ids=['id'], ?string $receivedAt=null): array
    {
        $filters=$_GET; unset($filters['cursor']); ksort($filters);
        $resources=new Resources($this->db,['cursor_context'=>Util::json([Util::id($this->tenant),Util::id($this->c['integration_id']),$this->c['auth_version'],$this->c['tenant_auth_version'],$this->c['scopes'],$this->r->path,$filters])],$this->validator);
        $page=isset($_GET['cursor'])?$resources->decodeCursor($_GET['cursor']):['snapshot'=>$this->db->one('SELECT UTC_TIMESTAMP(6) at')['at'],'exp'=>time()+900];
        $where="q.$at<=?"; $args[]=$page['snapshot'];
        if ($receivedAt!==null) { $where.=" AND q.$receivedAt<=?"; $args[]=$page['snapshot']; }
        if (isset($page['keys'])) {
            $columns=implode(',',array_map(fn($k)=>'q.'.$k,[$at,...$ids]));
            $where.=" AND ($columns) > (".implode(',',array_fill(0,count($ids)+1,'?')).')';
            $args[]=$page['keys'][0]; foreach (array_slice($page['keys'],1) as $key) { $args[]=Util::bin($key); }
        }
        $limit=(int)($_GET['limit']??50); $args[]=$limit+1;
        $rows=$this->db->run("SELECT * FROM ($sql) q WHERE $where ORDER BY q.$at,".implode(',',array_map(fn($k)=>'q.'.$k,$ids)).' LIMIT ?',$args)->fetchAll();
        $more=count($rows)>$limit; if ($more) { array_pop($rows); }
        $next=null;
        if ($more) { $last=end($rows); $page['keys']=[$last[$at],...array_map(fn($k)=>Util::id($last[$k]),$ids)]; $next=$resources->encodeCursor($page); }
        return ['data'=>$map($rows),'next_cursor'=>$next,'generated_at'=>Util::now(),'data_through'=>Util::time($page['snapshot'])];
    }
    private function userQuery(): array
    {
        $where='u.tenant_id=?'; $args=[$this->tenant];
        foreach ($this->filters as $field=>$id) { $where.=" AND u.$field=?"; $args[]=$id; }
        return ["SELECT u.id,u.display_name,u.firm_id,u.site_id,u.area_id,u.created_at,
            (SELECT MAX(d.last_seen_at) FROM devices d WHERE d.tenant_id=u.tenant_id AND d.user_id=u.id AND d.status='active') last_seen_at,
            (SELECT s.tier_id FROM subscriptions s WHERE s.tenant_id=u.tenant_id AND s.user_id=u.id AND s.status IN ('active','scheduled') AND s.starts_at<=UTC_TIMESTAMP(6) AND (s.ends_at IS NULL OR s.ends_at>UTC_TIMESTAMP(6)) ORDER BY s.starts_at DESC LIMIT 1) tier_id
            FROM users u WHERE $where",$args];
    }
    private function members(array $rows): array
    {
        if (!$rows) { return []; }
        $ids=array_column($rows,'id'); $stats=array_column($this->metrics->rows('user',$ids),null,'bucket');
        $roles=$this->db->run('SELECT ur.user_id,r.name FROM user_roles ur JOIN roles r ON r.tenant_id=ur.tenant_id AND r.id=ur.role_id WHERE ur.tenant_id=? AND ur.user_id IN ('.implode(',',array_fill(0,count($ids),'?')).') ORDER BY r.name,r.id',[$this->tenant,...$ids])->fetchAll();
        $labels=[]; foreach ($roles as $role) { $labels[$role['user_id']][]=$role['name']; }
        return array_map(function($row) use ($stats,$labels) {
            $out=[]; foreach (['id','firm_id','area_id','site_id'] as $key) { $out[$key]=Util::id($row[$key]); }
            $metric=$stats[$row['id']]??null;
            return $out+['display_name'=>$row['display_name'],'role_labels'=>array_slice($labels[$row['id']]??[],0,30),'tier_id'=>$row['tier_id']===null?null:Util::id($row['tier_id']),
                'last_seen_at'=>$row['last_seen_at']===null?null:Util::time($row['last_seen_at']),'focus_score'=>ExternalMetrics::score($metric),'productivity_percent'=>$metric===null?null:ExternalMetrics::percent($metric)];
        },$rows);
    }
    private function team(): array
    {
        [$sql,$args]=$this->userQuery(); $page=$this->page($sql,$args,fn($rows)=>$this->members($rows));
        $metrics=$this->metrics->rows('total',array_map(fn($m)=>Util::bin($m['id']),$page['data']));
        $page['data_through']=isset($metrics[0]['data_through'])?Util::time($metrics[0]['data_through']):null;
        return $page;
    }
    private function overview(): array
    {
        [$sql,$args]=$this->userQuery(); $rows=$this->db->run($sql.' AND u.id=?',[...$args,$this->member['id']])->fetchAll();
        $report=$this->metrics->report([$this->member['id']]);
        return ['member'=>$this->members($rows)[0],'trend'=>$report['series'],'generated_at'=>Util::now(),'data_through'=>$report['data_through']];
    }
    private function dashboard(): array
    {
        [$sql,$args]=$this->userQuery();
        $counts=$this->db->one("SELECT COUNT(*) total,SUM(last_seen_at>=UTC_TIMESTAMP(6)-INTERVAL 300 SECOND) online FROM ($sql) q",$args);
        $report=$this->metrics->report(); $total=$this->metrics->rows('total')[0]??null;
        $areas=$this->metrics->rows('area');
        if (count($areas)>100) { throw new ApiError(422,'validation_failed'); }
        $preview=$this->db->run($sql.' ORDER BY u.created_at,u.id LIMIT 20',$args)->fetchAll();
        $stats=array_column($this->metrics->rows('user',array_column($preview,'id')),null,'bucket');
        $roster=array_map(static function($r) use ($stats) {
            $s=$stats[$r['id']]??null; $expected=(int)($s['expected_seconds']??0);
            return ['user_id'=>Util::id($r['id']),'display_name'=>$r['display_name'],'online'=>$r['last_seen_at']!==null && strtotime($r['last_seen_at'].' UTC')>=time()-300,'utilization_percent'=>$expected>0?min(100,round(100*(int)$s['active_seconds']/$expected,2)):null];
        },$preview);
        return ['tenant_id'=>Util::id($this->tenant),'generated_at'=>Util::now(),'data_through'=>$report['data_through'],'timezone'=>$this->c['timezone'],'online_members'=>(int)$counts['online'],'total_members'=>(int)$counts['total'],
            'active_seconds'=>(int)($total['active_seconds']??0),'focus_score'=>ExternalMetrics::score($total),'productivity_percent'=>$total===null?null:ExternalMetrics::percent($total),
            'weekly_output'=>$report['series'],'workload_by_area'=>array_map(fn($r)=>['area_id'=>Util::id($r['bucket']),'active_seconds'=>(int)$r['active_seconds']],$areas),'roster'=>$roster];
    }
    private function devices(): array
    {
        return $this->page('SELECT id,tenant_id,user_id,hostname,status,agent_version,last_seen_at,os_edition,cpu,ram_bytes,encryption_state,capabilities,policy_version,version,created_at FROM devices WHERE tenant_id=? AND user_id=?',[$this->tenant,$this->member['id']],static fn($rows)=>array_map(static function($r) {
            $out=[]; foreach (['id','tenant_id','user_id'] as $key) { $out[$key]=Util::id($r[$key]); }
            foreach (['hostname','status','agent_version','os_edition','cpu','encryption_state'] as $key) { $out[$key]=$r[$key]; }
            return $out+['last_seen_at'=>$r['last_seen_at']===null?null:Util::time($r['last_seen_at']),'ram_bytes'=>(int)$r['ram_bytes'],'capabilities'=>json_decode($r['capabilities']),'policy_version'=>$r['policy_version']===null?null:(int)$r['policy_version'],'version'=>(int)$r['version']];
        },$rows));
    }
    private function activity(): array
    {
        $titles=($_GET['include_titles']??'false')==='true';
        $sql='SELECT event_id,device_id,started_at,ended_at,process_name,active_seconds,idle_seconds,received_at'.($titles?',window_title':'').' FROM episodes WHERE tenant_id=? AND user_id=? AND event_date>=? AND event_date<=? AND started_at>=? AND started_at<?';
        return $this->page($sql,[$this->tenant,$this->member['id'],substr($this->start,0,10),substr($this->end,0,10),$this->start,$this->end],static fn($rows)=>array_map(static function($r) use ($titles) {
            $out=['event_id'=>Util::id($r['event_id']),'started_at'=>Util::time($r['started_at']),'ended_at'=>Util::time($r['ended_at']),'process_name'=>$r['process_name'],'active_seconds'=>(int)$r['active_seconds'],'idle_seconds'=>(int)$r['idle_seconds']];
            if ($titles && $r['window_title']!==null) { $out['window_title']=$r['window_title']; } return $out;
        },$rows),'started_at',['event_id','device_id'],'received_at');
    }
    private function presence(): array
    {
        $location=($_GET['include_location']??'false')==='true';
        return $this->page('SELECT id,tenant_id,user_id,occurred_at,source,direction,site_id'.($location?',latitude,longitude,accuracy_m':'').' FROM check_ins WHERE tenant_id=? AND user_id=? AND occurred_at>=? AND occurred_at<?',[$this->tenant,$this->member['id'],$this->start,$this->end],static fn($rows)=>array_map(static function($r) use ($location) {
            $out=[]; foreach (['id','tenant_id','user_id'] as $key) { $out[$key]=Util::id($r[$key]); }
            $out+=['occurred_at'=>Util::time($r['occurred_at']),'source'=>$r['source'],'direction'=>$r['direction'],'site_id'=>$r['site_id']===null?null:Util::id($r['site_id'])];
            if ($location && $r['latitude']!==null) { $out['location']=['latitude'=>(float)$r['latitude'],'longitude'=>(float)$r['longitude'],'accuracy_m'=>(float)$r['accuracy_m']]; } return $out;
        },$rows),'occurred_at');
    }
    private function subscription(): array
    {
        $r=$this->db->one("SELECT id,tenant_id,user_id,tier_id,starts_at,ends_at,status,version FROM subscriptions WHERE tenant_id=? AND user_id=? AND status IN ('active','scheduled') AND starts_at<=UTC_TIMESTAMP(6) AND (ends_at IS NULL OR ends_at>UTC_TIMESTAMP(6)) ORDER BY starts_at DESC LIMIT 1",[$this->tenant,$this->member['id']]);
        if (!$r) { throw new ApiError(404,'resource_not_found'); }
        $out=[]; foreach (['id','tenant_id','user_id','tier_id'] as $key) { $out[$key]=Util::id($r[$key]); }
        return $out+['starts_at'=>Util::time($r['starts_at']),'ends_at'=>$r['ends_at']===null?null:Util::time($r['ends_at']),'status'=>'active','version'=>(int)$r['version']];
    }
    private function tiers(): array
    {
        return $this->page('SELECT id,tenant_id,name,badge_label,active,version,created_at FROM tier WHERE tenant_id=?',[$this->tenant],fn($rows)=>array_map(function($r) {
            $modules=array_column($this->db->run('SELECT module_code FROM tier_module WHERE tenant_id=? AND tier_id=? ORDER BY module_code',[$this->tenant,$r['id']])->fetchAll(),'module_code');
            return ['id'=>Util::id($r['id']),'tenant_id'=>Util::id($r['tenant_id']),'name'=>$r['name'],'badge_label'=>$r['badge_label'],'entitlements'=>$modules,'active'=>(bool)$r['active'],'version'=>(int)$r['version']];
        },$rows));
    }
    private function organization(): array
    {
        return $this->page('SELECT id,tenant_id,kind,name,parent_id,active,version,created_at FROM org_units WHERE tenant_id=?',[$this->tenant],static fn($rows)=>array_map(static fn($r)=>['id'=>Util::id($r['id']),'tenant_id'=>Util::id($r['tenant_id']),'kind'=>$r['kind'],'name'=>$r['name'],'parent_id'=>$r['parent_id']===null?null:Util::id($r['parent_id']),'active'=>(bool)$r['active'],'version'=>(int)$r['version']],$rows));
    }
    private function notifications(): array
    {
        $titles=['device.status_changed'=>'Estado de dispositivo actualizado','command.completed'=>'Comando completado','policy.changed'=>'Política actualizada','subscription.changed'=>'Suscripción actualizada','report.ready'=>'Reporte disponible'];
        return $this->page('SELECT id,tenant_id,type,resource_id,created_at FROM notifications WHERE tenant_id=? AND type IN (?,?,?,?,?)',[$this->tenant,...array_keys($titles)],static fn($rows)=>array_map(static fn($r)=>['id'=>Util::id($r['id']),'tenant_id'=>Util::id($r['tenant_id']),'type'=>$r['type'],'resource_id'=>Util::id($r['resource_id']),'created_at'=>Util::time($r['created_at']),'title'=>$titles[$r['type']]],$rows));
    }
}
