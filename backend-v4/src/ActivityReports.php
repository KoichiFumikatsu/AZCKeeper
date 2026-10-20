<?php
declare(strict_types=1);
namespace Keeper;

final class ActivityReports
{
    /** Tiempos que un periodo suma; las formulas dividen sumas, nunca promedian porcentajes diarios. */
    private const SUM_FIELDS=['active_seconds','idle_seconds','call_seconds','productive_seconds','focus_seconds','context_switches','deep_work_seconds','distraction_seconds','window_active_seconds','unclassified_seconds',...ProductivityFormula::FIELDS];
    private const PERIOD_FIELDS=['work_hours_active_seconds','work_hours_idle_seconds','lunch_active_seconds','lunch_idle_seconds','after_hours_active_seconds','after_hours_idle_seconds','work_hours_locked_seconds','lunch_locked_seconds','after_hours_locked_seconds'];
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
            if ($kind!=='user') { $this->access->requireFilter($kind,$id); }
            $sql.=" AND a.{$kind}_id=?"; $args[]=$id;
        }
        // Empresa del grupo y pais de la sede (sin filtro = todo el grupo).
        if (isset($_GET['company_id'])) {
            $id=Util::bin($_GET['company_id']);
            if (!$this->db->one("SELECT id FROM org_units WHERE tenant_id=? AND id=? AND kind='company'",[$this->c['tenant_id'],$id])) { throw new ApiError(404,'resource_not_found'); }
            $this->access->requireFilter('company',$id);
            $sql.=' AND a.company_id=?'; $args[]=$id;
        }
        if (isset($_GET['country'])) { $sql.=' AND EXISTS (SELECT 1 FROM org_units cs WHERE cs.tenant_id=a.tenant_id AND cs.id=a.site_id AND cs.country_code=?)'; $args[]=$_GET['country']; }
        if (isset($_GET['city'])) { $this->access->requireFilter('city',trim($_GET['city'])); $sql.=' AND EXISTS (SELECT 1 FROM org_units cy WHERE cy.tenant_id=a.tenant_id AND cy.id=a.site_id AND cy.city=?)'; $args[]=trim($_GET['city']); }
        return [$sql,$args];
    }
    public function dispatch(string $op,?string $user): array
    {
        if (isset($_GET['tenant']) && Util::bin($_GET['tenant'])!==$this->c['tenant_id']) { throw new ApiError(404,'resource_not_found'); }
        if ($user!==null) { $this->access->user($user); }
        if ($op==='getUserPolicies') { return AdminApi::response((new PolicyCompiler($this->db))->forUser($this->c['tenant_id'],$user)); }
        [$from,$to]=$this->range(in_array($op,['getPresenceReport','getAdminDashboard'],true));
        if ($op==='getAppsReport') { return $this->apps($from,$to); }
        ActivityCalendar::create($this->db,$this->c['tenant_id'],$from,$to);
        try {
            [$scope,$args]=$this->filter();
            $today=(new \DateTimeImmutable('today',new \DateTimeZone($this->c['timezone'])))->format('Y-m-d');
            if ($op==='getPresenceReport' && $from===$today && $to===(new \DateTimeImmutable($today))->modify('+1 day')->format('Y-m-d')) { $scope.=" AND u.status='active'"; }
            if ($user!==null) { $scope.=' AND a.user_id=?'; $args[]=$user; }
            $expected=ActivityCalendar::expected();
            $first="COALESCE(TIMESTAMP(c.day,f.first_activity_time)-INTERVAL c.utc_offset SECOND,d.first_activity,ed.first_activity,(SELECT MIN(ci.occurred_at) FROM check_ins ci WHERE ci.tenant_id=a.tenant_id AND ci.user_id=a.user_id AND ci.direction='in' AND ci.occurred_at>=c.starts_at AND ci.occurred_at<c.ends_at))";
            $scheduledLocal=ActivityCalendar::scheduledStart();
            $scheduled="TIMESTAMP(c.day,$scheduledLocal)-INTERVAL c.utc_offset SECOND";
            $last="COALESCE(d.last_activity,ed.last_activity,(SELECT MAX(ci.occurred_at) FROM check_ins ci WHERE ci.tenant_id=a.tenant_id AND ci.user_id=a.user_id AND ci.direction='out' AND ci.occurred_at>=c.starts_at AND ci.occurred_at<c.ends_at))";
            $base="FROM user_assignments a JOIN users u ON u.tenant_id=a.tenant_id AND u.id=a.user_id
                JOIN activity_calendar c ON c.schedule_id=COALESCE(a.schedule_id,(SELECT default_schedule_id FROM tenants WHERE tenant_id=a.tenant_id)) AND a.starts_at<c.ends_at AND (a.ends_at IS NULL OR a.ends_at>c.starts_at)
                JOIN retention_settings retention ON retention.tenant_id=a.tenant_id
                LEFT JOIN day_summary d ON d.tenant_id=a.tenant_id AND d.user_assignment_id=a.id AND d.user_id=a.user_id AND d.day=c.day
                LEFT JOIN focus_daily f ON f.tenant_id=a.tenant_id AND f.user_assignment_id=a.id AND f.user_id=a.user_id AND f.day=c.day
                LEFT JOIN (SELECT tenant_id,day,user_id,user_assignment_id,MIN(first_activity) first_activity,MAX(last_activity) last_activity,SUM(active_seconds) active_seconds FROM episode_daily WHERE tenant_id=? AND day>=? AND day<? GROUP BY tenant_id,day,user_id,user_assignment_id) ed ON ed.tenant_id=a.tenant_id AND ed.user_assignment_id=a.id AND ed.user_id=a.user_id AND ed.day=c.day
                WHERE a.tenant_id=? AND c.day>=DATE_SUB(UTC_DATE(),INTERVAL retention.aggregate_months MONTH) AND $scope";
            $queryArgs=[$this->c['tenant_id'],$from,$to,$this->c['tenant_id'],...$args];
            $this->db->run('DROP TEMPORARY TABLE IF EXISTS activity_report_data');
            // Materialize per-assignment calendar values before grouping: MariaDB enforces correlated GROUP BY differently.
            $periodSelect=implode(',',array_map(static fn($field)=>"d.$field",self::PERIOD_FIELDS));
            $periodSums=implode(',',array_map(static fn($field)=>"IF(COUNT($field)=COUNT(*),SUM($field),NULL) $field",self::PERIOD_FIELDS));
            $this->db->run("CREATE TEMPORARY TABLE activity_report_data ENGINE=InnoDB AS SELECT c.day,a.user_id,u.display_name,$periodSelect,
                COALESCE(d.active_seconds,ed.active_seconds,0) active_seconds,COALESCE(d.idle_seconds,0) idle_seconds,
                COALESCE(d.call_seconds,0) call_seconds,COALESCE(d.productive_seconds,0) productive_seconds,d.simulated_seconds,d.locked_seconds,
                COALESCE(d.window_active_seconds,d.active_seconds,ed.active_seconds,0) window_active_seconds,COALESCE(d.unclassified_seconds,0) unclassified_seconds,
                COALESCE(f.focus_seconds,0) focus_seconds,COALESCE(f.context_switches,0) context_switches,
                COALESCE(f.deep_work_seconds,0) deep_work_seconds,COALESCE(f.distraction_seconds,0) distraction_seconds,
                COALESCE(d.unproductive_seconds,f.distraction_seconds,0) unproductive_seconds,".ActivityCalendar::workday()." workday_seconds,
                $expected expected_seconds,$first first_activity,$last last_activity,
                COALESCE((SELECT m.minutes FROM punctuality_margins m WHERE m.tenant_id=a.tenant_id AND m.from_day<=c.day ORDER BY m.from_day DESC LIMIT 1),IF(EXISTS(SELECT 1 FROM punctuality_margins m WHERE m.tenant_id=a.tenant_id),-1,15)) margin_minutes,
                $scheduled scheduled_at,$scheduledLocal scheduled_start,
                f.first_activity_time,f.productivity_pct,f.constancy_pct,COALESCE(f.deep_work_sessions,0) deep_work_sessions,
                COALESCE(f.longest_focus_streak_seconds,0) longest_focus_streak_seconds $base",$queryArgs);
            $rows=$this->db->run('SELECT '.$periodSums.',day,user_id,display_name,SUM(active_seconds) active_seconds,SUM(idle_seconds) idle_seconds,
                SUM(call_seconds) call_seconds,SUM(productive_seconds) productive_seconds,SUM(simulated_seconds) simulated_seconds,SUM(locked_seconds) locked_seconds,SUM(focus_seconds) focus_seconds,
                SUM(window_active_seconds) window_active_seconds,SUM(unclassified_seconds) unclassified_seconds,
                SUM(context_switches) context_switches,SUM(deep_work_seconds) deep_work_seconds,SUM(distraction_seconds) distraction_seconds,
                SUM(unproductive_seconds) unproductive_seconds,MAX(workday_seconds) workday_seconds,MAX(expected_seconds) expected_seconds,MIN(first_activity) first_activity,MAX(last_activity) last_activity,MAX(margin_minutes) margin_minutes,
                MIN(scheduled_start) scheduled_start,MIN(IF(expected_seconds>0,scheduled_at,NULL)) scheduled_at_utc,MIN(first_activity_time) first_activity_time,AVG(productivity_pct) productivity_pct,AVG(constancy_pct) constancy_pct,
                SUM(deep_work_sessions) deep_work_sessions,MAX(longest_focus_streak_seconds) longest_focus_streak_seconds,
                IF(MAX(expected_seconds)=0 OR MIN(first_activity) IS NULL,NULL,TIMESTAMPDIFF(MINUTE,MIN(first_activity),MIN(IF(expected_seconds>0,scheduled_at,NULL)))) punctuality_minutes,
                TIMESTAMPDIFF(SECOND,MIN(first_activity),MIN(IF(expected_seconds>0,scheduled_at,NULL))) early_seconds,
                IF(MAX(expected_seconds)=0 OR MIN(first_activity) IS NULL,NULL,GREATEST(0,TIMESTAMPDIFF(SECOND,MIN(IF(expected_seconds>0,scheduled_at,NULL)),MIN(first_activity)))) late_seconds
                FROM activity_report_data GROUP BY day,user_id,display_name ORDER BY day,display_name,user_id')->fetchAll();
            // Jornada de cada (dia, persona): J, P y N de las formulas (ProductivityFormula); un periodo las suma.
            $rows=array_map(static fn($r)=>$r+ProductivityFormula::day((int)$r['workday_seconds'],(int)$r['productive_seconds'],(int)$r['window_active_seconds'],(int)$r['unproductive_seconds']),$rows);
            $data=array_map(fn($row)=>$this->day($row,$op==='getPresenceReport'),$rows);
            if ($op==='getAdminDashboard') { return $this->dashboard($rows,$from,$to); }
            if ($op==='getPresenceReport') { return AdminApi::response(['from'=>$from,'to'=>$to,'data'=>$data]); }
            if ($op==='getPeopleReport') {
                // Mismas filas (dia, persona) que /users/{id}/activity, sumadas por persona en una sola consulta.
                $people=[];
                foreach ($rows as $row) {
                    $key=$row['user_id'];
                    $people[$key]??=['user_id'=>Util::id($key),'display_name'=>$row['display_name'],'last'=>null,'sums'=>[]];
                    foreach (self::SUM_FIELDS as $field) { $people[$key]['sums'][$field]=($people[$key]['sums'][$field]??0)+(int)$row[$field]; }
                    $people[$key]['rows'][]=$row;
                    if ($row['last_activity']!==null && ($people[$key]['last']===null || $row['last_activity']>$people[$key]['last'])) { $people[$key]['last']=$row['last_activity']; }
                }
                usort($people,static fn($a,$b)=>strcmp($a['display_name'],$b['display_name']) ?: strcmp($a['user_id'],$b['user_id']));
                return AdminApi::response(['from'=>$from,'to'=>$to,'data'=>array_map(fn($p)=>['user_id'=>$p['user_id'],'display_name'=>$p['display_name'],'last_activity'=>$p['last']===null?null:Util::time($p['last']),'totals'=>$this->metrics($p['sums'])+self::measured($p['rows'])],array_values($people))]);
            }
            $sums=[];
            foreach (self::SUM_FIELDS as $field) { $sums[$field]=array_sum(array_column($rows,$field)); }
            return AdminApi::response(['user_id'=>Util::id($user),'from'=>$from,'to'=>$to,'data'=>$data,'totals'=>$this->metrics($sums)+self::measured($rows)]);
        } finally { $this->db->run('DROP TEMPORARY TABLE IF EXISTS activity_report_data'); $this->db->run('DROP TEMPORARY TABLE IF EXISTS activity_calendar'); }
    }
    /**
     * Resumen de hoy (dia de la empresa) en el alcance del acceso: exactamente /reports/presence sin filtros, la misma regla
     * y el mismo margen de puntualidad (ScheduleSettings). late = estado «tarde»; not_connected = «sin actividad» (jornada
     * hoy, sin festivo ni ausencia) cuya hora limite de llegada (entrada + margen) ya paso. Lo usa PanelNotifications.
     */
    public function todaySummary(): array
    {
        $saved=$_GET; $_GET=[];
        try { $report=json_decode($this->dispatch('getPresenceReport',null)['json'],true,64,JSON_THROW_ON_ERROR); }
        finally { $_GET=$saved; }
        $now=time();
        return ['late'=>count(array_filter($report['data'],static fn($row)=>$row['status']==='tarde')),
            'not_connected'=>count(array_filter($report['data'],static fn($row)=>self::notConnected($row,$now)))];
    }
    /** Sin conectarse: sin actividad en un dia con jornada y ya paso la hora limite de llegada. */
    public static function notConnected(array $row,int $now): bool
    {
        return $row['status']==='sin_actividad' && $row['arrival_deadline_at']!==null && strtotime($row['arrival_deadline_at'])<=$now;
    }
    private function dashboard(array $rows,string $from,string $to): array
    {
        [$scope,$args]=$this->filter();
        $members=$this->db->run("SELECT a.id,a.display_name,a.area_id,a.site_id,
            (SELECT MAX(d.last_seen_at) FROM devices d WHERE d.tenant_id=a.tenant_id AND d.user_id=a.id AND d.status='active') last_seen_at,
            (SELECT GROUP_CONCAT(d.hostname ORDER BY d.hostname SEPARATOR ', ') FROM devices d WHERE d.tenant_id=a.tenant_id AND d.user_id=a.id AND d.status='active') devices
            FROM (SELECT u.*,u.id user_id FROM users u) a WHERE a.tenant_id=? AND a.status='active' AND $scope ORDER BY a.display_name,a.id",[$this->c['tenant_id'],...$args])->fetchAll();
        $sum=static function(array $list): array { $out=[]; foreach (self::SUM_FIELDS as $field) { $out[$field]=array_sum(array_column($list,$field)); } return $out; };
        $totals=$this->metrics($sum($rows)); $byUser=[]; $byDay=[];
        foreach ($rows as $row) { $byUser[$row['user_id']][]=$row; $byDay[$row['day']][]=$row; }
        $roster=[]; $areas=[]; $sites=[]; $activeSites=[]; $counts=['online'=>0,'away'=>0,'offline'=>0];
        $allSites=$this->access->full() && !array_intersect(array_keys($_GET),['area','area_id','site','site_id','user','user_id','company_id','country','city']);
        $siteRows=$this->db->run("SELECT s.id FROM org_units s WHERE s.tenant_id=? AND s.kind='site' AND s.active=TRUE AND s.placeholder=FALSE".($allSites?'':" AND EXISTS(SELECT 1 FROM (SELECT u.*,u.id user_id FROM users u) a WHERE a.tenant_id=s.tenant_id AND a.site_id=s.id AND $scope)"),[$this->c['tenant_id'],...($allSites?[]:$args)])->fetchAll();
        foreach ($siteRows as $site) { $sites[$site['id']]=true; }
        foreach ($members as $member) {
            $seconds=$member['last_seen_at']===null?PHP_INT_MAX:max(0,time()-strtotime($member['last_seen_at'].' UTC'));
            $state=$seconds<=300?'online':($seconds<=900?'away':'offline'); $counts[$state]++;
            if (isset($sites[$member['site_id']]) && $state==='online') { $activeSites[$member['site_id']]=true; }
            $m=$this->metrics($sum($byUser[$member['id']]??[]));
            $expected=array_sum(array_column($byUser[$member['id']]??[],'expected_seconds'));
            if ($member['area_id']!==null) { $area=Util::id($member['area_id']); $areas[$area]=($areas[$area]??0)+$m['active_seconds']; }
            $roster[]=['user_id'=>Util::id($member['id']),'display_name'=>$member['display_name'],'online'=>$state==='online','connection_status'=>$state,
                'utilization_percent'=>$expected>0?min(100,round(100*$m['active_seconds']/$expected,2)):null,'active_seconds'=>$m['active_seconds'],'focus_score'=>$m['focus_score'],
                'devices'=>$member['devices']??'','last_seen_at'=>$member['last_seen_at']===null?null:Util::time($member['last_seen_at'])];
        }
        usort($roster,static fn($a,$b)=>array_search($a['connection_status'],['online','away','offline'])<=>array_search($b['connection_status'],['online','away','offline']) ?: strcmp($a['display_name'],$b['display_name']));
        $series=[]; foreach ($byDay as $day=>$list) { $m=$this->metrics($sum($list)); $series[]=['day'=>$day,'active_seconds'=>$m['active_seconds'],'idle_seconds'=>$m['idle_seconds'],'productivity_percent'=>$m['productivity_percent'],'focus_score'=>$m['focus_score'],'distraction_percent'=>$m['distraction_percent']]+array_intersect_key($m,array_flip(ProductivityFormula::FIELDS)); }
        $workload=[]; foreach ($areas as $area=>$seconds) { $workload[]=['area_id'=>$area,'active_seconds'=>$seconds]; }
        return AdminApi::response(['tenant_id'=>Util::id($this->c['tenant_id']),'from'=>$from,'to'=>$to,'generated_at'=>Util::now(),'data_through'=>null,'timezone'=>$this->c['timezone'],
            'online_members'=>$counts['online'],'away_members'=>$counts['away'],'offline_members'=>$counts['offline'],'total_members'=>count($members),'active_sites'=>count($activeSites),'total_sites'=>count($sites),
            'active_seconds'=>$totals['active_seconds'],'idle_seconds'=>$totals['idle_seconds'],'focus_score'=>$totals['focus_score'],'productivity_percent'=>$totals['productivity_percent'],
            'weekly_output'=>$series,'workload_by_area'=>array_slice($workload,0,100),'roster'=>array_slice($roster,0,20)]);
    }
    /** Entrada simulada (agente 4.0.22+) y bloqueado (4.0.24+) del periodo: null si ningun dia lo midio, no cero. */
    private static function measured(array $rows): array
    {
        $out=[];
        foreach (['simulated_seconds','locked_seconds'] as $field) {
            $values=array_filter(array_column($rows,$field),static fn($v)=>$v!==null);
            $out[$field]=$values?(int)array_sum($values):null;
        }
        $active=array_filter($rows,static fn($r)=>(int)$r['active_seconds']+(int)$r['idle_seconds']+(int)($r['locked_seconds']??0)>0);
        foreach (self::PERIOD_FIELDS as $field) {
            $values=array_filter(array_column($active,$field),static fn($v)=>$v!==null);
            $out[$field]=$active && count($values)===count($active)?(int)array_sum($values):null;
        }
        $constancy=array_filter(array_column($active,'constancy_pct'),static fn($v)=>$v!==null);
        $out['constancy_pct']=$constancy?round(array_sum($constancy)/count($constancy),2):null;
        $out['days_with_activity']=count(array_unique(array_column(array_filter($rows,static fn($r)=>(int)$r['active_seconds']>0),'day')));
        return $out;
    }
    private function metrics(array $r): array
    {
        $out=[];
        foreach (self::SUM_FIELDS as $field) { $out[$field]=(int)($r[$field]??0); }
        // Formulas (decision 2026-10-09, ProductivityFormula): productividad = P/J, focus = (P+N)/J, distraccion =
        // 100 - focus, sobre la jornada esperada J. focus_seconds sigue siendo el trabajo en bloques de 25 min o mas
        // («Trabajo profundo» en el panel). Sin clasificar: parte del uso medido, solo como aviso de confiabilidad.
        $out+=ProductivityFormula::percents($out);
        $out['unclassified_percent']=$out['window_active_seconds']>0?round(min(100,100*$out['unclassified_seconds']/$out['window_active_seconds']),2):null;
        return $out;
    }
    private function day(array $r,bool $presence): array
    {
        $out=['day'=>$r['day'],'first_activity'=>$r['first_activity']===null?null:Util::time($r['first_activity']),'last_activity'=>$r['last_activity']===null?null:Util::time($r['last_activity']),'late_seconds'=>$r['late_seconds']===null?null:(int)$r['late_seconds'],
            'scheduled_start'=>$r['scheduled_start'],'punctuality_minutes'=>$r['punctuality_minutes']===null?null:(int)$r['punctuality_minutes'],
            'status'=>$r['expected_seconds']==0?'no_laborable':($r['first_activity']===null?($r['active_seconds']>0?'actividad_sin_hora':'sin_actividad'):($r['late_seconds']>max(0,$r['margin_minutes'])*60?'tarde':($r['margin_minutes']>=0 && $r['early_seconds']>$r['margin_minutes']*60?'temprano':'a_tiempo')))];
        $period=[]; foreach (self::PERIOD_FIELDS as $field) { $period[$field]=$r[$field]===null?null:(int)$r[$field]; }
        // Hora limite de llegada: entrada del horario + margen de puntualidad (el mismo que decide «tarde»).
        $deadline=$r['expected_seconds']==0 || ($r['scheduled_at_utc']??null)===null ? null
            : Util::time((new \DateTimeImmutable($r['scheduled_at_utc'],new \DateTimeZone('UTC')))->modify('+'.(max(0,(int)$r['margin_minutes'])*60).' seconds')->format('Y-m-d H:i:s'));
        return $presence ? $out+['user_id'=>Util::id($r['user_id']),'display_name'=>$r['display_name'],'active_seconds'=>(int)$r['active_seconds'],'arrival_deadline_at'=>$deadline] : $out+$this->metrics($r)+$period+[
            'expected_seconds'=>(int)$r['expected_seconds'],'first_activity_time'=>$r['first_activity_time'],
            'productivity_pct'=>$r['productivity_pct']===null?null:round((float)$r['productivity_pct'],2),'constancy_pct'=>$r['constancy_pct']===null?null:round((float)$r['constancy_pct'],2),
            'deep_work_sessions'=>(int)$r['deep_work_sessions'],'longest_focus_streak_seconds'=>(int)$r['longest_focus_streak_seconds'],
            'simulated_seconds'=>$r['simulated_seconds']===null?null:(int)$r['simulated_seconds'],
            'locked_seconds'=>$r['locked_seconds']===null?null:(int)$r['locked_seconds']];
    }
    private function apps(string $from,string $to): array
    {
        [$scope,$args]=$this->filter(); $zone=new \DateTimeZone($this->c['timezone']);
        $start=Util::sqlTime((new \DateTimeImmutable($from,$zone))->format('c')); $end=Util::sqlTime((new \DateTimeImmutable($to,$zone))->format('c'));
        // Por aplicacion: activo (teclado/raton) e inactivo (ventana al frente sin uso) por separado; orden por uso activo.
        $part=fn(string $col)=>"SUM(FLOOR(e.$col*TIMESTAMPDIFF(MICROSECOND,e.started_at,LEAST(e.ended_at,?))/TIMESTAMPDIFF(MICROSECOND,e.started_at,e.ended_at))-FLOOR(e.$col*TIMESTAMPDIFF(MICROSECOND,e.started_at,GREATEST(e.started_at,?))/TIMESTAMPDIFF(MICROSECOND,e.started_at,e.ended_at)))";
        $rows=$this->db->run("SELECT process_name,active_seconds,idle_seconds,active_seconds+idle_seconds total_seconds,sessions,users_count,SUM(active_seconds+idle_seconds) OVER () all_seconds
            FROM (SELECT e.process_name,{$part('active_seconds')} active_seconds,{$part('idle_seconds')} idle_seconds,
              COUNT(*) sessions,COUNT(DISTINCT e.user_id) users_count
            FROM episodes e JOIN user_assignments a ON a.tenant_id=e.tenant_id AND a.id=e.user_assignment_id AND a.user_id=e.user_id
              JOIN retention_settings r ON r.tenant_id=e.tenant_id
            WHERE e.tenant_id=? AND e.event_date BETWEEN DATE_SUB(DATE(?),INTERVAL 1 DAY) AND DATE(?) AND e.event_date>=UTC_DATE()-INTERVAL r.raw_days DAY
              AND e.started_at<? AND e.ended_at>? AND $scope
              -- Ventanas de sistema (Keeper, pantalla de bloqueo; 0056) fuera del uso por aplicacion y de su porcentaje.
              AND NOT EXISTS (SELECT 1 FROM app_classification k WHERE k.tenant_id=0x00000000000040008000000000000001 AND k.process_name=e.process_name AND k.category='system')
              GROUP BY e.process_name) apps
            ORDER BY active_seconds DESC,total_seconds DESC,process_name LIMIT ?",[$end,$start,$end,$start,$this->c['tenant_id'],$start,$end,$end,$start,...$args,(int)($_GET['limit']??20)])->fetchAll();
        return AdminApi::response(['from'=>$from,'to'=>$to,'total_seconds'=>(int)($rows[0]['all_seconds']??0),'data'=>array_map(static fn($r)=>['process_name'=>$r['process_name'],'app'=>$r['process_name'],'total_seconds'=>(int)$r['total_seconds'],'active_seconds'=>(int)$r['active_seconds'],'idle_seconds'=>(int)$r['idle_seconds'],'sessions'=>(int)$r['sessions'],'users_count'=>(int)$r['users_count'],'percent_total'=>$r['all_seconds']>0?round(100*$r['total_seconds']/$r['all_seconds'],2):0],$rows)]);
    }
}
