<?php
declare(strict_types=1);
use Keeper\{Database,Util};

function k3UpdateTests(Database $db,array $platform,array $admin,array $agent,array $key,array $release): void
{
    $before=$GLOBALS['checks']; $id=$agent['device_id']; $path='/devices/'.$id;
    $d=expect(adminRequest($platform,'GET',$path),200,'update ring device');
    $patch=fn(array $body,int $version)=>adminRequest($platform,'PATCH',$path,$body,['if-match'=>'"'.$version.'"']);
    expect(adminRequest($admin,'PATCH',$path,['release_ring'=>'pruebas'],['if-match'=>'"'.$d['version'].'"']),403,'only platform changes update rings');
    expect($patch(['release_ring'=>'invalid'],$d['version']),422,'unknown ring denied');
    expect($patch(['release_ring'=>'pausado'],$d['version']),422,'pause requires reason');
    expect($patch(['release_ring'=>'pausado','release_pause_reason'=>'  '],$d['version']),422,'blank pause reason denied');
    expect($patch(['release_pause_reason'=>'alone'],$d['version']),422,'reason requires ring');
    expect($patch(['release_ring'=>'pruebas'],$d['version']+1),412,'update ring stale version');
    $d=expect($patch(['release_ring'=>'pruebas'],$d['version']),200,'select trial ring');
    expect(request('GET','/client/releases/'.$release['id'],null,$key,$agent['access_token']),404,'trial device cannot fetch stable release');
    $deploy=['release_id'=>$release['id'],'ring'=>'pruebas','percentage'=>100,'enabled'=>true];
    expect(adminRequest($platform,'POST','/release-deployments',$deploy,['idempotency-key'=>Util::uuid()]),201,'publish trial ring');
    expect(request('GET','/client/releases/'.$release['id'],null,$key,$agent['access_token']),200,'trial device receives trial release');
    $d=expect($patch(['release_ring'=>'pausado','release_pause_reason'=>'  Aplicación incompatible  '],$d['version']),200,'pause device');
    check($d['release_pause_reason']==='Aplicación incompatible','pause reason normalized');
    expect(adminRequest($platform,'POST','/release-deployments',array_replace($deploy,['ring'=>'pausado']),['idempotency-key'=>Util::uuid()]),422,'cannot publish paused ring');
    // Incluso un despliegue antiguo o insertado directamente no debe saltarse la pausa.
    $db->run("INSERT INTO release_deployments (tenant_id,release_id,ring,percentage,enabled) VALUES (?,?,'pausado',100,TRUE)",[$platform['tenant'],Util::bin($release['id'])]);
    expect(request('GET','/client/releases/'.$release['id'],null,$key,$agent['access_token']),404,'paused device cannot fetch explicit release');
    $sync=expect(request('POST','/client/sync',['protocol_version'=>1,'sequence'=>2,'policy_version'=>null,'release_id'=>null],$key,$agent['access_token'],Util::uuid()),200,'paused device still syncs');
    check($sync['release']===null,'paused sync has no release');
    $listed=expect(adminRequest($platform,'GET','/tenants/'.Util::id($platform['tenant']).'/devices?limit=100'),200,'paused reason in list');
    $match=array_values(array_filter($listed['data'],fn($r)=>$r['id']===$id))[0]; check($match['release_pause_reason']===$d['release_pause_reason'],'list exposes pause reason');
    $d=expect($patch(['release_ring'=>'stable'],$d['version']),200,'resume automatic updates');
    check($d['release_pause_reason']===null,'resume clears pause reason');
    expect(adminRequest($platform,'POST','/release-deployments',array_replace($deploy,['ring'=>'stable']),['idempotency-key'=>Util::uuid()]),201,'promote same release to stable');
    expect(request('GET','/client/releases/'.$release['id'],null,$key,$agent['access_token']),200,'resumed device receives release');
    $audit=$db->one("SELECT changed_fields FROM audit_log WHERE tenant_id=? AND resource_id=? AND action='patchDevice' ORDER BY at DESC LIMIT 1",[$platform['tenant'],Util::bin($id)]);
    check($audit!==null && str_contains($audit['changed_fields'],'release_ring') && str_contains($audit['changed_fields'],'release_pause_reason'),'ring changes audited');
    echo 'PASS K3 update rings ('.($GLOBALS['checks']-$before)." assertions)\n";
}
