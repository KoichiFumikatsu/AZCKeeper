<?php
declare(strict_types=1);
namespace Keeper;

final class ComplianceWorker
{
    public const SIGNALS=[
        'after_hours_pattern'=>'Actividad fuera del horario durante al menos cinco días con una hora diaria en siete días.',
        'foreign_app'=>'Actividad en una aplicación del catálogo VPN, espacio externo o máquina virtual.',
        'remote_desktop'=>'Actividad en una aplicación del catálogo de escritorio remoto.',
        'suspicious_idle'=>'Al menos dos horas registradas, con inactividad igual o superior al 80%.',
    ];
    public function __construct(private Database $db) {}
    public static function id(string $key): string
    {
        $id=substr(hash('sha256',$key,true),0,16); $id[6]=chr((ord($id[6])&15)|80); $id[8]=chr((ord($id[8])&63)|128); return $id;
    }
    public function calculate(string $tenant,string $day): void
    {
        $this->db->run('DELETE FROM suspicious_app_detections WHERE tenant_id=? AND day=?',[$tenant,$day]);
        $this->db->run("DELETE FROM dual_job_alerts WHERE tenant_id=? AND day=? AND source='worker' AND reviewed=FALSE",[$tenant,$day]);
        // Keep episodes first: MariaDB otherwise repeats a tenant scan for every assignment.
        $rows=$this->db->run("SELECT e.user_id,e.user_assignment_id,e.device_id,k.id app_id,k.category,e.process_name,
            MIN(GREATEST(e.started_at,c.starts_at)) first_activity,MAX(LEAST(e.ended_at,c.ends_at)) last_activity,
            SUM(FLOOR(e.active_seconds*TIMESTAMPDIFF(MICROSECOND,e.started_at,LEAST(e.ended_at,c.ends_at))/TIMESTAMPDIFF(MICROSECOND,e.started_at,e.ended_at))-
                FLOOR(e.active_seconds*TIMESTAMPDIFF(MICROSECOND,e.started_at,GREATEST(e.started_at,c.starts_at))/TIMESTAMPDIFF(MICROSECOND,e.started_at,e.ended_at))) active_seconds
            FROM episodes e FORCE INDEX (episode_rollup_day) STRAIGHT_JOIN user_assignments a ON a.tenant_id=e.tenant_id AND a.id=e.user_assignment_id
            STRAIGHT_JOIN activity_calendar c ON c.schedule_id=a.schedule_id
            JOIN suspicious_apps k ON k.tenant_id=e.tenant_id AND k.active=TRUE AND
              (LOCATE(LOWER(k.app_pattern),LOWER(e.process_name))>0 OR LOCATE(LOWER(k.app_pattern),LOWER(COALESCE(e.window_title,'')))>0)
            WHERE e.tenant_id=? AND e.event_date BETWEEN DATE_SUB(?,INTERVAL 2 DAY) AND DATE_ADD(?,INTERVAL 1 DAY)
              AND e.started_at<c.ends_at AND e.ended_at>c.starts_at AND e.active_seconds>0
            GROUP BY e.user_id,e.user_assignment_id,e.device_id,k.id,k.category,e.process_name",[$tenant,$day,$day])->fetchAll();
        $signals=[];
        foreach ($rows as $r) {
            if ((int)$r['active_seconds']===0) { continue; }
            $id=self::id($tenant.$day.$r['user_assignment_id'].$r['device_id'].$r['app_id'].$r['process_name']);
            $this->db->run('INSERT INTO suspicious_app_detections VALUES (?,?,?,?,?,?,?,?,?,?,?)',[$tenant,$id,$r['user_id'],$r['user_assignment_id'],$r['device_id'],$r['app_id'],$day,$r['process_name'],$r['active_seconds'],$r['first_activity'],$r['last_activity']]);
            $type=$r['category']==='remote_desktop'?'remote_desktop':'foreign_app';
            $signals[bin2hex($r['user_id']).':'.$type]['user']=$r['user_id'];
            $signals[bin2hex($r['user_id']).':'.$type]['type']=$type;
            $signals[bin2hex($r['user_id']).':'.$type]['apps'][]=['app_id'=>Util::id($r['app_id']),'process_name'=>$r['process_name'],'active_seconds'=>(int)$r['active_seconds']];
        }
        foreach ($signals as $s) { $this->alert($tenant,$day,$s['user'],$s['type'],'medium',['apps'=>$s['apps']]); }
        foreach ($this->db->run('SELECT user_id,SUM(active_seconds) active,SUM(idle_seconds) idle FROM day_summary WHERE tenant_id=? AND day=? GROUP BY user_id HAVING SUM(active_seconds+idle_seconds)>=7200 AND SUM(idle_seconds)>=0.8*SUM(active_seconds+idle_seconds)',[$tenant,$day]) as $r) {
            $this->alert($tenant,$day,$r['user_id'],'suspicious_idle','low',['active_seconds'=>(int)$r['active'],'idle_seconds'=>(int)$r['idle']]);
        }
        $from=(new \DateTimeImmutable($day))->modify('-6 days')->format('Y-m-d');
        ActivityCalendar::create($this->db,$tenant,$from,(new \DateTimeImmutable($day))->modify('+1 day')->format('Y-m-d'));
        $expected=ActivityCalendar::expected('a');
        $rows=$this->db->run("SELECT user_id,COUNT(*) days_count,SUM(seconds) seconds FROM (
            SELECT e.user_id,c.day,SUM(e.active_seconds*(
              GREATEST(0,TIMESTAMPDIFF(MICROSECOND,GREATEST(e.started_at,c.starts_at),LEAST(e.ended_at,c.work_start)))+
              GREATEST(0,TIMESTAMPDIFF(MICROSECOND,GREATEST(e.started_at,c.work_start+INTERVAL c.expected_seconds SECOND),LEAST(e.ended_at,c.ends_at))))/
              TIMESTAMPDIFF(MICROSECOND,e.started_at,e.ended_at)) seconds
            FROM episodes e FORCE INDEX (episode_rollup_day) STRAIGHT_JOIN user_assignments a ON a.tenant_id=e.tenant_id AND a.id=e.user_assignment_id
            STRAIGHT_JOIN activity_calendar c ON c.schedule_id=a.schedule_id
            WHERE e.tenant_id=? AND e.event_date BETWEEN DATE_SUB(?,INTERVAL 2 DAY) AND DATE_ADD(?,INTERVAL 1 DAY)
              AND e.started_at<c.ends_at AND e.ended_at>c.starts_at AND c.expected_seconds>0
              AND (e.started_at<c.work_start OR e.ended_at>c.work_start+INTERVAL c.expected_seconds SECOND) AND $expected>0
            GROUP BY e.user_id,c.day HAVING seconds>=3600) daily GROUP BY user_id HAVING COUNT(*)>=5",[$tenant,$from,$day])->fetchAll();
        foreach ($rows as $r) { $this->alert($tenant,$day,$r['user_id'],'after_hours_pattern','medium',['days'=>(int)$r['days_count'],'active_seconds'=>(int)$r['seconds'],'from'=>$from,'to'=>$day]); }
    }
    private function alert(string $tenant,string $day,string $user,string $type,string $severity,array $evidence): void
    {
        $this->db->run("INSERT INTO dual_job_alerts (tenant_id,id,user_id,day,alert_type,severity,evidence,source) VALUES (?,?,?,?,?,?,?,'worker') ON DUPLICATE KEY UPDATE evidence=IF(reviewed,evidence,VALUES(evidence))",[$tenant,self::id($tenant.$day.$user.$type),$user,$day,$type,$severity,Util::json($evidence)]);
    }
}
