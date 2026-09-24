<?php
declare(strict_types=1);
use Keeper\{Config,Database,ExternalAuth,Util,Validator};

function externalRequest(string $path, array $headers=[], string $method='GET', ?string $body=null): array
{
    $received=[]; $curl=curl_init(Config::get('ORIGIN').$path);
    curl_setopt_array($curl,[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>20,CURLOPT_HTTPHEADER=>array_map(fn($k,$v)=>$k.': '.$v,array_keys($headers),$headers),CURLOPT_HEADERFUNCTION=>static function($c,$line) use (&$received): int { if (str_contains($line,':')) { [$k,$v]=explode(':',$line,2); $received[strtolower($k)]=trim($v); } return strlen($line); }]);
    if ($body!==null) { curl_setopt($curl,CURLOPT_POSTFIELDS,$body); }
    $raw=curl_exec($curl); if ($raw===false) { throw new RuntimeException('External HTTP failed'); }
    $status=curl_getinfo($curl,CURLINFO_RESPONSE_CODE); curl_close($curl);
    $v=new Validator(); $json=json_decode($raw,true,64,JSON_THROW_ON_ERROR);
    if ($status>=400) { $v->named(isset($json['error'])?'OAuthError':'Problem',json_decode($raw)); }
    else {
        $route=explode('?',$path)[0]; if ($route==='/v1/oauth/token') { $route='/oauth/token'; }
        foreach ($v->contract['paths'] as $template=>$ops) {
            if (preg_match('~^'.str_replace('\{id\}','[^/]+',preg_quote($template,'~')).'$~D',$route)) {
                $v->check($ops[strtolower($method)]['responses'][(string)$status]['content']['application/json']['schema'],json_decode($raw)); break;
            }
        }
    }
    return [$status,$json,$received,$raw];
}
function externalCredential(Database $db, array $user, string $type, array $scopes): array
{
    $tenant=$user['tenant_id']; $integration=Util::bin(Util::uuid()); $id=Util::bin(Util::uuid()); $public=Util::uuid(); $secret=Util::b64(random_bytes(32));
    $db->run('INSERT INTO integrations (tenant_id,id,name,auth_type,created_at,expires_at) VALUES (?,?,?,?,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6)+INTERVAL 30 DAY)',[$tenant,$integration,'External test',$type]);
    $db->run("INSERT INTO principals (tenant_id,id,kind,integration_id) VALUES (?,?,'integration',?)",[$tenant,Util::bin(Util::uuid()),$integration]);
    foreach ($scopes as $scope) { $db->run('INSERT INTO integration_scopes (tenant_id,integration_id,scope_code) VALUES (?,?,?)',[$tenant,$integration,$scope]); }
    $table=$type==='api_key'?'api_keys':'oauth_clients'; $field=$type==='api_key'?'public_prefix':'client_id';
    $db->run("INSERT INTO $table (tenant_id,id,integration_id,$field,secret_hash,created_at,expires_at) VALUES (?,?,?,?,?,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6)+INTERVAL 30 DAY)",[$tenant,$id,$integration,$public,hash('sha256',$secret,true)]);
    return ['tenant'=>$tenant,'integration'=>$integration,'id'=>$id,'public'=>$public,'secret'=>$secret,'headers'=>$type==='api_key'?['X-API-Key'=>$public.'.'.$secret]:['Authorization'=>'Basic '.base64_encode(urlencode($public).':'.urlencode($secret)),'Content-Type'=>'application/x-www-form-urlencoded']];
}
function externalToken(array $c, ?string $scope=null): array
{
    return externalRequest('/v1/oauth/token',$c['headers'],'POST','grant_type=client_credentials'.($scope===null?'':'&scope='.urlencode($scope)));
}
function externalData(Database $db,array $u,int $active): array
{
    $t=$u['tenant_id']; $id=$u['id']; $device=Util::bin(Util::uuid()); $assignment=$db->one('SELECT id FROM user_assignments WHERE tenant_id=? AND user_id=?',[$t,$id])['id'];
    $db->run("UPDATE user_assignments SET starts_at='2026-09-01' WHERE tenant_id=? AND id=?",[$t,$assignment]);
    $db->run("INSERT INTO devices (tenant_id,id,user_id,hostname,agent_version,os_edition,cpu,ram_bytes,capabilities,last_seen_at) VALUES (?,?,?,'external-device','4.0','test','test',1024,'[]',UTC_TIMESTAMP(6))",[$t,$device,$id]);
    $da=Util::bin(Util::uuid()); $db->run("INSERT INTO device_assignments (tenant_id,id,device_id,user_id,starts_at,reason) VALUES (?,?,?,?,'2026-09-01','fixture')",[$t,$da,$device,$id]);
    $db->run("INSERT INTO episode_daily (tenant_id,day,user_id,user_assignment_id,device_id,process_name,category,active_seconds,idle_seconds,episode_count,calculation_version,calculated_at,data_through) VALUES (?,'2026-09-10',?,?,?,'editor','productive',999,1,1,'ext-test','2026-09-11','2026-09-10 23:00:00'),(?,'2026-09-11',?,?,?,'editor','productive',60,0,1,'ext-test','2026-09-12','2026-09-11 23:00:00')",[$t,$id,$assignment,$device,$t,$id,$assignment,$device]);
    $db->run("INSERT INTO day_summary (tenant_id,day,user_id,user_assignment_id,active_seconds,idle_seconds,productive_seconds,expected_seconds,coverage_percent,productivity_percent,calculation_version,timezone,calculated_at,data_through) VALUES (?,'2026-09-10',?,?,?,30,90,240,50,75,'ext-test','America/Bogota','2026-09-11','2026-09-10 23:00:00')",[$t,$id,$assignment,$active]);
    $db->run("INSERT INTO focus_daily (tenant_id,day,user_id,user_assignment_id,focus_seconds,focus_score,calculation_version,calculated_at,data_through) VALUES (?,'2026-09-10',?,?,80,66.5,'ext-test','2026-09-11','2026-09-10 22:00:00')",[$t,$id,$assignment]);
    $tier=Util::bin(Util::uuid()); $db->run("INSERT INTO tier (tenant_id,id,name,badge_label) VALUES (?,?,'Test','Test badge')",[$t,$tier]);
    $db->run("INSERT INTO subscriptions (tenant_id,id,user_id,tier_id,starts_at) VALUES (?,?,?,?,'2026-09-01')",[$t,Util::bin(Util::uuid()),$id,$tier]);
    foreach (['2026-09-10 04:59:59','2026-09-10 05:00:00','2026-09-11 04:59:59','2026-09-11 05:00:00'] as $at) {
        $db->run("INSERT INTO check_ins (tenant_id,id,event_id,source_identity,user_id,source,direction,occurred_at,latitude,longitude,accuracy_m,body_hash) VALUES (?,?,?,?,?,'manual','in',?,4.1,-74.2,10,?)",[$t,Util::bin(Util::uuid()),Util::bin(Util::uuid()),'fixture',$id,$at,hash('sha256','fixture',true)]);
    }
    $event=Util::bin(Util::uuid());
    $db->run("INSERT INTO episodes (tenant_id,device_id,event_id,user_id,device_assignment_id,user_assignment_id,event_date,started_at,ended_at,process_name,window_title,active_seconds,idle_seconds,body_hash) VALUES (?,?,?,?,?,?,'2026-09-10','2026-09-10 12:00:00','2026-09-10 12:01:00','editor','PRIVATE TITLE',30,30,?)",[$t,$device,$event,$id,$da,$assignment,hash('sha256','fixture',true)]);
    foreach (['device.status_changed','subscription.changed','private.unknown'] as $type) { $db->run('INSERT INTO notifications (tenant_id,id,user_id,type,resource_type,resource_id,title) VALUES (?,?,?,?,?,?,?)',[$t,Util::bin(Util::uuid()),$id,$type,'user',$id,'PRIVATE EMAIL / GPS / TITLE']); }
    return ['device'=>$device,'assignment'=>$assignment,'tier'=>$tier];
}
function externalTests(Database $db): void
{
    $before=$GLOBALS['checks']; $u=secondTenant($db); $v=secondTenant($db); $tenant=$u['tenant_id']; $uid=Util::id($u['id']); $foreign=Util::id($v['id']);
    $data=externalData($db,$u,120); externalData($db,$v,9000); adminMember($db,$u);
    $oauth=externalCredential($db,$u,'oauth2',ExternalAuth::SCOPES); $key=externalCredential($db,$u,'api_key',ExternalAuth::SCOPES); $other=externalCredential($db,$v,'api_key',ExternalAuth::SCOPES);
    $narrow=expect(externalToken($oauth,'members:read'),200,'OAuth subset'); check($narrow['scope']==='members:read' && $narrow['expires_in']===900,'OAuth scope and TTL');
    $token=expect(externalToken($oauth),200,'OAuth defaults'); $bearer=['Authorization'=>'Bearer '.$token['access_token']]; $limited=['Authorization'=>'Bearer '.$narrow['access_token']];
    $stored=$db->one('SELECT id,token_hash,TIMESTAMPDIFF(SECOND,created_at,expires_at) ttl FROM oauth_access_tokens WHERE tenant_id=? AND token_hash=?',[$tenant,hash('sha256',$token['access_token'],true)]);
    check($stored['ttl']===900 && $stored['token_hash']!==$token['access_token'],'opaque token stored hashed');
    $issued=array_column($db->run('SELECT scope_code FROM oauth_token_scopes WHERE tenant_id=? AND token_id=?',[$tenant,$stored['id']])->fetchAll(),'scope_code'); check(!array_diff($issued,ExternalAuth::SCOPES),'persisted scopes subset');
    $small=externalCredential($db,$u,'oauth2',['members:read']); $bad=expect(externalToken($small,'members:read dashboard:read'),400,'scope escalation'); check($bad['error']==='invalid_scope','OAuth invalid_scope');
    foreach (['grant_type=password','grant_type=client_credentials&grant_type=client_credentials','grant_type=client_credentials&client_secret=leak','grant_type=client_credentials&scope=','grant_type=client_credentials&scope=members:read%20%20tiers:read'] as $form) { expect(externalRequest('/v1/oauth/token',$oauth['headers'],'POST',$form),400,'invalid OAuth form'); }
    $wrong=$oauth; $wrong['headers']['Authorization']='Basic '.base64_encode($oauth['public'].':wrong');
    $failure=externalToken($wrong); expect($failure,401,'invalid OAuth secret'); check($failure[2]['www-authenticate']==='Basic realm="keeper-oauth"','OAuth Basic challenge');
    expect(externalRequest('/v1/oauth/token',['Content-Type'=>'application/json'],'POST','{}'),415,'OAuth media type');
    expect(externalRequest('/v1/oauth/token',$oauth['headers']+$key['headers'],'POST','grant_type=client_credentials'),400,'ambiguous OAuth credentials');
    expect(externalRequest('/v1/oauth/token',$oauth['headers']+['X-Tenant-ID'=>Util::id($v['tenant_id'])],'POST','grant_type=client_credentials'),400,'OAuth cannot select tenant');
    $range='?from=2026-09-10&to=2026-09-12'; $day='?from=2026-09-10&to=2026-09-11';
    $routes=['/dashboard'.$range,'/my-team'.$range,'/members/'.$uid.$range,'/members/'.$uid.'/devices','/members/'.$uid.'/activity'.$range,'/members/'.$uid.'/check-ins'.$range,'/members/'.$uid.'/subscription','/tiers','/productivity'.$range,'/organization','/notifications'];
    foreach ([$bearer,$key['headers']] as $headers) {
        foreach ($routes as $route) { $r=externalRequest('/ext/v1'.$route,$headers); expect($r,200,'external route '.$route); check(isset($r[2]['ratelimit-limit'],$r[2]['ratelimit-remaining'],$r[2]['ratelimit-reset'],$r[2]['x-ratelimit-scope']),'external quota headers'); check($r[2]['cache-control']==='private, max-age=30' && str_contains($r[2]['vary'],'X-API-Key'),'private credential cache'); }
        foreach ([''=>$range,'/devices'=>'','/activity'=>$range,'/check-ins'=>$range,'/subscription'=>''] as $suffix=>$query) { expect(externalRequest('/ext/v1/members/'.$foreign.$suffix.$query,$headers),404,'foreign member '.$suffix); }
        foreach (['area_id'=>$v['area_id'],'site_id'=>$v['site_id'],'user_id'=>$v['id']] as $filter=>$id) { expect(externalRequest('/ext/v1/productivity'.$range.'&'.$filter.'='.Util::id($id),$headers),404,'foreign filter '.$filter); }
        expect(externalRequest('/ext/v1/dashboard'.$range,$headers+['X-Tenant-ID'=>Util::id($v['tenant_id'])]),400,'tenant header rejected');
        expect(externalRequest('/ext/v1/dashboard'.$range.'&tenant_id='.Util::id($v['tenant_id']),$headers),422,'tenant query rejected');
    }
    foreach ($routes as $route) { if (str_starts_with($route,'/members/'.$uid.'?')) { continue; } expect(externalRequest('/ext/v1'.$route,$limited),403,'required scope '.$route); }
    $emptyKey=externalCredential($db,$u,'api_key',[]);
    foreach ($routes as $route) { expect(externalRequest('/ext/v1'.$route,$emptyKey['headers']),403,'API key missing scope '.$route); }
    $readKey=externalCredential($db,$u,'api_key',['members:read','activity:read','presence:read']);
    expect(externalRequest('/ext/v1/members/'.$uid.'/activity'.$range.'&include_titles=true',$readKey['headers']),403,'title scope gate');
    expect(externalRequest('/ext/v1/members/'.$uid.'/check-ins'.$range.'&include_location=true',$readKey['headers']),403,'location scope gate');
    $activity=expect(externalRequest('/ext/v1/members/'.$uid.'/activity'.$range,$bearer),200,'private activity'); check(count($activity['data'])===1 && !isset($activity['data'][0]['window_title']),'titles omitted');
    $activity=expect(externalRequest('/ext/v1/members/'.$uid.'/activity'.$range.'&include_titles=true',$bearer),200,'authorized title'); check($activity['data'][0]['window_title']==='PRIVATE TITLE','explicit title disclosure');
    $presence=expect(externalRequest('/ext/v1/members/'.$uid.'/check-ins'.$day,$bearer),200,'local date bounds'); check(count($presence['data'])===2 && !isset($presence['data'][0]['location']),'UTC bounds and GPS omitted');
    $presence=expect(externalRequest('/ext/v1/members/'.$uid.'/check-ins'.$day.'&include_location=true',$bearer),200,'authorized location'); check($presence['data'][0]['location']['latitude']===4.1,'GPS explicit disclosure');
    $feed=expect(externalRequest('/ext/v1/notifications',$bearer),200,'sanitized notifications'); check(count($feed['data'])===2 && !str_contains(Util::json($feed),'PRIVATE'),'notification allowlist and safe titles');
    $team=expect(externalRequest('/ext/v1/my-team'.$range.'&limit=1',$bearer),200,'team cursor first'); check($team['next_cursor']!==null,'team cursor exists');
    $cursor=urlencode($team['next_cursor']); $next=expect(externalRequest('/ext/v1/my-team'.$range.'&limit=1&cursor='.$cursor,$bearer),200,'team next'); check($next['data'][0]['id']!==$team['data'][0]['id'] && $next['next_cursor']===null,'cursor has no duplication');
    expect(externalRequest('/ext/v1/my-team'.$range.'&limit=1&cursor='.$cursor,$other['headers']),404,'foreign tenant cursor');
    expect(externalRequest('/ext/v1/my-team'.$range.'&limit=1&cursor='.$cursor,$key['headers']),404,'foreign integration cursor');
    expect(externalRequest('/ext/v1/my-team'.$day.'&limit=1&cursor='.$cursor,$bearer),404,'changed filter cursor');
    expect(externalRequest('/ext/v1/organization?limit=1&cursor='.$cursor,$bearer),404,'changed path cursor');
    foreach (['?from=2026-09-12&to=2026-09-10','?from=2026-02-30&to=2026-03-01','?from=2026-01-01&to=2026-03-01'] as $invalid) { expect(externalRequest('/ext/v1/dashboard'.$invalid,$bearer),422,'invalid date range'); }
    expect(externalRequest('/ext/v1/productivity?from=2026-01-01&to=2027-01-01',$bearer),200,'366-day report');
    expect(externalRequest('/ext/v1/my-team'.$range.'&status=active',$bearer),422,'undeclared filter');
    expect(externalRequest('/v1/ext/v1/tiers',$bearer),404,'no double prefix');
    expect(externalRequest('/ext/v1/tiers',$bearer+$key['headers']),400,'ambiguous external credentials');
    expect(externalRequest('/ext/v1/tiers'),401,'missing external credential');
    expect(externalRequest('/v1/users',$bearer+['X-Tenant-ID'=>Util::id($tenant)]),401,'OAuth cannot use administrative routes');
    expect(externalRequest('/v1/users',$key['headers']+['X-Tenant-ID'=>Util::id($tenant)]),401,'API key cannot use administrative routes');
    foreach (['POST','PUT','PATCH','DELETE'] as $method) { expect(externalRequest('/ext/v1/tiers',$bearer,$method),404,'external read only'); }
    $report=expect(externalRequest('/ext/v1/productivity'.$range,$bearer),200,'rollups');
    check($report['series'][0]['active_seconds']===120 && $report['series'][1]['active_seconds']===60 && $report['series'][0]['focus_score']===66.5 && $report['series'][0]['productivity_percent']===75,'summary precedence, daily fallback, focus, productivity');
    check($report['data_through']==='2026-09-10T22:00:00.000000Z','oldest contributing freshness');
    $dashboard=expect(externalRequest('/ext/v1/dashboard'.$range,$bearer),200,'tenant dashboard'); check($dashboard['active_seconds']===180 && $dashboard['total_members']===2 && $dashboard['online_members']===1,'fleet totals tenant only');
    $db->run('RENAME TABLE episodes TO external_test_hidden_episodes');
    try { foreach (['/dashboard','/my-team','/members/'.$uid,'/productivity'] as $path) { expect(externalRequest('/ext/v1'.$path.$range,$bearer),200,'aggregate requires no raw '.$path); } }
    finally { $db->run('RENAME TABLE external_test_hidden_episodes TO episodes'); }
    $limiter=new Keeper\RateLimiter(); $context=['tenant_id'=>$tenant,'integration_id'=>$oauth['integration']];
    $hold=function(int $depth) use (&$hold,$limiter,$context,$bearer): void {
        if ($depth===4) { expect(externalRequest('/ext/v1/tiers',$bearer),429,'four simultaneous integration queries'); return; }
        $limiter->externalQueries($context,fn()=>$hold($depth+1));
    }; $hold(0);
    expect(externalRequest('/ext/v1/tiers',$bearer),200,'concurrency leases released');
    $holdTenant=function(int $depth) use (&$holdTenant,$limiter,$tenant,$bearer): void {
        if ($depth===16) { $r=externalRequest('/ext/v1/tiers',$bearer); expect($r,429,'sixteen simultaneous tenant queries'); check($r[2]['x-ratelimit-scope']==='tenant','tenant concurrency scope'); return; }
        $limiter->externalQueries(['tenant_id'=>$tenant,'integration_id'=>Util::bin(Util::uuid())],fn()=>$holdTenant($depth+1));
    }; $holdTenant(0);
    $db->run('UPDATE oauth_access_tokens SET created_at=UTC_TIMESTAMP(6)-INTERVAL 16 MINUTE,expires_at=UTC_TIMESTAMP(6)-INTERVAL 1 MINUTE WHERE tenant_id=? AND token_hash=?',[$tenant,hash('sha256',$narrow['access_token'],true)]);
    expect(externalRequest('/ext/v1/members/'.$uid.$range,$limited),401,'expired OAuth token');
    $db->run('DELETE FROM oauth_token_scopes WHERE tenant_id=? AND integration_id=? AND scope_code=?',[$tenant,$oauth['integration'],'tiers:read']);
    $db->run('DELETE FROM integration_scopes WHERE tenant_id=? AND integration_id=? AND scope_code=?',[$tenant,$oauth['integration'],'tiers:read']);
    expect(externalRequest('/ext/v1/tiers',$bearer),403,'scope withdrawal effective immediately');
    $db->run('INSERT INTO integration_scopes (tenant_id,integration_id,scope_code) VALUES (?,?,?)',[$tenant,$oauth['integration'],'tiers:read']);
    expect(externalRequest('/ext/v1/tiers',$bearer),403,'grant addition does not enlarge issued token');
    $db->run('UPDATE integrations SET auth_version=auth_version+1 WHERE tenant_id=? AND id=?',[$tenant,$oauth['integration']]);
    expect(externalRequest('/ext/v1/tiers',$bearer),401,'integration auth revision invalidates token');
    $fresh=expect(externalToken($oauth),200,'token after revision'); $bearer=['Authorization'=>'Bearer '.$fresh['access_token']];
    $db->run('UPDATE oauth_clients SET revoked_at=UTC_TIMESTAMP(6) WHERE tenant_id=? AND id=?',[$tenant,$oauth['id']]);
    expect(externalRequest('/ext/v1/tiers',$bearer),401,'client revocation invalidates token');
    $db->run('UPDATE integrations SET active=FALSE WHERE tenant_id=? AND id=?',[$tenant,$key['integration']]); expect(externalRequest('/ext/v1/tiers',$key['headers']),401,'API integration deactivation');
    $db->run('UPDATE integrations SET active=TRUE WHERE tenant_id=? AND id=?',[$tenant,$key['integration']]);
    $db->run('UPDATE api_keys SET revoked_at=UTC_TIMESTAMP(6) WHERE tenant_id=? AND id=?',[$tenant,$key['id']]); expect(externalRequest('/ext/v1/tiers',$key['headers']),401,'API key revocation');
    $db->run('UPDATE api_keys SET created_at=UTC_TIMESTAMP(6)-INTERVAL 2 DAY,expires_at=UTC_TIMESTAMP(6)-INTERVAL 1 DAY WHERE tenant_id=? AND id=?',[$tenant,$emptyKey['id']]); expect(externalRequest('/ext/v1/tiers',$emptyKey['headers']),401,'API key expiry');
    $db->run('UPDATE tenants SET status=\'suspended\' WHERE tenant_id=?',[$tenant]); expect(externalRequest('/ext/v1/tiers',$readKey['headers']),401,'tenant suspended'); $db->run('UPDATE tenants SET status=\'active\' WHERE tenant_id=?',[$tenant]);
    $rateUser=secondTenant($db); $rateKey=externalCredential($db,$rateUser,'api_key',['tiers:read']); $limitedRate=false;
    for ($i=0;$i<Config::number('EXTERNAL_BURST',30)+100;$i++) {
        $r=externalRequest('/ext/v1/tiers',$rateKey['headers']); if ($r[0]===429) { check(isset($r[2]['retry-after']) && $r[2]['x-ratelimit-scope']==='integration','integration HTTP quota'); $limitedRate=true; break; }
    }
    check($limitedRate,'integration quota enforced');
    $rotated=Util::b64(random_bytes(32)); $db->run('UPDATE api_keys SET secret_hash=? WHERE tenant_id=? AND id=?',[hash('sha256',$rotated,true),$rateUser['tenant_id'],$rateKey['id']]);
    expect(externalRequest('/ext/v1/tiers',['X-API-Key'=>$rateKey['public'].'.'.$rotated]),429,'rotation preserves integration bucket');
    expect(externalRequest('/ext/v1/tiers',$other['headers']),200,'other tenant same IP unaffected');
    $tenantKey=externalCredential($db,$rateUser,'api_key',['tiers:read']);
    $bucket=['tenant','external:'.bin2hex($rateUser['tenant_id']),Config::number('EXTERNAL_TENANT_RATE',600),Config::number('EXTERNAL_TENANT_BURST',100)];
    for ($i=0;$i<$bucket[3]+100;$i++) { try { $limiter->take([$bucket]); } catch (Keeper\ApiError $e) { check($e->status===429,'tenant quota filled'); break; } }
    $r=externalRequest('/ext/v1/tiers',$tenantKey['headers']); expect($r,429,'tenant bucket shared across integrations'); check($r[2]['x-ratelimit-scope']==='tenant','tenant quota header');
    echo 'EXTERNAL PASS: '.($GLOBALS['checks']-$before)." assertions\n";
}
