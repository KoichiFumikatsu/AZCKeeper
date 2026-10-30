<?php
declare(strict_types=1);
namespace Keeper;

/** Consultas por cliente e intervalo, nunca un controlador o una petición por persona. */
final class SupervisorReadModel
{
    private array $candidates=[];
    private int $now;
    public function __construct(private Database $db,private array $origin,private array $query=[])
    {
        $this->now=time();
    }
    public function authorizedContexts(array $origin,?string $tenantId): array
    {
        $this->candidates=(new AdminAuth($this->db,new RateLimiter()))->supervisorContexts($origin);
        $selected=$tenantId===null?null:Util::bin($tenantId); $out=[];
        foreach ($this->candidates as $c) {
            if ($selected!==null && $selected!==$c['tenant_id']) { continue; }
            try { $c['access']=new AdminAccess($this->db,$c,'usuarios.ver','listUsers'); }
            catch (ApiError $e) { if ($e->status!==403) { throw $e; } continue; }
            $out[]=$c;
        }
        if (!$out) { throw new ApiError($selected===null?403:404,$selected===null?'permission_denied':'resource_not_found'); }
        return $out;
    }

    private static function timestamp(?string $v): ?int { return $v===null?null:(new \DateTimeImmutable($v,new \DateTimeZone('UTC')))->getTimestamp(); }
    private static function dateTime(?string $v): ?string { return $v===null?null:Util::time($v); }
    private function today(array $c): string { return (new \DateTimeImmutable('@'.$this->now))->setTimezone(new \DateTimeZone($c['timezone']))->format('Y-m-d'); }
    private static function shift(string $day,int $days): string { return (new \DateTimeImmutable($day))->modify("$days day")->format('Y-m-d'); }
    private function sections(array $c): array
    {
        $out=[]; $accesses=[];
        foreach (['activity'=>['reportes.ver','getPeopleReport'],'presence'=>['presencia.ver','getPresenceReport'],'devices'=>['equipos.ver','listTenantDevices'],
            'compliance'=>['cumplimiento.ver','listDualJobAlerts'],'rules'=>['reglas.ver','getUserRules']] as $section=>[$permission,$operation]) {
            try {
                $accesses[$section]=new AdminAccess($this->db,$c,$permission,$operation);
                $feature=Features::forOperation($operation,$permission);
                if ($feature!==null && !Features::has($this->db,$c['tenant_id'],$feature)) { unset($accesses[$section]); $out[$section]=SupervisorSignals::availability('not_contracted',$feature); }
                else { $out[$section]=SupervisorSignals::availability(); }
            } catch (ApiError $e) {
                if ($e->status!==403) { throw $e; }
                $out[$section]=SupervisorSignals::availability(str_starts_with($e->detail??'','feature_not_contracted:')?'not_contracted':'not_authorized',$e->detail??'permission_denied');
            }
        }
        $protection=$c['platform'];
        if (!$protection) {
            $g=AccessGrants::forContext($this->db,$c);
            $protection=$g!==null && (bool)$this->db->one("SELECT id FROM roles WHERE tenant_id=? AND id=? AND active=TRUE AND seed_key='it'",[$c['tenant_id'],$g['role_id']]);
        }
        if ($protection) { $out['protection']=$out['devices']; }
        return [$out,$accesses,$protection];
    }
    private function roster(array $c,?string $user=null): array
    {
        [$where,$args]=$c['access']->usersSql('u');
        if ($user!==null) { $where.=' AND u.id=?'; $args[]=$user; }
        foreach (['company','site','area'] as $kind) {
            if (isset($this->query[$kind.'_id'])) {
                $id=Util::bin($this->query[$kind.'_id']); $c['access']->requireFilter($kind,$id);
                $where.=" AND u.{$kind}_id=?"; $args[]=$id;
            }
        }
        if (isset($this->query['q']) && trim($this->query['q'])!=='') {
            $where.=" AND u.display_name LIKE ? ESCAPE '='"; $args[]='%'.strtr(trim($this->query['q']),['='=>'==','%'=>'=%','_'=>'=_']).'%';
        }
        return $this->db->run("SELECT u.id,u.display_name,u.company_id,u.site_id,u.area_id,u.position_id,
            co.name company_name,s.name site_name,a.name area_name,p.name position_name
            FROM users u LEFT JOIN org_units co ON co.tenant_id=u.tenant_id AND co.id=u.company_id
            LEFT JOIN org_units s ON s.tenant_id=u.tenant_id AND s.id=u.site_id LEFT JOIN org_units a ON a.tenant_id=u.tenant_id AND a.id=u.area_id
            LEFT JOIN org_units p ON p.tenant_id=u.tenant_id AND p.id=u.position_id
            WHERE u.tenant_id=? AND u.status='active' AND $where ORDER BY u.id",[$c['tenant_id'],...$args])->fetchAll();
    }
    /** Todos los equipos activos del lote; no se pagina antes de evaluar a la persona. */
    public function deviceFacts(array $context,AdminAccess $access,array $userIds): array
    {
        if (!$userIds) { return []; }
        [$where,$args]=$access->usersSql('u'); $in=implode(',',array_fill(0,count($userIds),'?'));
        $protection=$context['include_protection']??false;
        $select=''; $plans=[];
        if ($protection) {
            $plan=PersonPlans::effectiveSql('u');
            $select=",$plan plan_id,d.policy_version,
                (SELECT MAX(da.starts_at) FROM device_assignments da WHERE da.tenant_id=d.tenant_id AND da.device_id=d.id AND da.user_id=d.user_id AND da.ends_at IS NULL) assignment_at,
                (SELECT r.observed_at FROM security_reports r WHERE r.tenant_id=d.tenant_id AND r.device_id=d.id ORDER BY r.observed_at DESC,r.event_id DESC LIMIT 1) report_at,
                (SELECT r.controls FROM security_reports r WHERE r.tenant_id=d.tenant_id AND r.device_id=d.id ORDER BY r.observed_at DESC,r.event_id DESC LIMIT 1) controls,
                (SELECT e.policy_version FROM effective_policies e WHERE e.tenant_id=d.tenant_id AND e.device_id=d.id ORDER BY e.policy_version DESC LIMIT 1) compiled_version,
                (SELECT e.created_at FROM effective_policies e WHERE e.tenant_id=d.tenant_id AND e.device_id=d.id ORDER BY e.policy_version DESC LIMIT 1) compiled_at,
                (SELECT JSON_EXTRACT(e.document,'$.rules[*].kind') FROM effective_policies e WHERE e.tenant_id=d.tenant_id AND e.device_id=d.id ORDER BY e.policy_version DESC LIMIT 1) rule_kinds";
            foreach ($this->db->run('SELECT i.plan_id,i.feature_code FROM person_plan_items i JOIN tenant_features f ON f.tenant_id=? AND f.feature_code=i.feature_code WHERE i.tenant_id=?',[$context['tenant_id'],Util::bin(AdminAuth::PLATFORM)])->fetchAll() as $r) { $plans[$r['plan_id']][]=$r['feature_code']; }
        }
        $pending=$protection && (bool)$this->db->one('SELECT tenant_id FROM admin_policy_recompiles WHERE tenant_id=?',[$context['tenant_id']]);
        $rows=$this->db->run("SELECT d.id,d.user_id,d.hostname,d.agent_version,d.last_seen_at $select FROM devices d JOIN users u ON u.tenant_id=d.tenant_id AND u.id=d.user_id
            WHERE d.tenant_id=? AND d.status='active' AND u.status='active' AND u.id IN ($in) AND $where ORDER BY d.hostname,d.id",[$context['tenant_id'],...$userIds,...$args])->fetchAll();
        $out=[];
        foreach ($rows as $r) {
            $last=self::timestamp($r['last_seen_at']); $connection=SupervisorSignals::connection($last,$this->now);
            $dto=['id'=>Util::id($r['id']),'hostname'=>$r['hostname'],'agent_version'=>$r['agent_version'],
                'connection'=>['state'=>$connection,'last_seen_at'=>self::dateTime($r['last_seen_at']),'device_count'=>1,'availability'=>SupervisorSignals::availability()]];
            if ($protection) {
                $functions=$plans[$r['plan_id']]??[]; $controls=[];
                foreach (json_decode($r['controls']??'[]',true,64,JSON_THROW_ON_ERROR) as $control) { $controls[$control['control_id']]=$control; }
                $kinds=json_decode($r['rule_kinds']??'[]',true,64,JSON_THROW_ON_ERROR); $failed=[]; $unknown=[]; $informational=[]; $required=0;
                foreach (ProtectionSummary::CONTROLS as $key=>$def) {
                    if (!in_array($def['feature'],$functions,true)) {
                        if (($controls[$def['agent']]['state']??null)==='failed') { $informational[]=['code'=>'excluded_control_failed','control'=>$def['label']]; }
                        continue;
                    }
                    $required++; $state=$controls[$def['agent']]['state']??'unknown';
                    if ($state==='failed') { $failed[]=$def['label']; }
                    elseif ($state!=='applied') { $unknown[]=['code'=>$state==='unsupported'?'control_unsupported':'control_unknown','control'=>$def['label']]; }
                    elseif ($def['rule']!==null && !in_array($def['rule'],$kinds,true)) { $unknown[]=['code'=>'no_effective_rules','control'=>$def['label']]; }
                }
                $report=self::timestamp($r['report_at']); $compiled=self::timestamp($r['compiled_at']);
                $current=!$pending && $compiled!==null && $report!==null && $report>=$compiled && $r['assignment_at']!==null && $r['policy_version']!==null && (int)$r['policy_version']>=(int)$r['compiled_version'];
                $dto['protection']=SupervisorProtection::device(['now'=>$this->now,'device_id'=>$dto['id'],'has_controls'=>$required>0,'failed_controls'=>$failed,
                    'offline'=>$connection==='offline','last_seen_at'=>$last,'report_at'=>$report,'rules_current'=>$current,'assignment_at'=>self::timestamp($r['assignment_at']),
                    'all_required_applied'=>!$unknown && !$failed,'control_reasons'=>$unknown,'informational_reasons'=>$informational]);
            }
            $out[$r['user_id']][]=$dto;
        }
        return $out;
    }
    /** Una asignación ajena vuelve no disponible el detalle sin segmentación histórica. */
    private function restrictedHistory(array $c,AdminAccess $access,array $ids,string $from,string $to): array
    {
        if (!$ids) { return []; }
        [$where,$args]=$access->usersSql('a','user_id');
        $zone=new \DateTimeZone($c['timezone']); $utc=new \DateTimeZone('UTC');
        $start=(new \DateTimeImmutable($from,$zone))->setTimezone($utc)->format('Y-m-d H:i:s');
        $end=(new \DateTimeImmutable($to,$zone))->setTimezone($utc)->format('Y-m-d H:i:s');
        $rows=$this->db->run('SELECT DISTINCT a.user_id FROM user_assignments a WHERE a.tenant_id=? AND a.user_id IN ('.implode(',',array_fill(0,count($ids),'?')).") AND a.starts_at<? AND (a.ends_at IS NULL OR a.ends_at>?) AND NOT ($where)",[$c['tenant_id'],...$ids,$end,$start,...$args])->fetchAll();
        return array_fill_keys(array_column($rows,'user_id'),true);
    }
    public function complianceFacts(array $c,AdminAccess $access,array $ids,string $day): array
    {
        if (!$ids) { return []; }
        [$where,$args]=$access->usersSql('u'); [$historical,$ha]=$access->usersSql('a','user_id');
        $in=implode(',',array_fill(0,count($ids),'?')); $facts=[]; $tid=Util::id($c['tenant_id']);
        $restricted=$this->restrictedHistory($c,$access,$ids,$day,self::shift($day,1));
        $rows=$this->db->run("SELECT x.user_id,x.alert_type,x.severity,x.reviewed,x.evidence FROM dual_job_alerts x JOIN users u ON u.tenant_id=x.tenant_id AND u.id=x.user_id
            WHERE x.tenant_id=? AND x.day=? AND u.id IN ($in) AND $where",[$c['tenant_id'],$day,...$ids,...$args])->fetchAll();
        foreach ($rows as $r) {
            if (isset($restricted[$r['user_id']])) { continue; }
            $e=json_decode($r['evidence']??'{}',true,64,JSON_THROW_ON_ERROR);
            $apps=$r['alert_type']==='foreign_app'?($e['apps']??[[]]):[[]];
            foreach ($apps as $app) {
                $facts[$r['user_id']][]=['tenant_id'=>$tid,'user_id'=>Util::id($r['user_id']),'day'=>$day,'type'=>$r['alert_type'],'severity'=>$r['severity'],'reviewed'=>(bool)$r['reviewed'],
                    'process_name'=>is_string($app['process_name']??null)?$app['process_name']:null,'seconds'=>is_numeric($app['active_seconds']??$e['active_seconds']??null)?(int)($app['active_seconds']??$e['active_seconds']):null];
            }
        }
        $rows=$this->db->run("SELECT x.user_id,x.device_id,x.process_name,x.active_seconds,x.first_activity,x.last_activity,x.reviewed
            FROM suspicious_app_detections x JOIN users u ON u.tenant_id=x.tenant_id AND u.id=x.user_id JOIN user_assignments a ON a.tenant_id=x.tenant_id AND a.id=x.user_assignment_id AND a.user_id=x.user_id
            WHERE x.tenant_id=? AND x.day=? AND u.id IN ($in) AND $where AND $historical",[$c['tenant_id'],$day,...$ids,...$args,...$ha])->fetchAll();
        foreach ($rows as $r) {
            $facts[$r['user_id']][]=['tenant_id'=>$tid,'user_id'=>Util::id($r['user_id']),'device_id'=>Util::id($r['device_id']),'day'=>$day,'type'=>'detection','process_name'=>$r['process_name'],
                'seconds'=>(int)$r['active_seconds'],'first_at'=>self::dateTime($r['first_activity']),'last_at'=>self::dateTime($r['last_activity']),'reviewed'=>(bool)$r['reviewed']];
        }
        return [$facts,$restricted];
    }
    private function activityDto(?array $r,array $availability): array
    {
        if ($availability['status']==='available') { $availability=SupervisorSignals::availability($r===null?'unavailable':((int)$r['workday_expected_seconds']===0?'no_workday':(!$r['measured']?'no_measurement':'available')),$r===null?'no_calendar_or_assignment':null); }
        $measured=$r!==null && (bool)$r['measured'] && in_array($availability['status'],['available','no_workday'],true);
        return ['active_seconds'=>$measured?(int)$r['active_seconds']:null,'idle_seconds'=>$measured?(int)$r['idle_seconds']:null,'locked_seconds'=>$measured && $r['locked_seconds']!==null?(int)$r['locked_seconds']:null,
            'productivity_percent'=>$measured?ProductivityFormula::percents($r)['productivity_percent']:null,
            'workday_productive_seconds'=>$measured?(int)$r['workday_productive_seconds']:null,'workday_expected_seconds'=>$r!==null && in_array($availability['status'],['available','no_measurement','no_workday'],true)?(int)$r['workday_expected_seconds']:null,
            'measured'=>$measured,'availability'=>$availability,'data_through'=>$measured?self::dateTime($r['data_through']):null,'calculated_at'=>$measured?self::dateTime($r['calculated_at']):null];
    }
    private function tenantFacts(array $c,?string $day=null,?string $user=null): array
    {
        $day??=$this->today($c); $today=$this->today($c); $to=self::shift($day,1); $yesterday=self::shift($day,-1);
        [$sections,$accesses,$protection]=$this->sections($c);
        $people=$this->roster($c,$user); $ids=array_column($people,'id');
        $retention=$this->db->one('SELECT DATE_SUB(UTC_DATE(),INTERVAL aggregate_months MONTH) aggregate_before,DATE_SUB(UTC_DATE(),INTERVAL raw_days DAY) raw_before FROM retention_settings WHERE tenant_id=?',[$c['tenant_id']]);
        $retained=$retention!==null && $day>=$retention['aggregate_before'];
        $readAccess=$accesses['activity']??$accesses['presence']??null;
        $rows=[];
        if ($ids && $readAccess && $retained) {
            foreach ((new ActivityReports($this->db,$c,$readAccess))->readRows($yesterday,$to,['user_ids'=>$ids,'current_scope'=>true]) as $r) { $rows[$r['user_id']][$r['day']]=$r; }
        }
        $devices=isset($accesses['devices'])?$this->deviceFacts($c+['include_protection'=>$protection && $user!==null],$accesses['devices'],$ids):[];
        [$compliance,$restrictedCompliance]=isset($accesses['compliance']) && $retained && $ids?$this->complianceFacts($c,$accesses['compliance'],$ids,$day):[[],[]];
        $calculation=isset($accesses['compliance']) && $retained && $ids?$this->db->one('SELECT calculated_at,data_through,dirty FROM rollup_days WHERE tenant_id=? AND day=?',[$c['tenant_id'],$day]):null;
        $restricted=$readAccess?$this->restrictedHistory($c,$readAccess,$ids,$day,$to):[];
        $absences=[];
        if ($ids && isset($accesses['presence'])) {
            foreach ($this->db->run("SELECT user_id,starts_at,ends_at FROM absences WHERE tenant_id=? AND status='approved' AND user_id IN (".implode(',',array_fill(0,count($ids),'?')).') AND starts_at<? AND ends_at>?',[$c['tenant_id'],...$ids,self::shift($to,1),$yesterday])->fetchAll() as $a) { $absences[$a['user_id']][]=['start_at'=>Util::time($a['starts_at']),'end_at'=>Util::time($a['ends_at'])]; }
        }
        $out=[];
        foreach ($people as $u) {
            $id=$u['id']; $row=$rows[$id][$day]??null; $scheduleRow=$row; $scheduleDay=$day;
            if ($day===$today && isset($rows[$id][$yesterday])) {
                foreach ($rows[$id][$yesterday]['work_periods'] as $period) {
                    if (strtotime($period['start_at'])<=$this->now && strtotime($period['end_at'])>$this->now) { $scheduleRow=$rows[$id][$yesterday]; $scheduleDay=$yesterday; break; }
                }
            }
            $identity=['tenant_id'=>Util::id($c['tenant_id']),'user_id'=>Util::id($id),'display_name'=>$u['display_name'],'day'=>$day,'timezone'=>$c['timezone']];
            foreach (['company','site','area','position'] as $kind) { $identity[$kind]=$u[$kind.'_id']===null?null:['id'=>Util::id($u[$kind.'_id']),'name'=>$u[$kind.'_name']]; }
            $av=$sections['activity']; if ($av['status']==='available' && !$retained) { $av=SupervisorSignals::availability($retention===null?'unavailable':'not_retained','aggregate_retention'); }
            $activity=$this->activityDto(isset($accesses['activity'])?$row:null,$av);
            $pa=$sections['presence']; if ($pa['status']==='available' && (!$retained || $row===null)) { $pa=SupervisorSignals::availability(!$retained?'not_retained':'unavailable',!$retained?'aggregate_retention':'no_calendar_or_assignment'); }
            $arrival=['first_at'=>null,'source'=>null,'scheduled_at'=>null,'deadline_at'=>null,'margin_minutes'=>null,'late'=>null,'availability'=>$pa];
            $schedule=['day'=>$scheduleDay,'availability'=>$pa,'start_at'=>null,'end_at'=>null,'margin_minutes'=>null,'work_periods'=>[],'lunch'=>null,'absences'=>[]];
            if ($pa['status']==='available') {
                $signal=SupervisorSignals::arrival(['first_at'=>self::timestamp($row['first_activity']),'scheduled_at'=>self::timestamp($row['scheduled_at_utc']),'J'=>(int)$row['workday_expected_seconds'],'margin_minutes'=>(int)$row['margin_minutes']]);
                foreach (['first_at','scheduled_at','deadline_at'] as $key) { $signal[$key]=$signal[$key]===null?null:gmdate('Y-m-d\TH:i:s\Z',$signal[$key]); }
                $arrival=array_replace($arrival,$signal,['source'=>$row['arrival_source']]);
                $periods=$scheduleRow['work_periods'];
                $schedule=array_replace($schedule,['start_at'=>$periods[0]['start_at']??null,'end_at'=>$periods?end($periods)['end_at']:null,'margin_minutes'=>(int)$scheduleRow['margin_minutes']>=0?(int)$scheduleRow['margin_minutes']:null,
                    'work_periods'=>$periods,'lunch'=>$scheduleRow['lunch'],'absences'=>$absences[$id]??[]]);
            }
            $list=$devices[$id]??[]; $last=null;
            foreach ($list as $d) { if ($d['connection']['last_seen_at']!==null) { $last=max($last??0,strtotime($d['connection']['last_seen_at'])); } }
            $connection=['state'=>isset($accesses['devices'])?SupervisorSignals::connection($last,$this->now):null,'last_seen_at'=>$last===null?null:gmdate('Y-m-d\TH:i:s\Z',$last),
                'device_count'=>isset($accesses['devices'])?count($list):null,'availability'=>$sections['devices']];
            $facts=$compliance[$id]??[];
            $common=['tenant_id'=>$identity['tenant_id'],'user_id'=>$identity['user_id'],'day'=>$day,'reviewed'=>false,'severity'=>'medium'];
            if ($arrival['late']===true) { $facts[]=$common+['type'=>'late_arrival','first_at'=>$arrival['first_at']]; }
            $offlineAvailable=$day===$today && isset($accesses['devices']) && $schedule['availability']['status']==='available';
            $epoch=static fn($p)=>['start_at'=>strtotime($p['start_at']),'end_at'=>strtotime($p['end_at'])];
            $deadline=$schedule['start_at']!==null && $schedule['margin_minutes']!==null?strtotime($schedule['start_at'])+$schedule['margin_minutes']*60:null;
            if ($offlineAvailable && $connection['state']==='offline' && $deadline!==null && $this->now>$deadline
                && SupervisorSignals::inWorkPeriod($this->now,array_map($epoch,$schedule['work_periods']),array_map($epoch,[...$schedule['absences'],...($schedule['lunch']===null?[]:[$schedule['lunch']])]))) {
                $facts[]=$common+['type'=>'offline_in_schedule'];
            }
            $alerts=SupervisorSignals::alerts($facts); $pending=array_values(array_filter($alerts,static fn($a)=>!$a['reviewed']));
            $missing=[];
            if (!$offlineAvailable) { $missing[]='offline_in_schedule'; }
            if ($pa['status']!=='available') { $missing[]='late_arrival'; }
            if (!isset($accesses['compliance']) || !$retained || isset($restrictedCompliance[$id]) || ($calculation['calculated_at']??null)===null || $calculation['dirty']) { array_push($missing,'possible_dual_job','suspicious_app'); }
            $some=$pa['status']==='available' || (isset($accesses['compliance']) && $retained && !isset($restrictedCompliance[$id]) && (($calculation['calculated_at']??null)!==null || ($compliance[$id]??[])));
            $summary=['pending_count'=>$some?count($pending):null,'highest_severity'=>$pending[0]['severity']??null,'primary'=>$pending[0]??null,'complete'=>!$missing,
                'calculated_at'=>isset($restrictedCompliance[$id])?null:self::dateTime($calculation['calculated_at']??null),'data_through'=>isset($restrictedCompliance[$id])?null:self::dateTime($calculation['data_through']??null)];
            $current=['connection'=>$connection,'devices'=>$list];
            if ($protection && isset($accesses['devices']) && $user!==null) { $current['protection']=SupervisorProtection::person(array_column($list,'protection'),true); }
            $out[]=['dto'=>$identity+['connection'=>$connection,'arrival'=>$arrival,'activity'=>$activity,'alerts'=>$summary],
                'schedule'=>$schedule,'alerts'=>$alerts,'missing'=>$missing,'current'=>$current,
                'raw_detail_available'=>isset($accesses['activity']) && !isset($restricted[$id]) && $retention!==null && $day>=$retention['raw_before']];
        }
        return ['context'=>$c,'sections'=>$sections,'accesses'=>$accesses,'people'=>$out,'ids'=>$ids];
    }

    private function trend(array $batches): array
    {
        $periods=[]; $current=[]; $previous=[]; $complete=true; $closed=false;
        foreach ($batches as $batch) {
            $c=$batch['context']; $today=$this->today($c); $days=(int)(new \DateTimeImmutable($today))->format('N')-1;
            if ($days===0) { continue; }
            $closed=true; $from=self::shift($today,-$days); $before=self::shift($from,-7); $end=self::shift($today,-7);
            $periods[]=['tenant_id'=>Util::id($c['tenant_id']),'current_from'=>$from,'current_to'=>$today,'previous_from'=>$before,'previous_to'=>$end];
            if (!isset($batch['accesses']['activity'])) { $complete=false; continue; }
            if (!$batch['ids']) { continue; }
            $reader=new ActivityReports($this->db,$c,$batch['accesses']['activity']); $sets=[];
            foreach ([[$from,$today],[$before,$end]] as [$a,$b]) {
                $set=[];
                foreach ($reader->readRows($a,$b,['current_scope'=>true,'user_ids'=>$batch['ids']]) as $row) {
                    $id=$row['user_id']; $set[$id]??=['measured'=>true,'P'=>0,'J'=>0,'days'=>0]; $set[$id]['days']++;
                    if ((int)$row['workday_expected_seconds']<=0) { continue; }
                    $set[$id]['measured']=$set[$id]['measured'] && $row['measured'];
                    $set[$id]['P']+=(int)$row['workday_productive_seconds']; $set[$id]['J']+=(int)$row['workday_expected_seconds'];
                }
                $sets[]=$set;
            }
            foreach ($sets[0] as $id=>$now) {
                $old=$sets[1][$id]??null;
                if ($old!==null && $now['measured'] && $old['measured'] && $now['J']>0 && $old['J']>0 && $now['days']===$days && $old['days']===$days) { $current[]=$now; $previous[]=$old; }
            }
        }
        $a=SupervisorSignals::weighted($current); $b=SupervisorSignals::weighted($previous);
        return ['availability'=>SupervisorSignals::availability($closed && $current && $complete?'available':'unavailable',!$closed?'no_closed_days':(!$complete?'partial_access':(!$current?'no_common_measurement':null))),
            'periods'=>$periods,'people'=>count($current),'current'=>$a,'previous'=>$b,'delta_pp'=>$a['percent']===null || $b['percent']===null?null:$a['percent']-$b['percent']];
    }
    private function summarize(array $rows): array
    {
        $cohort=['people'=>count($rows),'measured_people'=>0,'no_measurement_people'=>0,'no_workday_people'=>0,'not_authorized_people'=>0];
        $weighted=[]; $counts=['connected'=>0,'away'=>0,'offline'=>0]; $countsComplete=true; $late=0; $recorded=0; $arrivalPeople=0; $arrivalsComplete=true; $margins=[];
        $alerts=0; $high=0; $alertPeople=0; $alertsComplete=true;
        foreach ($rows as $p) {
            $a=$p['activity']; $status=$a['availability']['status'];
            if ($status==='no_workday') { $cohort['no_workday_people']++; }
            elseif (in_array($status,['not_authorized','not_contracted'],true)) { $cohort['not_authorized_people']++; }
            elseif ($a['measured'] && ($a['workday_expected_seconds']??0)>0) { $cohort['measured_people']++; }
            else { $cohort['no_measurement_people']++; }
            $weighted[]=['measured'=>$a['measured'],'P'=>$a['workday_productive_seconds'],'J'=>$a['workday_expected_seconds']];
            if ($p['connection']['state']===null) { $countsComplete=false; } else { $counts[$p['connection']['state']]++; }
            if ($p['arrival']['availability']['status']==='available') {
                $arrivalPeople++; $late+=(int)($p['arrival']['late']===true); $recorded+=(int)($p['arrival']['first_at']!==null);
                $margins[$p['tenant_id']]=['tenant_id'=>$p['tenant_id'],'minutes'=>$p['arrival']['margin_minutes']];
            } else { $arrivalsComplete=false; }
            if ($p['alerts']['pending_count']!==null) { $alertPeople++; $alerts+=(int)($p['alerts']['pending_count']>0); $high+=(int)($p['alerts']['highest_severity']==='high'); }
            $alertsComplete=$alertsComplete && $p['alerts']['complete'];
        }
        $values=array_unique(array_column($margins,'minutes'),SORT_REGULAR);
        return ['cohort'=>$cohort,'counts'=>($countsComplete?$counts:array_fill_keys(array_keys($counts),null))+['availability'=>SupervisorSignals::availability($countsComplete?'available':'unavailable',$countsComplete?null:'partial_access')],
            'kpis'=>['productivity'=>SupervisorSignals::weighted($weighted),'arrivals'=>['late'=>$arrivalPeople || !$rows?$late:null,'recorded'=>$arrivalPeople || !$rows?$recorded:null,'margin_minutes'=>count($values)===1?reset($values):null,'margins'=>array_values($margins),'complete'=>$arrivalsComplete],
                'alerts'=>['people'=>$alertPeople || !$rows?$alerts:null,'high_people'=>$alertPeople || !$rows?$high:null,'complete'=>$alertsComplete]]];
    }
    private function cursorContext(string $kind): string
    {
        $auth=[];
        foreach ($this->candidates as $c) {
            $g=$c['platform']?null:AccessGrants::forContext($this->db,$c);
            $auth[]=[Util::id($c['tenant_id']),$c['auth_revision'],$this->today($c),$c['timezone'],
                $g===null?null:AccessGrants::dto($g),$g===null?[]:AccessGrants::permissions($this->db,$c['tenant_id'],$g['role_id']),Features::enabled($this->db,$c['tenant_id'])];
        }
        $query=$this->query; unset($query['cursor'],$query['devices_cursor'],$query['alerts_cursor']); ksort($query);
        return Util::json([bin2hex($this->origin['admin_tenant_id']),bin2hex($this->origin['admin_id']),bin2hex($this->origin['session_id']),$this->origin['platform'],$kind,$query,$auth]);
    }
    /** Cursor por clave, firmado contra la secuencia autorizada y los permisos actuales. */
    private function page(array $rows,array $keys,int $limit,?string $cursor,string $kind): array
    {
        $resources=new Resources($this->db,['cursor_context'=>$this->cursorContext($kind)],new Validator());
        $signature=hash('sha256',Util::json($keys)); $start=0; $exp=$this->now+900; $cut=gmdate('Y-m-d\TH:i:s\Z',$this->now);
        if ($cursor!==null) {
            try { $p=$resources->decodeCursor($cursor); }
            catch (ApiError) { throw new ApiError(422,'supervisor_cursor_stale'); }
            if (($p['signature']??null)!==$signature || ($p['limit']??null)!==$limit) { throw new ApiError(422,'supervisor_cursor_stale'); }
            $found=array_search($p['after']??null,$keys,true);
            if ($found===false) { throw new ApiError(422,'supervisor_cursor_stale'); }
            $start=$found+1; $exp=$p['exp']; $cut=$p['cut'];
        }
        $data=array_slice($rows,$start,$limit); $next=null;
        if ($start+count($data)<count($rows)) { $next=$resources->encodeCursor(['after'=>$keys[$start+count($data)-1],'signature'=>$signature,'limit'=>$limit,'exp'=>$exp,'cut'=>$cut]); }
        return ['data'=>$data,'total'=>count($rows),'next_cursor'=>$next];
    }
    public function overview(): array
    {
        foreach (['company_id','site_id','area_id'] as $key) { if (isset($this->query[$key]) && !isset($this->query['tenant_id'])) { throw new ApiError(422,'validation_failed'); } }
        $contexts=$this->authorizedContexts($this->origin,$this->query['tenant_id']??null);
        $batches=[]; $rows=[]; $sections=[]; $facets=['tenants'=>[],'companies'=>[],'areas'=>[],'sites'=>[]];
        foreach ($contexts as $c) {
            $batch=$this->tenantFacts($c); $batches[]=$batch;
            $sections[]=['tenant_id'=>Util::id($c['tenant_id']),'sections'=>$batch['sections']];
            if ($batch['people']) { $facets['tenants'][]=['id'=>Util::id($c['tenant_id']),'name'=>$c['tenant_name'],'timezone'=>$c['timezone'],'today'=>$this->today($c)]; }
            foreach ($batch['people'] as $f) {
                $p=$f['dto']; $rows[]=$p;
                foreach (['company'=>'companies','site'=>'sites','area'=>'areas'] as $field=>$list) {
                    if ($p[$field]!==null) { $facets[$list][$p['tenant_id'].':'.$p[$field]['id']]=['tenant_id'=>$p['tenant_id']]+$p[$field]; }
                }
            }
        }
        foreach ($facets as &$list) { $list=array_values($list); } unset($list);
        $summary=$this->summarize($rows); $summary['kpis']['trend']=$this->trend($batches);
        if (isset($this->query['state'])) {
            $state=$this->query['state']; $rows=array_values(array_filter($rows,static fn($p)=>$state==='with_alerts'?($p['alerts']['pending_count']??0)>0:$p['connection']['state']===$state));
        }
        $order=$this->query['order']??'attention';
        usort($rows,static fn($a,$b)=>SupervisorSignals::orderKey($a,$order)<=>SupervisorSignals::orderKey($b,$order));
        $keys=array_map(static fn($p)=>SupervisorSignals::orderKey($p,$order),$rows);
        return ['generated_at'=>gmdate('Y-m-d\TH:i:s\Z',$this->now),'capabilities'=>['admin_href'=>$this->origin['platform']?'/admin/':null,'sections'=>$sections],'facets'=>$facets]+$summary+
            $this->page($rows,$keys,(int)($this->query['limit']??50),$this->query['cursor']??null,'overview');
    }
    public function person(string $user): array
    {
        $contexts=$this->authorizedContexts($this->origin,Util::id($this->origin['tenant_id'])); $c=$contexts[0];
        $c['access']->user($user);
        $day=$this->query['day'];
        if ($day>$this->today($c)) { throw new ApiError(422,'invalid_range'); }
        $batch=$this->tenantFacts($c,$day,$user);
        if (!$batch['people']) { throw new ApiError(404,'resource_not_found'); }
        $p=$batch['people'][0]; $dto=$p['dto']; unset($dto['connection'],$dto['alerts']);
        $devices=$p['current']['devices'];
        $devicePage=$this->page($devices,array_column($devices,'id'),(int)($this->query['devices_limit']??50),$this->query['devices_cursor']??null,'devices:'.Util::id($user));
        $devicePage['availability']=$batch['sections']['devices'];
        if ($devicePage['availability']['status']!=='available') { $devicePage['total']=null; }
        $alertPage=$this->page($p['alerts'],array_map(static fn($a)=>[$a['key'],$a['severity'],$a['reviewed']],$p['alerts']),(int)($this->query['alerts_limit']??50),$this->query['alerts_cursor']??null,'alerts:'.Util::id($user));
        $alertPage+=['pending_count'=>$p['dto']['alerts']['pending_count'],'complete'=>!$p['missing'],'unavailable_types'=>$p['missing'],
            'calculated_at'=>$p['dto']['alerts']['calculated_at'],'data_through'=>$p['dto']['alerts']['data_through']];
        if ($alertPage['pending_count']===null) { $alertPage['total']=null; }
        $current=$p['current']; $current['devices']=$devicePage;
        return $dto+['generated_at'=>gmdate('Y-m-d\TH:i:s\Z',$this->now),'sections'=>$batch['sections'],'raw_detail_available'=>$p['raw_detail_available'],
            'schedule'=>$p['schedule'],'alerts'=>$alertPage,'current'=>$current];
    }
}
