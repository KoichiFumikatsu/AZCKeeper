<?php
declare(strict_types=1);
namespace Keeper;

final class PanelActivity
{
    public function __construct(private Database $db,private array $c,private AdminAccess $access) {}
    public function dispatch(string $op,?string $user): array
    {
        if ($op==='getDashboardProblems') { return $this->problems(); }
        $this->access->user($user);
        $zone=new \DateTimeZone($this->c['timezone']);
        $from=$op==='getUserTimeline'?$_GET['day']:$_GET['from'];
        $to=$op==='getUserTimeline'?(new \DateTimeImmutable($from))->modify('+1 day')->format('Y-m-d'):$_GET['to'];
        if ($from>=$to || (new \DateTimeImmutable($from))->diff(new \DateTimeImmutable($to))->days>31) { throw new ApiError(422,'invalid_range'); }
        $start=Util::sqlTime((new \DateTimeImmutable($from,$zone))->format('c')); $end=Util::sqlTime((new \DateTimeImmutable($to,$zone))->format('c'));
        $base='FROM episodes e JOIN retention_settings r ON r.tenant_id=e.tenant_id WHERE e.tenant_id=? AND e.user_id=? AND e.event_date BETWEEN DATE(?)-INTERVAL 1 DAY AND DATE(?) AND e.event_date>=UTC_DATE()-INTERVAL r.raw_days DAY AND e.started_at<? AND e.ended_at>?';
        $args=[$this->c['tenant_id'],$user,$start,$end,$end,$start];
        if ($op==='getUserWindows') {
            $part=static fn($col)=>"SUM(FLOOR(e.$col*TIMESTAMPDIFF(MICROSECOND,e.started_at,LEAST(e.ended_at,?))/TIMESTAMPDIFF(MICROSECOND,e.started_at,e.ended_at))-FLOOR(e.$col*TIMESTAMPDIFF(MICROSECOND,e.started_at,GREATEST(e.started_at,?))/TIMESTAMPDIFF(MICROSECOND,e.started_at,e.ended_at)))";
            $rows=$this->db->run("SELECT e.process_name,e.site,{$part('active_seconds')} active_seconds,{$part('idle_seconds')} idle_seconds,COUNT(*) sessions $base GROUP BY e.process_name,e.site ORDER BY active_seconds DESC,e.process_name,e.site",[$end,$start,$end,$start,...$args])->fetchAll();
            foreach ($rows as &$row) { foreach (['active_seconds','idle_seconds','sessions'] as $f) { $row[$f]=(int)$row[$f]; } } unset($row);
            return AdminApi::response(['user_id'=>Util::id($user),'from'=>$from,'to'=>$to,'data'=>$rows]);
        }
        $limit=(int)($_GET['limit']??200); $offset=(int)($_GET['offset']??0);
        $rows=$this->db->run("SELECT e.process_name,e.site,GREATEST(e.started_at,?) started_at,LEAST(e.ended_at,?) ended_at $base ORDER BY e.started_at,e.device_id,e.event_id LIMIT ? OFFSET ?",[$start,$end,...$args,$limit+1,$offset])->fetchAll();
        $more=count($rows)>$limit; if ($more) { array_pop($rows); }
        foreach ($rows as &$row) { $row['started_at']=Util::time($row['started_at']); $row['ended_at']=Util::time($row['ended_at']); } unset($row);
        return AdminApi::response(['user_id'=>Util::id($user),'day'=>$from,'data'=>$rows,'next_offset'=>$more?$offset+$limit:null]);
    }
    private function scope(string $permission): array
    {
        [$base,$args]=$this->access->usersSql('u');
        try { [$extra,$more]=(new AdminAccess($this->db,$this->c,$permission))->usersSql('u'); return ["($base) AND ($extra)",[...$args,...$more]]; }
        catch (ApiError $e) { if ($e->status!==403) { throw $e; } return ['FALSE',[]]; }
    }
    private function problems(): array
    {
        $tenant=$this->c['tenant_id']; $out=[];
        $add=static function(string $kind,string $label,array $r) use (&$out): void {
            $out[]=['kind'=>$kind,'label'=>$label,'user_id'=>isset($r['user_id'])?Util::id($r['user_id']):null,'display_name'=>$r['display_name']??null,'device_id'=>isset($r['device_id'])?Util::id($r['device_id']):null,'hostname'=>$r['hostname']??null];
        };
        [$scope,$args]=$this->scope('equipos.ver');
        $devices=$this->db->run("SELECT d.id device_id,d.user_id,d.hostname,d.last_seen_at,d.specs,u.display_name,u.schedule_id,
            (SELECT controls FROM security_reports s WHERE s.tenant_id=d.tenant_id AND s.device_id=d.id ORDER BY observed_at DESC,event_id DESC LIMIT 1) security_controls,
            (SELECT code FROM client_logs l WHERE l.tenant_id=d.tenant_id AND l.device_id=d.id AND component='AgentDiagnostics' AND code IN ('degraded','ready') ORDER BY at DESC,event_id DESC LIMIT 1) health,
            EXISTS(SELECT 1 FROM client_logs l WHERE l.tenant_id=d.tenant_id AND l.device_id=d.id AND code='simulated_input' AND at>=UTC_TIMESTAMP()-INTERVAL 1 DAY) simulated
            FROM devices d JOIN users u ON u.tenant_id=d.tenant_id AND u.id=d.user_id WHERE d.tenant_id=? AND d.status='active' AND u.status='active' AND $scope",[$tenant,...$args])->fetchAll();
        $day=(new \DateTimeImmutable('today',new \DateTimeZone($this->c['timezone'])))->format('Y-m-d');
        ActivityCalendar::create($this->db,$tenant,(new \DateTimeImmutable($day))->modify('-1 day')->format('Y-m-d'),(new \DateTimeImmutable($day))->modify('+1 day')->format('Y-m-d'));
        try {
            $expected=ActivityCalendar::expected();
            $working=array_column($this->db->run("SELECT DISTINCT a.user_id FROM user_assignments a JOIN activity_calendar c ON c.schedule_id=COALESCE(a.schedule_id,(SELECT default_schedule_id FROM tenants WHERE tenant_id=a.tenant_id)) WHERE a.tenant_id=? AND a.starts_at<=UTC_TIMESTAMP() AND (a.ends_at IS NULL OR a.ends_at>UTC_TIMESTAMP()) AND $expected>0 AND UTC_TIMESTAMP()>=c.work_start AND UTC_TIMESTAMP()<c.work_start+INTERVAL c.expected_seconds SECOND",[$tenant])->fetchAll(),'user_id');
            foreach ($devices as $d) {
                if (($d['last_seen_at']===null || strtotime($d['last_seen_at'].' UTC')<time()-900) && in_array($d['user_id'],$working,true)) { $add('disconnected','Sin conexión en horario laboral',$d); }
                $specs=json_decode($d['specs']??'{}',true);
                if (($specs['legacy_k3']['running']??false)===true) { $add('legacy_k3','K3 abierto',$d); }
                foreach (json_decode($d['security_controls']??'[]',true) as $control) { if (($control['state']??null)==='failed') { $add('control_failed','Control fallido: '.$control['control_id'],$d); } }
                if ($d['health']==='degraded') { $add('agent_degraded','Agente degradado',$d); }
                if ($d['simulated']) { $add('simulated_input','Entrada simulada en las últimas 24 horas',$d); }
            }
        } finally { $this->db->run('DROP TEMPORARY TABLE IF EXISTS activity_calendar'); }
        [$scope,$args]=$this->scope('cumplimiento.ver');
        foreach ($this->db->run("SELECT a.user_id,u.display_name,a.alert_type,COUNT(*) n FROM dual_job_alerts a JOIN users u ON u.tenant_id=a.tenant_id AND u.id=a.user_id WHERE a.tenant_id=? AND a.reviewed=FALSE AND $scope GROUP BY a.user_id,u.display_name,a.alert_type",[$tenant,...$args]) as $r) {
            $add('compliance',($r['alert_type']==='foreign_app'?'Apps sospechosas':'Doble empleo').' · '.$r['n'].' sin revisar',$r);
        }
        if ($this->c['platform'] && $this->db->one("SELECT 1 FROM productivity_cron_status WHERE tenant_id=? AND status='failed'",[$tenant])) { $add('calculation_failed','Falló el cálculo de productividad',[]); }
        return AdminApi::response(['generated_at'=>Util::now(),'data'=>$out]);
    }
}
