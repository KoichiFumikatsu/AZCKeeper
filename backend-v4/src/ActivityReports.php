<?php
declare(strict_types=1);
namespace Keeper;

final class ActivityReports
{
    public function __construct(private Database $db,private array $c,private AdminAccess $access) {}
    private function range(bool $presence=false): array
    {
        if (isset($_GET['day']) && (isset($_GET['from']) || isset($_GET['to']))) { throw new ApiError(422,'invalid_range'); }
        if ($presence && !isset($_GET['from'],$_GET['to'])) {
            if (isset($_GET['from']) || isset($_GET['to'])) { throw new ApiError(422,'invalid_range'); }
            $from=$_GET['day']??(new \DateTimeImmutable('now',new \DateTimeZone($this->c['timezone'])))->format('Y-m-d');
            $to=(new \DateTimeImmutable($from))->modify('+1 day')->format('Y-m-d');
        } else { $from=$_GET['from']; $to=$_GET['to']; }
        if ($from>=$to || (new \DateTimeImmutable($from))->diff(new \DateTimeImmutable($to))->days>31) { throw new ApiError(422,'invalid_range'); }
        return [$from,$to];
    }
    private function filter(): array
    {
        [$sql,$args]=$this->access->usersSql('a','user_id');
        foreach (['area','site','user'] as $kind) {
            if (isset($_GET[$kind],$_GET[$kind.'_id']) && strtolower($_GET[$kind])!==strtolower($_GET[$kind.'_id'])) { throw new ApiError(422,'validation_failed'); }
            $value=$_GET[$kind]??$_GET[$kind.'_id']??null;
            if ($value===null) { continue; }
            $id=Util::bin($value);
            if ($kind==='user') { $this->access->user($id); }
            elseif (!$this->db->one('SELECT id FROM org_units WHERE tenant_id=? AND id=? AND kind=?',[$this->c['tenant_id'],$id,$kind])) { throw new ApiError(404,'resource_not_found'); }
            $sql.=" AND a.{$kind}_id=?"; $args[]=$id;
        }
        return [$sql,$args];
    }
    public function dispatch(string $op,?string $user): array
    {
        if (isset($_GET['tenant']) && Util::bin($_GET['tenant'])!==$this->c['tenant_id']) { throw new ApiError(404,'resource_not_found'); }
        if ($user!==null) { $this->access->user($user); }
        if ($op==='getUserPolicies') { return AdminApi::response((new PolicyCompiler($this->db))->forUser($this->c['tenant_id'],$user)); }
        [$from,$to]=$this->range($op==='getPresenceReport');
        if ($op==='getAppsReport') { return $this->apps($from,$to); }
        ActivityCalendar::create($this->db,$this->c['tenant_id'],$from,$to);
        try {
            [$scope,$args]=$this->filter();
            if ($user!==null) { $scope.=' AND a.user_id=?'; $args[]=$user; }
            $expected=ActivityCalendar::expected();
            $first="COALESCE(TIMESTAMP(c.day,f.first_activity_time)-INTERVAL c.utc_offset SECOND,d.first_activity,ed.first_activity,(SELECT MIN(ci.occurred_at) FROM check_ins ci WHERE ci.tenant_id=a.tenant_id AND ci.user_id=a.user_id AND ci.direction='in' AND ci.occurred_at>=c.starts_at AND ci.occurred_at<c.ends_at))";
            $scheduled="COALESCE(TIMESTAMP(c.day,f.scheduled_start)-INTERVAL c.utc_offset SECOND,c.work_start)";
            $last="COALESCE(d.last_activity,ed.last_activity,(SELECT MAX(ci.occurred_at) FROM check_ins ci WHERE ci.tenant_id=a.tenant_id AND ci.user_id=a.user_id AND ci.direction='out' AND ci.occurred_at>=c.starts_at AND ci.occurred_at<c.ends_at))";
            $base="FROM user_assignments a JOIN users u ON u.tenant_id=a.tenant_id AND u.id=a.user_id
                JOIN activity_calendar c ON c.schedule_id=a.schedule_id AND a.starts_at<c.ends_at AND (a.ends_at IS NULL OR a.ends_at>c.starts_at)
                JOIN retention_settings retention ON retention.tenant_id=a.tenant_id
                LEFT JOIN day_summary d ON d.tenant_id=a.tenant_id AND d.user_assignment_id=a.id AND d.user_id=a.user_id AND d.day=c.day
                LEFT JOIN focus_daily f ON f.tenant_id=a.tenant_id AND f.user_assignment_id=a.id AND f.user_id=a.user_id AND f.day=c.day
                LEFT JOIN (SELECT tenant_id,day,user_id,user_assignment_id,MIN(first_activity) first_activity,MAX(last_activity) last_activity,SUM(active_seconds) active_seconds FROM episode_daily WHERE tenant_id=? AND day>=? AND day<? GROUP BY tenant_id,day,user_id,user_assignment_id) ed ON ed.tenant_id=a.tenant_id AND ed.user_assignment_id=a.id AND ed.user_id=a.user_id AND ed.day=c.day
                WHERE a.tenant_id=? AND c.day>=DATE_SUB(UTC_DATE(),INTERVAL retention.aggregate_months MONTH) AND $scope";
            $queryArgs=[$this->c['tenant_id'],$from,$to,$this->c['tenant_id'],...$args];
            $this->db->run('DROP TEMPORARY TABLE IF EXISTS activity_report_data');
            // Materialize per-assignment calendar values before grouping: MariaDB enforces correlated GROUP BY differently.
            $this->db->run("CREATE TEMPORARY TABLE activity_report_data ENGINE=InnoDB AS SELECT c.day,a.user_id,u.display_name,
                COALESCE(d.active_seconds,ed.active_seconds,0) active_seconds,COALESCE(d.idle_seconds,0) idle_seconds,
                COALESCE(d.call_seconds,0) call_seconds,COALESCE(d.productive_seconds,0) productive_seconds,
                COALESCE(f.focus_seconds,0) focus_seconds,COALESCE(f.context_switches,0) context_switches,
                COALESCE(f.deep_work_seconds,0) deep_work_seconds,COALESCE(f.distraction_seconds,0) distraction_seconds,
                $expected expected_seconds,$first first_activity,$last last_activity,
                $scheduled scheduled_at,COALESCE(f.scheduled_start,c.start_local) scheduled_start,
                f.first_activity_time,f.productivity_pct,f.constancy_pct,COALESCE(f.deep_work_sessions,0) deep_work_sessions,
                COALESCE(f.longest_focus_streak_seconds,0) longest_focus_streak_seconds $base",$queryArgs);
            $rows=$this->db->run('SELECT day,user_id,display_name,SUM(active_seconds) active_seconds,SUM(idle_seconds) idle_seconds,
                SUM(call_seconds) call_seconds,SUM(productive_seconds) productive_seconds,SUM(focus_seconds) focus_seconds,
                SUM(context_switches) context_switches,SUM(deep_work_seconds) deep_work_seconds,SUM(distraction_seconds) distraction_seconds,
                MAX(expected_seconds) expected_seconds,MIN(first_activity) first_activity,MAX(last_activity) last_activity,
                MIN(scheduled_start) scheduled_start,MIN(first_activity_time) first_activity_time,AVG(productivity_pct) productivity_pct,AVG(constancy_pct) constancy_pct,
                SUM(deep_work_sessions) deep_work_sessions,MAX(longest_focus_streak_seconds) longest_focus_streak_seconds,
                IF(MAX(expected_seconds)=0 OR MIN(first_activity) IS NULL,NULL,TIMESTAMPDIFF(MINUTE,MIN(first_activity),MIN(IF(expected_seconds>0,scheduled_at,NULL)))) punctuality_minutes,
                IF(MAX(expected_seconds)=0 OR MIN(first_activity) IS NULL,NULL,GREATEST(0,TIMESTAMPDIFF(SECOND,MIN(IF(expected_seconds>0,scheduled_at,NULL)),MIN(first_activity)))) late_seconds
                FROM activity_report_data GROUP BY day,user_id,display_name ORDER BY day,display_name,user_id')->fetchAll();
            $data=array_map(fn($row)=>$this->day($row,$op==='getPresenceReport'),$rows);
            if ($op==='getPresenceReport') { return AdminApi::response(['from'=>$from,'to'=>$to,'data'=>$data]); }
            if ($op==='getPeopleReport') {
                // Mismas filas (dia, persona) que /users/{id}/activity, sumadas por persona en una sola consulta.
                $people=[];
                foreach ($rows as $row) {
                    $key=$row['user_id'];
                    $people[$key]??=['user_id'=>Util::id($key),'display_name'=>$row['display_name'],'last'=>null,'sums'=>[]];
                    foreach (['active_seconds','idle_seconds','call_seconds','productive_seconds','focus_seconds','context_switches','deep_work_seconds','distraction_seconds'] as $field) { $people[$key]['sums'][$field]=($people[$key]['sums'][$field]??0)+(int)$row[$field]; }
                    if ($row['last_activity']!==null && ($people[$key]['last']===null || $row['last_activity']>$people[$key]['last'])) { $people[$key]['last']=$row['last_activity']; }
                }
                usort($people,static fn($a,$b)=>strcmp($a['display_name'],$b['display_name']) ?: strcmp($a['user_id'],$b['user_id']));
                return AdminApi::response(['from'=>$from,'to'=>$to,'data'=>array_map(fn($p)=>['user_id'=>$p['user_id'],'display_name'=>$p['display_name'],'last_activity'=>$p['last']===null?null:Util::time($p['last']),'totals'=>$this->metrics($p['sums'])],array_values($people))]);
            }
            $sums=[];
            foreach (['active_seconds','idle_seconds','call_seconds','productive_seconds','focus_seconds','context_switches','deep_work_seconds','distraction_seconds'] as $field) { $sums[$field]=array_sum(array_column($rows,$field)); }
            return AdminApi::response(['user_id'=>Util::id($user),'from'=>$from,'to'=>$to,'data'=>$data,'totals'=>$this->metrics($sums)]);
        } finally { $this->db->run('DROP TEMPORARY TABLE IF EXISTS activity_report_data'); $this->db->run('DROP TEMPORARY TABLE IF EXISTS activity_calendar'); }
    }
    private function metrics(array $r): array
    {
        $out=[];
        foreach (['active_seconds','idle_seconds','call_seconds','productive_seconds','focus_seconds','context_switches','deep_work_seconds','distraction_seconds'] as $field) { $out[$field]=(int)($r[$field]??0); }
        $out['productivity_percent']=$out['active_seconds']>0?round(100*$out['productive_seconds']/$out['active_seconds'],2):null;
        $out['focus_score']=$out['active_seconds']>0?round(100*$out['focus_seconds']/$out['active_seconds'],2):null;
        return $out;
    }
    private function day(array $r,bool $presence): array
    {
        $out=['day'=>$r['day'],'first_activity'=>$r['first_activity']===null?null:Util::time($r['first_activity']),'last_activity'=>$r['last_activity']===null?null:Util::time($r['last_activity']),'late_seconds'=>$r['late_seconds']===null?null:(int)$r['late_seconds'],
            'scheduled_start'=>$r['scheduled_start'],'punctuality_minutes'=>$r['punctuality_minutes']===null?null:(int)$r['punctuality_minutes'],
            'status'=>$r['expected_seconds']==0?'no_laborable':($r['first_activity']===null?($r['active_seconds']>0?'actividad_sin_hora':'sin_actividad'):($r['late_seconds']>0?'tarde':'a_tiempo'))];
        return $presence ? $out+['user_id'=>Util::id($r['user_id']),'display_name'=>$r['display_name'],'active_seconds'=>(int)$r['active_seconds']] : $out+$this->metrics($r)+[
            'expected_seconds'=>(int)$r['expected_seconds'],'first_activity_time'=>$r['first_activity_time'],
            'productivity_pct'=>$r['productivity_pct']===null?null:round((float)$r['productivity_pct'],2),'constancy_pct'=>$r['constancy_pct']===null?null:round((float)$r['constancy_pct'],2),
            'deep_work_sessions'=>(int)$r['deep_work_sessions'],'longest_focus_streak_seconds'=>(int)$r['longest_focus_streak_seconds']];
    }
    private function apps(string $from,string $to): array
    {
        [$scope,$args]=$this->filter(); $zone=new \DateTimeZone($this->c['timezone']);
        $start=Util::sqlTime((new \DateTimeImmutable($from,$zone))->format('c')); $end=Util::sqlTime((new \DateTimeImmutable($to,$zone))->format('c'));
        $rows=$this->db->run("SELECT process_name,total_seconds,sessions,users_count,SUM(total_seconds) OVER () all_seconds
            FROM (SELECT e.process_name,SUM(FLOOR((e.active_seconds+e.idle_seconds)*TIMESTAMPDIFF(MICROSECOND,e.started_at,LEAST(e.ended_at,?))/TIMESTAMPDIFF(MICROSECOND,e.started_at,e.ended_at))-
              FLOOR((e.active_seconds+e.idle_seconds)*TIMESTAMPDIFF(MICROSECOND,e.started_at,GREATEST(e.started_at,?))/TIMESTAMPDIFF(MICROSECOND,e.started_at,e.ended_at))) total_seconds,
              COUNT(*) sessions,COUNT(DISTINCT e.user_id) users_count
            FROM episodes e JOIN user_assignments a ON a.tenant_id=e.tenant_id AND a.id=e.user_assignment_id AND a.user_id=e.user_id
              JOIN retention_settings r ON r.tenant_id=e.tenant_id
            WHERE e.tenant_id=? AND e.event_date BETWEEN DATE_SUB(DATE(?),INTERVAL 1 DAY) AND DATE(?) AND e.event_date>=UTC_DATE()-INTERVAL r.raw_days DAY
              AND e.started_at<? AND e.ended_at>? AND $scope GROUP BY e.process_name) apps
            ORDER BY total_seconds DESC,process_name LIMIT ?",[$end,$start,$this->c['tenant_id'],$start,$end,$end,$start,...$args,(int)($_GET['limit']??20)])->fetchAll();
        return AdminApi::response(['from'=>$from,'to'=>$to,'total_seconds'=>(int)($rows[0]['all_seconds']??0),'data'=>array_map(static fn($r)=>['process_name'=>$r['process_name'],'app'=>$r['process_name'],'total_seconds'=>(int)$r['total_seconds'],'sessions'=>(int)$r['sessions'],'users_count'=>(int)$r['users_count'],'percent_total'=>$r['all_seconds']>0?round(100*$r['total_seconds']/$r['all_seconds'],2):0],$rows)]);
    }
}
