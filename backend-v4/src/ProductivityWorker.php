<?php
declare(strict_types=1);
namespace Keeper;

final class ProductivityWorker
{
    public const VERSION='activity-b1-1';
    public function __construct(private Database $db) {}

    public function run(?string $tenant=null, int $maxDays=5, int $seconds=20): array
    {
        if ($maxDays<1 || $maxDays>366 || $seconds<1 || $seconds>300) { throw new \InvalidArgumentException('Invalid work budget'); }
        $start=microtime(true); $done=0;
        $tenants=array_column($this->db->run('SELECT tenant_id FROM tenants'.($tenant===null?'':' WHERE tenant_id=?'),$tenant===null?[]:[Util::bin($tenant)])->fetchAll(),'tenant_id');
        foreach ($tenants as $t) { $this->db->run("INSERT INTO productivity_cron_status (tenant_id,last_run,status) VALUES (?,UTC_TIMESTAMP(6),'running') ON DUPLICATE KEY UPDATE last_run=VALUES(last_run),status='running'",[$t]); }
        $this->db->run('SET SESSION innodb_lock_wait_timeout=2');
        try {
        // Existing/imported aggregates outside raw retention must never be replaced with empty data.
        while ($done<$maxDays && microtime(true)-$start<$seconds) {
            $row=$this->db->one('SELECT r.tenant_id,r.day FROM rollup_days r JOIN retention_settings s ON s.tenant_id=r.tenant_id WHERE r.dirty=TRUE AND r.day>=UTC_DATE()-INTERVAL s.raw_days DAY AND r.day>=DATE_SUB(UTC_DATE(),INTERVAL s.aggregate_months MONTH)'.($tenant===null?'':' AND r.tenant_id=?').' ORDER BY r.day,r.tenant_id LIMIT 1',$tenant===null?[]:[Util::bin($tenant)]);
            if (!$row) { break; }
            $lock='rollup:'.bin2hex($row['tenant_id']).':'.$row['day'];
            if (!(int)$this->db->one('SELECT GET_LOCK(?,0) acquired',[$lock])['acquired']) { break; }
            try { $this->calculate($row['tenant_id'],$row['day']); $done++; }
            finally { $this->db->run('SELECT RELEASE_LOCK(?)',[$lock]); }
        }
            foreach ($tenants as $t) { $this->db->run("UPDATE productivity_cron_status SET status='ok',last_success=UTC_TIMESTAMP(6) WHERE tenant_id=?",[$t]); }
            return ['days'=>$done,'elapsed_seconds'=>round(microtime(true)-$start,3),'calculation_version'=>self::VERSION];
        } catch (\Throwable $e) {
            foreach ($tenants as $t) { $this->db->run("UPDATE productivity_cron_status SET status='failed' WHERE tenant_id=?",[$t]); }
            throw $e;
        }
    }

    private function calculate(string $tenant,string $day): void
    {
        ActivityCalendar::create($this->db,$tenant,$day,(new \DateTimeImmutable($day))->modify('+1 day')->format('Y-m-d'));
        foreach (['activity_segments','activity_runs','activity_focus'] as $t) { $this->db->run("DROP TEMPORARY TABLE IF EXISTS $t"); }
        try {
            $this->db->transaction(function () use ($tenant,$day): void {
                $checkpoint=$this->db->one('SELECT * FROM rollup_days WHERE tenant_id=? AND day=? FOR UPDATE',[$tenant,$day]);
                if (!$checkpoint['dirty']) { return; }
                $through=$this->db->one('SELECT UTC_TIMESTAMP(6) t')['t'];
                // Broad UTC date bounds prune partitions; exact local bounds split midnight episodes.
                $this->db->run("CREATE TEMPORARY TABLE activity_segments ENGINE=InnoDB AS
                    SELECT x.*,FLOOR(active_seconds*b/duration)-FLOOR(active_seconds*a/duration) active,
                      FLOOR(idle_seconds*b/duration)-FLOOR(idle_seconds*a/duration) idle,
                      COALESCE(FLOOR(call_seconds*FLOOR(active_seconds*b/duration)/NULLIF(active_seconds,0))-FLOOR(call_seconds*FLOOR(active_seconds*a/duration)/NULLIF(active_seconds,0)),0) calls
                    FROM (SELECT e.user_id,e.user_assignment_id,e.device_id,e.event_id,e.process_name,
                      COALESCE(k.category,'unclassified') category,e.active_seconds,e.idle_seconds,e.call_seconds,
                      GREATEST(e.started_at,c.starts_at) first_at,LEAST(e.ended_at,c.ends_at) last_at,
                      TIMESTAMPDIFF(MICROSECOND,e.started_at,e.ended_at) duration,
                      TIMESTAMPDIFF(MICROSECOND,e.started_at,GREATEST(e.started_at,c.starts_at)) a,
                      TIMESTAMPDIFF(MICROSECOND,e.started_at,LEAST(e.ended_at,c.ends_at)) b
                    FROM episodes e FORCE INDEX (episode_rollup_day) JOIN user_assignments u ON u.tenant_id=e.tenant_id AND u.id=e.user_assignment_id
                    JOIN activity_calendar c ON c.schedule_id=u.schedule_id
                    LEFT JOIN app_classification k ON k.tenant_id=e.tenant_id AND k.process_name=e.process_name
                    WHERE e.tenant_id=? AND e.event_date BETWEEN DATE_SUB(?,INTERVAL 2 DAY) AND DATE_ADD(?,INTERVAL 1 DAY)
                      AND e.started_at<c.ends_at AND e.ended_at>c.starts_at) x",[$tenant,$day,$day]);
                $this->db->run("CREATE TEMPORARY TABLE activity_runs ENGINE=InnoDB AS
                    SELECT z.*,SUM(new_run) OVER (PARTITION BY user_id,user_assignment_id,device_id ORDER BY first_at,event_id ROWS UNBOUNDED PRECEDING) run_id
                    FROM (SELECT y.*,IF(previous_process IS NULL OR previous_process<>process_name OR previous_end<first_at-INTERVAL 60 SECOND OR idle>0 OR COALESCE(previous_idle,0)>0,1,0) new_run,
                    IF(previous_process IS NOT NULL AND previous_process<>process_name,1,0) switched
                    FROM (SELECT s.*,LAG(process_name) OVER w previous_process,LAG(last_at) OVER w previous_end,LAG(idle) OVER w previous_idle
                      FROM activity_segments s WINDOW w AS (PARTITION BY user_id,user_assignment_id,device_id ORDER BY first_at,event_id)) y) z");
                $this->db->run("CREATE TEMPORARY TABLE activity_focus ENGINE=InnoDB AS
                    SELECT user_id,user_assignment_id,SUM(productive) focus_seconds,SUM(switched) context_switches,
                      SUM(IF(productive>=1500 AND idle=0,productive,0)) deep_work_seconds,SUM(distraction) distraction_seconds,
                      SUM(IF(productive>=1500 AND idle=0,1,0)) deep_work_sessions,MAX(IF(idle=0,productive,0)) longest_focus_streak_seconds
                    FROM (SELECT user_id,user_assignment_id,device_id,run_id,SUM(IF(category='productive',active,0)) productive,
                      SUM(idle) idle,SUM(switched) switched,SUM(IF(category='unproductive',active,0)) distraction
                      FROM activity_runs GROUP BY user_id,user_assignment_id,device_id,run_id) r GROUP BY user_id,user_assignment_id");
                foreach (['episode_daily','focus_daily','day_summary'] as $table) { $this->db->run("DELETE FROM $table WHERE tenant_id=? AND day=? AND calculation_version NOT LIKE 'k3-import-%'",[$tenant,$day]); }
                $this->db->run('INSERT INTO episode_daily (tenant_id,day,user_id,user_assignment_id,device_id,process_name,category,active_seconds,idle_seconds,call_seconds,episode_count,calculation_version,calculated_at,data_through,first_activity,last_activity)
                    SELECT ?,?,user_id,user_assignment_id,device_id,process_name,category,SUM(active),SUM(idle),SUM(calls),COUNT(*),?,UTC_TIMESTAMP(6),?,MIN(IF(active>0,first_at,NULL)),MAX(IF(active>0,last_at,NULL)) FROM activity_segments GROUP BY user_id,user_assignment_id,device_id,process_name,category',[$tenant,$day,self::VERSION,$through]);
                $expected=ActivityCalendar::expected('u');
                // Los totales del dia salen del snapshot del agente cuando existe, y de los
                // episodios cuando no. Son dos señales distintas: el snapshot mide interaccion
                // con teclado/raton (es lo que medía keeper_activity_day de K3) y los episodios
                // miden ventanas. Un equipo encendido sin ventana en primer plano no genera
                // episodios pero si actividad, asi que tomar solo episodios perdia ese tiempo.
                // Totales y desglose se toman de la MISMA fuente para que el reparto no pueda
                // sumar mas que su total.
                $this->db->run("INSERT INTO day_summary (tenant_id,day,user_id,user_assignment_id,active_seconds,idle_seconds,call_seconds,productive_seconds,expected_seconds,coverage_percent,productivity_percent,calculation_version,timezone,calculated_at,data_through,first_activity,last_activity,late_seconds,
                      work_hours_active_seconds,work_hours_idle_seconds,lunch_active_seconds,lunch_idle_seconds,after_hours_active_seconds,after_hours_idle_seconds,sample_count,utc_offset_minutes)
                    SELECT ?,?,u.user_id,u.id,COALESCE(s.active,e.active,0),COALESCE(s.idle,e.idle,0),COALESCE(s.calls,e.calls,0),COALESCE(e.productive,0),$expected,
                    IF($expected=0,0,LEAST(100,100*COALESCE(s.active+s.idle,e.active+e.idle,0)/NULLIF($expected,0))),100*e.productive/NULLIF(COALESCE(s.active,e.active),0),?,c.timezone,UTC_TIMESTAMP(6),?,
                    COALESCE(s.first_at,e.first_at),COALESCE(s.last_at,e.last_at),
                    IF($expected=0 OR COALESCE(s.first_at,e.first_at) IS NULL,NULL,GREATEST(0,TIMESTAMPDIFF(SECOND,c.work_start,COALESCE(s.first_at,e.first_at)))),
                    s.work_active,s.work_idle,s.lunch_active,s.lunch_idle,s.after_active,s.after_idle,s.samples,s.utc_offset
                    FROM user_assignments u JOIN activity_calendar c ON c.schedule_id=u.schedule_id
                    LEFT JOIN (SELECT user_id,user_assignment_id,SUM(active) active,SUM(idle) idle,SUM(calls) calls,SUM(IF(category='productive',active,0)) productive,
                      MIN(IF(active>0,first_at,NULL)) first_at,MAX(IF(active>0,last_at,NULL)) last_at FROM activity_segments GROUP BY user_id,user_assignment_id) e ON e.user_assignment_id=u.id AND e.user_id=u.user_id
                    LEFT JOIN (SELECT user_id,SUM(active_seconds) active,SUM(idle_seconds) idle,SUM(call_seconds) calls,
                      MIN(first_activity_at) first_at,MAX(last_activity_at) last_at,SUM(sample_count) samples,MIN(utc_offset_minutes) utc_offset,
                      -- Si algun dispositivo no reporta el desglose, el reparto del dia queda
                      -- DESCONOCIDO en vez de una suma parcial que aparentaria estar completa.
                      IF(COUNT(*)=COUNT(work_hours_active_seconds),SUM(work_hours_active_seconds),NULL) work_active,
                      IF(COUNT(*)=COUNT(work_hours_idle_seconds),SUM(work_hours_idle_seconds),NULL) work_idle,
                      IF(COUNT(*)=COUNT(lunch_active_seconds),SUM(lunch_active_seconds),NULL) lunch_active,
                      IF(COUNT(*)=COUNT(lunch_idle_seconds),SUM(lunch_idle_seconds),NULL) lunch_idle,
                      IF(COUNT(*)=COUNT(after_hours_active_seconds),SUM(after_hours_active_seconds),NULL) after_active,
                      IF(COUNT(*)=COUNT(after_hours_idle_seconds),SUM(after_hours_idle_seconds),NULL) after_idle
                      FROM activity_snapshots WHERE tenant_id=? AND day=? GROUP BY user_id) s ON s.user_id=u.user_id
                    WHERE u.tenant_id=? AND u.starts_at<c.ends_at AND (u.ends_at IS NULL OR u.ends_at>c.starts_at)
                      AND NOT EXISTS (SELECT 1 FROM day_summary old WHERE old.tenant_id=u.tenant_id AND old.day=c.day AND old.user_assignment_id=u.id)",[$tenant,$day,self::VERSION,$through,$tenant,$day,$tenant]);
                $this->db->run('INSERT INTO focus_daily (tenant_id,day,user_id,user_assignment_id,focus_seconds,focus_score,context_switches,deep_work_seconds,distraction_seconds,calculation_version,calculated_at,data_through,first_activity_time,scheduled_start,punctuality_minutes,productivity_pct,constancy_pct,deep_work_sessions,longest_focus_streak_seconds)
                    SELECT d.tenant_id,d.day,d.user_id,d.user_assignment_id,COALESCE(f.focus_seconds,0),100*f.focus_seconds/NULLIF(d.active_seconds,0),COALESCE(f.context_switches,0),COALESCE(f.deep_work_seconds,0),COALESCE(f.distraction_seconds,0),?,UTC_TIMESTAMP(6),?,
                      TIME(d.first_activity+INTERVAL c.utc_offset SECOND),c.start_local,IF(d.expected_seconds=0,NULL,TIMESTAMPDIFF(MINUTE,d.first_activity,c.work_start)),
                      100*d.productive_seconds/NULLIF(d.active_seconds+d.idle_seconds,0),NULL,COALESCE(f.deep_work_sessions,0),COALESCE(f.longest_focus_streak_seconds,0)
                    FROM day_summary d JOIN user_assignments u ON u.tenant_id=d.tenant_id AND u.id=d.user_assignment_id JOIN activity_calendar c ON c.schedule_id=u.schedule_id
                    LEFT JOIN activity_focus f ON f.user_id=d.user_id AND f.user_assignment_id=d.user_assignment_id WHERE d.tenant_id=? AND d.day=?
                    AND NOT EXISTS (SELECT 1 FROM focus_daily old WHERE old.tenant_id=d.tenant_id AND old.day=d.day AND old.user_assignment_id=d.user_assignment_id)',[self::VERSION,$through,$tenant,$day]);
                $this->constancy($tenant,$day);
                (new ComplianceWorker($this->db))->calculate($tenant,$day);
                $this->db->run('UPDATE rollup_days r JOIN retention_settings s ON s.tenant_id=r.tenant_id SET r.dirty=FALSE,r.calculated_revision=r.input_revision,r.calculation_version=?,r.calculated_at=UTC_TIMESTAMP(6),r.data_through=?,r.finalized_at=IF(r.day<UTC_DATE()-INTERVAL (s.late_arrival_days+2) DAY,UTC_TIMESTAMP(6),NULL) WHERE r.tenant_id=? AND r.day=?',[self::VERSION,$through,$tenant,$day]);
            });
        } finally {
            foreach (['activity_segments','activity_runs','activity_focus','activity_calendar'] as $t) { $this->db->run("DROP TEMPORARY TABLE IF EXISTS $t"); }
        }
    }

    private function constancy(string $tenant,string $day): void
    {
        $occupied=[];
        $rows=$this->db->run('SELECT s.user_assignment_id,s.first_at,s.last_at,c.work_start,c.expected_seconds FROM activity_segments s JOIN user_assignments u ON u.tenant_id=? AND u.id=s.user_assignment_id JOIN activity_calendar c ON c.schedule_id=u.schedule_id WHERE s.active>0',[$tenant]);
        foreach ($rows as $r) {
            $start=strtotime($r['work_start'].' UTC'); $end=$start+(int)$r['expected_seconds'];
            $a=max($start,strtotime($r['first_at'].' UTC')); $b=min($end,strtotime($r['last_at'].' UTC'));
            for ($block=(int)floor(($a-$start)/1800);$a<$b && $start+$block*1800<$b;$block++) { $occupied[bin2hex($r['user_assignment_id'])][$block]=true; }
        }
        foreach ($this->db->run('SELECT user_assignment_id,expected_seconds FROM day_summary WHERE tenant_id=? AND day=?',[$tenant,$day]) as $r) {
            $pct=$r['expected_seconds']>0?round(100*count($occupied[bin2hex($r['user_assignment_id'])]??[])/ceil($r['expected_seconds']/1800),2):null;
            $this->db->run("UPDATE focus_daily SET constancy_pct=? WHERE tenant_id=? AND day=? AND user_assignment_id=? AND calculation_version NOT LIKE 'k3-import-%'",[$pct,$tenant,$day,$r['user_assignment_id']]);
        }
    }
}
