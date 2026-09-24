<?php
declare(strict_types=1);
namespace Keeper;

final class OperationsApi
{
    private string $tenant;
    public function __construct(private Database $db,private array $c,private AdminAccess $access,private Request $r) { $this->tenant=$c['tenant_id']; }
    private function row(string $table,string $id): array
    {
        $row=$this->db->one("SELECT * FROM $table WHERE tenant_id=? AND id=? LOCK IN SHARE MODE",[$this->tenant,$id]);
        if (!$row) { throw new ApiError(404,'resource_not_found'); } return $row;
    }
    private function page(string $sql,array $args,callable $map): array
    {
        $limit=(int)($_GET['limit']??50); $offset=(int)($_GET['offset']??0);
        $rows=$this->db->run($sql.' LIMIT ? OFFSET ?',[...$args,$limit+1,$offset])->fetchAll();
        $more=count($rows)>$limit; if ($more) { array_pop($rows); }
        return AdminApi::response(['data'=>array_map($map,$rows),'next_offset'=>$more?$offset+$limit:null]);
    }
    public function dispatch(string $op,?string $id,?object $b): array
    {
        if (str_contains($op,'Holiday') || in_array($op,['listSuspiciousApps','getSuspiciousApp','createSuspiciousApp','putSuspiciousApp','deleteSuspiciousApp'],true)) { return $this->catalog($op,$id,$b); }
        if ($op==='listComplianceSignals') {
            $rows=[]; foreach (ComplianceWorker::SIGNALS as $code=>$description) { $rows[]=['code'=>$code,'description'=>$description]; }
            return AdminApi::response(['data'=>$rows,'next_offset'=>null]);
        }
        if (in_array($op,['listDualJobAlerts','listSuspiciousDetections'],true)) { return $this->signals($op); }
        if (in_array($op,['listInstallCoverage','putInstallCoverage'],true)) { return $this->coverage($id,$b); }
        if ($op==='listClientLogs') { return $this->logs(); }
        $this->access->requireFull();
        if ($op==='getServerHealth') {
            $cron=$this->db->one('SELECT * FROM productivity_cron_status WHERE tenant_id=?',[$this->tenant]);
            $count=fn($table,$where)=>(int)$this->db->one("SELECT COUNT(*) n FROM $table WHERE tenant_id=? AND $where",[$this->tenant])['n'];
            $last=fn($table,$field)=>$this->db->one("SELECT MAX($field) t FROM $table WHERE tenant_id=?",[$this->tenant])['t'];
            return AdminApi::response(['version'=>'v4','calculation_version'=>ProductivityWorker::VERSION,'database_version'=>$this->db->one('SELECT VERSION() v')['v'],
                'pending_rollup_days'=>$count('rollup_days','dirty=TRUE'),'pending_commands'=>$count('device_command',"status IN ('pending','delivered','running')"),
                'pending_policy_compiles'=>$count('admin_policy_recompiles','1=1'),'cron_last_run'=>isset($cron['last_run'])?Util::time($cron['last_run']):null,
                'cron_last_success'=>isset($cron['last_success'])?Util::time($cron['last_success']):null,'cron_status'=>$cron['status']??'never',
                'last_sync'=>($t=$last('devices','last_seen_at'))?Util::time($t):null,'last_ingest'=>($t=$last('episode_ingest_keys','received_at'))?Util::time($t):null]);
        }
        if ($op==='putPanelSettings') {
            $this->db->run('INSERT INTO panel_settings (tenant_id,install_coverage_heartbeat_days) VALUES (?,?) ON DUPLICATE KEY UPDATE install_coverage_heartbeat_days=VALUES(install_coverage_heartbeat_days)',[$this->tenant,$b->install_coverage_heartbeat_days]);
            $this->audit('panel_settings',$this->tenant,$b);
        }
        return AdminApi::response(['install_coverage_heartbeat_days'=>(int)($this->db->one('SELECT install_coverage_heartbeat_days FROM panel_settings WHERE tenant_id=?',[$this->tenant])['install_coverage_heartbeat_days']??7)]);
    }
    private function audit(string $type,string $id,object $b): void { (new Audit($this->db))->record($this->c,$this->r,$this->r->operation['operationId'],$type,$id,array_keys(get_object_vars($b))); }
    private function catalog(string $op,?string $id,?object $b): array
    {
        $this->access->requireFull();
        $table=str_contains($op,'HolidaySociet')?'sociedades':(str_contains($op,'Holiday')?'holidays':'suspicious_apps');
        $dto=function($r) use ($table) {
            $out=['id'=>Util::id($r['id'])];
            if ($table==='suspicious_apps') { return $out+['app_pattern'=>$r['app_pattern'],'category'=>$r['category'],'description'=>$r['description'],'active'=>(bool)$r['active']]; }
            $out['name']=$r['name'];
            if ($table==='sociedades') { $out['firm_id']=Util::id($r['firm_id']); }
            if ($table==='holidays') { $out['day']=$r['day']; $out['society_ids']=array_map(fn($x)=>Util::id($x['society_id']),$this->db->run('SELECT society_id FROM holiday_society_links WHERE tenant_id=? AND holiday_id=? ORDER BY society_id',[$this->tenant,$r['id']])->fetchAll()); }
            return $out;
        };
        if (str_starts_with($op,'list')) { return $this->page("SELECT * FROM $table WHERE tenant_id=? ORDER BY id",[$this->tenant],$dto); }
        $old=$id===null?null:$this->row($table,$id);
        if (str_starts_with($op,'get')) { return AdminApi::response($dto($old)); }
        $id??=Util::bin(Util::uuid());
        if (str_starts_with($op,'delete')) {
            $this->db->run("DELETE FROM $table WHERE tenant_id=? AND id=?",[$this->tenant,$id]); $this->audit($table,$id,(object)[]);
            if ($table==='holidays') { ActivityCalendar::refresh($this->db,$this->tenant,[$old['day']]); }
            if ($table==='suspicious_apps') { $this->dirtyCompliance(); }
            return ['status'=>204,'json'=>'','headers'=>[]];
        }
        if ($table==='holidays') {
            foreach ($b->society_ids as $society) { $this->row('sociedades',Util::bin($society)); }
            $this->db->run('INSERT INTO holidays (tenant_id,id,day,name) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE day=VALUES(day),name=VALUES(name)',[$this->tenant,$id,$b->day,$b->name]);
            $this->db->run('DELETE FROM holiday_society_links WHERE tenant_id=? AND holiday_id=?',[$this->tenant,$id]);
            foreach ($b->society_ids as $society) { $this->db->run('INSERT INTO holiday_society_links VALUES (?,?,?)',[$this->tenant,$id,Util::bin($society)]); }
        } elseif ($table==='sociedades') {
            $firm=$this->row('org_units',Util::bin($b->firm_id));
            if ($firm['kind']!=='firm') { throw new ApiError(422,'validation_failed'); }
            $this->db->run('INSERT INTO sociedades (tenant_id,id,name,firm_id) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE name=VALUES(name),firm_id=VALUES(firm_id)',[$this->tenant,$id,$b->name,$firm['id']]);
        } else {
            if ($old) { $this->db->run('UPDATE suspicious_apps SET app_pattern=?,category=?,description=?,active=? WHERE tenant_id=? AND id=?',[$b->app_pattern,$b->category,$b->description,(int)$b->active,$this->tenant,$id]); }
            else { $this->db->run('INSERT INTO suspicious_apps VALUES (?,?,?,?,?,?)',[$this->tenant,$id,$b->app_pattern,$b->category,$b->description,(int)$b->active]); }
        }
        $this->audit($table,$id,$b);
        if ($table==='holidays') { ActivityCalendar::refresh($this->db,$this->tenant,[$b->day,...($old?[$old['day']]:[])]); }
        if ($table==='suspicious_apps') { $this->dirtyCompliance(); }
        return AdminApi::response($dto($this->row($table,$id)),$old?200:201);
    }
    private function dirtyCompliance(): void
    {
        $this->db->run('INSERT INTO rollup_days (tenant_id,day) SELECT e.tenant_id,e.event_date FROM episodes e JOIN retention_settings r ON r.tenant_id=e.tenant_id WHERE e.tenant_id=? AND e.event_date>=UTC_DATE()-INTERVAL r.raw_days DAY GROUP BY e.tenant_id,e.event_date ON DUPLICATE KEY UPDATE dirty=TRUE,input_revision=input_revision+1,finalized_at=NULL',[$this->tenant]);
        $this->db->run('UPDATE rollup_days r JOIN retention_settings s ON s.tenant_id=r.tenant_id SET r.dirty=TRUE,r.input_revision=r.input_revision+1,r.finalized_at=NULL WHERE r.tenant_id=? AND r.day>=UTC_DATE()-INTERVAL s.raw_days DAY',[$this->tenant]);
    }
    private function signals(string $op): array
    {
        [$scope,$args]=$this->access->usersSql('u');
        $from=$_GET['from']??gmdate('Y-m-d',time()-30*86400); $to=$_GET['to']??gmdate('Y-m-d',time()+86400);
        if ($from>=$to || (new \DateTimeImmutable($from))->diff(new \DateTimeImmutable($to))->days>366) { throw new ApiError(422,'invalid_range'); }
        if (isset($_GET['user_id'])) { $user=Util::bin($_GET['user_id']); $this->access->user($user); $scope.=' AND u.id=?'; $args[]=$user; }
        $table=$op==='listDualJobAlerts'?'dual_job_alerts':'suspicious_app_detections';
        return $this->page("SELECT s.*,u.display_name FROM $table s JOIN users u ON u.tenant_id=s.tenant_id AND u.id=s.user_id JOIN retention_settings r ON r.tenant_id=s.tenant_id WHERE s.tenant_id=? AND s.day>=? AND s.day<? AND s.day>=DATE_SUB(UTC_DATE(),INTERVAL r.aggregate_months MONTH) AND $scope ORDER BY s.day DESC,s.id",[$this->tenant,$from,$to,...$args],static function($r) use ($op) {
            $out=['id'=>Util::id($r['id']),'user_id'=>Util::id($r['user_id']),'display_name'=>$r['display_name'],'day'=>$r['day']];
            if ($op==='listDualJobAlerts') { return $out+['alert_type'=>$r['alert_type'],'severity'=>$r['severity'],'evidence'=>$r['evidence']??'{}','reviewed'=>(bool)$r['reviewed'],'notes'=>$r['notes']]; }
            return $out+['device_id'=>Util::id($r['device_id']),'app_id'=>Util::id($r['app_id']),'process_name'=>$r['process_name'],'active_seconds'=>(int)$r['active_seconds'],'first_activity'=>Util::time($r['first_activity']),'last_activity'=>Util::time($r['last_activity'])];
        });
    }
    private function coverage(?string $id,?object $b): array
    {
        if ($id!==null) {
            $this->access->user($id);
            $this->db->run('INSERT INTO install_coverage_notes VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE note_text=VALUES(note_text),is_exempt=VALUES(is_exempt)',[$this->tenant,$id,$b->note_text,(int)$b->is_exempt]);
            $this->audit('install_coverage_notes',$id,$b);
        }
        [$scope,$args]=$this->access->usersSql('u');
        if ($id) { $scope.=' AND u.id=?'; $args[]=$id; }
        $sql="SELECT u.id user_id,u.display_name,COALESCE(n.note_text,'') note_text,COALESCE(n.is_exempt,FALSE) is_exempt,
            (SELECT COUNT(*) FROM devices d WHERE d.tenant_id=u.tenant_id AND d.user_id=u.id AND d.status='active') device_count,
            (SELECT COUNT(*) FROM devices d WHERE d.tenant_id=u.tenant_id AND d.user_id=u.id AND d.status='active' AND EXISTS (SELECT 1 FROM device_keys k WHERE k.tenant_id=d.tenant_id AND k.device_id=d.id AND k.revoked_at IS NULL)) enrolled_count,
            (SELECT MAX(d.last_seen_at) FROM devices d WHERE d.tenant_id=u.tenant_id AND d.user_id=u.id AND d.status='active') last_seen_at,
            (SELECT MAX(d.last_seen_at) FROM devices d WHERE d.tenant_id=u.tenant_id AND d.user_id=u.id AND d.status='active' AND EXISTS (SELECT 1 FROM device_keys k WHERE k.tenant_id=d.tenant_id AND k.device_id=d.id AND k.revoked_at IS NULL)) enrolled_last_seen_at,
            COALESCE(p.install_coverage_heartbeat_days,7) heartbeat_days
            FROM users u LEFT JOIN install_coverage_notes n ON n.tenant_id=u.tenant_id AND n.user_id=u.id LEFT JOIN panel_settings p ON p.tenant_id=u.tenant_id WHERE u.tenant_id=? AND u.status='active' AND $scope ORDER BY u.id";
        $map=static function($r) {
            $status=$r['is_exempt']?'exempt':($r['device_count']==0?'missing':($r['enrolled_count']==0?'pending':(!$r['enrolled_last_seen_at'] || strtotime($r['enrolled_last_seen_at'].' UTC')<time()-$r['heartbeat_days']*86400?'stale':'covered')));
            return ['user_id'=>Util::id($r['user_id']),'display_name'=>$r['display_name'],'device_count'=>(int)$r['device_count'],'enrolled_count'=>(int)$r['enrolled_count'],'last_seen_at'=>$r['last_seen_at']?Util::time($r['last_seen_at']):null,'status'=>$status,'note_text'=>$r['note_text'],'is_exempt'=>(bool)$r['is_exempt']];
        };
        if ($id) { $row=$this->db->one($sql,[$this->tenant,...$args]); if (!$row) { throw new ApiError(404,'resource_not_found'); } return AdminApi::response($map($row)); }
        return $this->page($sql,[$this->tenant,...$args],$map);
    }
    private function logs(): array
    {
        [$scope,$args]=$this->access->usersSql('u');
        if (isset($_GET['device_id'])) { $id=Util::bin($_GET['device_id']); $d=$this->row('devices',$id); $this->access->user($d['user_id']); $scope.=' AND l.device_id=?'; $args[]=$id; }
        if (isset($_GET['level'])) { $scope.=' AND l.level=?'; $args[]=$_GET['level']; }
        return $this->page("SELECT l.*,d.hostname FROM client_logs l JOIN devices d ON d.tenant_id=l.tenant_id AND d.id=l.device_id JOIN users u ON u.tenant_id=d.tenant_id AND u.id=d.user_id JOIN retention_settings r ON r.tenant_id=l.tenant_id WHERE l.tenant_id=? AND l.at>=UTC_TIMESTAMP()-INTERVAL r.log_days DAY AND $scope ORDER BY l.at DESC,l.device_id,l.event_id",[$this->tenant,...$args],static fn($r)=>['device_id'=>Util::id($r['device_id']),'event_id'=>Util::id($r['event_id']),'hostname'=>$r['hostname'],'at'=>Util::time($r['at']),'level'=>$r['level'],'code'=>$r['code'],'component'=>$r['component'],'error_code'=>$r['error_code']]);
    }
}
