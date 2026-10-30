<?php
declare(strict_types=1);
require dirname(__DIR__).'/config/bootstrap.php';
use Keeper\{SupervisorProtection as P,SupervisorSignals as S};
$checks=0;
function expectSame(mixed $expected,mixed $actual): void { global $checks; $checks++; if ($expected!==$actual) { throw new RuntimeException('Expected '.json_encode($expected).', got '.json_encode($actual)); } }
$now=1800000000;
$base=['now'=>$now,'has_controls'=>true,'failed_controls'=>[],'offline'=>false,'report_at'=>$now-86400,'rules_current'=>true,'all_required_applied'=>true];
expectSame('protected',P::device($base)['state']);
foreach ([['report_at'=>$now-86401],['report_at'=>null],['report_at'=>$now+1],['offline'=>true],['rules_current'=>false],['all_required_applied'=>false],['assignment_at'=>$now-10]] as $change) { expectSame('unconfirmed',P::device(array_replace($base,$change))['state']); }
foreach ([['failed_controls'=>['web']],['offline'=>true,'failed_controls'=>['usb']],['report_at'=>$now-90000,'failed_controls'=>['web']],['has_controls'=>false]] as $change) { expectSame('unprotected',P::device(array_replace($base,$change))['state']); }
expectSame(['control_failed','device_offline','stale_report'],array_column(P::device(array_replace($base,['failed_controls'=>['web'],'offline'=>true,'report_at'=>$now-86401]))['reasons'],'code'));
expectSame('unprotected',P::person([],true)['state']);
expectSame('protected',P::person([P::device($base)],true)['state']);
expectSame('unprotected',P::person([P::device($base),P::device(array_replace($base,['failed_controls'=>['usb']]))],true)['state']);
expectSame('unconfirmed',P::person([P::device($base),P::device(array_replace($base,['offline'=>true]))],true)['state']);

foreach ([300=>'connected',301=>'away',900=>'away',901=>'offline'] as $age=>$expected) { expectSame($expected,S::connection($now-$age,$now)); }
expectSame('offline',S::connection(null,$now));
expectSame('unconfirmed',P::device(array_replace($base,['report_at'=>$now+1,'failed_controls'=>['web']]))['state']);
$excluded=P::device($base+['informational_reasons'=>[['code'=>'excluded_control_failed','control'=>'USB']]]);
expectSame('protected',$excluded['state']);
expectSame(['all_confirmed','excluded_control_failed'],array_column($excluded['reasons'],'code'));
foreach ([900=>false,901=>true] as $seconds=>$late) { expectSame($late,S::arrival(['first_at'=>$now+$seconds,'scheduled_at'=>$now,'J'=>3600,'margin_minutes'=>15])['late']); }
expectSame(false,S::arrival(['first_at'=>$now+901,'scheduled_at'=>$now,'J'=>3600,'margin_minutes'=>20])['late']);
expectSame(true,S::arrival(['first_at'=>$now+1,'scheduled_at'=>$now,'J'=>3600,'margin_minutes'=>0])['late']);
expectSame(null,S::arrival(['first_at'=>null,'scheduled_at'=>$now,'J'=>3600])['late']);
expectSame(null,S::arrival(['first_at'=>$now+901,'scheduled_at'=>$now,'J'=>0])['late']);
expectSame(25.0,S::weighted([['measured'=>true,'P'=>60,'J'=>120],['measured'=>true,'P'=>60,'J'=>360]])['percent']);
expectSame(0.0,S::weighted([['measured'=>true,'P'=>0,'J'=>120]])['percent']);
expectSame(null,S::weighted([['measured'=>false,'P'=>0,'J'=>120]])['percent']);
expectSame(null,S::weighted([['measured'=>true,'P'=>20,'J'=>0]])['percent']);
expectSame(100.0,S::weighted([['measured'=>true,'P'=>180,'J'=>120],['measured'=>true,'P'=>60,'J'=>120]])['percent']);
$facts=[['tenant_id'=>'t','user_id'=>'u','day'=>'2026-10-30','type'=>'foreign_app','process_name'=>'APP.exe','app_name'=>'App','severity'=>'low','device_id'=>'a','reviewed'=>false],['tenant_id'=>'t','user_id'=>'u','day'=>'2026-10-30','type'=>'detection','process_name'=>'app.exe','app_name'=>'App','device_id'=>'b','reviewed'=>false]];
$alerts=S::alerts($facts); expectSame(1,count($alerts)); expectSame('suspicious_app',$alerts[0]['kind']); expectSame(['a','b'],$alerts[0]['device_ids']);
expectSame('low',S::alerts([$facts[0]])[0]['severity']);
$person=['display_name'=>'Ána','tenant_id'=>'t','user_id'=>'u','connection'=>['state'=>'away'],'alerts'=>['primary'=>$alerts[0]]];
expectSame(S::attentionKey($person),S::attentionKey($person+['protection'=>['state'=>'unprotected']]));
expectSame(true,S::attentionKey($person)>S::attentionKey(array_replace($person,['alerts'=>['primary'=>array_replace($alerts[0],['severity'=>'high'])]])));
expectSame(false,S::inWorkPeriod($now,[['start_at'=>$now-10,'end_at'=>$now+10]],[['start_at'=>$now-1,'end_at'=>$now+1]]));
expectSame(true,S::inWorkPeriod($now,[['start_at'=>$now-10,'end_at'=>$now+10]],[]));
expectSame(false,S::inWorkPeriod($now,[['start_at'=>$now-10,'end_at'=>$now]],[]));
echo "SUPERVISOR UNIT PASS: $checks assertions\n";
