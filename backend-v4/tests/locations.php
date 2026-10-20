<?php
declare(strict_types=1);
use Keeper\{Database,DeviceLocations,PolicyCompiler,PolicyComposer,Util,Validator};

function locationTests(Database $db): void
{
    $before=$GLOBALS['checks'];
    $site=['site_id'=>'sede','public_ips'=>'["8.8.8.8"]','wifi'=>'[{"ssid":"Oficina","bssid":"aa:bb:cc:dd:ee:ff"}]','latitude'=>4.6,'longitude'=>-74.1,'radius_m'=>100];
    $point=(object)['network_type'=>'unknown'];
    check(DeviceLocations::classify($point,null,[$site])[0]==='sin_dato','ubicación sin evidencia');
    check(DeviceLocations::classify($point,'8.8.8.8',[$site])===['en_sede','sede','red'],'IP de sede');
    check(DeviceLocations::classify($point,'1.1.1.1',[$site])[0]==='fuera','IP sin coincidencia');
    $point=(object)['network_type'=>'wifi','wifi_ssid'=>'Oficina','wifi_bssid'=>'AA:BB:CC:DD:EE:FF','latitude'=>0.0,'longitude'=>0.0,'accuracy_m'=>1.0];
    check(DeviceLocations::classify($point,null,[$site])===['en_sede','sede','red'],'red antes de geocerca y BSSID normalizado');
    $point=(object)['network_type'=>'cable','latitude'=>4.6,'longitude'=>-74.1,'accuracy_m'=>99.0];
    check(DeviceLocations::classify($point,null,[$site])===['en_sede','sede','geocerca'],'geocerca válida');
    $point->accuracy_m=100;
    check(DeviceLocations::classify($point,null,[$site])[0]==='fuera','precisión igual al radio no coincide');
    check(DeviceLocations::classify((object)['network_type'=>'cable'],null,[$site])[0]==='sin_dato','cable sin identificador no inventa fuera');
    $other=$site; $other['site_id']='otra';
    check(DeviceLocations::classify((object)[],'8.8.8.8',[$site,$other])===['en_sede',null,'red'],'red compartida no inventa sede');
    $assignment=fn($scope,$value)=>['id'=>Util::uuid(),'scope'=>$scope,'priority'=>0,'rules'=>[],'management_hosts'=>[],'location_tracking'=>$value];
    $input=['tenant_id'=>Util::uuid(),'device_id'=>Util::uuid(),'functions'=>['ubicacion'],'assignments'=>[$assignment('tenant',true)],'work_schedule'=>null,'rule_schedules'=>[],'location_supported'=>true];
    check(PolicyComposer::compose($input)->location_tracking===true,'ubicación contratada y compatible');
    check(!isset(PolicyComposer::compose(array_replace($input,['location_supported'=>false]))->location_tracking),'agente viejo sin campo');
    check(!isset(PolicyComposer::compose(array_replace($input,['functions'=>[]]))->location_tracking),'plan sin función no captura');
    check(!isset(PolicyComposer::compose(array_replace($input,['assignments'=>[$assignment('tenant',true),$assignment('device',false)]]))->location_tracking),'equipo apaga regla de empresa');

    $u=secondTenant($db); $t=$u['tenant_id']; $key=keypair(); $token=enroll($db,$u,$key); $device=Util::bin($token['device_id']);
    $db->run("UPDATE devices SET agent_version='4.0.29' WHERE tenant_id=? AND id=?",[$t,$device]);
    $db->run('UPDATE device_assignments SET starts_at=UTC_TIMESTAMP(6)-INTERVAL 2 DAY WHERE tenant_id=? AND device_id=?',[$t,$device]);
    $db->run('UPDATE user_assignments SET starts_at=UTC_TIMESTAMP(6)-INTERVAL 2 DAY WHERE tenant_id=?',[$t]);
    accessResetPreloginQuota();
    $admin=adminFixture($db,adminMember($db,$u),['reglas.ver','reglas.editar','usuarios.ver','equipos.ver','equipos.editar','presencia.ubicacion_ver','organizacion.editar','organizacion.ver']);
    $policy=expect(adminRequest($admin,'POST','/policies',['name'=>'Ubicación piloto','target_type'=>'device','target_id'=>$token['device_id'],'enabled'=>true,'rules'=>[],'location_tracking'=>true]),201,'regla ubicación');
    check($policy['location_tracking']===true,'regla devuelve ubicación');
    $p=['event_id'=>Util::uuid(),'at'=>gmdate('Y-m-d\TH:i:s\Z',time()-60),'network_id'=>Util::uuid(),'network_type'=>'wifi','wifi_ssid'=>'Oficina','wifi_bssid'=>'aa:bb:cc:dd:ee:ff'];
    $send=fn($points)=>expect(request('POST','/client/sync',['protocol_version'=>1,'sequence'=>1,'policy_version'=>null,'release_id'=>null,'locations'=>$points],$key,$token['access_token'],Util::uuid()),200,'sync ubicación');
    $sync=$send([$p]); check($sync['location_acks'][0]['status']==='accepted','punto aceptado');
    check($send([$p])['location_acks'][0]['status']==='duplicate','punto duplicado no se duplica');
    $bad=$p; $bad['wifi_ssid']='Cambio';
    check($send([$bad])['location_acks'][0]['status']==='rejected','evento cambiado rechazado');
    $bad['event_id']=Util::uuid(); $bad['latitude']=4.6;
    check($send([$bad])['location_acks'][0]['status']==='rejected','coordenadas incompletas rechazadas');
    $row=$db->one('SELECT * FROM device_locations WHERE tenant_id=? AND device_id=? AND id=?',[$t,$device,Util::bin($p['event_id'])]);
    check($row!==null && $row['public_ip']===null && !$row['ip_at_capture'],'loopback no inventa IP pública');
    $settings=['public_ips'=>['8.8.8.8'],'wifi'=>[['ssid'=>'Oficina','bssid'=>'aa:bb:cc:dd:ee:ff']],'latitude'=>null,'longitude'=>null,'radius_m'=>null];
    expect(adminRequest($admin,'PUT','/organization/'.Util::id($u['site_id']).'/location',$settings),200,'configurar sede');
    check((new DeviceLocations($db))->reclassify($t)===1,'trabajador procesa sede guardada');
    check($db->one('SELECT result FROM device_locations WHERE tenant_id=? AND device_id=? AND id=?',[$t,$device,Util::bin($p['event_id'])])['result']==='en_sede','reclasifica punto reciente');
    $db->run('UPDATE device_locations SET in_work_hours=FALSE WHERE tenant_id=?',[$t]);
    $zone=$db->one('SELECT timezone FROM schedules WHERE tenant_id=? AND id=?',[$t,$u['schedule_id']])['timezone'];
    $day=(new DateTimeImmutable($p['at']))->setTimezone(new DateTimeZone($zone))->format('Y-m-d');
    $path='/devices/'.$token['device_id'].'/locations?day='.$day;
    $history=expect(adminRequest($admin,'GET',$path),200,'historial laboral');
    check($history['data']===[] && $history['latest']===null,'ni último punto revela fuera de horario');
    $history=expect(adminRequest($admin,'GET',$path.'&include_out_of_hours=true'),200,'historial completo');
    check(count($history['data'])===1 && $history['latest']['result']==='en_sede','admin completo ve punto');
    check((int)$db->one("SELECT COUNT(*) n FROM audit_log WHERE tenant_id=? AND action='location.out_of_hours_read'",[$t])['n']===1,'consulta ampliada auditada');
    $scoped=adminFixture($db,adminMember($db,$u),['equipos.ver','presencia.ubicacion_ver'],false,'area',[$u['area_id']]);
    expect(adminRequest($scoped,'GET',$path.'&include_out_of_hours=true'),403,'supervisor no amplía horario');
    $external=externalCredential($db,$u,'api_key',['location:read']);
    $withoutScope=externalCredential($db,$u,'api_key',['members:read']);
    $externalPath='/ext/v1/members/'.Util::id($u['id']).'/locations?day='.$day;
    expect(externalRequest($externalPath.'&include_location=true',$withoutScope['headers']),403,'externo exige scope');
    expect(externalRequest($externalPath,$external['headers']),422,'externo exige parametro de inclusion');
    expect(externalRequest($externalPath.'&include_location=false',$external['headers']),403,'externo exige inclusion explicita');
    $visible=expect(externalRequest($externalPath.'&include_location=true',$external['headers']),200,'externo con scope');
    check($visible['data']===[] && $visible['latest']===null && !$visible['can_include_out_of_hours'],'externo oculta todo punto no laboral');
    $db->run('UPDATE device_locations SET in_work_hours=TRUE WHERE tenant_id=?',[$t]);
    $visible=expect(externalRequest($externalPath.'&include_location=true',$external['headers']),200,'externo con punto laboral');
    check(count($visible['data'])===1,'externo devuelve punto laboral');
    $columns='tenant_id,id,device_id,user_id,at,network_type,wifi_ssid,wifi_bssid,result,in_work_hours,body_hash';
    for ($i=0;$i<502;$i++) {
        $db->run('INSERT INTO device_locations ('.$columns.') SELECT tenant_id,?,device_id,user_id,at,network_type,wifi_ssid,wifi_bssid,result,in_work_hours,body_hash FROM device_locations WHERE tenant_id=? AND device_id=? AND id=?',[Util::bin(Util::uuid()),$t,$device,Util::bin($p['event_id'])]);
    }
    $settings['wifi']=[]; $settings['public_ips']=[];
    expect(adminRequest($admin,'PUT','/organization/'.Util::id($u['site_id']).'/location',$settings),200,'recalculo limitado en HTTP');
    check($db->one('SELECT cursor_at FROM location_reclassification WHERE tenant_id=?',[$t])['cursor_at']===null,'HTTP encola sin recalcular historial');
    check((int)$db->one("SELECT COUNT(*) n FROM device_locations WHERE tenant_id=? AND result='fuera'",[$t])['n']===0,'HTTP no bloquea con escrituras por punto');
    check((new DeviceLocations($db))->reclassify($t,500)===500,'trabajador procesa primer lote acotado');
    check((int)$db->one("SELECT COUNT(*) n FROM device_locations WHERE tenant_id=? AND result='fuera'",[$t])['n']===500,'primer lote solo modifica 500 puntos');
    check((new DeviceLocations($db))->reclassify($t,2)===2,'continua tras reiniciar el trabajador');
    check((new DeviceLocations($db))->reclassify($t,2)===1,'termina lote restante');
    check($db->one('SELECT * FROM location_reclassification WHERE tenant_id=?',[$t])===null,'retira trabajo completado');
    check((int)$db->one("SELECT COUNT(*) n FROM device_locations WHERE tenant_id=? AND result='fuera'",[$t])['n']===503,'todos los puntos reclasificados');
    $db->run('DELETE FROM device_locations WHERE tenant_id=? AND id<>?',[$t,Util::bin($p['event_id'])]);
    $lost='/devices/'.$token['device_id'].'/lost';
    expect(adminRequest($admin,'PUT',$lost,['lost'=>true,'confirmation'=>'','reason'=>'Prueba']),422,'confirmación requerida');
    expect(adminRequest($admin,'PUT',$lost,['lost'=>true,'confirmation'=>'PERDIDO','reason'=>'Prueba']),200,'abrir caso');
    $db->run('UPDATE device_locations SET at=UTC_TIMESTAMP(6)-INTERVAL 91 DAY WHERE tenant_id=?',[$t]);
    check((new DeviceLocations($db))->purge($t,10,microtime(true)+10)===0,'caso abierto conserva puntos');
    expect(adminRequest($admin,'PUT',$lost,['lost'=>false,'confirmation'=>'CERRAR','reason'=>'']),200,'cerrar caso');
    check((new DeviceLocations($db))->purge($t,10,microtime(true)+10)===1,'cerrar permite purgar vencidos');
    $db->run("UPDATE devices SET agent_version='4.0.28' WHERE tenant_id=? AND id=?",[$t,$device]);
    (new PolicyCompiler($db))->recompile($token['device_id']);
    $doc=json_decode($db->one('SELECT document FROM effective_policies WHERE tenant_id=? AND device_id=? ORDER BY policy_version DESC LIMIT 1',[$t,$device])['document']);
    check(!isset($doc->location_tracking),'compilador omite campo en 4.0.28');
    $old=expect(request('POST','/client/sync',['protocol_version'=>1,'sequence'=>1,'policy_version'=>null,'release_id'=>null],$key,$token['access_token'],Util::uuid()),200,'sync agente anterior');
    check(!array_key_exists('location_acks',$old),'respuesta anterior no recibe ACK nuevo');
    if (getenv('KEEPER_TEST_LOCATION_VOLUME')==='1') {
        require_once __DIR__.'/location-volume.php';
        locationVolumeTest($db,$t,$device,$u['id']);
    }
    echo 'PASS locations ('.($GLOBALS['checks']-$before)." assertions)\n";
}
