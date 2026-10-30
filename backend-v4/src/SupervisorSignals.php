<?php
declare(strict_types=1);
namespace Keeper;

final class SupervisorSignals
{
    public static function availability(string $status='available',?string $reason=null): array { return ['status'=>$status,'reason'=>$reason]; }
    public static function connection(?int $lastSeen,int $now): string { return $lastSeen===null || $now-$lastSeen>900?'offline':($now-$lastSeen>300?'away':'connected'); }
    public static function arrival(array $day): array
    {
        $margin=$day['margin_minutes']??15;
        $start=$day['scheduled_at']??null; $first=$day['first_at']??null;
        $deadline=$start!==null && $margin>=0?$start+$margin*60:null;
        return ['first_at'=>$first,'scheduled_at'=>$start,'deadline_at'=>$deadline,'margin_minutes'=>$margin>=0?$margin:null,
            'late'=>$first!==null && $deadline!==null && ($day['J']??0)>0?$first>$deadline:null];
    }
    public static function weighted(array $days): array
    {
        $p=0; $j=0; $people=0;
        foreach ($days as $d) { if (($d['measured']??false) && ($d['J']??0)>0) { $p+=(int)$d['P']; $j+=(int)$d['J']; $people++; } }
        return ['percent'=>$j>0?min(100.0,100.0*$p/$j):null,'workday_productive_seconds'=>$people?$p:null,'workday_expected_seconds'=>$people?$j:null];
    }
    public static function inWorkPeriod(int $now,array $periods,array $exclusions): bool
    {
        foreach ($exclusions as $p) { if ($now>=$p['start_at'] && $now<$p['end_at']) { return false; } }
        foreach ($periods as $p) { if ($now>=$p['start_at'] && $now<$p['end_at']) { return true; } }
        return false;
    }
    /** Evidencia limitada: nunca títulos ni el JSON original del detector. */
    public static function alerts(array $facts): array
    {
        $out=[]; $ranks=['high'=>0,'medium'=>1,'low'=>2];
        foreach ($facts as $f) {
            $type=$f['type'];
            $kind=match($type) { 'foreign_app','detection'=>'suspicious_app','remote_desktop','after_hours_pattern','suspicious_idle'=>'possible_dual_job','late_arrival','offline_in_schedule'=>$type,default=>null };
            if ($kind===null) { continue; }
            $process=isset($f['process_name'])?mb_substr($f['process_name'],0,200):null;
            $app=isset($f['app_name'])?mb_substr($f['app_name'],0,200):null;
            $identity=$kind==='suspicious_app'?ProtectionSummary::fold($process??$app??''):$type;
            $key=hash('sha256',Util::json([$f['tenant_id'],$f['user_id'],$f['day'],$kind,$identity]));
            $severity=isset($ranks[$f['severity']??''])?$f['severity']:'medium';
            $reviewed=(bool)($f['reviewed']??false);
            if (!isset($out[$key])) {
                $out[$key]=['key'=>$key,'kind'=>$kind,'severity'=>$severity,'day'=>$f['day'],'reviewed'=>$reviewed,
                    'evidence'=>['type'=>$type,'process_name'=>$process,'app_name'=>$app,'seconds'=>isset($f['seconds'])?max(0,(int)$f['seconds']):null,
                        'first_at'=>$f['first_at']??null,'last_at'=>$f['last_at']??null,'explanation'=>match($kind) { 'late_arrival'=>'Primera señal posterior al margen configurado','offline_in_schedule'=>'Sin señal reciente durante la jornada','possible_dual_job'=>'Señal pendiente de interpretación; no confirma doble empleo',default=>'Aplicación detectada' }],
                    'device_ids'=>[]];
            } else {
                $out[$key]['reviewed']=$out[$key]['reviewed'] && $reviewed;
                if ($ranks[$severity]<$ranks[$out[$key]['severity']]) { $out[$key]['severity']=$severity; }
            }
            if (isset($f['device_id'])) { $out[$key]['device_ids'][]=$f['device_id']; }
        }
        foreach ($out as &$a) { $a['device_ids']=array_values(array_unique($a['device_ids'])); sort($a['device_ids']); } unset($a);
        $out=array_values($out);
        usort($out,static fn($a,$b)=>[self::alertRank($a),$a['key']]<=>[self::alertRank($b),$b['key']]);
        return $out;
    }
    private static function alertRank(array $a): int
    {
        if ($a['severity']==='high') { return 0; }
        if ($a['severity']==='medium' && in_array($a['kind'],['suspicious_app','possible_dual_job'],true)) { return 1; }
        return match($a['kind']) { 'late_arrival'=>2,'offline_in_schedule'=>3,default=>4 };
    }
    public static function attentionKey(array $person): array
    {
        $rank=isset($person['alerts']['primary'])?self::alertRank($person['alerts']['primary']):5;
        if (($person['connection']['state']??null)==='away') { $rank=min($rank,3); }
        return [$rank,ProtectionSummary::fold($person['display_name']),$person['tenant_id'],$person['user_id']];
    }
    public static function orderKey(array $person,string $order): array
    {
        $name=[ProtectionSummary::fold($person['display_name']),$person['tenant_id'],$person['user_id']];
        if ($order==='attention') { return self::attentionKey($person); }
        if ($order==='name') { return $name; }
        $metric=$person['activity'][$order==='active'?'active_seconds':'productivity_percent'];
        return [$metric===null?1:0,-($metric??0),...$name];
    }
}
