<?php
declare(strict_types=1);
namespace Keeper;

/** Tres estados actuales, independientes del resumen administrativo anterior. */
final class SupervisorProtection
{
    public static function device(array $input): array
    {
        $reasons=[];
        $add=static function (string $code,?string $control=null) use (&$reasons,$input): void {
            $reasons[]=['code'=>$code,'device_id'=>$input['device_id']??null,'control'=>$control,
                'report_at'=>isset($input['report_at'])?gmdate('Y-m-d\TH:i:s\Z',$input['report_at']):null,
                'last_seen_at'=>isset($input['last_seen_at'])?gmdate('Y-m-d\TH:i:s\Z',$input['last_seen_at']):null];
        };
        if (!$input['has_controls']) { $add('plan_without_controls'); }
        $future=$input['report_at']!==null && $input['report_at']>$input['now'];
        foreach ($future?[]:$input['failed_controls'] as $control) { $add('control_failed',$control); }
        if ($input['offline']) { $add('device_offline'); }
        if ($input['report_at']===null) { $add('no_report'); }
        elseif ($input['report_at']>$input['now']) { $add('future_report'); }
        elseif ($input['now']-$input['report_at']>86400) { $add('stale_report'); }
        if (!$input['rules_current']) { $add('rules_pending'); }
        if (isset($input['assignment_at']) && ($input['report_at']===null || $input['report_at']<$input['assignment_at'])) { $add('report_before_assignment'); }
        if (!$input['all_required_applied']) { $add('controls_unknown'); }
        foreach ($input['control_reasons']??[] as $reason) { $add($reason['code'],$reason['control']); }
        $state=(!$input['has_controls'] || (!$future && $input['failed_controls']))?'unprotected':($reasons?'unconfirmed':'protected');
        if (!$reasons) { $add('all_confirmed'); }
        foreach ($input['informational_reasons']??[] as $reason) { $add($reason['code'],$reason['control']); }
        return ['state'=>$state,'reasons'=>$reasons];
    }

    public static function person(array $devices,bool $hasControls): array
    {
        if (!$devices || !$hasControls) { return ['state'=>'unprotected','reasons'=>[['code'=>!$devices?'no_devices':'plan_without_controls','device_id'=>null,'control'=>null,'report_at'=>null,'last_seen_at'=>null]]]; }
        $states=array_column($devices,'state');
        $state=in_array('unprotected',$states,true)?'unprotected':(in_array('unconfirmed',$states,true)?'unconfirmed':'protected');
        $reasons=[];
        foreach ($devices as $device) { if ($device['state']!=='protected' || $state==='protected') { array_push($reasons,...$device['reasons']); } }
        return ['state'=>$state,'reasons'=>$reasons];
    }
}
